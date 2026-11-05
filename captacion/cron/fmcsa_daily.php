<?php
/**
 * Prospectos FMCSA (A10, doc §7): autoridades nuevas de la API publica de data.transportation.gov. Hostinger -> Cron Jobs, CADA HORA (minuto 20):
 *   20 * * * * /opt/alt/php83/usr/bin/php /home/u480515229/domains/northstarfreightsolutions.us/public_html/captacion/cron/fmcsa_daily.php >/dev/null 2>&1
 *
 * Decide por la hora de Nueva York (con cambio de hora incluido): corre UNA vez al dia desde las 6:00 a. m. ET; si ese dia no corrio, la siguiente hora la recupera.
 * Si no termina (tiempo FMCSA_MAX_SECONDS = 40 s o memoria), guarda el cursor y la siguiente hora continua. Idempotente, con flock. Solo CLI.
 *   --force          corre ahora aunque ya haya corrido hoy o sea antes de las 6:00 (no salta el tope diario ni los filtros)
 *   --now=AAAA-MM-DDTHH:MM   hora ET simulada, solo para ensayar
 * Salida: exit 0 (incluye «nada que hacer»); exit 1 si Socrata fallo (se reintenta a la hora siguiente) o hay una excepcion.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Clock;
use Captacion\Services\ProspectImport;
use Captacion\Services\Settings;
use Captacion\Services\Storage;

Storage::ensure(Storage::dir('locks'));
$lock = fopen(Storage::dir('locks/fmcsa_daily.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "locked\n");
    exit(0);
}
$now = null;
$force = false;
foreach ($argv as $a) {
    if (str_starts_with($a, '--now=')) {
        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($a, 6), new DateTimeZone('America/New_York'));
        $now = $d !== false ? $d->getTimestamp() : null;
    }
    $force = $force || $a === '--force';
}
try {
    $r = ProspectImport::tick($now, ['force' => $force]);
    $run = $r['run'] ?? null;
    Settings::set('cron.prospects', ['at' => Clock::now(), 'status' => $r['status'], 'day' => $run['day'] ?? null, 'finished' => !empty($run['finished'])]);
    fwrite(STDOUT, json_encode(['status' => $r['status'], 'day' => $run['day'] ?? null, 'finished' => !empty($run['finished']), 'pages' => $run['pages'] ?? 0, 'counters' => $run['counters'] ?? null, 'error' => $run['error'] ?? null], JSON_UNESCAPED_UNICODE) . "\n");
    flock($lock, LOCK_UN);
    exit($r['status'] === 'error' ? 1 : 0);
} catch (Throwable $t) {
    Storage::error('fmcsa_daily failed', get_class($t) . ': ' . $t->getMessage());
    fwrite(STDERR, 'fmcsa_daily: ' . Storage::redact($t->getMessage()) . "\n");
    flock($lock, LOCK_UN);
    exit(1);
}
