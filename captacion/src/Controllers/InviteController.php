<?php
/**
 * /invite/{token}: alta del lector de NorthStar. Pagina publica con token de un solo uso (48 h, 256 bits): contraseña (>= 12) y 2FA TOTP con codigos de recuperacion,
 * con las mismas reglas que el alta del administrador (setup.php): la cuenta no queda activa hasta confirmar un codigo TOTP valido. El secreto vive cifrado en la sesion entre pasos.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\ActivityLog;
use Captacion\Services\Auth;
use Captacion\Services\Crypto;
use Captacion\Services\Invites;
use Captacion\Services\RateLimiter;
use Captacion\Services\Session;
use Captacion\Services\Totp;

final class InviteController extends Responder
{
    public static function handle(string $token): never
    {
        if (RateLimiter::tooMany('pub_invite', self::ip(), 40, 3600)) {
            self::html('message', ['title' => 'Demasiadas visitas', 'heading' => 'Demasiadas visitas', 'text' => 'Espera un rato e inténtalo de nuevo.', 'linkHref' => '/login', 'linkText' => 'Ir a entrar'], 429);
        }
        RateLimiter::hit('pub_invite', self::ip(), 3600);
        $found = Invites::find($token);
        if ($found === null) {
            self::html('message', ['title' => 'Invitación', 'heading' => 'Invitación no válida', 'text' => 'Este enlace no existe, ya se usó o caducó. Pide uno nuevo al administrador.', 'linkHref' => '/login', 'linkText' => 'Ir a entrar'], 404);
        }
        $user = $found['user'];
        Session::start();
        $vars = ['title' => 'Activar cuenta', 'csrf' => Session::csrf(), 'step' => 'form', 'invName' => (string) $user['name'], 'invEmail' => (string) $user['email'], 'token' => $token];
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::html('invite', $vars);
        }
        if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
            self::html('invite', ['error' => 'El formulario caducó. Inténtalo de nuevo.'] + $vars, 403);
        }
        if (Auth::isBlocked((string) $user['email'], self::ip())) {
            self::html('invite', ['error' => 'Demasiados intentos. Espera 15 minutos.'] + $vars, 429);
        }
        if (($_POST['action'] ?? '') === 'confirm') {
            self::confirm($found, $vars);
        }
        $pw = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $pw2 = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
        $err = match (true) {
            mb_strlen($pw) < Auth::MIN_PASSWORD => 'La contraseña debe tener al menos ' . Auth::MIN_PASSWORD . ' caracteres.',
            $pw !== $pw2                        => 'Las contraseñas no coinciden.',
            !Auth::appKeyReady()                => 'El servidor no está listo para crear cuentas (APP_KEY). Avisa al administrador.',
            default                             => '',
        };
        if ($err !== '') {
            self::html('invite', ['error' => $err] + $vars, 422);
        }
        $secret = Totp::generateSecret();
        $codes = Auth::generateRecoveryCodes(10);
        $_SESSION['invite_pending'] = Crypto::encrypt((string) json_encode(['uid' => (int) $user['id'], 'hash' => Auth::hashPassword($pw), 'algo' => Auth::algo(), 'secret' => $secret, 'codes' => $codes, 'at' => Clock::time(), 'token' => hash('sha256', $token)]));
        self::confirmPage($vars, $secret, $codes, (string) $user['email'], null);
    }

    private static function confirm(array $found, array $vars): never
    {
        $user = $found['user'];
        $blob = is_string($_SESSION['invite_pending'] ?? null) ? Crypto::decrypt($_SESSION['invite_pending']) : null;
        $p = $blob !== null ? json_decode($blob, true) : null;
        if (!is_array($p) || Clock::time() - (int) ($p['at'] ?? 0) > 1800 || (int) $p['uid'] !== (int) $user['id'] || !hash_equals((string) $p['token'], hash('sha256', (string) $vars['token']))) {
            unset($_SESSION['invite_pending']);
            self::html('invite', ['error' => 'La activación caducó. Empieza de nuevo.'] + $vars, 422);
        }
        $step = Totp::verify((string) $p['secret'], is_string($_POST['code'] ?? null) ? $_POST['code'] : '', Clock::time(), 1);
        if ($step === null) {
            Auth::recordAttempt((string) $user['email'], self::ip(), 'totp', false);
            self::confirmPage($vars, (string) $p['secret'], (array) $p['codes'], (string) $user['email'], 'Ese código no es correcto. Revisa la hora del móvil e inténtalo de nuevo.', 422);
        }
        $ok = Db::transaction(static function () use ($p, $found): bool {
            // un solo uso: gana quien marque la invitacion; y la cuenta solo se activa si seguia sin 2FA
            if (Db::run('UPDATE user_invites SET used_at = ? WHERE id = ? AND used_at IS NULL', [Clock::now(), (int) $found['invite']['id']])->rowCount() !== 1) {
                return false;
            }
            $n = Db::run(
                "UPDATE users SET password_hash = ?, password_algo = ?, totp_secret = ?, totp_confirmed_at = ?, totp_last_step = NULL WHERE id = ? AND role = 'viewer' AND is_active = 1 AND totp_confirmed_at IS NULL",
                [$p['hash'], $p['algo'], Crypto::encrypt((string) $p['secret']), Clock::now(), (int) $p['uid']]
            )->rowCount();
            if ($n !== 1) {
                throw new \RuntimeException('cuenta no activable');
            }
            Db::run('DELETE FROM recovery_codes WHERE user_id = ?', [(int) $p['uid']]);
            Auth::storeRecoveryCodes((int) $p['uid'], (array) $p['codes']);
            ActivityLog::record('invite_accepted', (int) $p['uid'], 'user', (int) $p['uid'], ['password_algo' => $p['algo']]);
            return true;
        });
        unset($_SESSION['invite_pending']);
        if (!$ok) {
            self::html('message', ['title' => 'Invitación', 'heading' => 'Invitación no válida', 'text' => 'Este enlace ya se usó. Pide uno nuevo al administrador.', 'linkHref' => '/login', 'linkText' => 'Ir a entrar'], 410);
        }
        self::html('invite', ['step' => 'done'] + $vars);
    }

    private static function confirmPage(array $vars, string $secret, array $codes, string $email, ?string $error, int $status = 200): never
    {
        self::html('invite', ['step' => 'confirm', 'secretGrouped' => Totp::grouped($secret), 'codes' => $codes, 'error' => $error, 'otpauth' => Totp::uri(Auth::issuer(), $email, $secret)] + $vars, $status);
    }
}
