<?php
/** Limite por ventana deslizante en archivos (storage/ratelimit/<hash>.json, flock). Sin base de datos: sirve aunque la base este caida. */

declare(strict_types=1);

namespace Captacion\Services;

final class RateLimiter
{
    private static function file(string $bucket, string $key): string
    {
        return Storage::dir('ratelimit') . '/' . hash('sha256', $bucket . '|' . $key) . '.json';
    }

    /** Marcas vigentes (timestamps) de este bucket y clave. */
    public static function hits(string $bucket, string $key, int $window, ?int $now = null): array
    {
        $file = self::file($bucket, $key);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        $min = ($now ?? time()) - $window;
        return is_array($data) ? array_values(array_filter($data, static fn($t) => is_int($t) && $t > $min)) : [];
    }

    public static function tooMany(string $bucket, string $key, int $limit, int $window, ?int $now = null): bool
    {
        return count(self::hits($bucket, $key, $window, $now)) >= $limit;
    }

    public static function hit(string $bucket, string $key, int $window, ?int $now = null): int
    {
        $dir = Storage::dir('ratelimit');
        if (!Storage::ensure($dir)) {
            return 0;
        }
        $fh = @fopen(self::file($bucket, $key), 'c+');
        if ($fh === false) {
            return 0;
        }
        $n = 0;
        if (flock($fh, LOCK_EX)) {
            $t = $now ?? time();
            $data = json_decode((string) stream_get_contents($fh), true);
            $data = is_array($data) ? array_values(array_filter($data, static fn($x) => is_int($x) && $x > $t - $window)) : [];
            $data[] = $t;
            $n = count($data);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($data));
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        @chmod(self::file($bucket, $key), 0600);
        if (random_int(1, 100) === 1) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                if (@filemtime($f) < time() - 86400) {
                    @unlink($f);
                }
            }
        }
        return $n;
    }
}
