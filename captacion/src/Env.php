<?php
/** Variables de entorno del CRM: config/.env (o la ruta de CAPTACION_ENV_FILE), con respaldo a getenv(). Nunca imprime valores. */

declare(strict_types=1);

namespace Captacion;

final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function path(): string
    {
        $custom = getenv('CAPTACION_ENV_FILE');
        return is_string($custom) && $custom !== '' ? $custom : CAPTACION_ROOT . '/config/.env';
    }

    /** Lee el archivo .env (KEY=VALUE, # comentarios, comillas simples o dobles). Devuelve false si no existe. */
    public static function load(?string $file = null): bool
    {
        self::$loaded = true;
        $file ??= self::path();
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $k)) {
                continue;
            }
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            } else {
                $v = trim((string) preg_replace('/\s+#.*$/', '', $v));   // comentario al final de la linea
            }
            self::$vars[$k] = $v;
        }
        return true;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (isset(self::$vars[$key]) && self::$vars[$key] !== '') {
            return self::$vars[$key];
        }
        $env = getenv($key);
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return $default;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v !== null && preg_match('/^-?\d+$/', $v) === 1 ? (int) $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Solo para pruebas y scripts: fija o borra (null) una variable en memoria. */
    public static function set(string $key, ?string $value): void
    {
        if ($value === null) {
            unset(self::$vars[$key]);
            putenv($key);
        } else {
            self::$vars[$key] = $value;
        }
    }

    public static function reset(): void
    {
        self::$vars = [];
        self::$loaded = false;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }
}
