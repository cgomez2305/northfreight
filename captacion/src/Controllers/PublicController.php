<?php
/**
 * Paginas PUBLICAS con token firmado (sin sesion ni cookies): /c/{token} (NorthStar confirma «Firmó» / «Lo rechazamos») y /u/{token} (baja de correo de un clic).
 * Reglas: noindex y CSP estricta (SecurityHeaders), limite de peticiones por IP (60/hora y ruta, aunque sean GET), el token va firmado con HMAC (APP_KEY) y no lleva datos personales,
 * un GET nunca cambia nada en /c (los escaneres de correo abren los enlaces): el cambio ocurre con el POST del boton. Las respuestas de error no distinguen por que fallo un token manipulado.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Middleware\SecurityHeaders;
use Captacion\Services\ActivityLog;
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\Handoff;
use Captacion\Services\RateLimiter;
use Captacion\Services\Unsub;
use Captacion\View;

final class PublicController
{
    public const LIMIT = 60;

    private static function page(string $view, array $vars, int $status = 200): never
    {
        http_response_code($status);
        SecurityHeaders::send();
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        echo View::render($view, $vars + ['title' => 'NorthStar Freight Solutions', 'css' => ['auth', 'pub']]);
        exit;
    }

    private static function limited(string $bucket): bool
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        if (RateLimiter::tooMany($bucket, $ip, self::LIMIT, 3600)) {
            return true;
        }
        RateLimiter::hit($bucket, $ip, 3600);
        return false;
    }

    // --- /c/{token}: confirmacion de NorthStar ----------------------------------------------------------------------------------

    public static function confirm(string $token): never
    {
        if (self::limited('pub_confirm')) {
            header('Retry-After: 3600');
            self::page('confirm', ['state' => 'limited'], 429);
        }
        $v = Handoff::verifyToken($token);
        if ($v['status'] === 'invalid') {
            self::page('confirm', ['state' => 'invalid'], 404);
        }
        if ($v['status'] !== 'ok') {
            self::page('confirm', ['state' => $v['status']], 410);
        }
        $lead = $v['lead'];
        $name = Fmt::name($lead);
        $vars = ['state' => 'form', 'action' => $v['action'], 'name' => $name, 'token' => $token, 'reasons' => Catalog::LOSS_REASONS, 'reason' => 'rejected', 'note' => '', 'error' => null];
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::page('confirm', $vars);
        }
        $reason = is_string($_POST['reason'] ?? null) ? $_POST['reason'] : 'rejected';
        $note = is_string($_POST['note'] ?? null) ? mb_substr($_POST['note'], 0, 500) : '';
        $r = Handoff::confirm((int) $v['handoff']['id'], $v['action'], 'link', null, ['reason' => $reason, 'note' => $note]);
        if (!$r['ok']) {
            // faltan datos del rechazo: se vuelve a mostrar el formulario; cualquier otro motivo (ya confirmado, lead movido): pagina neutra
            if ($v['action'] === 'rejected' && (str_contains($r['msg'], 'motivo') || str_contains($r['msg'], 'nota'))) {
                self::page('confirm', ['error' => $r['msg'], 'reason' => $reason, 'note' => $note] + $vars, 422);
            }
            self::page('confirm', ['state' => 'used'], 410);
        }
        ActivityLog::record('handoff_link_' . $v['action'], null, 'lead', (int) $lead['id'], ['handoff_id' => (int) $v['handoff']['id']]);
        self::page('confirm', ['state' => 'done', 'action' => $v['action'], 'name' => $name]);
    }

    // --- /u/{token}: baja de correo ------------------------------------------------------------------------------------------------

    public static function unsubscribe(string $token): never
    {
        if (self::limited('pub_unsub')) {
            header('Retry-After: 3600');
            self::page('unsub', ['state' => 'limited', 'lang' => 'en'], 429);
        }
        $id = Unsub::verify($token);
        if ($id === null) {
            self::page('unsub', ['state' => 'invalid', 'lang' => 'en'], 404);
        }
        // GET (el enlace del correo) y POST (List-Unsubscribe=One-Click de los clientes de correo) dan de baja al instante: un clic, nada mas que pedir.
        $r = Unsub::apply($id, 'unsubscribe_link');
        if (!$r['ok']) {
            self::page('unsub', ['state' => 'invalid', 'lang' => 'en'], 404);
        }
        self::page('unsub', ['state' => 'done', 'lang' => $r['lang'], 'first_name' => $r['first_name'], 'already' => $r['already']]);
    }
}
