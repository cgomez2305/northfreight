<?php
/** Registro de actividad (§9.11): logins, fallos, exportaciones y cambios. Nunca guarda contrasenas, codigos ni secretos. */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class ActivityLog
{
    public static function record(string $action, ?int $userId = null, ?string $entityType = null, string|int|null $entityId = null, array $changes = [], ?string $ip = null): void
    {
        try {
            Db::insert('activity_log', [
                'user_id'     => $userId,
                'action'      => substr($action, 0, 60),
                'entity_type' => $entityType,
                'entity_id'   => $entityId === null ? null : substr((string) $entityId, 0, 40),
                'changes'     => $changes ?: null,
                'ip'          => $ip ?? self::ip(),
                'created_at'  => Clock::now(),
            ]);
        } catch (\Throwable $t) {
            Storage::error('activity_log failed', get_class($t) . ': ' . $t->getMessage());
        }
    }

    public static function ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }
}
