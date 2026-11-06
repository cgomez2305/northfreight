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
                'agent'       => self::agent(),
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

    /**
     * Agente resumido de la peticion actual ("Chrome 130 · Windows"). Nunca se guarda el user agent completo. null en CLI o si no se reconoce.
     */
    public static function agent(?string $ua = null): ?string
    {
        $ua ??= (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            return null;
        }
        $browser = null;
        foreach ([['Edg', 'Edge'], ['OPR', 'Opera'], ['Firefox', 'Firefox'], ['Chrome', 'Chrome'], ['CriOS', 'Chrome'], ['Version', 'Safari']] as [$token, $name]) {
            if (preg_match('#' . $token . '/(\d+)#', $ua, $m) === 1 && ($name !== 'Safari' || str_contains($ua, 'Safari'))) {
                $browser = $name . ' ' . $m[1];
                break;
            }
        }
        if ($browser === null) {
            if (stripos($ua, 'curl/') !== false) {
                $browser = 'curl';
            } elseif (stripos($ua, 'Safari') !== false) {
                $browser = 'Safari';
            } else {
                return null;
            }
        }
        $os = match (true) {
            str_contains($ua, 'Windows')                                        => 'Windows',
            str_contains($ua, 'Android')                                        => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')           => 'iOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh')    => 'macOS',
            str_contains($ua, 'CrOS')                                           => 'ChromeOS',
            str_contains($ua, 'Linux')                                          => 'Linux',
            default                                                             => null,
        };
        return mb_substr($browser . ($os !== null ? ' · ' . $os : ''), 0, 80);
    }

    /** IP para mostrar: IPv4 tal cual; IPv6 solo los primeros 4 grupos ("2001:db8:1:2::"). */
    public static function shortIp(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return '—';
        }
        if (!str_contains($ip, ':')) {
            return $ip;
        }
        $full = @inet_pton($ip);
        if ($full === false) {
            return mb_substr($ip, 0, 20);
        }
        $g = array_slice(str_split(bin2hex($full), 4), 0, 4);
        return implode(':', array_map(static fn($x) => ltrim($x, '0') ?: '0', $g)) . '::';
    }

    public static function ip(): ?string
    {
        $ip = ClientIp::get() ?: null;
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }
}
