<?php
/**
 * public/setup.php: alta del administrador (primer uso). Solo funciona si `users` esta vacia Y SETUP_TOKEN esta en el .env; despues responde 404 para siempre.
 * El token viaja en un campo de formulario (nunca en la URL). La cuenta NO se crea hasta confirmar un codigo TOTP valido: mientras tanto el secreto y los codigos de
 * recuperacion viven cifrados en la sesion. Se muestran en pantalla hasta confirmar y nunca mas.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\User;
use Captacion\Services\ActivityLog;
use Captacion\Services\Auth;
use Captacion\Services\Crypto;
use Captacion\Services\Session;
use Captacion\Services\Totp;

final class SetupController extends Responder
{
    public static function handle(): never
    {
        $token = Env::get('SETUP_TOKEN');
        if ($token === null || strlen($token) < 32 || User::count() > 0) {
            self::notFound();
        }
        Session::start();
        $vars = ['title' => 'Instalación', 'csrf' => Session::csrf(), 'step' => 'form'];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::html('setup', $vars);
        }
        if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
            self::html('setup', ['error' => 'El formulario caducó. Inténtalo de nuevo.'] + $vars, 403);
        }
        $ip = self::ip();
        if (Auth::isBlocked('setup', $ip)) {
            self::html('setup', ['error' => 'Demasiados intentos. Espera 15 minutos.'] + $vars, 429);
        }

        if (($_POST['action'] ?? '') === 'confirm') {
            self::confirm($vars, $ip);
        }

        // Paso 1: token + datos
        $given = is_string($_POST['setup_token'] ?? null) ? $_POST['setup_token'] : '';
        $name = is_string($_POST['name'] ?? null) ? mb_substr(trim($_POST['name']), 0, 120) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $pw = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $pw2 = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
        $old = ['name' => $name, 'email' => $email];
        if (!hash_equals($token, $given)) {
            Auth::recordAttempt('setup', $ip, 'setup', false);
            ActivityLog::record('setup_failed', null, 'user', null, ['reason' => 'token'], $ip);
            self::html('setup', ['error' => 'Token de instalación incorrecto.', 'old' => $old] + $vars, 403);
        }
        $err = match (true) {
            $name === ''                                             => 'Escribe tu nombre.',
            !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 => 'El correo no es válido.',
            mb_strlen($pw) < Auth::MIN_PASSWORD                      => 'La contraseña debe tener al menos ' . Auth::MIN_PASSWORD . ' caracteres.',
            $pw !== $pw2                                             => 'Las contraseñas no coinciden.',
            !Auth::appKeyReady()                                     => 'APP_KEY no está bien configurada en config/.env (64 caracteres hexadecimales).',
            default                                                  => '',
        };
        if ($err !== '') {
            self::html('setup', ['error' => $err, 'old' => $old] + $vars, 422);
        }
        $secret = Totp::generateSecret();
        $codes = Auth::generateRecoveryCodes(10);
        $_SESSION['setup_pending'] = Crypto::encrypt((string) json_encode([
            'name' => $name, 'email' => $email, 'hash' => Auth::hashPassword($pw), 'algo' => Auth::algo(), 'secret' => $secret, 'codes' => $codes, 'at' => Clock::time(),
        ]));
        self::confirmPage($vars, $secret, $codes, $email, null);
    }

    private static function confirm(array $vars, string $ip): never   // totp_last_step queda NULL a proposito: el codigo de confirmacion puede servir para el primer login (el setup ya no se puede repetir)
    {
        $blob = is_string($_SESSION['setup_pending'] ?? null) ? Crypto::decrypt($_SESSION['setup_pending']) : null;
        $p = $blob !== null ? json_decode($blob, true) : null;
        if (!is_array($p) || Clock::time() - (int) ($p['at'] ?? 0) > 1800) {
            unset($_SESSION['setup_pending']);
            self::html('setup', ['error' => 'La instalación caducó. Empieza de nuevo.'] + $vars, 422);
        }
        $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
        $step = Totp::verify((string) $p['secret'], $code, Clock::time(), 1);
        if ($step === null) {
            Auth::recordAttempt('setup', $ip, 'setup', false);
            self::confirmPage($vars, (string) $p['secret'], (array) $p['codes'], (string) $p['email'], 'Ese código no es correcto. Revisa la hora del móvil e inténtalo de nuevo.', 422);
        }
        // Atomico: solo crea el usuario si la tabla sigue vacia (dos altas simultaneas no pueden ganar las dos)
        $created = Db::transaction(static function () use ($p): bool {
            $n = Db::run(
                "INSERT INTO users (name, email, password_hash, password_algo, totp_secret, totp_confirmed_at, role, is_active, created_at)
                 SELECT ?, ?, ?, ?, ?, ?, 'admin', 1, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users)",
                [$p['name'], $p['email'], $p['hash'], $p['algo'], Crypto::encrypt((string) $p['secret']), Clock::now(), Clock::now()]
            )->rowCount();
            if ($n !== 1) {
                return false;
            }
            $uid = (int) Db::pdo()->lastInsertId();
            Auth::storeRecoveryCodes($uid, (array) $p['codes']);
            ActivityLog::record('setup_admin', $uid, 'user', $uid, ['password_algo' => $p['algo']]);
            return true;
        });
        unset($_SESSION['setup_pending']);
        if (!$created) {
            self::notFound();
        }
        $note = $p['algo'] === 'bcrypt' ? 'Este PHP no incluye Argon2id: la contraseña se guardó con bcrypt (coste 12). Quedó anotado en el registro de actividad.' : null;
        self::html('setup', ['step' => 'done', 'csrf' => Session::csrf(), 'algoNote' => $note, 'title' => 'Instalación']);
    }

    private static function confirmPage(array $vars, string $secret, array $codes, string $email, ?string $error, int $status = 200): never
    {
        self::html('setup', [
            'step' => 'confirm', 'secretGrouped' => Totp::grouped($secret), 'codes' => $codes, 'error' => $error,
            'otpauth' => Totp::uri(Auth::issuer(), $email, $secret),
        ] + $vars, $status);
    }
}
