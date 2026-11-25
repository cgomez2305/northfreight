<?php
/**
 * Fase AV: webhooks de Vonage (answer y event) y los dos endpoints del panel (/ajax/vonage/start y /ajax/vonage/call). Ver Services\Vonage.
 * Webhooks: método -> configurado (503) -> límite por IP -> tamaño -> FIRMA (JWT HS256, antes de interpretar nada) -> trabajo. Nunca registran cuerpos ni teléfonos.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Middleware\SecurityHeaders;
use Captacion\Services\Integrations;
use Captacion\Services\Storage;
use Captacion\Services\Vonage;
use Captacion\Services\Webhook;

final class VonageController extends Responder
{
    /** @return array cuerpo (JSON en POST, query en GET) ya con la firma comprobada */
    private static function intake(array $methods): array
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        $m = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if (!in_array($m, $methods, true)) {
            self::json(['ok' => false, 'error' => 'method_not_allowed'], 405, ['Allow: ' . implode(', ', $methods)]);
        }
        if (!Vonage::configured()) {
            self::json(['ok' => false, 'error' => 'not_configured'], 503);
        }
        $ip = self::ip();
        if (Webhook::tooMany('vonage', $ip) || Webhook::sigBlocked('vonage', $ip)) {
            self::json(['ok' => false, 'error' => 'rate_limited'], 429, ['Retry-After: 900']);
        }
        Webhook::hit('vonage', $ip);
        $raw = '';
        if ($m === 'POST') {
            $max = Webhook::maxBody();
            $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
            if (strlen($raw) > $max) {
                self::json(['ok' => false, 'error' => 'payload_too_large'], 413);
            }
        }
        if (($why = Vonage::verifyWebhook($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null), $raw)) !== null) {
            Webhook::sigFailed('vonage', $ip);
            self::json(['ok' => false, 'error' => 'bad_signature'], 401);
        }
        $in = $m === 'POST' ? json_decode($raw, true) : $_GET;
        if (!is_array($in)) {
            parse_str($raw, $in);
        }
        return is_array($in) ? $in : [];
    }

    public static function answer(): never
    {
        $in = self::intake(['GET', 'POST']);
        try {
            $ncco = Vonage::answer($in);
            Integrations::webhook('vonage', 'ok', 'answer');
        } catch (\Throwable $t) {
            Storage::error('vonage answer', get_class($t) . ': ' . $t->getMessage());
            Integrations::webhook('vonage', 'err', 'Error al preparar la llamada');
            $ncco = [['action' => 'talk', 'text' => 'Error del sistema. Intenta de nuevo.', 'language' => 'es-ES']];
        }
        http_response_code(200);
        SecurityHeaders::send(false);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ncco, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function event(): never
    {
        $in = self::intake(['GET', 'POST']);
        try {
            Vonage::event($in);
            Integrations::webhook('vonage', 'ok', (string) ($in['status'] ?? 'evento'));
        } catch (\Throwable $t) {
            Storage::error('vonage event', get_class($t) . ': ' . $t->getMessage());
            Integrations::webhook('vonage', 'err', 'Error al guardar un evento de llamada');
            self::json(['ok' => false], 500);
        }
        self::json(['ok' => true]);
    }

    /** POST /ajax/vonage/start {prospect_id} (solo administradores, CSRF). */
    public static function start(): never
    {
        [$user, $body] = self::jsonPost(1024);
        self::requireAdminJson($user);
        $r = Vonage::start($user, (int) ($body['prospect_id'] ?? 0));
        self::json($r, $r['ok'] ? 200 : 422);
    }

    /** POST /ajax/vonage/call {id}: estado de una llamada del propio agente. */
    public static function call(): never
    {
        [$user, $body] = self::jsonPost(512);
        self::requireAdminJson($user);
        $s = Vonage::status((int) ($body['id'] ?? 0), (int) $user['id']);
        self::json($s !== null ? ['ok' => true] + $s : ['ok' => false], $s !== null ? 200 : 404);
    }

    private static function requireAdminJson(array $user): void
    {
        if (($user['role'] ?? '') !== 'admin') {
            self::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
    }
}
