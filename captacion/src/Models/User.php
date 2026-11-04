<?php
/** Usuarios del panel. Hoy un solo administrador (Carlos); la tabla admite mas perfiles (role admin|viewer) sin matriz de permisos. */

declare(strict_types=1);

namespace Captacion\Models;

use Captacion\Db;

final class User
{
    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM users');
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Db::one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [strtolower(trim($email))]);
    }
}
