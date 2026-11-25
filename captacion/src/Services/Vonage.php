<?php
/**
 * Fase AV (2026-11-25): llamadas a prospectos desde el NAVEGADOR con Vonage (Voice API + Vonage Client SDK). Carlos: «podemos conectarnos por API a Vonage para manejar
 * las llamadas»; eligió que los agentes (en Venezuela) hablen desde el navegador. APAGADA hasta que existan las variables en config/.env:
 *   VONAGE_APPLICATION_ID    id de la «aplicación» de Vonage con capacidad Voice
 *   VONAGE_PRIVATE_KEY       ruta al archivo .key de esa aplicación (relativa a config/ o absoluta; fuera de public/, nunca en git)
 *   VONAGE_NUMBER            número de EE. UU. de NorthStar comprado en Vonage, en E.164 (se muestra al prospecto como identificador)
 *   VONAGE_SIGNATURE_SECRET  «signature secret» de la cuenta (firma HS256 de los webhooks)
 *   opcionales: VONAGE_API_BASE (https://api.nexmo.com; para pruebas), VONAGE_MAX_CALL_SECONDS (1800), VONAGE_RING_SECONDS (45)
 *
 * Flujo («clic para llamar», UNA llamada a UN número con una persona al teléfono; nada de marcador, buzón grabado ni detección de contestador):
 *   1. El agente pulsa «Llamar con Vonage». POST /ajax/vonage/start comprueba en el servidor horario permitido, «No llamar más», supresión y teléfono, crea una fila
 *      en prospect_voice_calls con un TICKET de un solo uso (5 min) y devuelve el JWT del usuario de Vonage del agente.
 *   2. El navegador abre sesión con el SDK y hace serverCall({ticket}). Vonage pide el NCCO al webhook answer (firmado): se vuelve a comprobar todo y se responde
 *      «connect» al teléfono del prospecto desde VONAGE_NUMBER. El navegador nunca elige el número: sale del ticket.
 *   3. Los eventos (firmados) actualizan la fila: sonando, contestó, duración, colgó, sin respuesta… El agente sigue registrando el resultado con los botones de siempre.
 *
 * Supuestos a confirmar con la cuenta real (README §35): la API de usuarios (POST /v1/users, 409 si ya existe) y que el tramo al teléfono llega al webhook de eventos con su
 * propio uuid y el mismo conversation_uuid. El resto sale de la documentación de Vonage leída el 2026-11-25.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;

final class Vonage
{
    public const VARS = ['VONAGE_APPLICATION_ID', 'VONAGE_PRIVATE_KEY', 'VONAGE_NUMBER', 'VONAGE_SIGNATURE_SECRET'];
    public const SDK_VERSION = '2.11.2';
    public const SDK_URL = 'https://cdn.jsdelivr.net/npm/@vonage/client-sdk@2.11.2/dist/vonageClientSDK.min.js';
    public const SDK_SRI = 'sha384-SF4N1R/Aujxod5q955WZiXKZgdXfi3wGBLnbwxTHndEGtyyE0WPxPXHqkOs9+Zpk';
    public const PATHS = ['answer' => '/api/webhooks/vonage-answer.php', 'event' => '/api/webhooks/vonage-event.php'];
    public const TICKET_TTL = 300;
    public const JWT_TTL = 3600;
    public const FINAL = ['completed', 'busy', 'unanswered', 'timeout', 'rejected', 'failed', 'cancelled', 'blocked'];
    public const STATUS_LABELS = [
        'requested' => 'Conectando', 'dialing' => 'Llamando', 'ringing' => 'Sonando', 'answered' => 'En llamada', 'completed' => 'Terminada', 'busy' => 'Ocupado',
        'unanswered' => 'No contestó', 'timeout' => 'No contestó', 'rejected' => 'Rechazada', 'failed' => 'Falló', 'cancelled' => 'Cancelada', 'blocked' => 'No permitida',
    ];
    /** ACL mínima para llamar desde el SDK (documentación de Vonage, «Authentication»). */
    private const ACL = ['paths' => [
        '/*/sessions/**' => ['methods' => ['POST']], '/*/conversations/*' => ['methods' => ['GET']], '/*/conversations/*/rtc/*/answer' => ['methods' => ['POST']],
        '/*/conversations/*/rtc/*/offer/*' => ['methods' => ['POST']], '/*/conversations/*/members/*' => ['methods' => ['PUT', 'DELETE']], '/*/knocking/**' => ['methods' => ['POST', 'DELETE']],
        '/*/legs/**' => ['methods' => ['POST', 'GET']], '/*/v2/rtc/**' => ['methods' => ['POST', 'GET']],
    ]];

    // --- Configuración ----------------------------------------------------------------------------------------------------------------

    /** @return list<string> variables que FALTAN */
    public static function missing(): array
    {
        $out = [];
        foreach (self::VARS as $k) {
            $v = trim((string) (Env::get($k) ?? ''));
            if ($v === '' || $v === 'CHANGE-ME' || ($k === 'VONAGE_PRIVATE_KEY' && self::privateKey() === null) || ($k === 'VONAGE_NUMBER' && self::number() === null)
                || ($k === 'VONAGE_SIGNATURE_SECRET' && strlen($v) < 16)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    public static function configured(): bool
    {
        return self::missing() === [];
    }

    public static function base(): string
    {
        return rtrim((string) (Env::get('VONAGE_API_BASE') ?? 'https://api.nexmo.com'), '/');
    }

    /** Número de NorthStar en Vonage, solo dígitos (formato del NCCO), o null si no es un número de EE. UU. válido. */
    public static function number(): ?string
    {
        $p = Phone::normalize((string) (Env::get('VONAGE_NUMBER') ?? ''));
        return $p['ok'] && is_string($p['e164']) && str_starts_with($p['e164'], '+1') ? substr($p['e164'], 1) : null;
    }

    /** Clave privada de la aplicación (PEM) o null. Ruta relativa = respecto a config/. */
    private static function privateKey(): ?\OpenSSLAsymmetricKey
    {
        $path = trim((string) (Env::get('VONAGE_PRIVATE_KEY') ?? ''));
        if ($path === '') {
            return null;
        }
        if (!str_starts_with($path, '/')) {
            $path = CAPTACION_ROOT . '/config/' . $path;
        }
        $pem = is_file($path) && is_readable($path) ? (string) file_get_contents($path) : '';
        $k = $pem !== '' ? openssl_pkey_get_private($pem) : false;
        return $k === false ? null : $k;
    }

    public static function url(string $kind): string
    {
        return rtrim((string) (Env::get('SELF_BASE_URL') ?? Url::base()), '/') . self::PATHS[$kind];
    }

    // --- JWT ---------------------------------------------------------------------------------------------------------------------------

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** JWT RS256 de la aplicación (para la API) o del usuario del SDK ($sub + ACL). */
    public static function jwt(?string $sub = null, int $ttl = 900): string
    {
        $key = self::privateKey();
        if ($key === null) {
            throw new \RuntimeException('Falta la clave privada de Vonage (VONAGE_PRIVATE_KEY).');
        }
        $now = Clock::time();
        $claims = ['application_id' => (string) Env::get('VONAGE_APPLICATION_ID'), 'iat' => $now, 'nbf' => $now - 5, 'exp' => $now + max(30, min(86400, $ttl)), 'jti' => bin2hex(random_bytes(16))];
        if ($sub !== null) {
            $claims += ['sub' => $sub, 'acl' => self::ACL];
        }
        $data = self::b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . self::b64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($data, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('No se pudo firmar el JWT de Vonage.');
        }
        return $data . '.' . self::b64($sig);
    }

    /**
     * Firma de un webhook de Vonage: «Authorization: Bearer <JWT HS256>» con VONAGE_SIGNATURE_SECRET; iat de menos de 10 minutos y, si trae payload_hash y hay cuerpo,
     * que sea el SHA-256 del cuerpo. @return ?string null si es válida, o el motivo
     */
    public static function verifyWebhook(?string $auth, string $body, ?int $now = null): ?string
    {
        $secret = (string) (Env::get('VONAGE_SIGNATURE_SECRET') ?? '');
        if (strlen($secret) < 16) {
            return 'sin VONAGE_SIGNATURE_SECRET';
        }
        if (!is_string($auth) || preg_match('/^Bearer\s+([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', trim($auth), $m) !== 1) {
            return 'sin firma';
        }
        $head = json_decode((string) self::unb64($m[1]), true);
        if (!is_array($head) || ($head['alg'] ?? '') !== 'HS256') {
            return 'algoritmo no aceptado';
        }
        if (!hash_equals(self::b64(hash_hmac('sha256', $m[1] . '.' . $m[2], $secret, true)), $m[3])) {
            return 'firma inválida';
        }
        $c = json_decode((string) self::unb64($m[2]), true);
        $now ??= Clock::time();
        if (!is_array($c) || !isset($c['iat']) || abs($now - (int) $c['iat']) > 600) {
            return 'firma vencida';
        }
        if ($body !== '' && isset($c['payload_hash']) && !hash_equals(strtolower((string) $c['payload_hash']), hash('sha256', $body))) {
            return 'el cuerpo no coincide con la firma';
        }
        return null;
    }

    // --- Usuarios del SDK --------------------------------------------------------------------------------------------------------------

    public static function userName(int $userId): string
    {
        return 'ns-agent-' . $userId;
    }

    /** Crea (una vez) el usuario de Vonage del agente. Supuesto a confirmar: POST /v1/users devuelve 409 si el nombre ya existe. */
    public static function ensureUser(array $user): array
    {
        $name = self::userName((int) $user['id']);
        $key = 'vonage.user.' . (int) $user['id'];
        if (Settings::get($key) === $name) {
            return ['ok' => true, 'name' => $name];
        }
        $r = Http::request('POST', self::base() . '/v1/users', (string) json_encode(['name' => $name, 'display_name' => mb_substr((string) ($user['name'] ?? 'Agente'), 0, 60)]),
            ['Content-Type: application/json', 'Authorization: Bearer ' . self::jwt()], 8, 3);
        if (in_array($r['status'], [200, 201, 409], true)) {
            Settings::set($key, $name);
            return ['ok' => true, 'name' => $name];
        }
        Integrations::record('vonage', false, 'Alta del usuario del agente: HTTP ' . $r['status'] . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : ''));
        return ['ok' => false, 'msg' => $r['status'] === 401 || $r['status'] === 403 ? 'Vonage rechazó las credenciales (revisa VONAGE_APPLICATION_ID y la clave privada).' : 'No se pudo preparar tu usuario de Vonage (HTTP ' . $r['status'] . ').'];
    }

    // --- Antes de llamar ---------------------------------------------------------------------------------------------------------------

    /** Por qué NO se puede llamar ahora a este prospecto (null = se puede). */
    public static function blockReason(?array $p, int $now): ?string
    {
        if ($p === null || $p['deleted_at'] !== null) {
            return 'El prospecto no existe.';
        }
        if ((int) $p['do_not_call'] === 1 || $p['status'] === 'dnc') {
            return 'Está en «No llamar más».';
        }
        if ($p['converted_lead_id'] !== null) {
            return 'Ya es un lead: llámalo desde su ficha.';
        }
        if ($p['phone'] === null || !str_starts_with((string) $p['phone'], '+1')) {
            return 'No tiene un teléfono de EE. UU.';
        }
        if (Db::value('SELECT 1 FROM dnc_numbers WHERE phone_e164 = ?', [$p['phone']]) !== null || Suppression::isListed((string) $p['phone'])) {
            return 'Ese teléfono está en la lista de no llamar.';
        }
        $tz = UsTimeZones::check((string) $p['state'], $now);
        if (!$tz['allowed']) {
            return $tz['known'] ? 'Fuera del horario permitido: allá son las ' . $tz['local_hm'] . ' ' . $tz['zone_label'] . '.' : 'Sin estado no se sabe la hora local.';
        }
        return null;
    }

    /**
     * POST /ajax/vonage/start: comprueba, crea la fila con su ticket y devuelve el JWT del agente.
     * @return array{ok:bool,msg?:string,jwt?:string,ticket?:string,call_id?:int}
     */
    public static function start(array $user, int $prospectId): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'msg' => 'Vonage no está configurado.'];
        }
        $uid = (int) $user['id'];
        if (RateLimiter::tooMany('vonage_start', (string) $uid, 60, 3600)) {
            return ['ok' => false, 'msg' => 'Demasiadas llamadas seguidas: espera unos minutos.'];
        }
        $p = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ?', [$prospectId]);
        if (($why = self::blockReason($p, Clock::time())) !== null) {
            return ['ok' => false, 'msg' => $why];
        }
        $active = Db::value("SELECT id FROM prospect_voice_calls WHERE user_id = ? AND status IN ('dialing', 'ringing', 'answered') AND created_at > ?", [$uid, Clock::at(Clock::time() - 3 * 3600)]);
        if ($active !== null) {
            Db::run("UPDATE prospect_voice_calls SET status = 'cancelled', ended_at = COALESCE(ended_at, ?) WHERE id = ?", [Clock::now(), (int) $active]);   // una llamada a la vez por agente: la anterior quedó colgada sin evento
        }
        $u = self::ensureUser($user);
        if (!$u['ok']) {
            return ['ok' => false, 'msg' => $u['msg']];
        }
        RateLimiter::hit('vonage_start', (string) $uid, 3600);
        $ticket = bin2hex(random_bytes(16));
        $id = (int) Db::insert('prospect_voice_calls', ['prospect_id' => $prospectId, 'user_id' => $uid, 'ticket' => $ticket, 'status' => 'requested', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        ActivityLog::record('prospect_vcall_start', $uid, 'prospect', $prospectId, ['call_id' => $id]);
        return ['ok' => true, 'jwt' => self::jwt($u['name'], self::JWT_TTL), 'ticket' => $ticket, 'call_id' => $id];
    }

    // --- Webhooks ----------------------------------------------------------------------------------------------------------------------

    private static function talk(string $text): array
    {
        return [['action' => 'talk', 'text' => $text, 'language' => 'es-ES']];
    }

    /** Webhook answer: NCCO para la llamada del navegador. Lo que el agente oye si no se puede: un aviso (al prospecto no se le llama). @return list<array> */
    public static function answer(array $in): array
    {
        $cd = is_array($in['custom_data'] ?? null) ? $in['custom_data'] : [];
        $ticket = (string) ($cd['ticket'] ?? ($in['ticket'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/', $ticket) !== 1) {
            return self::talk('Llamada no permitida.');
        }
        return Db::transaction(static function () use ($ticket, $in): array {
            $c = Db::one('SELECT * FROM prospect_voice_calls WHERE ticket = ? FOR UPDATE', [$ticket]);
            $now = Clock::time();
            if ($c === null || $c['status'] !== 'requested' || (int) Clock::parse((string) $c['created_at']) < $now - self::TICKET_TTL) {
                return self::talk('Esta llamada ya no es válida. Vuelve a pulsar Llamar.');
            }
            $from = (string) ($in['from_user'] ?? '');
            if ($from !== '' && $from !== self::userName((int) $c['user_id'])) {
                Db::update('prospect_voice_calls', (int) $c['id'], ['status' => 'blocked', 'reject_reason' => 'otro usuario']);
                return self::talk('Llamada no permitida.');
            }
            $p = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ?', [(int) $c['prospect_id']]);
            if (($why = self::blockReason($p, $now)) !== null) {
                Db::update('prospect_voice_calls', (int) $c['id'], ['status' => 'blocked', 'reject_reason' => mb_substr($why, 0, 60), 'ended_at' => Clock::now()]);
                return self::talk('No se puede llamar ahora. ' . $why);
            }
            Db::update('prospect_voice_calls', (int) $c['id'], ['status' => 'dialing', 'app_uuid' => mb_substr((string) ($in['uuid'] ?? ''), 0, 64) ?: null,
                'conversation_uuid' => mb_substr((string) ($in['conversation_uuid'] ?? ''), 0, 64) ?: null]);
            return [[
                'action' => 'connect', 'from' => self::number(), 'endpoint' => [['type' => 'phone', 'number' => substr((string) $p['phone'], 1)]],
                'eventUrl' => [self::url('event')], 'eventMethod' => 'POST', 'timeout' => max(15, min(120, Env::int('VONAGE_RING_SECONDS', 45))),
                'limit' => max(60, min(7200, Env::int('VONAGE_MAX_CALL_SECONDS', 1800))),
            ]];
        });
    }

    /** Webhook de eventos: actualiza la llamada (tramo al prospecto) sin retroceder un estado final. */
    public static function event(array $in): void
    {
        $conv = (string) ($in['conversation_uuid'] ?? '');
        $uuid = (string) ($in['uuid'] ?? '');
        $status = (string) ($in['status'] ?? '');
        if ($conv === '' || $status === '') {
            return;
        }
        Db::transaction(static function () use ($conv, $uuid, $status, $in): void {
            $c = Db::one('SELECT * FROM prospect_voice_calls WHERE conversation_uuid = ? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$conv]);
            if ($c === null) {
                return;
            }
            $id = (int) $c['id'];
            $final = in_array($c['status'], array_merge(self::FINAL, ['blocked']), true);
            $now = Clock::now();
            $u = [];
            if ($uuid !== '' && $uuid === (string) $c['app_uuid']) {   // tramo del navegador: si el agente cuelga antes de que contesten, la llamada queda cancelada
                if (in_array($status, ['completed', 'disconnected', 'failed', 'rejected', 'cancelled'], true) && !$final && $c['status'] !== 'answered') {
                    $u = ['status' => 'cancelled', 'ended_at' => $now];
                }
            } else {
                if ($c['pstn_uuid'] === null && $uuid !== '') {
                    $u['pstn_uuid'] = mb_substr($uuid, 0, 64);
                }
                if (!$final || ($status === 'completed' && $c['status'] === 'completed')) {
                    switch ($status) {
                        case 'ringing':
                            if (in_array($c['status'], ['requested', 'dialing'], true)) {
                                $u['status'] = 'ringing';
                            }
                            break;
                        case 'answered':
                            $u += ['status' => 'answered', 'answered_at' => $now];
                            break;
                        case 'completed':
                            $u['status'] = $c['status'] === 'answered' || $c['answered_at'] !== null ? 'completed' : 'unanswered';
                            $u['ended_at'] = $now;
                            $u['duration'] = $c['answered_at'] !== null ? max(0, min(86400, (int) ($in['duration'] ?? 0))) : 0;
                            if (isset($in['price']) && is_numeric($in['price'])) {
                                $u['price'] = (float) $in['price'];
                            }
                            if (isset($in['rate']) && is_numeric($in['rate'])) {
                                $u['rate'] = (float) $in['rate'];
                            }
                            $u['disconnected_by'] = mb_substr((string) ($in['disconnected_by'] ?? ''), 0, 16) ?: null;
                            break;
                        case 'busy': case 'unanswered': case 'timeout': case 'rejected': case 'failed': case 'cancelled':
                            $u += ['status' => $status, 'ended_at' => $now];
                            break;
                    }
                }
            }
            if ($u) {
                Db::update('prospect_voice_calls', $id, $u);
            }
        });
    }

    // --- Lectura -----------------------------------------------------------------------------------------------------------------------

    /** Estado de una llamada para el navegador del agente que la hizo. @return ?array */
    public static function status(int $callId, int $userId): ?array
    {
        $c = Db::one('SELECT id, prospect_id, status, answered_at, ended_at, duration, reject_reason FROM prospect_voice_calls WHERE id = ? AND user_id = ?', [$callId, $userId]);
        if ($c === null) {
            return null;
        }
        $answered = $c['answered_at'] !== null;
        return ['id' => (int) $c['id'], 'status' => (string) $c['status'], 'label' => self::STATUS_LABELS[$c['status']] ?? (string) $c['status'], 'answered' => $answered,
            'final' => in_array($c['status'], self::FINAL, true), 'duration' => $c['duration'] !== null ? (int) $c['duration'] : null, 'reason' => (string) ($c['reject_reason'] ?? ''),
            'suggest' => in_array($c['status'], self::FINAL, true) ? ($answered ? 'talked' : ($c['status'] === 'failed' ? '' : 'no_answer')) : ''];
    }

    /** Hoy (día ET): llamadas por Vonage, contestadas y minutos hablados del equipo. @return array{calls:int,answered:int,minutes:int} */
    public static function todayStats(int $now): array
    {
        try {
            $start = Prospects::etToUtc(Prospects::etDay($now)) ?? Clock::at($now - 86400);
            $r = Db::one("SELECT COUNT(*) n, SUM(answered_at IS NOT NULL) a, COALESCE(SUM(duration), 0) d FROM prospect_voice_calls WHERE created_at >= ? AND status <> 'requested'", [$start]);
            return ['calls' => (int) ($r['n'] ?? 0), 'answered' => (int) ($r['a'] ?? 0), 'minutes' => (int) round(((int) ($r['d'] ?? 0)) / 60)];
        } catch (\Throwable) {
            return ['calls' => 0, 'answered' => 0, 'minutes' => 0];
        }
    }

    /** Última llamada por Vonage de cada prospecto (para la lista). @param list<int> $ids @return array<int,array> */
    public static function lastFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        try {
            $out = [];
            foreach (Db::all('SELECT prospect_id, status, answered_at, duration, created_at FROM prospect_voice_calls WHERE status <> \'requested\' AND prospect_id IN (' . implode(',', $ids) . ') ORDER BY id') as $r) {
                $out[(int) $r['prospect_id']] = $r;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** «Probar» de Integraciones: un GET firmado a la API de usuarios. @return array{ok:bool,level:string,msg:string} */
    public static function test(): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'level' => 'warn', 'msg' => 'Vonage no está configurado: faltan ' . implode(', ', self::missing()) . ' en config/.env.'];
        }
        $r = Http::request('GET', self::base() . '/v1/users?page_size=1', null, ['Authorization: Bearer ' . self::jwt()], 8, 3);
        if ($r['status'] === 200) {
            Integrations::record('vonage', true);
            return ['ok' => true, 'level' => 'ok', 'msg' => 'Vonage aceptó las credenciales de la aplicación. Falta pegar las URL de los webhooks en la aplicación de Vonage (si no lo hiciste) y probar una llamada real.'];
        }
        $msg = $r['status'] === 0 ? 'No se pudo llegar a Vonage (' . $r['error'] . ').' : ($r['status'] === 401 || $r['status'] === 403 ? 'Vonage rechazó las credenciales: revisa VONAGE_APPLICATION_ID y la clave privada.' : 'Vonage respondió HTTP ' . $r['status'] . '.');
        Integrations::record('vonage', false, $msg);
        return ['ok' => false, 'level' => 'bad', 'msg' => $msg];
    }
}
