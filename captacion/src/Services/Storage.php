<?php
/** Rutas y registro bajo captacion/storage (denegado por web). Los logs nunca llevan secretos: Storage::redact() los quita. */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Env;

final class Storage
{
    public static function dir(string $sub = ''): string
    {
        $base = Env::get('STORAGE_DIR') ?? CAPTACION_ROOT . '/storage';
        return $sub === '' ? $base : rtrim($base, '/') . '/' . ltrim($sub, '/');
    }

    public static function ensure(string $dir, int $mode = 0750): bool
    {
        return is_dir($dir) || @mkdir($dir, $mode, true) || is_dir($dir);
    }

    /** Quita de un texto los secretos conocidos (valores del .env) y patrones de token de Telegram o webKey de FMCSA. */
    public static function redact(string $s): string
    {
        foreach (['TELEGRAM_BOT_TOKEN', 'FMCSA_WEBKEY', 'DB_PASS', 'APP_KEY', 'SMTP_PASS', 'INTERNAL_API_TOKEN', 'SETUP_TOKEN'] as $k) {
            $v = Env::get($k);
            if ($v !== null && strlen($v) >= 6) {
                $s = str_replace($v, '[redacted]', $s);
            }
        }
        $s = (string) preg_replace('/bot\d{5,}:[A-Za-z0-9_-]{20,}/', 'bot[redacted]', $s);
        $s = (string) preg_replace('/(webKey=)[^&\s"\']+/i', '$1[redacted]', $s);
        $s = str_replace(CAPTACION_ROOT, '[captacion]', $s);
        return (string) preg_replace('/[\r\n]+/', ' ', $s);
    }

    /** Linea en storage/<name>.log con rotacion simple a 1 MB. */
    public static function log(string $name, string $text): void
    {
        if (!preg_match('/^[a-z_]{3,20}$/', $name) || !self::ensure(self::dir())) {
            return;
        }
        $file = self::dir($name . '.log');
        clearstatcache(true, $file);
        if (is_file($file) && (int) @filesize($file) > 1048576) {
            @rename($file, $file . '.1');
        }
        @file_put_contents($file, gmdate('c') . ' ' . self::redact($text) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $context, string $detail = ''): void
    {
        self::log('error', $context . ($detail !== '' ? ' :: ' . $detail : ''));
    }
}
