<?php
/**
 * Webhooks salientes GENERICOS (fase AM). Un destino (URL HTTPS) con secreto HMAC, los eventos a los que esta suscrito y un interruptor «incluir contacto».
 * Distinto del webhook de ENTREGA a NorthStar (Handoff), que es un solo evento con confirmacion; este avisa de lo que pasa en el CRM para n8n u otros sistemas.
 *
 * Eventos (EVENTS): lead.created, lead.hot, lead.stage_changed, task.overdue, consent.revoked, lead.anonymized y rule.fired (lo emite la accion «enviar webhook saliente» de las reglas).
 *
 * USO desde otro codigo:  OutboundWebhooks::emit('lead.anonymized', $leadId, [], 'anon:' . $leadId . ':' . $stamp);
 *   - $event  uno de EVENTS (si no esta suscrito o el webhook esta apagado, no hace nada y devuelve null);
 *   - $leadId id del lead (o null si el evento no es de un lead); el cuerpo lleva id, codigo, etapa, temperatura, fuente, puntuacion y enlace a la ficha;
 *   - $extra  datos escalares propios del evento (se limpian: nunca nombre, telefono ni correo);
 *   - $key    clave de idempotencia opcional: el mismo (evento, clave) no se encola dos veces. Sin clave cada llamada es un evento nuevo.
 *   Nunca lanza. Si ya hay una transaccion abierta, la entrega se encola dentro de ella (si hace rollback, el evento tambien desaparece).
 *   `lead.anonymized` NUNCA lleva nombre ni telefono, ni siquiera con «incluir contacto».
 *
 * Envio: por la cola (job `outbound_webhook`), reintentos a 1, 5 y 15 min (Queue::BACKOFF), POST JSON versionado (schema northstar.captacion.v1) con
 *   X-NS-Signature: sha256=<hex> = HMAC-SHA256(secreto, "<X-NS-Timestamp>.<cuerpo>"), X-NS-Timestamp, X-NS-Event, X-NS-Delivery e Idempotency-Key (= event_id). Quien recibe debe rechazar
 *   marcas de tiempo de mas de 5 minutos y descartar un event_id repetido. Mismo filtro SSRF que el webhook de entrega (Handoff::urlProblem), REVALIDADO al enviar. Sin redirecciones.
 * El secreto vive cifrado en settings, se muestra una sola vez al generarlo y nunca se registra.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class OutboundWebhooks
{
    public const SCHEMA = 'northstar.captacion.v1';
    public const EVENTS = [
        'lead.created' => 'Lead nuevo', 'lead.hot' => 'Lead caliente', 'lead.stage_changed' => 'Cambio de etapa', 'task.overdue' => 'Tarea vencida',
        'consent.revoked' => 'Consentimiento revocado', 'lead.anonymized' => 'Lead anonimizado', 'rule.fired' => 'Una regla de eventos actuó',
    ];
    /** Claves que jamas viajan en $extra aunque alguien las pase. */
    private const PII_KEYS = ['name', 'first_name', 'last_name', 'phone', 'phone_e164', 'email', 'address', 'dob'];
    public const KEEP_DAYS = 30;

    // --- Configuracion ---------------------------------------------------------------------------------------------------------------------

    /** @return array{enabled:bool,url:string,secret:string,secret_set:bool,events:list<string>,contact:bool} el secreto va descifrado: NUNCA lo imprimas ni lo registres */
    public static function config(): array
    {
        try {
            $s = Settings::get('webhooks.out', []);
        } catch (\Throwable) {
            $s = [];
        }
        $s = is_array($s) ? $s : [];
        $secret = '';
        if (is_string($s['secret'] ?? null) && $s['secret'] !== '') {
            try {
                $secret = Crypto::decrypt($s['secret']) ?? '';
            } catch (\Throwable) {
                $secret = '';
            }
        }
        $events = array_values(array_filter(is_array($s['events'] ?? null) ? $s['events'] : [], static fn($e) => is_string($e) && isset(self::EVENTS[$e])));
        return ['enabled' => (bool) ($s['enabled'] ?? false), 'url' => is_string($s['url'] ?? null) ? $s['url'] : '', 'secret' => $secret, 'secret_set' => $secret !== '', 'events' => $events, 'contact' => (bool) ($s['contact'] ?? false)];
    }

    /** Configuracion para la pantalla: igual que config() pero SIN el secreto. @return array{enabled:bool,url:string,secret_set:bool,events:list<string>,contact:bool} */
    public static function viewConfig(): array
    {
        $c = self::config();
        unset($c['secret']);
        return $c;
    }

    /** ¿Esta apagado, sin URL o sin secreto? Entonces no se emite nada. */
    public static function active(): bool
    {
        $c = self::config();
        return $c['enabled'] && $c['url'] !== '' && $c['secret_set'];
    }

    public static function wants(string $event): bool
    {
        $c = self::config();
        return $c['enabled'] && $c['url'] !== '' && $c['secret_set'] && in_array($event, $c['events'], true);
    }

    /** @return array{ok:bool,errors:array<string,string>,values:array{enabled:bool,url:string,events:list<string>,contact:bool}} */
    public static function validate(array $in): array
    {
        $err = [];
        $url = is_string($in['url'] ?? null) ? trim($in['url']) : '';
        $on = ($in['enabled'] ?? '') === '1';
        $events = [];
        foreach ((is_array($in['events'] ?? null) ? $in['events'] : []) as $e) {
            if (is_string($e) && isset(self::EVENTS[$e]) && !in_array($e, $events, true)) {
                $events[] = $e;
            }
        }
        if ($url !== '') {
            $p = Handoff::urlProblem($url);
            if ($p !== null) {
                $err['url'] = $p;
            }
        } elseif ($on) {
            $err['url'] = 'Para activar los webhooks salientes escribe la URL de destino.';
        }
        if ($on && !$events) {
            $err['events'] = 'Elige al menos un evento para activar los webhooks salientes.';
        }
        return ['ok' => !$err, 'errors' => $err, 'values' => ['enabled' => $on, 'url' => $url, 'events' => $events, 'contact' => ($in['contact'] ?? '') === '1']];
    }

    /**
     * Guarda la configuracion. $secret: null = no tocar; '' = borrar; texto = nuevo (>= 16 caracteres). @return array{ok:bool,msg:string}
     */
    public static function saveConfig(array $v, ?string $secret, int $userId): array
    {
        $before = self::config();
        $raw = Settings::get('webhooks.out', []);
        $raw = is_array($raw) ? $raw : [];
        $enc = (string) ($raw['secret'] ?? '');
        if ($secret !== null) {
            if ($secret !== '' && strlen($secret) < 16) {
                return ['ok' => false, 'msg' => 'El secreto debe tener al menos 16 caracteres (usa «Generar uno nuevo»).'];
            }
            $enc = $secret === '' ? '' : Crypto::encrypt($secret);
        }
        if ($v['enabled'] && $enc === '') {
            return ['ok' => false, 'msg' => 'Para activar los webhooks salientes falta el secreto de firma: genera uno.'];
        }
        Settings::set('webhooks.out', ['enabled' => $v['enabled'], 'url' => $v['url'], 'secret' => $enc, 'events' => $v['events'], 'contact' => $v['contact']]);
        $pick = static fn(array $c): array => ['enabled' => $c['enabled'], 'url' => $c['url'], 'events' => implode(',', $c['events']), 'contact' => $c['contact']];
        ActivityLog::settingsChange($userId, 'webhooks.out', $pick($before), $pick($v));   // el registro lleva destino y eventos, nunca el secreto
        if ($secret !== null) {
            ActivityLog::record('webhooks_out_secret_changed', $userId, 'settings', 'webhooks.out', ['cleared' => $secret === '']);
        }
        return ['ok' => true, 'msg' => 'Webhooks salientes guardados.'];
    }

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    // --- Emitir ---------------------------------------------------------------------------------------------------------------------------

    /** Datos del lead que viajan siempre (sin PII) y, con «incluir contacto», nombre y telefono. @return array<string,mixed> */
    private static function leadBlock(int $leadId, bool $contact, bool $minimal): array
    {
        $out = ['id' => $leadId, 'code' => Fmt::code($leadId), 'url' => Url::base() . '/leads/' . $leadId];
        if ($minimal) {
            return $out;
        }
        $l = Lead::find($leadId);
        if ($l === null) {
            return $out;
        }
        $out += ['stage' => (string) $l['stage'], 'pipeline' => (string) $l['pipeline'], 'temperature' => (string) $l['temperature'], 'source' => (string) $l['source'], 'score' => (int) $l['score']];
        if ($contact) {
            $out['contact'] = ['name' => Fmt::name($l), 'phone' => (string) ($l['phone_e164'] ?? '')];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function cleanExtra(array $extra, int $depth = 0): array
    {
        $out = [];
        foreach (array_slice($extra, 0, 20, true) as $k => $v) {
            $k = (string) $k;
            if (in_array(strtolower($k), self::PII_KEYS, true) || preg_match('/^[A-Za-z0-9_.-]{1,30}$/', $k) !== 1) {
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $depth < 2 ? self::cleanExtra($v, $depth + 1) : [];
            } elseif (is_string($v)) {
                $out[$k] = mb_substr($v, 0, 200);
            } elseif (is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Encola un evento. @return ?int id de la entrega (null: apagado, sin suscripcion, duplicado o error; nunca lanza).
     */
    public static function emit(string $event, ?int $leadId, array $extra = [], ?string $key = null): ?int
    {
        try {
            if (!isset(self::EVENTS[$event]) || !self::wants($event)) {
                return null;
            }
            $cfg = self::config();
            $eventId = 'evt_' . ($key !== null ? substr(sha1($event . '|' . $key), 0, 28) : bin2hex(random_bytes(14)));
            $body = [
                'schema' => self::SCHEMA, 'event' => $event, 'event_id' => $eventId, 'created_at' => gmdate('c', Clock::time()),
                'lead' => $leadId !== null && $leadId > 0 ? self::leadBlock($leadId, $cfg['contact'] && $event !== 'lead.anonymized', $event === 'lead.anonymized') : null,
                'data' => (object) self::cleanExtra($extra),
            ];
            $st = Db::run('INSERT IGNORE INTO outbound_deliveries (event_id, event, lead_id, payload, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$eventId, $event, $leadId, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 'pending', Clock::now()]);
            if ($st->rowCount() !== 1) {
                return null;   // ya se habia emitido este evento
            }
            $id = (int) Db::pdo()->lastInsertId();
            Queue::push('outbound_webhook', ['delivery_id' => $id], Clock::now(), 'outbound:' . $id);
            if (random_int(1, 300) === 1) {
                Db::run('DELETE FROM outbound_deliveries WHERE created_at < ?', [Clock::shift(-self::KEEP_DAYS * 86400)]);
            }
            return $id;
        } catch (\Throwable $t) {
            Storage::error('outbound webhook emit failed', $event . ' ' . get_class($t) . ': ' . $t->getMessage());
            return null;
        }
    }

    // --- Enviar ---------------------------------------------------------------------------------------------------------------------------

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Un POST firmado. @return array{ok:bool,status:int,error:string}
     * @param array<string,mixed> $body
     */
    private static function post(array $cfg, string $event, string $eventId, array $body): array
    {
        $problem = Handoff::urlProblem($cfg['url']);   // se revalida al ENVIAR: el DNS pudo cambiar desde que se guardo la URL
        if ($problem !== null) {
            return ['ok' => false, 'status' => 0, 'error' => 'bloqueado: ' . $problem, 'blocked' => true];
        }
        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $ts = (string) Clock::time();
        $r = Http::request('POST', $cfg['url'], $json, [
            'Content-Type: application/json', 'X-NS-Timestamp: ' . $ts, 'X-NS-Signature: ' . self::sign($cfg['secret'], $ts, $json), 'X-NS-Event: ' . $event, 'X-NS-Delivery: ' . $eventId, 'Idempotency-Key: ' . $eventId,
        ], 5, 3);
        if ($r['status'] === 0) {
            return ['ok' => false, 'status' => 0, 'error' => 'sin respuesta' . ($r['error'] !== '' ? ': ' . $r['error'] : '')];
        }
        if ($r['status'] < 200 || $r['status'] > 299) {
            return ['ok' => false, 'status' => $r['status'], 'error' => 'HTTP ' . $r['status']];
        }
        return ['ok' => true, 'status' => $r['status'], 'error' => ''];
    }

    /** Handler del job outbound_webhook: lanza si hay que reintentar (la cola espera 1, 5 y 15 minutos). */
    public static function deliver(array $payload, array $job = []): void
    {
        $d = Db::one('SELECT * FROM outbound_deliveries WHERE id = ?', [(int) ($payload['delivery_id'] ?? 0)]);
        if ($d === null || $d['status'] !== 'pending') {
            return;   // ya enviada, omitida o fallida del todo
        }
        $id = (int) $d['id'];
        $cfg = self::config();
        if (!($cfg['enabled'] && $cfg['url'] !== '' && $cfg['secret_set'])) {
            Db::update('outbound_deliveries', $id, ['status' => 'skipped', 'last_error' => 'Webhook apagado o sin configurar']);
            return;
        }
        $body = Db::decode($d['payload']);
        $body['data'] = (object) ($body['data'] ?? []);   // «data» siempre es un objeto JSON, tambien vacio
        $attempts = (int) $d['attempts'] + 1;
        $r = self::post($cfg, (string) $d['event'], (string) $d['event_id'], $body);
        if ($r['ok']) {
            Db::update('outbound_deliveries', $id, ['status' => 'sent', 'attempts' => $attempts, 'http_status' => $r['status'], 'last_error' => null, 'sent_at' => Clock::now()]);
            Integrations::record('webhook_out', true);
            return;
        }
        $err = mb_substr(Storage::redact($r['error']), 0, 180);
        Integrations::record('webhook_out', false, $err);
        if (!empty($r['blocked'])) {
            Db::update('outbound_deliveries', $id, ['status' => 'failed', 'attempts' => $attempts, 'http_status' => null, 'last_error' => $err]);
            throw new PermanentJobFailure('Webhook saliente ' . $err);
        }
        $last = (int) ($job['attempts'] ?? $attempts) > count(Queue::BACKOFF);   // la cola ya no reintentara: queda fallida
        Db::update('outbound_deliveries', $id, ['status' => $last ? 'failed' : 'pending', 'attempts' => $attempts, 'http_status' => $r['status'] > 0 ? $r['status'] : null, 'last_error' => $err]);
        throw new \RuntimeException('El webhook saliente falló: ' . $err);
    }

    /** Boton «Probar»: un evento `webhook.test` con un lead ficticio, enviado AHORA (no por la cola). Queda registrado en las entregas. @return array{ok:bool,msg:string} */
    public static function sendTest(int $userId): array
    {
        $cfg = self::config();
        if (!($cfg['url'] !== '' && $cfg['secret_set'])) {
            return ['ok' => false, 'msg' => 'Guarda primero la URL y genera el secreto de firma.'];
        }
        $eventId = 'evt_test_' . bin2hex(random_bytes(8));
        $body = [
            'schema' => self::SCHEMA, 'event' => 'webhook.test', 'event_id' => $eventId, 'created_at' => gmdate('c', Clock::time()),
            'lead' => ['id' => 0, 'code' => 'LD-0000', 'url' => Url::base() . '/leads', 'stage' => 'new', 'pipeline' => 'A', 'temperature' => 'warm', 'source' => 'test', 'score' => 50] + ($cfg['contact'] ? ['contact' => ['name' => 'Lead de prueba', 'phone' => '+15555550100']] : []),
            'data' => (object) ['test' => true],
        ];
        $r = self::post($cfg, 'webhook.test', $eventId, $body);
        Db::run('INSERT IGNORE INTO outbound_deliveries (event_id, event, lead_id, payload, status, attempts, http_status, last_error, created_at, sent_at) VALUES (?, ?, NULL, ?, ?, 1, ?, ?, ?, ?)',
            [$eventId, 'webhook.test', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['ok'] ? 'sent' : 'failed', $r['status'] > 0 ? $r['status'] : null, $r['ok'] ? null : mb_substr(Storage::redact($r['error']), 0, 180), Clock::now(), $r['ok'] ? Clock::now() : null]);
        Integrations::record('webhook_out', $r['ok'], $r['ok'] ? '' : $r['error']);
        ActivityLog::record('webhooks_out_test', $userId, 'settings', 'webhooks.out', ['ok' => $r['ok'], 'status' => $r['status']]);
        return $r['ok'] ? ['ok' => true, 'msg' => 'Prueba entregada (HTTP ' . $r['status'] . '). Recibió un evento webhook.test con un lead ficticio.'] : ['ok' => false, 'msg' => 'La prueba falló: ' . $r['error'] . '.'];
    }

    // --- Para la pantalla --------------------------------------------------------------------------------------------------------------------

    /** @return array{counts:array<string,int>,recent:list<array>} */
    public static function overview(): array
    {
        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM outbound_deliveries WHERE created_at > ? GROUP BY status', [Clock::shift(-7 * 86400)]) as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $recent = Db::all('SELECT id, event_id, event, lead_id, status, attempts, http_status, last_error, created_at, sent_at FROM outbound_deliveries ORDER BY id DESC LIMIT 8');
        return ['counts' => $counts, 'recent' => $recent];
    }
}
