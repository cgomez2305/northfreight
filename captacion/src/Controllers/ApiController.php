<?php
/**
 * POST /api/lead.php: endpoint unico de entrada de leads para fuentes externas (n8n, WhatsApp entrante, carga manual). JSON o formulario.
 * Autorizacion: Authorization: Bearer <INTERNAL_API_TOKEN>. El formulario web del sitio NO pasa por aqui (usa LeadIngest en proceso): no hay CORS ni token en navegador.
 *
 * Limites por IP (storage/ratelimit): 10 autenticaciones fallidas / 15 min y API_RATE_LIMIT peticiones / hora (120 por defecto).
 * Respuestas: 201 creado | 200 reingreso/reapertura/duplicado | 401 | 405 | 413 | 415 | 422 | 429 | 503. Sin datos personales en las respuestas ni en los logs.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Inbound;
use Captacion\Services\Webhook;
use Captacion\Services\LeadIngest;
use Captacion\Services\RateLimiter;
use Captacion\Services\Storage;

final class ApiController extends Responder
{
    public const MAX_BODY = 65536;

    public static function lead(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::json(['ok' => false, 'error' => 'method_not_allowed'], 405, ['Allow: POST']);
        }
        $ip = self::ip();
        $apiLimit = max(1, Env::int('API_RATE_LIMIT', 120));
        if (RateLimiter::tooMany('api_auth', $ip, 10, 900) || RateLimiter::tooMany('api', $ip, $apiLimit, 3600)) {
            self::json(['ok' => false, 'error' => 'rate_limited'], 429, ['Retry-After: 900']);
        }
        RateLimiter::hit('api', $ip, 3600);

        $expected = Env::get('INTERNAL_API_TOKEN');
        if ($expected === null || strlen($expected) < 32) {
            self::json(['ok' => false, 'error' => 'api_disabled'], 503);
        }
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $given = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) === 1 ? $m[1] : '';
        if ($given === '' || !hash_equals($expected, $given)) {
            RateLimiter::hit('api_auth', $ip, 900);
            header('WWW-Authenticate: Bearer realm="captacion"');
            self::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
            self::json(['ok' => false, 'error' => 'payload_too_large'], 413);
        }
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_starts_with($type, 'application/json')) {
            $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
            $data = strlen($raw) <= self::MAX_BODY ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                self::json(['ok' => false, 'error' => 'invalid_json'], 400);
            }
        } elseif (str_starts_with($type, 'application/x-www-form-urlencoded') || str_starts_with($type, 'multipart/form-data')) {
            $data = $_POST;
        } else {
            self::json(['ok' => false, 'error' => 'unsupported_media_type'], 415);
        }

        $source = is_string($data['source'] ?? null) ? (string) $data['source'] : 'api';
        try {
            $r = LeadIngest::handle($data, $source, ['ip' => $ip, 'fmcsa_sync' => true]);
        } catch (\Throwable $t) {
            Storage::error('api/lead failed', get_class($t) . ': ' . $t->getMessage());
            self::json(['ok' => false, 'error' => 'unavailable'], 503, ['Retry-After: 60']);
        }
        if (!$r['ok']) {
            $status = $r['reason'] === 'invalid' ? 422 : ($r['reason'] === 'rate_limited' ? 429 : 422);
            self::json(['ok' => false, 'error' => $r['reason'], 'fields' => $r['errors']], $status);
        }
        $out = ['ok' => true, 'status' => $r['status'], 'lead_id' => $r['lead_id'], 'score' => $r['score'], 'temperature' => $r['temperature']];
        // WhatsApp entrante (Q4b): el lead escribio primero. El primer mensaje abre la ventana de 24 h para responder (consents.channel = whatsapp_inbound);
        // NO es consentimiento de marketing. Formato: "inbound": {"channel":"whatsapp","text":"…","at":"ISO 8601","message_id":"wamid…"}
        if (is_array($data['inbound'] ?? null) && $r['lead_id'] !== null) {
            $out['inbound_recorded'] = self::inbound((int) $r['lead_id'], $data['inbound'], $r['status'] === 'created');
        }
        self::json($out, $r['status'] === 'created' ? 201 : 200);
    }

    private static function inbound(int $leadId, array $in, bool $isNew): bool
    {
        $channel = in_array($in['channel'] ?? 'whatsapp', ['whatsapp', 'sms'], true) ? (string) ($in['channel'] ?? 'whatsapp') : '';
        $mid = is_string($in['message_id'] ?? null) && preg_match('/^[A-Za-z0-9._=+-]{1,120}$/', $in['message_id']) === 1 ? $in['message_id'] : null;
        if ($channel === '') {
            return false;
        }
        try {
            return (bool) Db::transaction(static function () use ($leadId, $in, $channel, $mid, $isNew): bool {
                if ($mid !== null && Webhook::claim('lead_in', Webhook::key($channel, $mid), null, 'mensaje entrante') === null) {
                    return true;   // el mismo mensaje ya se registro
                }
                Inbound::record($leadId, $channel, (string) ($in['text'] ?? ''), Inbound::when($in['at'] ?? null), $mid, 'api');
                if (!$isNew) {
                    Inbound::replied($leadId, $channel, (string) ($in['text'] ?? ''));
                }
                return true;
            });
        } catch (\Throwable $t) {
            Storage::error('api/lead inbound failed', get_class($t) . ': ' . $t->getMessage());
            return false;
        }
    }
}
