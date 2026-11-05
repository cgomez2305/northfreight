<?php
/**
 * Informes automaticos (A13 resumen semanal, A14 informe mensual). Hostinger -> Cron Jobs, CADA HORA (minuto 10):
 *   10 * * * * /opt/alt/php83/usr/bin/php /home/u480515229/domains/northstarfreightsolutions.us/public_html/captacion/cron/reports_cron.php >/dev/null 2>&1
 *
 * Decide por la hora de Nueva York (con cambio de hora incluido):
 *   - lunes desde las 8:00 a. m. (con recuperacion hasta el martes 23:59): resumen semanal por correo a Carlos, de la semana anterior;
 *   - dias 1 a 5 de cada mes (desde la 1:00 a. m. del dia 1): genera el informe del mes anterior como BORRADOR y avisa a Carlos.
 * Idempotente (tabla `reports` con clave unica y marcas de envio): correrlo varias veces no duplica nada. --now=AAAA-MM-DDTHH:MM (hora ET) solo para ensayar.
 * Solo CLI. flock para no solaparse.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Services\ReportJobs;
use Captacion\Services\Settings;
use Captacion\Services\Storage;

Storage::ensure(Storage::dir('locks'));
$lock = fopen(Storage::dir('locks/reports_cron.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "locked\n");
    exit(0);
}
$now = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--now=')) {
        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($a, 6), new DateTimeZone('America/New_York'));
        $now = $d !== false ? $d->getTimestamp() : null;
    }
}
try {
    $r = ReportJobs::tick($now);
    Settings::set('cron.reports', ['at' => gmdate('Y-m-d H:i:s'), 'weekly' => $r['weekly'], 'monthly' => $r['monthly']]);
    fwrite(STDOUT, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
} catch (Throwable $t) {
    Storage::error('reports_cron failed', get_class($t) . ': ' . $t->getMessage());
    fwrite(STDERR, 'reports_cron: ' . Storage::redact($t->getMessage()) . "\n");
    flock($lock, LOCK_UN);
    exit(1);
}
flock($lock, LOCK_UN);
