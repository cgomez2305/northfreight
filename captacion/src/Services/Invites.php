<?php
/**
 * Usuarios lectores de NorthStar (rol `viewer`, doc §10): alta por invitacion, desactivar/activar y reinicio del 2FA. Solo el administrador (con confirmacion reciente) las usa.
 *
 *  - invite(): crea el usuario SIN contrasena utilizable ni 2FA (no puede entrar) y un enlace de un solo uso (/invite/{token}, 48 h). El token se muestra UNA vez; en la base solo queda su SHA-256.
 *  - El invitado fija su contraseña (>= 12), añade el secreto TOTP y confirma un codigo: mismas reglas que el administrador (Auth, Totp, codigos de recuperacion).
 *  - reset2fa(): borra secreto TOTP, codigos de recuperacion y contraseña y emite un enlace nuevo: la persona repite el alta. Sus sesiones abiertas dejan de valer (Responder::user exige 2FA activo).
 *  - Las acciones solo aplican a cuentas viewer: un administrador no se desactiva ni se reinicia desde aqui.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\User;

final class Invites
{
    public const TTL = 172800;   // 48 h

    /** @return list<array> los lectores con su estado */
    public static function viewers(): array
    {
        return Db::all(
            "SELECT u.id, u.name, u.email, u.is_active, u.last_login_at, u.totp_confirmed_at, u.created_at, u.deactivated_at,
                    (SELECT MAX(i.expires_at) FROM user_invites i WHERE i.user_id = u.id AND i.used_at IS NULL) AS invite_until
               FROM users u WHERE u.role = 'viewer' AND u.deleted_at IS NULL ORDER BY u.id DESC"
        );
    }

    /** @return array{ok:bool,errors:array<string,string>,link:?string,user_id:?int} */
    public static function invite(string $name, string $email, int $adminId): array
    {
        $name = trim((string) preg_replace('/[^\P{C}]/u', '', mb_substr($name, 0, 120)));
        $email = strtolower(trim($email));
        $err = [];
        if (mb_strlen($name) < 2) {
            $err['name'] = 'Escribe el nombre de la persona.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $err['email'] = 'El correo no es válido.';
        } elseif (Db::value('SELECT COUNT(*) FROM users WHERE email = ?', [$email]) > 0) {
            $err['email'] = 'Ya existe una cuenta con ese correo.';
        }
        if ($err) {
            return ['ok' => false, 'errors' => $err, 'link' => null, 'user_id' => null];
        }
        return Db::transaction(static function () use ($name, $email, $adminId): array {
            $uid = Db::insert('users', [
                'name' => $name, 'email' => $email, 'password_hash' => Auth::hashPassword(bin2hex(random_bytes(24))), 'password_algo' => Auth::algo(),
                'totp_secret' => null, 'totp_confirmed_at' => null, 'role' => 'viewer', 'is_active' => 1, 'invited_by' => $adminId,
            ]);
            $link = self::issue($uid, $adminId);
            ActivityLog::record('user_invite', $adminId, 'user', $uid, ['role' => 'viewer', 'email' => $email]);
            return ['ok' => true, 'errors' => [], 'link' => $link, 'user_id' => $uid];
        });
    }

    /** Invalida los enlaces pendientes del usuario y emite uno nuevo. Devuelve el enlace en claro (no se vuelve a poder ver). */
    private static function issue(int $userId, int $adminId): string
    {
        Db::run('UPDATE user_invites SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [Clock::now(), $userId]);
        $token = bin2hex(random_bytes(32));
        Db::insert('user_invites', ['user_id' => $userId, 'token_hash' => hash('sha256', $token), 'expires_at' => Clock::shift(self::TTL), 'created_by' => $adminId]);
        return Url::base() . '/invite/' . $token;
    }

    private static function viewer(int $id): ?array
    {
        $u = User::find($id);
        return $u !== null && $u['role'] === 'viewer' ? $u : null;
    }

    /** Enlace nuevo para quien aun no activo su cuenta (o la perdio). @return array{ok:bool,msg:string,link:?string} */
    public static function relink(int $userId, int $adminId): array
    {
        $u = self::viewer($userId);
        if ($u === null) {
            return ['ok' => false, 'msg' => 'Esa cuenta no existe o no es de un lector.', 'link' => null];
        }
        if ($u['totp_confirmed_at'] !== null) {
            return ['ok' => false, 'msg' => 'Esa cuenta ya está activada. Si perdió el acceso, usa «Reiniciar 2FA».', 'link' => null];
        }
        $link = self::issue($userId, $adminId);
        ActivityLog::record('user_relink', $adminId, 'user', $userId);
        return ['ok' => true, 'msg' => 'Enlace nuevo creado. Cópialo ahora: no se vuelve a mostrar.', 'link' => $link];
    }

    /** @return array{ok:bool,msg:string,link:?string} */
    public static function reset2fa(int $userId, int $adminId): array
    {
        $u = self::viewer($userId);
        if ($u === null) {
            return ['ok' => false, 'msg' => 'Esa cuenta no existe o no es de un lector.', 'link' => null];
        }
        $link = Db::transaction(static function () use ($userId, $adminId): string {
            Db::run('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
            Db::update('users', $userId, ['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null, 'password_hash' => Auth::hashPassword(bin2hex(random_bytes(24)))]);
            return self::issue($userId, $adminId);
        });
        ActivityLog::record('user_reset_2fa', $adminId, 'user', $userId);
        return ['ok' => true, 'msg' => 'Contraseña y 2FA reiniciados. La persona debe repetir el alta con este enlace: cópialo ahora.', 'link' => $link];
    }

    public static function setActive(int $userId, bool $active, int $adminId): array
    {
        $u = self::viewer($userId);
        if ($u === null) {
            return ['ok' => false, 'msg' => 'Esa cuenta no existe o no es de un lector.'];
        }
        Db::update('users', $userId, ['is_active' => $active ? 1 : 0, 'deactivated_at' => $active ? null : Clock::now()]);
        if (!$active) {
            Db::run('UPDATE user_invites SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [Clock::now(), $userId]);
        }
        ActivityLog::record($active ? 'user_activate' : 'user_deactivate', $adminId, 'user', $userId);
        return ['ok' => true, 'msg' => $active ? 'Cuenta activada.' : 'Cuenta desactivada: ya no puede entrar y sus sesiones dejan de valer.'];
    }

    /** Invitacion valida (sin usar, vigente, cuenta activa y sin 2FA todavia) a partir del token del enlace. @return ?array{invite:array,user:array} */
    public static function find(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $i = Db::one('SELECT * FROM user_invites WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), Clock::now()]);
        $u = $i !== null ? User::find((int) $i['user_id']) : null;
        return $u !== null && $u['role'] === 'viewer' && (int) $u['is_active'] === 1 && $u['totp_confirmed_at'] === null ? ['invite' => $i, 'user' => $u] : null;
    }
}
