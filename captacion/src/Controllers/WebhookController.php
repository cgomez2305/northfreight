<?php
/**
 * Webhooks entrantes (Q4b): meta, calcom, sms (Twilio) y n8n. Un archivo delgado por origen en public/api/webhooks/ llama a run().
 * Orden fijo (ver Services\Webhook): metodo -> configurado (503) -> limite de tasa -> tamano -> FIRMA -> idempotencia -> trabajo (los pesados, a la cola).
 * Cada evento se procesa en UNA transaccion junto con su registro de idempotencia: si algo falla, no queda marcado como recibido y el emisor lo reintenta.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Middleware\SecurityHeaders;
use Captacion\Services\Appointments;
use Captacion\Services\Integrations;
use Captacion\Services\MetaLeads;
use Captacion\Services\N8nBridge;
use Captacion\Services\Queue;
use Captacion\Services\SmsInbound;
use Captacion\Services\Storage;
use Captacion\Services\Webhook;

final class WebhookController extends Responder
{
    public static function run(string $source): never
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        Storage::ensure(Storage::dir());
        ini_set('error_log', Storage::dir('error.log'));
        set_exception_handler(static function (\Throwable $t) use ($source): void {
            Storage::error('webhook ' . $source . ' ' . get_class($t), $t->getMessage() . ' @' . basename($t->getFile()) . ':' . $t->getLine());
            try {
                Integrations::webhook($source, 'err', 'Error interno: ' . get_class($t));
            } catch (\Throwable) {
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo '{"ok":false,"error":"server_error"}';
        });
        match ($source) {
            'meta' => self::meta(), 'calcom' => self::calcom(), 'twilio' => self::sms(), 'n8n' => self::n8n(),
        };
    }

    /** Pasos comunes. @return string cuerpo crudo (vacio en GET) */
    private static function intake(string $source, array $methods, string $label): string
    {
        $m = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if (!in_array($m, $methods, true)) {
            self::json(['ok' => false, 'error' => 'method_not_allowed'], 405, ['Allow: ' . implode(', ', $methods)]);
        }
        if (!Webhook::configured($source)) {
            self::json(['ok' => false, 'error' => 'not_configured', 'message' => $label . ' sin configurar: faltan ' . implode(', ', Webhook::missing($source)) . ' en config/.env'], 503);
        }
        $ip = self::ip();
        if (Webhook::tooMany($source, $ip) || Webhook::sigBlocked($source, $ip)) {
            self::json(['ok' => false, 'error' => 'rate_limited'], 429, ['Retry-After: 900']);
        }
        Webhook::hit($source, $ip);
        if ($m === 'GET') {
            return '';
        }
        $max = Webhook::maxBody();
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) {
            self::json(['ok' => false, 'error' => 'payload_too_large'], 413);
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max) {
            self::json(['ok' => false, 'error' => 'payload_too_large'], 413);
        }
        return $raw;
    }

    private static function badSignature(string $source, string $why): never
    {
        Webhook::sigFailed($source, self::ip());
        self::json(['ok' => false, 'error' => 'invalid_signature'], 401);
    }

    private static function header(string $name): string
    {
        return (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
    }

    // --- Meta Lead Ads -------------------------------------------------------------------------------------------------------------

    private static function meta(): never
    {
        $raw = self::intake('meta', ['GET', 'POST'], 'Meta Lead Ads');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $q);   // PHP cambia «hub.mode» por «hub_mode»
            $mode = (string) ($q['hub_mode'] ?? ($q['hub.mode'] ?? ''));
            $tok = (string) ($q['hub_verify_token'] ?? ($q['hub.verify_token'] ?? ''));
            $ch = (string) ($q['hub_challenge'] ?? ($q['hub.challenge'] ?? ''));
            $expected = (string) Env::get('META_VERIFY_TOKEN');
            if ($mode !== 'subscribe' || $tok === '' || !hash_equals($expected, $tok)) {
                self::badSignature('meta', 'verify_token');
            }
            if (preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $ch) !== 1) {
                self::json(['ok' => false, 'error' => 'invalid_challenge'], 400);
            }
            Integrations::webhook('meta', 'ok', 'verificación del webhook');
            http_response_code(200);
            SecurityHeaders::send(false);
            header('Content-Type: text/plain; charset=utf-8');
            echo $ch;
            exit;
        }
        if ((Env::get('META_PAGE_TOKEN') ?? '') === '') {
            self::json(['ok' => false, 'error' => 'not_configured', 'message' => 'Meta sin configurar: falta META_PAGE_TOKEN en config/.env'], 503);   // Meta reintenta mas tarde
        }
        if (!Webhook::equalsHex(self::header('X-Hub-Signature-256'), Webhook::hmacHex('sha256', Webhook::secret('meta'), $raw))) {
            self::badSignature('meta', 'signature');
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            self::json(['ok' => false, 'error' => 'invalid_json'], 400);
        }
        if (($body['object'] ?? '') !== 'page') {
            self::json(['ok' => true, 'ignored' => 'object']);
        }
        $leads = MetaLeads::parseWebhook($body);
        $new = 0;
        $dup = 0;
        Db::transaction(static function () use ($leads, &$new, &$dup): void {
            foreach ($leads as $l) {
                $id = Webhook::claim('meta', Webhook::key($l['leadgen_id']), $l['created_time'] !== null ? Clock::at($l['created_time']) : null, 'lead de Meta en cola');
                if ($id === null) {
                    $dup++;
                    continue;
                }
                if (str_starts_with($l['leadgen_id'], MetaLeads::TEST_PREFIX)) {
                    Webhook::finish($id, 'ignored', null, 'evento de prueba (sin efectos)');
                    continue;
                }
                Queue::push('meta_fetch_lead', $l, Clock::now(), 'meta_lead:' . $l['leadgen_id']);
                $new++;
            }
        });
        Integrations::webhook('meta', 'ok', $new . ' lead(s) en cola');
        self::json(['ok' => true, 'received' => count($leads), 'queued' => $new, 'duplicates' => $dup]);
    }

    // --- Cal.com -------------------------------------------------------------------------------------------------------------------

    private static function calcom(): never
    {
        $raw = self::intake('calcom', ['POST'], 'Cal.com');
        if (!Webhook::equalsHex(self::header('X-Cal-Signature-256'), Webhook::hmacHex('sha256', Webhook::secret('calcom'), $raw))) {
            self::badSignature('calcom', 'signature');
        }
        $env = json_decode($raw, true);
        if (!is_array($env)) {
            self::json(['ok' => false, 'error' => 'invalid_json'], 400);
        }
        $ev = Appointments::parse($env);
        if ($ev === 'ping') {
            Integrations::webhook('calcom', 'ok', 'PING');
            self::json(['ok' => true, 'test' => true]);
        }
        if (is_string($ev)) {
            $why = $ev;
            if ($why !== 'not_handled') {
                Integrations::webhook('calcom', 'err', 'Evento inválido: ' . $why);
            }
            self::json(['ok' => $why === 'not_handled', 'ignored' => $why], $why === 'not_handled' ? 200 : 422);
        }
        $r = Db::transaction(static function () use ($ev): array {
            $id = Webhook::claim('calcom', Webhook::key($ev['trigger'], $ev['uid'], (string) $ev['start'], (string) $ev['event_at']), Clock::at($ev['event_at']));
            if ($id === null) {
                return ['status' => 'duplicate', 'summary' => '', 'lead_id' => null, 'appointment_id' => null];
            }
            $res = Appointments::apply($ev);
            Webhook::finish($id, $res['status'] === 'processed' ? 'processed' : 'ignored', $res['lead_id'], $res['summary']);
            return $res;
        });
        Integrations::webhook('calcom', 'ok', $r['summary'] !== '' ? $r['summary'] : 'duplicado');
        self::json(['ok' => true, 'status' => $r['status'], 'appointment_id' => $r['appointment_id']]);
    }

    // --- Twilio (SMS) --------------------------------------------------------------------------------------------------------------

    private static function sms(): never
    {
        $raw = self::intake('twilio', ['POST'], 'Twilio');
        $params = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $params[urldecode($k)] = urldecode($v);
        }
        $url = Webhook::url('twilio') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
        if (!Webhook::twilioValid(self::header('X-Twilio-Signature'), $url, $params, Webhook::secret('twilio'))) {
            self::badSignature('twilio', 'signature');
        }
        $r = Db::transaction(static fn() => SmsInbound::handle($params));
        Integrations::webhook('twilio', 'ok', $r['summary']);
        http_response_code(200);
        SecurityHeaders::send(false);
        header('Content-Type: text/xml; charset=utf-8');
        echo $r['twiml'];
        exit;
    }

    // --- n8n -----------------------------------------------------------------------------------------------------------------------

    private static function n8n(): never
    {
        $raw = self::intake('n8n', ['POST'], 'n8n');
        $ts = self::header('X-NS-Timestamp');
        $ok = ctype_digit($ts) && abs(Clock::time() - (int) $ts) <= 300
            && Webhook::equalsHex(self::header('X-NS-Signature'), Webhook::hmacHex('sha256', Webhook::secret('n8n'), $ts . '.' . $raw));
        if (!$ok) {
            self::badSignature('n8n', 'signature');
        }
        $e = json_decode($raw, true);
        if (!is_array($e)) {
            self::json(['ok' => false, 'error' => 'invalid_json'], 400);
        }
        if (($e['event'] ?? '') === 'ping') {
            Integrations::webhook('n8n', 'ok', 'ping');
            self::json(['ok' => true, 'test' => true]);
        }
        $eventId = is_string($e['event_id'] ?? null) ? $e['event_id'] : '';
        if (preg_match('/^[A-Za-z0-9_.:-]{8,80}$/', $eventId) !== 1) {
            self::json(['ok' => false, 'error' => 'event_id_required'], 422);
        }
        $leadId = is_int($e['lead_id'] ?? null) ? (int) $e['lead_id'] : 0;
        if (!in_array($e['event'] ?? '', N8nBridge::EVENTS, true) || $leadId < 1) {
            self::json(['ok' => false, 'error' => 'invalid_event'], 422);
        }
        $res = Db::transaction(static function () use ($e, $eventId, $leadId): array {
            $id = Webhook::claim('n8n', Webhook::key($eventId));
            if ($id === null) {
                return ['ok' => true, 'duplicate' => true];
            }
            $r = N8nBridge::applyEvent($leadId, $e);
            Webhook::finish($id, $r['ok'] ? 'processed' : 'error', $r['ok'] ? $leadId : null, $r['ok'] ? ($r['summary'] ?? null) : ($r['error'] ?? 'error'));
            return $r;
        });
        if (!($res['ok'] ?? false)) {
            self::json(['ok' => false, 'error' => $res['error'] ?? 'error'], ($res['error'] ?? '') === 'lead_not_found' ? 404 : 422);
        }
        if (($e['event'] ?? '') !== 'error') {   // un evento «error» ya dejo su marca roja en la integracion
            Integrations::webhook('n8n', 'ok', (string) ($res['summary'] ?? 'duplicado'));
        }
        self::json(['ok' => true] + (isset($res['duplicate']) ? ['duplicate' => true] : []));
    }
}
