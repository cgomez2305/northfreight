<?php
/**
 * API interna para n8n (/internal/*, Q4b). Autorizacion: Authorization: Bearer <INTERNAL_API_TOKEN> (>= 32 caracteres; si falta, 503), comparacion en tiempo constante,
 * 10 autenticaciones fallidas / 15 min por IP y INTERNAL_RATE_LIMIT peticiones/hora por IP (1500: n8n consulta cada pocos minutos). Cuerpos JSON de hasta 16 KB.
 * Permisos acotados: solo estas rutas y solo los campos que documenta Services\N8nBridge. Sin CORS. Respuestas sin mas datos personales que los necesarios para enviar.
 *
 *   GET   /internal/ping
 *   GET   /internal/sequences/due?channel=whatsapp|sms&limit=50
 *   GET   /internal/reminders/due?limit=50
 *   GET   /internal/leads/by-phone?phone=+13055550100
 *   PATCH /internal/leads/{id}                 lista blanca de campos
 *   POST  /internal/leads/{id}/events          {event_id, event|type, channel, template, ...}
 *   POST  /internal/leads/{id}/consent-check   {channel}
 *   POST  /internal/leads/{id}/handoff-ready   {summary}
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Integrations;
use Captacion\Services\Messaging;
use Captacion\Services\N8nBridge;
use Captacion\Services\RateLimiter;
use Captacion\Services\Storage;
use Captacion\Services\Webhook;

final class InternalController extends Responder
{
    public const MAX_BODY = 16384;

    public static function run(): never
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        Storage::ensure(Storage::dir());
        ini_set('error_log', Storage::dir('error.log'));
        set_exception_handler(static function (\Throwable $t): void {
            Storage::error('internal ' . get_class($t), $t->getMessage() . ' @' . basename($t->getFile()) . ':' . $t->getLine());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"ok":false,"error":"server_error"}';
        });
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = '/' . trim((string) preg_replace('#/{2,}#', '/', is_string($path) ? rawurldecode($path) : '/'), '/');
        $ip = self::ip();
        if (RateLimiter::tooMany('int_auth', $ip, 10, 900) || RateLimiter::tooMany('int', $ip, max(10, Env::int('INTERNAL_RATE_LIMIT', 1500)), 3600)) {
            self::json(['ok' => false, 'error' => 'rate_limited'], 429, ['Retry-After: 900']);
        }
        RateLimiter::hit('int', $ip, 3600);
        $expected = Env::get('INTERNAL_API_TOKEN');
        if ($expected === null || strlen($expected) < 32) {
            self::json(['ok' => false, 'error' => 'api_disabled'], 503);
        }
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $given = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) === 1 ? $m[1] : '';
        if ($given === '' || !hash_equals($expected, $given)) {
            RateLimiter::hit('int_auth', $ip, 900);
            header('WWW-Authenticate: Bearer realm="captacion-internal"');
            self::json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        $q = static fn(string $k): string => is_string($_GET[$k] ?? null) ? (string) $_GET[$k] : '';
        $limit = is_numeric($q('limit')) ? (int) $q('limit') : 50;

        if ($path === '/internal/ping') {
            self::allow($method, ['GET']);
            self::json(['ok' => true, 'time' => gmdate('c')]);
        }
        if ($path === '/internal/sequences/due') {
            self::allow($method, ['GET']);
            $ch = $q('channel') !== '' ? $q('channel') : 'whatsapp';
            if (!in_array($ch, ['whatsapp', 'sms'], true)) {
                self::json(['ok' => false, 'error' => 'channel_not_supported', 'message' => 'El correo lo envia el propio CRM; aqui solo whatsapp y sms.'], 400);
            }
            self::json(['ok' => true, 'channel' => $ch, 'items' => N8nBridge::due($ch, $limit)]);
        }
        if ($path === '/internal/reminders/due') {
            self::allow($method, ['GET']);
            self::json(['ok' => true, 'items' => N8nBridge::dueReminders($limit)]);
        }
        if ($path === '/internal/leads/by-phone') {
            self::allow($method, ['GET']);
            $r = N8nBridge::byPhone($q('phone'));
            $r === null ? self::json(['ok' => true, 'found' => false]) : self::json(['ok' => true, 'found' => true] + $r);
        }
        if (preg_match('#^/internal/leads/(\d{1,12})(?:/([a-z-]{4,16}))?$#', $path, $mm) === 1) {
            $id = (int) $mm[1];
            $sub = $mm[2] ?? '';
            if ($sub === '') {
                self::allow($method, ['PATCH']);
                $r = N8nBridge::patch($id, self::body());
                self::done($r);
            }
            self::allow($method, ['POST']);
            $body = self::body();
            if ($sub === 'consent-check') {
                $ch = is_string($body['channel'] ?? null) ? strtolower($body['channel']) : '';
                if (!in_array($ch, Messaging::CHANNELS, true)) {
                    self::json(['ok' => false, 'error' => 'channel_required', 'channels' => Messaging::CHANNELS], 422);
                }
                $r = Messaging::consentCheck($id, $ch);
                if ($r['reason'] === 'lead_not_found') {
                    self::json(['ok' => false, 'error' => 'lead_not_found'], 404);
                }
                self::json(['ok' => true] + $r);
            }
            if ($sub === 'handoff-ready') {
                self::done(N8nBridge::handoffReady($id, $body));
            }
            if ($sub === 'events') {
                $eid = is_string($body['event_id'] ?? null) ? $body['event_id'] : (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
                if (preg_match('/^[A-Za-z0-9_.:-]{8,80}$/', $eid) !== 1) {
                    self::json(['ok' => false, 'error' => 'event_id_required', 'message' => 'Manda event_id (8 a 80 caracteres) o la cabecera Idempotency-Key: un mismo evento dos veces no se repite.'], 422);
                }
                $body['event'] = $body['event'] ?? ($body['type'] ?? '');
                if (!in_array($body['event'], N8nBridge::EVENTS, true)) {
                    self::json(['ok' => false, 'error' => 'invalid_event', 'events' => N8nBridge::EVENTS], 422);
                }
                $res = Db::transaction(static function () use ($id, $body, $eid): array {
                    $wid = Webhook::claim('n8n', Webhook::key('i', (string) $id, $eid));
                    if ($wid === null) {
                        return ['ok' => true, 'duplicate' => true];
                    }
                    $r = N8nBridge::applyEvent($id, $body);
                    Webhook::finish($wid, $r['ok'] ? 'processed' : 'error', $r['ok'] ? $id : null, $r['ok'] ? ($r['summary'] ?? null) : ($r['error'] ?? 'error'));
                    return $r;
                });
                if (($res['ok'] ?? false) && ($body['event'] ?? '') !== 'error') {
                    Integrations::webhook('n8n', 'ok', (string) ($res['summary'] ?? 'duplicado'));
                }
                self::done($res);
            }
        }
        self::json(['ok' => false, 'error' => 'not_found'], 404);
    }

    private static function allow(string $method, array $allowed): void
    {
        if (!in_array($method, $allowed, true)) {
            self::json(['ok' => false, 'error' => 'method_not_allowed'], 405, ['Allow: ' . implode(', ', $allowed)]);
        }
    }

    /** @return array<string,mixed> */
    private static function body(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
            self::json(['ok' => false, 'error' => 'payload_too_large'], 413);
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            self::json(['ok' => false, 'error' => 'payload_too_large'], 413);
        }
        if (trim($raw) === '') {
            return [];
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || array_is_list($d) && $d !== []) {
            self::json(['ok' => false, 'error' => 'invalid_json'], 400);
        }
        return $d;
    }

    private static function done(array $r): never
    {
        if ($r['ok'] ?? false) {
            self::json($r);
        }
        $e = (string) ($r['error'] ?? 'error');
        self::json($r, $e === 'lead_not_found' ? 404 : 422);
    }
}
