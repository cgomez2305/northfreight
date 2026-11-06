<?php
/**
 * Sesion PHP propia del panel (doc §17): cookie NSCRM HttpOnly + Secure + SameSite=Lax, archivos en storage/sessions (0700), ID regenerado al iniciar sesion,
 * caduca a las 8 h de inactividad (SESSION_IDLE_SECONDS) y, aunque haya actividad, a las 24 h de haber iniciado sesion (SESSION_ABSOLUTE_SECONDS), y token CSRF por sesion.
 * En APP_ENV=local (pruebas por http) la cookie no lleva Secure; en cualquier otro entorno SIEMPRE.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Env;

final class Session
{
    public const NAME = 'NSCRM';
    private static bool $expired = false;

    public static function idle(): int
    {
        return max(1, Env::int('SESSION_IDLE_SECONDS', 28800));
    }

    /** Vida maxima de una sesion autenticada desde el login, con o sin actividad (24 h por defecto): una cookie robada no vale para siempre. */
    public static function absolute(): int
    {
        return max(60, Env::int('SESSION_ABSOLUTE_SECONDS', 86400));
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = Storage::dir('sessions');
        Storage::ensure($dir, 0700);
        session_name(self::NAME);
        session_cache_limiter('');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => Env::get('APP_ENV', 'production') !== 'local',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) max(self::idle(), 3600));
        ini_set('session.gc_probability', '1');   // con save_path propio PHP no limpia sesiones viejas por si solo en Debian/Ubuntu
        ini_set('session.gc_divisor', '100');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        if (is_dir($dir)) {
            session_save_path($dir);
        }
        session_start();

        // Caducidad por inactividad
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        $loginAt = (int) ($_SESSION['login_at'] ?? 0);
        if (($last > 0 && Clock::time() - $last > self::idle()) || (!empty($_SESSION['uid']) && $loginAt > 0 && Clock::time() - $loginAt > self::absolute())) {
            self::$expired = true;
            $_SESSION = ['expired_notice' => 1];   // el login lo muestra una vez
            session_regenerate_id(true);
        }
        if (!empty($_SESSION['uid']) || !empty($_SESSION['pending_uid'])) {
            $_SESSION['last_activity'] = Clock::time();
        }
    }

    /** La sesion anterior caduco por inactividad (en esta peticion o en la que redirigio aqui). Consume el aviso. */
    public static function wasExpired(): bool
    {
        $was = self::$expired || !empty($_SESSION['expired_notice']);
        unset($_SESSION['expired_notice']);
        return $was;
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    /** Sesion autenticada: regenera el ID (anti fijacion de sesion). */
    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION = ['uid' => $userId, 'last_activity' => Clock::time(), 'login_at' => Clock::time(), 'csrf' => bin2hex(random_bytes(32))];
    }

    /** Contrasena correcta, falta el segundo factor (valido 5 min). */
    public static function setPending(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION = ['pending_uid' => $userId, 'pending_at' => Clock::time(), 'last_activity' => Clock::time(), 'csrf' => bin2hex(random_bytes(32))];
    }

    public static function pendingUserId(): ?int
    {
        if (empty($_SESSION['pending_uid']) || Clock::time() - (int) ($_SESSION['pending_at'] ?? 0) > 300) {
            return null;
        }
        return (int) $_SESSION['pending_uid'];
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(self::NAME, '', ['expires' => 1, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            session_destroy();
        }
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(mixed $token): bool
    {
        return is_string($token) && $token !== '' && !empty($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $token);
    }
}
