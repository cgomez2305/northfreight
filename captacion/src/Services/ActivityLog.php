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

    /** Aplana un arreglo a ruta.con.puntos => valor (para comparar ajustes anidados). */
    private static function flat(mixed $v, string $prefix = ''): array
    {
        if (!is_array($v)) {
            return [$prefix => $v];
        }
        if (!$v) {
            return [$prefix => []];
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out += self::flat($x, $prefix === '' ? (string) $k : $prefix . '.' . $k);
        }
        return $out;
    }

    /**
     * Registra un cambio de configuracion con los valores anterior y nuevo SOLO de lo que cambio (rutas con puntos). Nunca recibe secretos: quien llama pasa
     * ajustes del panel, no variables del .env. Devuelve true si habia algo que registrar.
     */
    public static function settingsChange(int $userId, string $group, array $before, array $after): bool
    {
        $a = self::flat($before);
        $b = self::flat($after);
        $diff = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $path) {
            if (($a[$path] ?? null) !== ($b[$path] ?? null)) {
                $diff[$path] = [$a[$path] ?? null, $b[$path] ?? null];
            }
        }
        if (!$diff) {
            return false;
        }
        $extra = count($diff) > 40 ? ['truncated' => count($diff)] : [];
        self::record('settings_change', $userId, 'settings', $group, ['diff' => array_slice($diff, 0, 40, true)] + $extra);
        return true;
    }

    public static function ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }
}
