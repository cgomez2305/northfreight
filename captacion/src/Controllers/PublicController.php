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
    public const LIMIT_VALID = 2000;   // peticiones por IP y hora con un token de baja VÁLIDO (techo anti-abuso, muy por encima del uso real)

    private static function page(string $view, array $vars, int $status = 200): never
    {
        http_response_code($status);
        SecurityHeaders::send();
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        echo View::render($view, $vars + ['title' => 'NorthStar Freight Solutions', 'css' => ['auth', 'pub']]);
        exit;
    }

    private static function limited(string $bucket, int $limit = self::LIMIT): bool
    {
        $ip = \Captacion\Services\ClientIp::get();
        if (RateLimiter::tooMany($bucket, $ip, $limit, 3600)) {
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
        // La firma se comprueba PRIMERO (es solo un HMAC). Un token válido solo sale de un correo nuestro y su baja es idempotente: no puede quedar bloqueada por un límite
        // por IP compartido (Gmail, pasarelas corporativas y escáneres de enlaces salen de pocas IP y la baja es una obligación legal). Los tokens inválidos sí tienen límite estricto.
        $id = Unsub::verify($token);
        $pid = $id === null ? Unsub::verifyProspect($token) : null;   // fase AQ: baja de un prospecto FMCSA (correos en lote), mismo enlace /u/{token} con otro prefijo firmado
        if (self::limited($id === null && $pid === null ? 'pub_unsub' : 'pub_unsub_ok', $id === null && $pid === null ? self::LIMIT : self::LIMIT_VALID)) {
            header('Retry-After: 3600');
            self::page('unsub', ['state' => 'limited', 'lang' => 'en'], 429);
        }
        if ($pid !== null) {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                self::page('unsub', ['state' => 'confirm', 'lang' => 'en', 'token' => $token]);
            }
            $r = \Captacion\Services\ProspectMail::unsubscribe($pid, 'unsubscribe_link');
            if (!$r['ok']) {
                self::page('unsub', ['state' => 'invalid', 'lang' => 'en'], 404);
            }
            self::page('unsub', ['state' => 'done', 'lang' => 'en', 'first_name' => '', 'already' => $r['already']]);
        }
        if ($id === null) {
            self::page('unsub', ['state' => 'invalid', 'lang' => 'en'], 404);
        }
        $lead = \Captacion\Models\Lead::find($id);
        if ($lead === null) {
            self::page('unsub', ['state' => 'invalid', 'lang' => 'en'], 404);
        }
        $lang = in_array($lead['language'], ['en', 'es'], true) ? (string) $lead['language'] : 'en';
        // GET (el enlace del correo): SOLO muestra la confirmacion. Los escaneres de seguridad de correo precargan los enlaces y darian bajas falsas.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::page('unsub', ['state' => 'confirm', 'lang' => $lang, 'token' => $token]);
        }
        // POST (el boton de la pagina o el List-Unsubscribe=One-Click del cliente de correo): el token firmado es la credencial, sin CSRF.
        $r = Unsub::apply($id, 'unsubscribe_link');
        if (!$r['ok']) {
            self::page('unsub', ['state' => 'invalid', 'lang' => 'en'], 404);
        }
        self::page('unsub', ['state' => 'done', 'lang' => $r['lang'], 'first_name' => $r['first_name'], 'already' => $r['already']]);
    }

    // --- /o/{token}: píxel de apertura de los correos a prospectos (fase AQ) ----------------------------------------------------------

    public static function open(string $token): never
    {
        $rid = Unsub::verifyOpen($token);
        if ($rid !== null && !self::limited('pub_open', self::LIMIT_VALID)) {
            try {
                \Captacion\Services\ProspectMail::recordOpen($rid);
            } catch (\Throwable) {
                // nunca rompe la imagen
            }
        }
        http_response_code(200);
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
}
