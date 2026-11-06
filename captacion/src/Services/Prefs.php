<?php
/**
 * Preferencias de interfaz por usuario (tabla user_prefs, lista blanca de claves y valores): tema (light|dark|auto), barra lateral (open|collapsed), densidad de la lista y del tablero
 * (comfortable|compact), columnas visibles de la bandeja y leads recientes de la paleta de comandos (solo ids). Nunca se guarda nada que no pase la validacion de esta clase.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class Prefs
{
    public const DEFAULTS = ['theme' => 'auto', 'side' => 'open', 'density' => 'comfortable', 'cols' => 'prof,st,eq,pts,temp,stage,src,when', 'lmode' => 'lista', 'tcols' => 'prof,st,eq,trucks,exp,pts,temp,stage,src,when,task', 'calm' => 'mes'];
    /** Modo de vista de los leads que se recuerda por usuario (Q5c): cada uno es una pantalla con los mismos filtros (clave => ruta). */
    public const MODES = ['lista' => '/leads', 'tablero' => '/pipeline', 'tabla' => '/tabla', 'calendario' => '/calendario', 'cronologia' => '/cronologia'];
    /** Columnas opcionales de la bandeja (clave => etiqueta). «Lead» y la casilla siempre se ven. */
    public const COLUMNS = ['prof' => 'Perfil', 'st' => 'Estado', 'eq' => 'Equipo', 'pts' => 'Puntos', 'temp' => 'Temperatura', 'stage' => 'Etapa', 'src' => 'Fuente', 'when' => 'Recibido', 'task' => 'Próxima tarea'];
    private const MAX_RECENT = 8;

    /** @var array<int,array<string,string>> */
    private static array $cache = [];

    /** @return array<string,string> con los valores por defecto aplicados */
    public static function all(int $userId): array
    {
        if (!isset(self::$cache[$userId])) {
            $rows = Db::all('SELECT pkey, pvalue FROM user_prefs WHERE user_id = ?', [$userId]);
            self::$cache[$userId] = array_column($rows, 'pvalue', 'pkey');
        }
        $out = self::DEFAULTS;
        foreach (self::$cache[$userId] as $k => $v) {
            if (isset(self::DEFAULTS[$k]) && self::valid($k, $v) !== null) {
                $out[$k] = self::valid($k, $v);
            }
        }
        return $out;
    }

    public static function get(int $userId, string $key): string
    {
        return self::all($userId)[$key] ?? '';
    }

    /** @return list<string> columnas visibles, en el orden canonico */
    public static function columns(int $userId): array
    {
        $on = explode(',', self::get($userId, 'cols'));
        return array_values(array_filter(array_keys(self::COLUMNS), static fn($c) => in_array($c, $on, true)));
    }

    /** Devuelve el valor normalizado o null si no es valido. */
    public static function valid(string $key, mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        return match ($key) {
            'theme' => in_array($v, ['light', 'dark', 'auto'], true) ? $v : null,
            'side' => in_array($v, ['open', 'collapsed'], true) ? $v : null,
            'density' => in_array($v, ['comfortable', 'compact'], true) ? $v : null,
            'cols' => self::validCols($v),
            'lmode' => isset(self::MODES[$v]) ? $v : null,
            'tcols' => self::validTableCols($v),
            'calm' => in_array($v, ['mes', 'semana', 'dia'], true) ? $v : null,
            default => null,
        };
    }

    private static function validCols(string $v): ?string
    {
        if ($v === '' || preg_match('/^[a-z,]{1,80}$/', $v) !== 1) {
            return null;
        }
        $keep = array_values(array_filter(array_keys(self::COLUMNS), static fn($c) => in_array($c, explode(',', $v), true)));
        return $keep ? implode(',', $keep) : null;
    }

    /** Columnas de la Tabla (Q5c): el ORDEN importa (es el que eligio la persona); solo claves conocidas, sin repetir y al menos una. */
    private static function validTableCols(string $v): ?string
    {
        if ($v === '' || preg_match('/^[a-z,]{1,120}$/', $v) !== 1) {
            return null;
        }
        $keep = array_values(array_unique(array_filter(explode(',', $v), static fn($c) => isset(LeadTable::COLUMNS[$c]))));
        return $keep ? implode(',', $keep) : null;
    }

    /** @return list<string> columnas de la Tabla en el orden elegido */
    public static function tableColumns(int $userId): array
    {
        return explode(',', self::get($userId, 'tcols'));
    }

    /** Recuerda el ultimo modo de vista usado (solo escribe si cambia). La barra lateral lleva a el. */
    public static function remember(int $userId, string $mode): void
    {
        if (isset(self::MODES[$mode]) && self::get($userId, 'lmode') !== $mode) {
            self::set($userId, 'lmode', $mode);
        }
    }

    /** Ruta de «Leads» en la barra lateral: el modo que la persona uso por ultima vez. */
    public static function home(string $mode): string
    {
        return self::MODES[$mode] ?? '/leads';
    }

    /** @return bool true si se guardo (clave y valor validos) */
    public static function set(int $userId, string $key, mixed $value): bool
    {
        $v = self::valid($key, $value);
        if (!isset(self::DEFAULTS[$key]) || $v === null) {
            return false;
        }
        Db::run('INSERT INTO user_prefs (user_id, pkey, pvalue) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pvalue = VALUES(pvalue)', [$userId, $key, $v]);
        unset(self::$cache[$userId]);
        return true;
    }

    /** @return list<int> ids de los ultimos leads abiertos (mas reciente primero) */
    public static function recent(int $userId): array
    {
        $raw = (string) Db::value("SELECT pvalue FROM user_prefs WHERE user_id = ? AND pkey = 'recent'", [$userId]);
        return array_values(array_filter(array_map('intval', explode(',', $raw)), static fn($i) => $i > 0));
    }

    public static function touchRecent(int $userId, int $leadId): void
    {
        $ids = array_slice(array_values(array_unique([$leadId, ...self::recent($userId)])), 0, self::MAX_RECENT);
        Db::run("INSERT INTO user_prefs (user_id, pkey, pvalue) VALUES (?, 'recent', ?) ON DUPLICATE KEY UPDATE pvalue = VALUES(pvalue)", [$userId, implode(',', $ids)]);
    }
}
