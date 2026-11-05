<?php
/**
 * Publica las vacantes al SITIO: escribe storage/jobs.json (del sitio, fuera de la web: el .htaccess raiz bloquea /storage/) de forma atomica y con esquema versionado.
 * app/data/jobs.php del sitio lee ese archivo, valida y sanea cada campo y, si falta o esta corrupto, usa el arreglo vacio (el sitio no tiene base de datos y nunca falla por esto).
 *
 *   {"schema":1,"generated_at":"2026-11-05T12:00:00Z","jobs":{"<slug>":{"status":"active|paused","title":…,"posted":"AAAA-MM-DD","valid_through":"AAAA-MM-DD","employment_type":…,
 *     "location":{"city","region","postal","country"},"summary":…,"equipment":…,"responsibilities":[…],"requirements":[…],"benefits":[…],"pay":{"min","max","unit","currency"}}}}
 *
 * Ruta: SITE_STORAGE_DIR del .env o, por defecto, <raiz del repo>/storage (en Hostinger: public_html/storage). Permisos 0640 (carpeta 0750). Si la escritura falla lanza
 * RuntimeException y la transaccion de quien llama se revierte. Las vacantes vencidas y las cerradas no se incluyen. LiteSpeed cachea el HTML 5 minutos: el cambio tarda hasta 5.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;

final class JobsPublisher
{
    public const SCHEMA = 1;

    public static function dir(): string
    {
        $d = Env::get('SITE_STORAGE_DIR');
        return rtrim($d !== null && $d !== '' ? $d : dirname(CAPTACION_ROOT) . '/storage', '/');
    }

    public static function file(): string
    {
        return self::dir() . '/jobs.json';
    }

    /** Estructura que se publica (solo publicadas y en pausa, sin vencer). */
    public static function build(): array
    {
        $jobs = [];
        $today = JobPostings::today();
        foreach (JobPostings::all() as $j) {
            if (!in_array($j['status'], ['active', 'paused'], true) || $j['posted_on'] === null || $j['valid_through'] === null || (string) $j['valid_through'] < $today) {
                continue;
            }
            $loc = array_filter(['city' => $j['city'], 'region' => $j['region'], 'postal' => $j['postal']], static fn($v) => $v !== null && $v !== '') + ['country' => 'US'];
            $job = [
                'status' => $j['status'], 'title' => $j['title'], 'posted' => $j['posted_on'], 'valid_through' => $j['valid_through'], 'employment_type' => $j['employment_type'], 'location' => $loc,
            ];
            foreach (['summary', 'equipment'] as $k) {
                if ($j[$k] !== null && $j[$k] !== '') {
                    $job[$k] = $j[$k];
                }
            }
            foreach (array_keys(JobPostings::LIST_FIELDS) as $k) {
                if ($j[$k]) {
                    $job[$k] = $j[$k];
                }
            }
            if ($j['pay_unit'] !== null && ($j['pay_min'] !== null || $j['pay_max'] !== null)) {
                $job['pay'] = array_filter(['min' => $j['pay_min'] !== null ? (float) $j['pay_min'] : null, 'max' => $j['pay_max'] !== null ? (float) $j['pay_max'] : null, 'unit' => $j['pay_unit'], 'currency' => 'USD'], static fn($v) => $v !== null);
            }
            $jobs[(string) $j['slug']] = $job;
        }
        return ['schema' => self::SCHEMA, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z', Clock::time()), 'jobs' => $jobs ?: new \stdClass()];
    }

    /**
     * Escribe el archivo de forma atomica (temporal en la misma carpeta + rename). @return array{path:string,count:int,bytes:int}
     * @throws \RuntimeException si no se puede escribir (permisos, disco)
     */
    public static function publish(): array
    {
        $data = self::build();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta storage/ del sitio. Revisa los permisos.');
        }
        $tmp = $dir . '/.jobs.json.' . bin2hex(random_bytes(6)) . '.tmp';
        $n = @file_put_contents($tmp, $json . "\n", LOCK_EX);
        if ($n === false) {
            @unlink($tmp);
            Storage::error('jobs.json no se pudo escribir', 'dir=' . basename($dir));
            throw new \RuntimeException('No se pudo escribir storage/jobs.json del sitio (permisos o disco). No se aplicó el cambio.');
        }
        @chmod($tmp, 0640);
        $back = json_decode((string) file_get_contents($tmp), true);
        if (!is_array($back) || ($back['schema'] ?? null) !== self::SCHEMA) {
            @unlink($tmp);
            throw new \RuntimeException('El archivo de vacantes no pasó la verificación. No se aplicó el cambio.');
        }
        if (!@rename($tmp, self::file())) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo reemplazar storage/jobs.json del sitio. No se aplicó el cambio.');
        }
        Settings::set('jobs.published', ['at' => Clock::now(), 'count' => count((array) $data['jobs'])]);
        return ['path' => self::file(), 'count' => count((array) $data['jobs']), 'bytes' => (int) $n];
    }
}
