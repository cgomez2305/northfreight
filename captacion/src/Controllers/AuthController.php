<?php
/** /login, /login/2fa y /logout. Contrasena + TOTP (o codigo de recuperacion). CSRF en todo POST. */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Models\User;
use Captacion\Services\ActivityLog;
use Captacion\Services\Auth;
use Captacion\Services\Session;

final class AuthController extends Responder
{
    public static function login(): never
    {
        Session::start();
        if (Session::userId() !== null) {
            $u = User::find((int) Session::userId());
            self::redirect(($u['role'] ?? '') === 'viewer' ? '/resultados' : '/');
        }
        $vars = ['title' => 'Entrar', 'csrf' => Session::csrf(), 'notice' => Session::wasExpired() ? 'La sesión caducó por inactividad. Entra de nuevo.' : null];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::html('login', $vars);
        }
        if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
            self::html('login', ['error' => 'El formulario caducó. Inténtalo de nuevo.'] + $vars, 403);
        }
        $email = is_string($_POST['email'] ?? null) ? mb_substr(trim($_POST['email']), 0, 190) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $r = Auth::attemptPassword($email, $password, self::ip());
        if ($r['status'] === 'blocked') {
            header('Retry-After: ' . Auth::WINDOW);
            self::html('login', ['error' => 'Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.', 'email' => $email] + $vars, 429);
        }
        if ($r['status'] !== 'ok') {
            self::html('login', ['error' => 'Correo o contraseña incorrectos.', 'email' => $email] + $vars, 401);
        }
        Session::setPending((int) $r['user']['id']);
        self::redirect('/login/2fa');
    }

    public static function twofa(): never
    {
        Session::start();
        $uid = Session::pendingUserId();
        $user = $uid !== null ? User::find($uid) : null;
        if ($user === null) {
            self::redirect('/login');
        }
        $vars = ['title' => 'Verificación', 'csrf' => Session::csrf()];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::html('twofa', $vars);
        }
        if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
            self::html('twofa', ['error' => 'El formulario caducó. Inténtalo de nuevo.'] + $vars, 403);
        }
        $code = is_string($_POST['code'] ?? null) ? mb_substr($_POST['code'], 0, 40) : '';
        $r = Auth::verifySecondFactor($user, $code, self::ip());
        if ($r['status'] === 'blocked') {
            Session::destroy();
            http_response_code(429);
            header('Retry-After: ' . Auth::WINDOW);
            Session::start();
            self::html('login', ['title' => 'Entrar', 'csrf' => Session::csrf(), 'error' => 'Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.'], 429);
        }
        if ($r['status'] !== 'ok') {
            self::html('twofa', ['error' => 'Código incorrecto.'] + $vars, 401);
        }
        Session::login((int) $user['id']);
        if ($r['via'] === 'recovery') {
            $_SESSION['notice'] = 'Entraste con un código de recuperación. Te quedan ' . (int) $r['recovery_left'] . ' sin usar.';
        }
        self::redirect(($user['role'] ?? '') === 'viewer' ? '/resultados' : '/');
    }

    public static function logout(): never
    {
        Session::start();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Session::checkCsrf($_POST['_csrf'] ?? null)) {
            self::redirect('/');
        }
        $uid = Session::userId();
        if ($uid !== null) {
            ActivityLog::record('logout', $uid, 'user', $uid);
        }
        Session::destroy();
        self::redirect('/login');
    }
}
