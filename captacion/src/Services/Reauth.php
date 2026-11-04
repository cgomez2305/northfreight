<?php
/**
 * Reautenticacion para acciones sensibles de Configuracion y Copias: contrasena + codigo TOTP (no sirven los codigos de recuperacion), valida REAUTH_SECONDS
 * (10 min por defecto) en la sesion. Mientras no esta vigente, los formularios de Configuracion se muestran bloqueados. Comparte con el login el limite de
 * 5 fallos cada 15 min por IP y por correo (los fallos de aqui tambien bloquean el acceso: una sesion robada no puede adivinar la contrasena).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Env;

final class Reauth
{
    public static function ttl(): int
    {
        return max(1, Env::int('REAUTH_SECONDS', 600));
    }

    /** Segundos que le quedan a la confirmacion (0 = no vigente). */
    public static function remaining(): int
    {
        $at = (int) ($_SESSION['reauth_at'] ?? 0);
        return $at > 0 ? max(0, $at + self::ttl() - Clock::time()) : 0;
    }

    public static function fresh(): bool
    {
        return self::remaining() > 0;
    }

    public static function forget(): void
    {
        unset($_SESSION['reauth_at']);
    }

    /** @return array{status:'ok'|'invalid'|'blocked'} */
    public static function attempt(array $user, string $password, string $code, string $ip): array
    {
        $email = (string) $user['email'];
        $uid = (int) $user['id'];
        if (Auth::isBlocked($email, $ip)) {
            ActivityLog::record('reauth_blocked', $uid, 'user', $uid, [], $ip);
            return ['status' => 'blocked'];
        }
        $ok = password_verify($password, (string) $user['password_hash']) && Auth::consumeTotp($user, $code);
        if (!$ok) {
            Auth::recordAttempt($email, $ip, 'reauth', false);
            ActivityLog::record('reauth_failed', $uid, 'user', $uid, [], $ip);
            return ['status' => 'invalid'];
        }
        $_SESSION['reauth_at'] = Clock::time();
        ActivityLog::record('reauth_ok', $uid, 'user', $uid, [], $ip);
        return ['status' => 'ok'];
    }
}
