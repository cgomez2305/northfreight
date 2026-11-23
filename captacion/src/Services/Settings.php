<?php
/** Ajustes editables (tabla settings, valor JSON). Cache por peticion. */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class Settings
{
    /** @var array<string,mixed> */
    private static array $cache = [];
    /** @var array<string,true> claves que NO existen en la tabla (se recuerdan para no repetir la consulta en cada llamada) */
    private static array $missing = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        if (isset(self::$missing[$key])) {
            return $default;
        }
        $raw = Db::value('SELECT value FROM settings WHERE `key` = ?', [$key]);
        if ($raw === null) {
            self::$missing[$key] = true;   // antes cada lectura de un ajuste sin guardar iba a la base: en un informe de 30.000 leads eran 30.000 consultas
            return $default;
        }
        $v = json_decode((string) $raw, true);
        return self::$cache[$key] = ($v === null && $raw !== 'null') ? $default : $v;
    }

    public static function set(string $key, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Db::run('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $json]);
        self::$cache[$key] = $value;
        unset(self::$missing[$key]);
    }

    public static function clear(): void
    {
        self::$cache = [];
        self::$missing = [];
    }
}
