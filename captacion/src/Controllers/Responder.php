<?php
/** Utilidades de respuesta compartidas por los controladores: sesion, CSRF, mensajes flash, paginas con el shell del panel y JSON. */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Db;
use Captacion\Middleware\SecurityHeaders;
use Captacion\Models\User;
use Captacion\Services\Notifications;
use Captacion\Services\Session;
use Captacion\Services\Tasks;
use Captacion\View;

abstract class Responder
{
    protected static function redirect(string $path, int $status = 303): never
    {
        SecurityHeaders::send();
        header('Location: ' . $path, true, $status);
        exit;
    }

    protected static function html(string $view, array $vars = [], int $status = 200): never
    {
        http_response_code($status);
        SecurityHeaders::send();
        header('Content-Type: text/html; charset=utf-8');
        echo View::render($view, $vars);
        exit;
    }

    /**
     * Usuario autenticado (2FA completo) o redireccion a /login.
     * AUTORIZACION POR ROL, DENEGADA POR DEFECTO: una cuenta `viewer` (el lector de NorthStar) solo entra donde el controlador lo declara con $viewerOk = true
     * (/resultados e /informes). En cualquier otra ruta recibe 403 sin ningun dato. Un controlador nuevo que llame a user() sin mas queda cerrado para el lector.
     */
    protected static function user(bool $viewerOk = false): array
    {
        Session::start();
        $uid = Session::userId();
        $user = $uid !== null ? User::find($uid) : null;
        if ($user === null || (int) $user['is_active'] !== 1 || $user['totp_confirmed_at'] === null) {
            if ($uid !== null) {
                Session::destroy();   // la cuenta ya no existe, se desactivo o se reinicio su 2FA: fuera
            }
            self::redirect('/login');
        }
        if (($user['role'] ?? '') === 'viewer' && !$viewerOk) {
            self::html('message', ['title' => 'Sin permiso', 'heading' => 'Sin permiso', 'text' => 'Tu cuenta solo puede ver los resultados y los informes.', 'linkHref' => '/resultados', 'linkText' => 'Ir a resultados'], 403);
        }
        return $user;
    }

    /** Igual que user() pero para endpoints JSON: 401 en vez de redirigir y 403 para el lector. */
    protected static function userJson(bool $viewerOk = false): array
    {
        Session::start();
        $uid = Session::userId();
        $user = $uid !== null ? User::find($uid) : null;
        if ($user === null || (int) $user['is_active'] !== 1 || $user['totp_confirmed_at'] === null) {
            self::json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }
        if (($user['role'] ?? '') === 'viewer' && !$viewerOk) {
            self::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        return $user;
    }

    /** Exige POST con token CSRF valido (campo _csrf). Si falla, avisa y vuelve a $back. */
    protected static function requirePost(string $back): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::redirect($back);
        }
        if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
            self::flash(false, 'El formulario caducó. Inténtalo de nuevo.');
            self::redirect($back);
        }
    }

    protected static function flash(bool $ok, string $msg): void
    {
        $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
    }

    protected static function takeFlash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        if (!empty($_SESSION['notice']) && $f === null) {
            $f = ['ok' => true, 'msg' => (string) $_SESSION['notice']];
        }
        unset($_SESSION['notice']);
        return is_array($f) ? $f : null;
    }

    /**
     * Pagina del panel: añade el shell (barra lateral, barra superior, campana, usuario) a las variables de la vista.
     * @param list<array{0:string,1:?string}> $crumbs [[texto, url|null], ...]
     */
    protected static function page(string $view, array $user, string $active, array $crumbs, array $vars = [], int $status = 200): never
    {
        $viewer = ($user['role'] ?? '') === 'viewer';
        // El lector de NorthStar no ve campana, atajos de leads, buscador ni contadores: nada que se parezca a un lead.
        $notes = $viewer ? [] : Notifications::all((int) $user['id']);
        $hot = $viewer ? 0 : (int) Db::value("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND temperature = 'hot' AND first_contact_at IS NULL AND stage = 'new'");
        self::html($view, $vars + [
            'shell' => true, 'user' => $user, 'csrf' => Session::csrf(), 'active' => $active, 'crumbs' => $crumbs, 'bell' => $notes,
            'unread' => Notifications::unread($notes), 'hotBadge' => $hot, 'overdueBadge' => $viewer ? 0 : Tasks::overdueCount(), 'isAdmin' => ($user['role'] ?? '') === 'admin', 'isViewer' => $viewer, 'flash' => self::takeFlash(),
            'sysOk' => !array_filter($notes, static fn($n) => in_array($n['kind'], ['bad', 'warn'], true)),
        ], $status);
    }

    /** Solo administradores: el resto ve una pagina 403 (los viewers no ven ni la barra lateral de administracion). */
    protected static function requireAdmin(array $user): void
    {
        if (($user['role'] ?? '') !== 'admin') {
            self::html('message', ['title' => 'Sin permiso', 'heading' => 'Sin permiso', 'text' => 'Esta sección es solo para administradores.'], 403);
        }
    }

    /** Las cuentas de solo lectura (viewer) no escriben: en paginas se avisa y se vuelve a $back. */
    protected static function requireWriter(array $user, string $back): void
    {
        if (($user['role'] ?? '') === 'viewer') {
            self::flash(false, 'Tu cuenta es de solo lectura.');
            self::redirect($back);
        }
    }

    /**
     * Endpoint JSON con escritura: POST, sesion, CSRF en X-CSRF-Token y cuerpo JSON de hasta 4 KB (o $max).
     * @return array{0:array,1:array} [usuario, cuerpo]
     */
    protected static function jsonPost(int $max = 4096): array
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::json(['ok' => false, 'error' => 'method'], 405, ['Allow: POST']);
        }
        $user = self::userJson();
        if (!Session::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            self::json(['ok' => false, 'error' => 'csrf'], 403);
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max) {
            self::json(['ok' => false, 'error' => 'too_large'], 413);
        }
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            self::json(['ok' => false, 'error' => 'invalid'], 400);
        }
        return [$user, $body];
    }

    protected static function notFound(): never
    {
        self::html('message', ['title' => 'No encontrado', 'heading' => 'No encontrado', 'text' => 'Esta página no existe.'], 404);
    }

    protected static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    protected static function json(array $body, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        SecurityHeaders::send(false);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $h) {
            header($h);
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Descarga de un archivo generado (CSV). */
    protected static function download(string $filename, string $mime, string $body): never
    {
        http_response_code(200);
        SecurityHeaders::send(false);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }
}
