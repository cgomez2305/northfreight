<?php
/**
 * Autenticacion del panel: contrasena (Argon2id; bcrypt coste 12 si el PHP no trae Argon2id), 2FA TOTP obligatorio, codigos de recuperacion de un solo uso
 * y limite de 5 intentos fallidos cada 15 minutos por IP y por correo (tabla login_attempts). Todo se registra en activity_log. Sin cuentas por defecto.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\User;

final class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW = 900;   // 15 min
    public const MIN_PASSWORD = 12;
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt';
    }

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);   // sin Argon2id en este PHP: bcrypt coste 12 (anotado en README y en activity_log al crear el admin)
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= self::hashPassword(bin2hex(random_bytes(8)));
    }

    // --- Limite de intentos -----------------------------------------------------------------------------------

    public static function isBlocked(string $email, string $ip): bool
    {
        $since = Clock::shift(-self::WINDOW);
        $byIp = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [$ip, $since]);
        $byEmail = $email === '' ? 0 : (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?', [$email, $since]);
        return $byIp >= self::MAX_ATTEMPTS || $byEmail >= self::MAX_ATTEMPTS;
    }

    public static function recordAttempt(string $email, string $ip, string $kind, bool $success): void
    {
        Db::insert('login_attempts', ['email' => substr($email, 0, 190), 'ip' => substr($ip, 0, 45), 'kind' => $kind, 'success' => $success ? 1 : 0, 'created_at' => Clock::now()]);
        if (random_int(1, 50) === 1) {
            Db::run('DELETE FROM login_attempts WHERE created_at < ?', [Clock::shift(-30 * 86400)]);
        }
    }

    // --- Paso 1: contrasena -------------------------------------------------------------------------------------

    /** @return array{status:'ok'|'invalid'|'blocked',user:?array} */
    public static function attemptPassword(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        if (self::isBlocked($email, $ip)) {
            ActivityLog::record('login_blocked', null, 'user', null, ['email' => $email], $ip);
            return ['status' => 'blocked', 'user' => null];
        }
        $user = $email !== '' ? User::findByEmail($email) : null;
        $ok = false;
        if ($user !== null && (int) $user['is_active'] === 1 && $user['totp_confirmed_at'] !== null) {
            $ok = password_verify($password, (string) $user['password_hash']);
        } else {
            password_verify($password, self::dummyHash());   // mismo coste: no revela si el correo existe
        }
        if (!$ok) {
            self::recordAttempt($email, $ip, 'password', false);
            ActivityLog::record('login_failed', $user !== null ? (int) $user['id'] : null, 'user', null, ['email' => $email], $ip);
            return ['status' => 'invalid', 'user' => null];
        }
        if (password_needs_rehash((string) $user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT, defined('PASSWORD_ARGON2ID') ? [] : ['cost' => 12])) {
            Db::update('users', (int) $user['id'], ['password_hash' => self::hashPassword($password), 'password_algo' => self::algo()]);
        }
        return ['status' => 'ok', 'user' => $user];
    }

    // --- Paso 2: TOTP o codigo de recuperacion ---------------------------------------------------------------

    /** @return array{status:'ok'|'invalid'|'blocked',via:?string,recovery_left:?int} */
    public static function verifySecondFactor(array $user, string $code, string $ip): array
    {
        $email = (string) $user['email'];
        $uid = (int) $user['id'];
        if (self::isBlocked($email, $ip)) {
            ActivityLog::record('login_blocked', $uid, 'user', $uid, ['step' => '2fa'], $ip);
            return ['status' => 'blocked', 'via' => null, 'recovery_left' => null];
        }
        $code = trim($code);
        $via = null;
        $secret = Crypto::decrypt((string) $user['totp_secret']);
        if ($secret !== null && preg_match('/^\d{3}\s?\d{3}$/', $code) === 1) {
            $step = Totp::verify($secret, $code, Clock::time(), 1, $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null);
            if ($step !== null) {
                // Atomico: solo gana si nadie uso ya este paso (o uno posterior)
                $n = Db::run('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $uid, $step])->rowCount();
                $via = $n === 1 ? 'totp' : null;
            }
        } elseif ($code !== '' && self::useRecoveryCode($uid, $code)) {
            $via = 'recovery';
        }
        if ($via === null) {
            self::recordAttempt($email, $ip, 'totp', false);
            ActivityLog::record('login_2fa_failed', $uid, 'user', $uid, [], $ip);
            return ['status' => 'invalid', 'via' => null, 'recovery_left' => null];
        }
        self::recordAttempt($email, $ip, 'totp', true);
        Db::update('users', $uid, ['last_login_at' => Clock::now()]);
        $left = (int) Db::value('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$uid]);
        ActivityLog::record('login_ok', $uid, 'user', $uid, ['via' => $via], $ip);
        if ($via === 'recovery') {
            ActivityLog::record('recovery_code_used', $uid, 'user', $uid, ['left' => $left], $ip);
        }
        return ['status' => 'ok', 'via' => $via, 'recovery_left' => $left];
    }

    // --- Codigos de recuperacion ---------------------------------------------------------------------------------

    public static function normalizeRecovery(string $code): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($code));
    }

    /** 10 codigos "xxxxx-xxxxx" (50 bits cada uno). Devuelve los codigos en claro: mostrarlos UNA vez. */
    public static function generateRecoveryCodes(int $n = 10): array
    {
        $out = [];
        $max = strlen(self::RECOVERY_ALPHABET) - 1;
        while (count($out) < $n) {
            $c = '';
            for ($i = 0; $i < 10; $i++) {
                $c .= self::RECOVERY_ALPHABET[random_int(0, $max)];
            }
            $out[$c] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return array_values($out);
    }

    public static function storeRecoveryCodes(int $userId, array $codes): void
    {
        foreach ($codes as $c) {
            Db::insert('recovery_codes', ['user_id' => $userId, 'code_hash' => Crypto::hmac('recovery|' . self::normalizeRecovery($c))]);
        }
    }

    private static function useRecoveryCode(int $userId, string $code): bool
    {
        $norm = self::normalizeRecovery($code);
        if (strlen($norm) !== 10) {
            return false;
        }
        return Db::run('UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [Clock::now(), $userId, Crypto::hmac('recovery|' . $norm)])->rowCount() === 1;
    }

    public static function appKeyReady(): bool
    {
        try {
            Crypto::key();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function issuer(): string
    {
        return (string) Env::get('TOTP_ISSUER', 'NorthStar Captacion');
    }
}
