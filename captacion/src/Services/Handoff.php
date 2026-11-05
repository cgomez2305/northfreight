<?php
/**
 * HandoffService (doc §11, A9): entrega de la ficha de un lead a NorthStar cuando pasa a «Presentado a NorthStar», y confirmacion posterior (firmado / rechazado).
 *
 * Flujo
 *  1. LeadActions::setStage(... 'presented') llama a onPresented() DENTRO de su transaccion: crea la entrega (`handoffs`, version 1) y una `handoff_deliveries` + un job
 *     `handoff_deliver` por destino activo (correo, webhook firmado). Es idempotente: si ya hay una entrega abierta del mismo intento no crea otra. Sin ningun destino configurado
 *     la entrega queda `no_target` y el lead conserva handoff_status = pending (la interfaz avisa «Destino de entrega sin configurar»).
 *  2. La cola entrega (handler deliver(): reintentos 1/5/15 min). Cada exito/fallo deja evento `handoff_sent` / `handoff_failed`.
 *  3. NorthStar (o Carlos) confirma: enlace sin login /c/{token} (dos tokens, «Firmó» y «Lo rechazamos», HMAC con APP_KEY, 30 dias, un solo uso) o los botones de la ficha.
 *     Firmó -> etapa Firmado/Contratado, signed_at, handoff_status = signed, conversion, A12 a los 30 dias y aviso por Telegram.
 *     Rechazado -> etapa Perdido/Descartado con el motivo, handoff_status = rejected, tarea para Carlos y aviso por Telegram.
 *  4. «Reenviar entrega» crea la version siguiente y deja sin efecto (superseded) los enlaces de la anterior.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\Handoff\DispatchCrmTarget;
use Captacion\Services\Handoff\EmailTarget;
use Captacion\Services\Handoff\HandoffTarget;
use Captacion\Services\Handoff\WebhookTarget;

final class Handoff
{
    public const TOKEN_DAYS = 30;

    /** @return list<HandoffTarget> */
    public static function targets(): array
    {
        return [new EmailTarget(), new WebhookTarget(), new DispatchCrmTarget()];
    }

    public static function target(string $key): ?HandoffTarget
    {
        foreach (self::targets() as $t) {
            if ($t->key() === $key) {
                return $t;
            }
        }
        return null;
    }

    /** @return list<HandoffTarget> los destinos configurados y activos */
    public static function activeTargets(): array
    {
        return array_values(array_filter(self::targets(), static fn(HandoffTarget $t) => $t->enabled()));
    }

    public static function configured(): bool
    {
        return self::activeTargets() !== [];
    }

    // --- Configuracion (Configuracion > Entrega) --------------------------------------------------------------------------

    /** @return array{email:string,webhook_url:string,webhook_on:bool,webhook_secret:string,secret_set:bool} el secreto va descifrado: NUNCA lo imprimas ni lo registres */
    public static function config(): array
    {
        try {
            $s = Settings::get('handoff', []);
        } catch (\Throwable) {
            $s = [];
        }
        $s = is_array($s) ? $s : [];
        $secret = '';
        if (is_string($s['webhook_secret'] ?? null) && $s['webhook_secret'] !== '') {
            try {
                $secret = Crypto::decrypt($s['webhook_secret']) ?? '';
            } catch (\Throwable) {
                $secret = '';
            }
        }
        $email = is_string($s['email'] ?? null) && filter_var($s['email'], FILTER_VALIDATE_EMAIL) ? $s['email'] : '';
        return ['email' => $email, 'webhook_url' => is_string($s['webhook_url'] ?? null) ? $s['webhook_url'] : '', 'webhook_on' => (bool) ($s['webhook_on'] ?? false), 'webhook_secret' => $secret, 'secret_set' => $secret !== ''];
    }

    /** Motivo por el que una URL de webhook no sirve, o null. HTTPS obligatorio (http solo con APP_ENV=local); sin credenciales en la URL; sin direcciones privadas ni localhost en produccion. */
    public static function urlProblem(string $url): ?string
    {
        $p = parse_url($url);
        $local = !Env::isProduction();
        if ($p === false || !isset($p['scheme'], $p['host']) || strlen($url) > 300) {
            return 'Escribe una URL completa (https://…), de hasta 300 caracteres.';
        }
        if (!in_array(strtolower($p['scheme']), $local ? ['https', 'http'] : ['https'], true)) {
            return 'La URL debe empezar por https://.';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'No pongas usuario ni contraseña en la URL: usa el secreto de firma.';
        }
        $host = strtolower($p['host']);
        if (!$local) {
            $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) !== $host ? gethostbyname($host) : null);
            if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
                || ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
                return 'La URL apunta a una dirección interna o privada; usa la URL pública de tu n8n.';
            }
        }
        return null;
    }

    /** @return array{ok:bool,errors:array<string,string>,values:array{email:string,webhook_url:string,webhook_on:bool}} */
    public static function validate(array $in): array
    {
        $err = [];
        $email = is_string($in['email'] ?? null) ? trim($in['email']) : '';
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
            $err['email'] = 'Ese correo no es válido. Déjalo vacío para no entregar por correo.';
        }
        $url = is_string($in['webhook_url'] ?? null) ? trim($in['webhook_url']) : '';
        $on = ($in['webhook_on'] ?? '') === '1';
        if ($url !== '') {
            $p = self::urlProblem($url);
            if ($p !== null) {
                $err['webhook_url'] = $p;
            }
        } elseif ($on) {
            $err['webhook_url'] = 'Para activar el webhook escribe su URL.';
        }
        return ['ok' => !$err, 'errors' => $err, 'values' => ['email' => $email, 'webhook_url' => $url, 'webhook_on' => $on]];
    }

    /**
     * Guarda destino y secreto. $secret: null = no tocar; '' = borrar; texto = nuevo (>= 16 caracteres).
     * @return array{ok:bool,msg:string}
     */
    public static function saveConfig(array $v, ?string $secret, int $userId): array
    {
        $before = self::config();
        $raw = Settings::get('handoff', []);
        $raw = is_array($raw) ? $raw : [];
        $next = ['email' => $v['email'], 'webhook_url' => $v['webhook_url'], 'webhook_on' => $v['webhook_on'], 'webhook_secret' => (string) ($raw['webhook_secret'] ?? '')];
        $secretChanged = false;
        if ($secret !== null) {
            if ($secret !== '' && strlen($secret) < 16) {
                return ['ok' => false, 'msg' => 'El secreto debe tener al menos 16 caracteres (usa «Generar uno nuevo»).'];
            }
            $next['webhook_secret'] = $secret === '' ? '' : Crypto::encrypt($secret);
            $secretChanged = true;
        }
        if ($next['webhook_on'] && $next['webhook_secret'] === '') {
            return ['ok' => false, 'msg' => 'Para activar el webhook falta el secreto de firma.'];
        }
        Settings::set('handoff', $next);
        // el registro lleva el destino y si cambio el secreto, nunca el secreto
        ActivityLog::settingsChange($userId, 'handoff', ['email' => $before['email'], 'webhook_url' => $before['webhook_url'], 'webhook_on' => $before['webhook_on']], ['email' => $v['email'], 'webhook_url' => $v['webhook_url'], 'webhook_on' => $v['webhook_on']]);
        if ($secretChanged) {
            ActivityLog::record('handoff_secret_changed', $userId, 'settings', 'handoff', ['cleared' => $secret === '']);
        }
        return ['ok' => true, 'msg' => 'Destino de entrega guardado.'];
    }

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    // --- Tokens de confirmacion (/c/{token}) -------------------------------------------------------------------------------

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @param 's'|'r' $action firmado | rechazado */
    public static function token(array $h, string $action): string
    {
        $payload = self::b64((int) $h['id'] . '.' . $action . '.' . (int) Clock::parse((string) $h['expires_at']) . '.' . $h['nonce']);
        return $payload . self::b64(hash_hmac('sha256', 'confirm|' . $payload, Crypto::key(), true));   // sin punto: algunos servidores tratan «.xxx» como extension de archivo
    }

    /** @return array{signed:string,rejected:string} */
    public static function urls(array $h): array
    {
        return ['signed' => Url::base() . '/c/' . self::token($h, 's'), 'rejected' => Url::base() . '/c/' . self::token($h, 'r')];
    }

    /** @return array{status:'ok'|'invalid'|'expired'|'used',handoff:?array,action:?string,lead:?array} */
    public static function verifyToken(string $token): array
    {
        $bad = ['status' => 'invalid', 'handoff' => null, 'action' => null, 'lead' => null];
        if (preg_match('/^([A-Za-z0-9_-]{20,200})([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            return $bad;
        }
        try {
            $expected = self::b64(hash_hmac('sha256', 'confirm|' . $m[1], Crypto::key(), true));
        } catch (\Throwable) {
            return $bad;
        }
        if (!hash_equals($expected, $m[2])) {
            return $bad;
        }
        $raw = base64_decode(strtr($m[1], '-_', '+/'), true);
        if ($raw === false || preg_match('/^(\d{1,12})\.([sr])\.(\d{9,11})\.([a-f0-9]{32})$/', $raw, $p) !== 1) {
            return $bad;
        }
        $h = Db::one('SELECT * FROM handoffs WHERE id = ?', [(int) $p[1]]);
        if ($h === null || !hash_equals((string) $h['nonce'], $p[4])) {
            return $bad;
        }
        $lead = Lead::find((int) $h['lead_id']);
        $base = ['handoff' => $h, 'action' => $p[2] === 's' ? 'signed' : 'rejected', 'lead' => $lead];
        if ($h['confirm_status'] !== null || $lead === null) {
            return ['status' => 'used'] + $base;
        }
        if ((int) $p[3] < Clock::time() || (int) Clock::parse((string) $h['expires_at']) < Clock::time()) {
            return ['status' => 'expired'] + $base;
        }
        return ['status' => 'ok'] + $base;
    }

    // --- Crear la entrega --------------------------------------------------------------------------------------------------

    /** Entrega abierta (sin confirmar) del intento actual del lead, o null. */
    public static function open(int $leadId): ?array
    {
        return Db::one('SELECT h.* FROM handoffs h JOIN leads l ON l.id = h.lead_id AND l.attempt = h.attempt WHERE h.lead_id = ? AND h.confirm_status IS NULL ORDER BY h.version DESC LIMIT 1', [$leadId]);
    }

    /** Llamado por LeadActions::setStage al pasar a «Presentado» (dentro de su transaccion). Idempotente. */
    public static function onPresented(int $leadId, ?int $userId): ?int
    {
        $open = self::open($leadId);
        if ($open !== null) {
            return (int) $open['id'];
        }
        return self::create($leadId, 'stage', $userId);
    }

    /** Crea la entrega siguiente (version +1) y encola un job por destino activo. */
    private static function create(int $leadId, string $reason, ?int $userId): int
    {
        $lead = Lead::find($leadId);
        if ($lead === null) {
            throw new \RuntimeException('El lead no existe.');
        }
        $attempt = (int) $lead['attempt'];
        $version = 1 + (int) Db::value('SELECT COALESCE(MAX(version), 0) FROM handoffs WHERE lead_id = ? AND attempt = ?', [$leadId, $attempt]);
        $id = Db::insert('handoffs', [
            'lead_id' => $leadId, 'attempt' => $attempt, 'version' => $version, 'reason' => $reason, 'created_by' => $userId, 'status' => 'pending',
            'nonce' => bin2hex(random_bytes(16)), 'expires_at' => Clock::shift(self::TOKEN_DAYS * 86400),
        ]);
        Db::update('leads', $leadId, ['handoff_status' => 'pending']);
        LeadActions::event($leadId, $version > 1 ? 'handoff_resent' : 'handoff_created', ['handoff_id' => $id, 'version' => $version, 'reason' => $reason], $userId);
        ActivityLog::record($version > 1 ? 'handoff_resend' : 'handoff_create', $userId, 'lead', $leadId, ['handoff_id' => $id, 'version' => $version]);
        self::attachDeliveries($id);
        return $id;
    }

    /** Crea las entregas que falten para los destinos activos (y las encola). @return int destinos con entrega nueva */
    public static function attachDeliveries(int $handoffId): int
    {
        $h = Db::one('SELECT * FROM handoffs WHERE id = ?', [$handoffId]);
        if ($h === null) {
            return 0;
        }
        $n = 0;
        foreach (self::activeTargets() as $t) {
            $st = Db::run('INSERT IGNORE INTO handoff_deliveries (handoff_id, target, status) VALUES (?, ?, ?)', [$handoffId, $t->key(), 'pending']);
            if ($st->rowCount() === 1) {
                $n++;
                Queue::push('handoff_deliver', ['handoff_id' => $handoffId, 'target' => $t->key()], Clock::now(), 'handoff:' . $handoffId . ':' . $t->key());
            }
        }
        if ($n === 0 && (int) Db::value('SELECT COUNT(*) FROM handoff_deliveries WHERE handoff_id = ?', [$handoffId]) === 0) {
            Db::update('handoffs', $handoffId, ['status' => 'no_target']);
            LeadActions::event((int) $h['lead_id'], 'handoff_no_target', ['handoff_id' => $handoffId], null);
        } elseif ($n > 0 && $h['status'] === 'no_target') {
            Db::update('handoffs', $handoffId, ['status' => 'pending']);
        }
        return $n;
    }

    /** «Reenviar entrega»: nueva version; las anteriores sin confirmar quedan sin efecto. */
    public static function resend(int $leadId, int $userId): array
    {
        return Db::transaction(static function () use ($leadId, $userId): array {
            $lead = Lead::find($leadId, true);
            if ($lead === null) {
                return ['ok' => false, 'msg' => 'El lead no existe.'];
            }
            if ($lead['stage'] !== 'presented') {
                return ['ok' => false, 'msg' => 'Solo se reenvía la entrega de un lead en «Presentado a NorthStar».'];
            }
            Db::run("UPDATE handoffs SET confirm_status = 'superseded' WHERE lead_id = ? AND attempt = ? AND confirm_status IS NULL", [$leadId, (int) $lead['attempt']]);
            $id = self::create($leadId, 'manual', $userId);
            $targets = (int) Db::value('SELECT COUNT(*) FROM handoff_deliveries WHERE handoff_id = ?', [$id]);
            return ['ok' => true, 'msg' => $targets > 0 ? 'Entrega reenviada (versión ' . (int) Db::value('SELECT version FROM handoffs WHERE id = ?', [$id]) . '): ' . $targets . ' destino' . ($targets === 1 ? '' : 's') . ' en cola.' : 'Entrega creada, pero no hay destino configurado: Configuración > Entrega.'];
        });
    }

    /** Entregas abiertas que se quedaron sin destino: les crea las entregas que falten ahora que hay destino. */
    public static function retryPending(int $userId): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'msg' => 'Todavía no hay ningún destino activo.'];
        }
        $n = 0;
        foreach (Db::all("SELECT id FROM handoffs WHERE confirm_status IS NULL AND status = 'no_target'") as $r) {
            $n += self::attachDeliveries((int) $r['id']) > 0 ? 1 : 0;
        }
        ActivityLog::record('handoff_retry_pending', $userId, 'handoff', null, ['handoffs' => $n]);
        return ['ok' => true, 'msg' => $n > 0 ? $n . ' entrega' . ($n === 1 ? '' : 's') . ' pendiente' . ($n === 1 ? '' : 's') . ' puesta' . ($n === 1 ? '' : 's') . ' en cola.' : 'No había entregas pendientes sin destino.'];
    }

    // --- Entrega (job handoff_deliver) -------------------------------------------------------------------------------------

    public static function deliver(array $payload, array $job = []): void
    {
        $h = Db::one('SELECT * FROM handoffs WHERE id = ?', [(int) ($payload['handoff_id'] ?? 0)]);
        $d = $h !== null ? Db::one('SELECT * FROM handoff_deliveries WHERE handoff_id = ? AND target = ?', [(int) $h['id'], (string) ($payload['target'] ?? '')]) : null;
        if ($h === null || $d === null) {
            throw new PermanentJobFailure('Entrega inexistente.');
        }
        if ($d['status'] === 'sent' || $d['status'] === 'skipped') {
            return;   // idempotente: una entrega ya hecha no se repite
        }
        $lead = Lead::find((int) $h['lead_id']);
        if ($lead === null) {
            throw new PermanentJobFailure('El lead ya no existe.');
        }
        $target = self::target((string) $d['target']);
        if ($h['confirm_status'] === 'superseded' || $target === null || !$target->enabled()) {
            Db::update('handoff_deliveries', (int) $d['id'], ['status' => 'skipped', 'error' => $h['confirm_status'] === 'superseded' ? 'Reemplazada por una entrega más nueva.' : 'Destino apagado.']);
            self::refreshStatus((int) $h['id']);
            return;
        }
        $h['urls'] = self::urls($h);
        $attempts = (int) $d['attempts'] + 1;
        try {
            $r = $target->send(HandoffCard::build($lead), $h);
        } catch (\RuntimeException $t) {
            $msg = mb_substr(Storage::redact($t->getMessage()), 0, 280);
            $final = (int) ($job['attempts'] ?? $attempts) > count(Queue::BACKOFF);
            Db::update('handoff_deliveries', (int) $d['id'], ['status' => $final ? 'failed' : 'retrying', 'attempts' => $attempts, 'error' => $msg]);
            if ($final) {
                LeadActions::event((int) $h['lead_id'], 'handoff_failed', ['handoff_id' => (int) $h['id'], 'target' => $d['target'], 'error' => $msg], null);
                ActivityLog::record('handoff_failed', null, 'lead', (int) $h['lead_id'], ['target' => $d['target'], 'handoff_id' => (int) $h['id']]);
            }
            self::refreshStatus((int) $h['id']);
            throw $t;
        }
        Db::update('handoff_deliveries', (int) $d['id'], ['status' => 'sent', 'attempts' => $attempts, 'http_code' => $r['code'], 'error' => null, 'sent_at' => Clock::now()]);
        LeadActions::event((int) $h['lead_id'], 'handoff_sent', ['handoff_id' => (int) $h['id'], 'target' => $d['target'], 'version' => (int) $h['version'], 'logged_only' => str_contains($r['msg'], 'registró') || null], null);
        ActivityLog::record('handoff_sent', null, 'lead', (int) $h['lead_id'], ['target' => $d['target'], 'handoff_id' => (int) $h['id']]);
        self::refreshStatus((int) $h['id']);
    }

    public static function refreshStatus(int $handoffId): void
    {
        $by = [];
        foreach (Db::all('SELECT status FROM handoff_deliveries WHERE handoff_id = ?', [$handoffId]) as $r) {
            $by[(string) $r['status']] = ($by[(string) $r['status']] ?? 0) + 1;
        }
        $total = array_sum($by);
        $sent = $by['sent'] ?? 0;
        $failed = $by['failed'] ?? 0;
        $open = ($by['pending'] ?? 0) + ($by['retrying'] ?? 0);
        $status = match (true) {
            $total === 0                    => 'no_target',
            $open > 0                       => $sent > 0 ? 'partial' : 'pending',
            $failed > 0 && $sent > 0        => 'partial',
            $failed > 0                     => 'failed',
            $sent > 0                       => 'sent',
            default                         => 'pending',
        };
        Db::update('handoffs', $handoffId, ['status' => $status]);
    }

    // --- Confirmacion --------------------------------------------------------------------------------------------------------

    /**
     * Confirma una entrega abierta. $via: link | panel | crm. $extra: reason (motivo del doc §7) y note (solo al rechazar).
     * Exactamente una vez: un segundo intento (o un enlace de otra version) no cambia nada.
     * @return array{ok:bool,msg:string,lead_id:?int}
     */
    public static function confirm(int $handoffId, string $outcome, string $via, ?int $userId, array $extra = []): array
    {
        if (!in_array($outcome, ['signed', 'rejected'], true)) {
            return ['ok' => false, 'msg' => 'Resultado no válido.', 'lead_id' => null];
        }
        $reason = (string) ($extra['reason'] ?? 'rejected');
        $note = trim((string) preg_replace('/[^\P{C}\n\t]/u', '', (string) ($extra['note'] ?? '')));
        $note = mb_substr($note, 0, 500);
        if ($outcome === 'rejected') {
            if (!isset(Catalog::LOSS_REASONS[$reason])) {
                return ['ok' => false, 'msg' => 'Elige un motivo de la lista.', 'lead_id' => null];
            }
            if ($reason === 'other' && mb_strlen($note) < 3) {
                return ['ok' => false, 'msg' => 'Con el motivo «Otro» escribe una nota que lo explique.', 'lead_id' => null];
            }
        }
        try {
            return Db::transaction(static function () use ($handoffId, $outcome, $via, $userId, $reason, $note): array {
                $h = Db::one('SELECT * FROM handoffs WHERE id = ? FOR UPDATE', [$handoffId]);
                if ($h === null || $h['confirm_status'] !== null) {
                    return ['ok' => false, 'msg' => 'Esta entrega ya fue confirmada o reemplazada.', 'lead_id' => $h !== null ? (int) $h['lead_id'] : null];
                }
                $lead = Lead::find((int) $h['lead_id'], true);
                if ($lead === null || $lead['stage'] !== 'presented') {
                    return ['ok' => false, 'msg' => 'El lead ya no está en «Presentado a NorthStar»: no hay nada que confirmar.', 'lead_id' => $lead !== null ? (int) $lead['id'] : null];
                }
                $leadId = (int) $lead['id'];
                $now = Clock::now();
                Db::update('handoffs', $handoffId, ['confirm_status' => $outcome, 'confirmed_at' => $now, 'confirmed_via' => $via] + ($outcome === 'rejected' ? ['reject_reason' => $reason, 'reject_note' => $note !== '' ? $note : null] : []));
                $who = $via === 'link' ? 'Confirmado por NorthStar (enlace)' : 'Marcado a mano en el panel';
                if ($outcome === 'signed') {
                    $to = $lead['pipeline'] === 'B' ? 'hired' : 'signed';
                    $r = LeadActions::setStage($leadId, $to, ['auto' => true, 'note' => $who], $userId);
                    if (!$r['ok']) {
                        throw new \RuntimeException($r['msg']);
                    }
                    LeadActions::event($leadId, 'handoff_confirmed', ['handoff_id' => $handoffId, 'via' => $via], $userId);
                    ActivityLog::record('handoff_signed', $userId, 'lead', $leadId, ['handoff_id' => $handoffId, 'via' => $via]);
                    return ['ok' => true, 'msg' => 'Firma registrada.', 'lead_id' => $leadId];
                }
                $r = LeadActions::setStage($leadId, 'lost', ['auto' => true, 'reason' => $reason, 'note' => ($note !== '' ? $note : $who)], $userId);
                if (!$r['ok']) {
                    throw new \RuntimeException($r['msg']);
                }
                Db::update('leads', $leadId, ['handoff_status' => 'rejected']);
                LeadActions::event($leadId, 'handoff_rejected', ['handoff_id' => $handoffId, 'via' => $via, 'reason' => $reason, 'note' => $note !== '' ? $note : null], $userId);
                ActivityLog::record('handoff_rejected', $userId, 'lead', $leadId, ['handoff_id' => $handoffId, 'via' => $via, 'reason' => $reason]);
                $title = 'Revisar el rechazo de NorthStar: ' . Catalog::LOSS_REASONS[$reason];
                $taskId = Db::insert('tasks', ['title' => $title, 'lead_id' => $leadId, 'due_at' => $now, 'priority' => 1, 'created_by' => $userId, 'created_at' => $now]);
                LeadActions::event($leadId, 'task_created', ['task_id' => $taskId, 'title' => $title, 'due_at' => $now, 'priority' => 1], $userId);
                Tasks::resetCache();
                Queue::push('handoff_notify', ['lead_id' => $leadId, 'kind' => 'rejected', 'handoff_id' => $handoffId], $now, 'handoff_notify:rejected:' . $handoffId);
                return ['ok' => true, 'msg' => 'Rechazo registrado.', 'lead_id' => $leadId];
            });
        } catch (\RuntimeException $t) {
            return ['ok' => false, 'msg' => $t->getMessage(), 'lead_id' => null];
        }
    }

    /** Marcado manual desde la ficha (Carlos). Usa la entrega abierta; sin ella, hace el mismo cambio de etapa sin entrega. */
    public static function confirmFromPanel(int $leadId, string $outcome, int $userId, array $extra = []): array
    {
        $open = self::open($leadId);
        if ($open !== null) {
            return self::confirm((int) $open['id'], $outcome, 'panel', $userId, $extra);
        }
        $lead = Lead::find($leadId);
        if ($lead === null || $lead['stage'] !== 'presented') {
            return ['ok' => false, 'msg' => 'El lead no está en «Presentado a NorthStar».', 'lead_id' => null];
        }
        $r = $outcome === 'signed'
            ? LeadActions::setStage($leadId, $lead['pipeline'] === 'B' ? 'hired' : 'signed', ['note' => 'Marcado a mano en el panel'], $userId)
            : LeadActions::setStage($leadId, 'lost', ['reason' => (string) ($extra['reason'] ?? 'rejected'), 'note' => (string) ($extra['note'] ?? '')], $userId);
        return ['ok' => $r['ok'], 'msg' => $r['msg'], 'lead_id' => $leadId];
    }

    /** Llamado por LeadActions::setStage al pasar a Firmado/Contratado (por la via que sea): cierra la entrega abierta, registra la conversion, programa A12 y avisa a Carlos. Idempotente. */
    public static function afterSigned(array $lead, ?int $userId): void
    {
        $leadId = (int) $lead['id'];
        $now = Clock::now();
        $h = Db::one('SELECT id FROM handoffs WHERE lead_id = ? AND attempt = ? AND confirm_status IS NULL ORDER BY version DESC LIMIT 1', [$leadId, (int) $lead['attempt']]);
        if ($h !== null) {
            Db::update('handoffs', (int) $h['id'], ['confirm_status' => 'signed', 'confirmed_at' => $now, 'confirmed_via' => 'panel']);
        }
        LeadActions::event($leadId, 'conversion', ['pipeline' => $lead['pipeline'], 'source' => $lead['source']], $userId);
        Sequences::enrollReferral($leadId, Clock::time());
        try {
            Referrals::onSigned($lead, $userId);   // si el lead llego con un codigo de referido: bono «pendiente de aprobar» y aviso
        } catch (\Throwable $t) {
            Storage::error('referral onSigned failed', get_class($t) . ': ' . $t->getMessage());
        }
        Queue::push('handoff_notify', ['lead_id' => $leadId, 'kind' => 'signed'], $now, 'handoff_notify:signed:' . $leadId . ':' . (int) $lead['attempt']);
    }

    /** Job handoff_notify: aviso a Carlos por Telegram cuando NorthStar firma o rechaza. */
    public static function notify(array $payload): void
    {
        $lead = Lead::find((int) ($payload['lead_id'] ?? 0));
        if ($lead === null) {
            throw new PermanentJobFailure('El lead ya no existe.');
        }
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = Fmt::name($lead);
        $link = 'Panel: <a href="' . $e(Url::lead((int) $lead['id'])) . '">abrir lead #' . (int) $lead['id'] . '</a>';
        if (($payload['kind'] ?? '') === 'rejected') {
            $h = Db::one('SELECT reject_reason, reject_note FROM handoffs WHERE id = ?', [(int) ($payload['handoff_id'] ?? 0)]);
            $text = "<b>RECHAZADO por NorthStar</b>\n<b>" . $e($name) . '</b> (' . $e(Catalog::label(Catalog::PROFILES, $lead['profile'])) . ")\nMotivo: " . $e(Catalog::label(Catalog::LOSS_REASONS, $h['reject_reason'] ?? 'rejected'))
                . (!empty($h['reject_note']) ? "\nNota: " . $e($h['reject_note']) : '') . "\n" . $link;
        } else {
            $text = "<b>FIRMADO</b>: " . $e($name) . ' (' . $e(Catalog::label(Catalog::PROFILES, $lead['profile'])) . ")\nFuente: " . $e(Catalog::source($lead['source'])) . "\n" . $link;
        }
        Telegram::send($text);
        LeadIngest::event((int) $lead['id'], 'alert_sent', ['channel' => 'telegram', 'kind' => (string) ($payload['kind'] ?? 'signed')]);
    }

    // --- Prueba de entrega (Configuracion > Entrega) -----------------------------------------------------------------------

    /** Envia la ficha ficticia a cada destino activo, en el momento. Guarda el resultado en settings['handoff.last_test']. */
    public static function sendTest(int $userId): array
    {
        $card = HandoffCard::sample();
        $h = ['id' => 0, 'version' => 1, 'reason' => 'test', 'urls' => ['signed' => '', 'rejected' => '']];
        $results = [];
        foreach (self::activeTargets() as $t) {
            try {
                $r = $t->send($card, $h);
                $results[$t->key()] = ['ok' => true, 'msg' => $r['msg'], 'code' => $r['code']];
            } catch (\Throwable $e) {
                $results[$t->key()] = ['ok' => false, 'msg' => mb_substr(Storage::redact($e->getMessage()), 0, 240), 'code' => null];
            }
        }
        $out = ['at' => Clock::now(), 'results' => $results];
        Settings::set('handoff.last_test', $out);
        ActivityLog::record('handoff_test', $userId, 'settings', 'handoff', ['targets' => array_keys($results), 'ok' => count(array_filter($results, static fn($r) => $r['ok']))]);
        return $out;
    }

    // --- Para las pantallas ------------------------------------------------------------------------------------------------------

    /** Estado de la entrega de un lead para la ficha. */
    public static function forLead(int $leadId): array
    {
        $latest = Db::one('SELECT * FROM handoffs WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$leadId]);
        return [
            'latest' => $latest, 'deliveries' => $latest !== null ? Db::all('SELECT target, status, attempts, error, sent_at FROM handoff_deliveries WHERE handoff_id = ? ORDER BY id', [(int) $latest['id']]) : [],
            'open' => self::open($leadId) !== null, 'configured' => self::configured(),
        ];
    }

    /** Resumen para Configuracion > Entrega. */
    public static function overview(): array
    {
        $by = ['pending' => 0, 'sent' => 0, 'partial' => 0, 'failed' => 0, 'no_target' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM handoffs WHERE confirm_status IS NULL OR confirm_status <> ? GROUP BY status', ['superseded']) as $r) {
            $by[(string) $r['status']] = (int) $r['n'];
        }
        $recent = Db::all(
            "SELECT h.id, h.lead_id, h.version, h.status, h.confirm_status, h.created_at, l.first_name, l.last_name,
                    (SELECT GROUP_CONCAT(CONCAT(d.target, ':', d.status) ORDER BY d.id SEPARATOR ' · ') FROM handoff_deliveries d WHERE d.handoff_id = h.id) AS deliveries
               FROM handoffs h JOIN leads l ON l.id = h.lead_id ORDER BY h.id DESC LIMIT 8"
        );
        return ['counts' => $by, 'recent' => $recent, 'last_test' => Settings::get('handoff.last_test'), 'waiting' => (int) Db::value("SELECT COUNT(*) FROM handoffs WHERE confirm_status IS NULL AND status = 'no_target'")];
    }
}
