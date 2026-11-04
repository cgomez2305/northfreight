<?php
/**
 * Procesa la cola `jobs` (doc §3). Hostinger -> Cron Jobs, CADA MINUTO:
 *   * * * * * /opt/alt/php83/usr/bin/php /home/u480515229/domains/northstarfreightsolutions.us/public_html/captacion/cron/process_jobs.php >/dev/null 2>&1
 *
 * - flock en storage/locks/process_jobs.lock: si el minuto anterior sigue corriendo, este sale sin hacer nada (sin solapes).
 * - Maximo ~50 s por pasada, en lotes de 20; reintentos a 1, 5 y 15 min y despues estado `failed` (visible en el panel).
 * - Solo CLI. Exit 0 aunque no haya trabajo; exit 1 si no hay base de datos (para que el cron lo deje en su registro de errores).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Env;
use Captacion\Services\JobHandlers;
use Captacion\Services\Queue;
use Captacion\Services\Storage;

Storage::ensure(Storage::dir('locks'));
$lock = fopen(Storage::dir('locks/process_jobs.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "locked: otra pasada sigue en curso\n");
    exit(0);
}

try {
    $stats = Queue::runBatch(JobHandlers::all(), Env::int('JOBS_MAX_SECONDS', 50), 20);
    fwrite(STDOUT, 'jobs: ' . json_encode($stats) . "\n");
} catch (Throwable $t) {
    Storage::error('process_jobs aborted', get_class($t) . ': ' . $t->getMessage());
    fwrite(STDERR, 'process_jobs: ' . Storage::redact($t->getMessage()) . "\n");
    flock($lock, LOCK_UN);
    exit(1);
}
flock($lock, LOCK_UN);
