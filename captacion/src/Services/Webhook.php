<?php
/**
 * Base de los webhooks entrantes (fase Q4b): meta, calcom, sms (Twilio) y n8n. Cada uno se activa SOLO si sus variables existen en el .env; si no, 503 not_configured.
 *
 * Orden de cada entrada publica: 1) metodo 2) configurada 3) limite de tasa por IP 4) tamano del cuerpo 5) FIRMA (tiempo constante, ANTES de interpretar nada)
 * 6) idempotencia (webhook_events, UNIQUE source+event_key) 7) el trabajo pesado va a la cola. Nada de esto registra secretos ni cuerpos de mensajes.
 *
 * Variables: META_VERIFY_TOKEN + META_APP_SECRET + META_PAGE_TOKEN · CALCOM_WEBHOOK_SECRET · TWILIO_TOKEN (+ TWILIO_SID, TWILIO_FROM, TWILIO_WEBHOOK_URL) · N8N_WEBHOOK_SECRET.
 * Opcionales: WEBHOOK_RATE_LIMIT (1200 peticiones/hora por IP y fuente), WEBHOOK_MAX_BODY (131072 bytes), WEBHOOK_SIG_FAILS (15 firmas malas/15 min por IP).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;

final class Webhook
{
    public const SOURCES = ['meta', 'calcom', 'twilio', 'n8n'];
    public const MIN_SECRET = 8;
    /** Ruta publica de cada webhook (respecto a la raiz del subdominio). */
    public const PATHS = ['meta' => '/api/webhooks/meta.php', 'calcom' => '/api/webhooks/calcom.php', 'twilio' => '/api/webhooks/sms.php', 'n8n' => '/api/webhooks/n8n.php'];
    public const TITLES = ['meta' => 'Meta Lead Ads', 'calcom' => 'Cal.com', 'twilio' => 'Twilio (SMS)', 'n8n' => 'n8n'];

    public static function maxBody(): int
    {
        return max(4096, min(1048576, Env::int('WEBHOOK_MAX_BODY', 131072)));
    }

    private static function val(string $k): string
    {
        $v = Env::get($k);
        return $v !== null && strlen($v) >= self::MIN_SECRET && $v !== 'CHANGE-ME' ? $v : '';
    }

    /** Variables que hacen falta para que el endpoint funcione. @return list<string> las que FALTAN */
    public static function missing(string $source): array
    {
        $need = ['meta' => ['META_VERIFY_TOKEN', 'META_APP_SECRET'], 'calcom' => ['CALCOM_WEBHOOK_SECRET'], 'twilio' => ['TWILIO_TOKEN'], 'n8n' => ['N8N_WEBHOOK_SECRET']][$source] ?? [];
        return array_values(array_filter($need, static fn($k) => self::val($k) === ''));
    }

    public static function configured(string $source): bool
    {
        return self::missing($source) === [];
    }

    /** Secreto con el que se firma el cuerpo de este origen (nunca se imprime). */
    public static function secret(string $source): string
    {
        return self::val(['meta' => 'META_APP_SECRET', 'calcom' => 'CALCOM_WEBHOOK_SECRET', 'twilio' => 'TWILIO_TOKEN', 'n8n' => 'N8N_WEBHOOK_SECRET'][$source] ?? '');
    }

    public static function url(string $source): string
    {
        if ($source === 'twilio' && (Env::get('TWILIO_WEBHOOK_URL') ?? '') !== '') {
            return (string) Env::get('TWILIO_WEBHOOK_URL');
        }
        return Url::base() . (self::PATHS[$source] ?? '');
    }

    // --- Limites ---------------------------------------------------------------------------------------------------------------------

    public static function tooMany(string $source, string $ip): bool
    {
        return RateLimiter::tooMany('wh_' . $source, $ip, max(10, Env::int('WEBHOOK_RATE_LIMIT', 1200)), 3600);
    }

    public static function hit(string $source, string $ip): void
    {
        RateLimiter::hit('wh_' . $source, $ip, 3600);
    }

    public static function sigBlocked(string $source, string $ip): bool
    {
        return RateLimiter::tooMany('wh_sig_' . $source, $ip, max(3, Env::int('WEBHOOK_SIG_FAILS', 15)), 900);
    }

    public static function sigFailed(string $source, string $ip): void
    {
        RateLimiter::hit('wh_sig_' . $source, $ip, 900);
        Integrations::webhook($source, 'sig');
    }

    // --- Firmas ----------------------------------------------------------------------------------------------------------------------

    public static function hmacHex(string $algo, string $secret, string $data): string
    {
        return hash_hmac($algo, $data, $secret);
    }

    /** Compara en tiempo constante. Acepta el prefijo "sha256=" y mayusculas. */
    public static function equalsHex(string $given, string $expectedHex): bool
    {
        $g = strtolower(trim($given));
        if (str_starts_with($g, 'sha256=')) {
            $g = substr($g, 7);
        }
        return preg_match('/^[0-9a-f]{64}$/', $g) === 1 && hash_equals($expectedHex, $g);
    }

    /**
     * Firma de Twilio: base64(HMAC-SHA1(token, URL completa + parametros POST ordenados por nombre, nombre y valor pegados)). La URL debe ser EXACTAMENTE la que Twilio
     * tiene configurada; se prueban las variantes con y sin puerto por defecto. $params = pares del cuerpo form-urlencoded tal cual llegaron.
     * @param array<string,string|list<string>> $params
     */
    public static function twilioValid(string $given, string $url, array $params, string $token): bool
    {
        if ($given === '' || strlen($given) > 100) {
            return false;
        }
        ksort($params, SORT_STRING);
        $tail = '';
        foreach ($params as $k => $v) {
            foreach ((array) $v as $one) {
                $tail .= $k . $one;
            }
        }
        $urls = [$url];
        $p = parse_url($url);
        if (is_array($p) && isset($p['scheme'], $p['host'])) {
            $port = (int) ($p['port'] ?? 0);
            $default = $p['scheme'] === 'https' ? 443 : 80;
            $rest = ($p['path'] ?? '') . (isset($p['query']) ? '?' . $p['query'] : '');
            $urls[] = $p['scheme'] . '://' . $p['host'] . ($port === 0 ? ':' . $default : '') . $rest;   // Twilio a veces firma con el puerto explicito
            if ($port === $default) {
                $urls[] = $p['scheme'] . '://' . $p['host'] . $rest;
            }
        }
        $ok = false;
        foreach (array_unique($urls) as $u) {
            $ok = hash_equals(base64_encode(hash_hmac('sha1', $u . $tail, $token, true)), $given) || $ok;   // sin cortocircuito
        }
        return $ok;
    }

    // --- Idempotencia ---------------------------------------------------------------------------------------------------------------

    /** Clave estable de un evento (se acorta con hash si pasa de 120 caracteres). */
    public static function key(string ...$parts): string
    {
        $k = implode(':', $parts);
        return strlen($k) > 120 ? substr(hash('sha256', $k), 0, 64) : $k;
    }

    /** Reclama el evento. @return ?int id de la fila, o null si YA se habia recibido (duplicado: no se vuelve a procesar). */
    public static function claim(string $source, string $key, ?string $eventAt = null, ?string $summary = null): ?int
    {
        $st = Db::run('INSERT IGNORE INTO webhook_events (source, event_key, status, event_at, summary) VALUES (?, ?, ?, ?, ?)', [$source, substr($key, 0, 128), 'received', $eventAt, $summary !== null ? mb_substr($summary, 0, 190) : null]);
        if ($st->rowCount() !== 1) {
            return null;
        }
        if (random_int(1, 200) === 1) {
            Db::run('DELETE FROM webhook_events WHERE created_at < ?', [Clock::shift(-60 * 86400)]);
        }
        return (int) Db::pdo()->lastInsertId();
    }

    public static function finish(int $id, string $status, ?int $leadId = null, ?string $summary = null): void
    {
        Db::run('UPDATE webhook_events SET status = ?, lead_id = COALESCE(?, lead_id), summary = COALESCE(?, summary) WHERE id = ?', [$status, $leadId, $summary !== null ? mb_substr($summary, 0, 190) : null, $id]);
    }

    /** Resumen para el panel: eventos de los ultimos 7 dias por estado. @return array{processed:int,ignored:int,error:int,last:?string} */
    public static function recent(string $source): array
    {
        $out = ['processed' => 0, 'ignored' => 0, 'error' => 0, 'last' => null];
        foreach (Db::all('SELECT status, COUNT(*) AS n, MAX(created_at) AS at FROM webhook_events WHERE source = ? AND created_at > ? GROUP BY status', [$source, Clock::shift(-7 * 86400)]) as $r) {
            if (isset($out[$r['status']])) {
                $out[$r['status']] = (int) $r['n'];
            }
            $out['last'] = $out['last'] === null || (string) $r['at'] > $out['last'] ? (string) $r['at'] : $out['last'];
        }
        return $out;
    }
}
