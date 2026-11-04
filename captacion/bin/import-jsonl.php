<?php
/**
 * Importa a la base del CRM los leads que el sitio guardo en storage/leads/AAAA-MM.jsonl (respaldo de la fase P). IDEMPOTENTE por el `id` del lead (ld_xxx):
 * correrlo dos veces no duplica nada. Solo CLI.
 *
 *   php captacion/bin/import-jsonl.php                      importa todos los meses de ../storage/leads (silencioso: sin alertas ni avisos)
 *   php captacion/bin/import-jsonl.php --dir=/ruta/leads    otra carpeta con .jsonl
 *   php captacion/bin/import-jsonl.php --month=2026-11      solo ese mes
 *   php captacion/bin/import-jsonl.php --only-pending       solo las lineas con "crm_ingested": false (o sin la clave: leads de antes del CRM)
 *   php captacion/bin/import-jsonl.php --alerts             encola tambien alertas/avisos (por defecto NO: un lote viejo no debe disparar Telegram)
 *   php captacion/bin/import-jsonl.php --dry-run            cuenta y valida sin escribir
 *
 * Las importaciones no pasan por el antispam (esos leads ya los acepto el sitio: honeypot y limite por IP) y no consultan FMCSA en linea (encolan fmcsa_enrich si hay USDOT).
 * No modifica ni borra los JSONL. Lineas ilegibles se saltan y se cuentan.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Db;
use Captacion\Services\LeadIngest;

$opts = getopt('', ['dir::', 'month::', 'only-pending', 'alerts', 'dry-run', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Uso: php captacion/bin/import-jsonl.php [--dir=carpeta] [--month=AAAA-MM] [--only-pending] [--alerts] [--dry-run]\n");
    exit(0);
}
$dir = isset($opts['dir']) && is_string($opts['dir']) && $opts['dir'] !== '' ? rtrim($opts['dir'], '/') : dirname(CAPTACION_ROOT) . '/storage/leads';
$month = isset($opts['month']) && is_string($opts['month']) ? $opts['month'] : '';
if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
    fwrite(STDERR, "--month debe ser AAAA-MM\n");
    exit(2);
}
$files = glob($dir . '/' . ($month !== '' ? $month : '*') . '.jsonl') ?: [];
sort($files);
if (!$files) {
    fwrite(STDERR, "No hay archivos .jsonl en $dir\n");
    exit(1);
}
$dry = isset($opts['dry-run']);
try {
    Db::pdo();
} catch (Throwable $t) {
    fwrite(STDERR, 'No se pudo conectar a la base: ' . $t->getMessage() . "\n");
    exit(1);
}

$n = ['lines' => 0, 'created' => 0, 'duplicate_id' => 0, 'reentry' => 0, 'reopened' => 0, 'signed_reentry' => 0, 'rejected' => 0, 'skipped' => 0, 'errors' => 0];
foreach ($files as $file) {
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        fwrite(STDERR, "No se pudo abrir $file\n");
        continue;
    }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $n['lines']++;
        $lead = json_decode($line, true);
        if (!is_array($lead) || !isset($lead['id'])) {
            $n['errors']++;
            fwrite(STDERR, basename($file) . ': linea ilegible (se salta)' . "\n");
            continue;
        }
        if (isset($opts['only-pending']) && ($lead['crm_ingested'] ?? false) === true) {
            $n['skipped']++;
            continue;
        }
        if ($dry) {
            $n['created']++;   // en dry-run solo se valida la lectura
            continue;
        }
        try {
            $r = LeadIngest::handle(LeadIngest::fromSiteLead($lead), 'web_careers', ['skip_antispam' => true, 'quiet' => !isset($opts['alerts']), 'fmcsa_sync' => false]);
        } catch (Throwable $t) {
            $n['errors']++;
            fwrite(STDERR, basename($file) . ' ' . $lead['id'] . ': ' . get_class($t) . ': ' . $t->getMessage() . "\n");
            continue;
        }
        $key = $r['ok'] ? $r['status'] : 'rejected';
        $n[$key] = ($n[$key] ?? 0) + 1;
        if (!$r['ok']) {
            fwrite(STDERR, basename($file) . ' ' . $lead['id'] . ': rechazado (' . $r['reason'] . ")\n");
        }
    }
    fclose($fh);
}
fwrite(STDOUT, ($dry ? '[dry-run] ' : '') . json_encode($n) . "\n");
exit($n['errors'] > 0 ? 1 : 0);
