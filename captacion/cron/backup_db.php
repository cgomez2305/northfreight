<?php
/**
 * Copia diaria de la base del CRM (doc §17). Hostinger -> Cron Jobs, CADA HORA a los :30 (el script decide si toca):
 *   30 * * * * /opt/alt/php83/usr/bin/php /home/u480515229/domains/northstarfreightsolutions.us/public_html/captacion/cron/backup_db.php >/dev/null 2>&1
 *
 * Solo hace la copia cuando son las 3:30 a. m. de Nueva York (America/New_York, con su cambio de hora) y aun no hay una copia buena de ese dia, o cuando la ultima
 * buena tiene mas de 25 h (recuperar un dia perdido). Despues purga las copias de mas de 30 dias y el registro de actividad mas viejo que su retencion.
 *   --force   hace la copia ahora, sin mirar la hora
 * Sin mysqldump: el volcado es PHP puro (Services/Backup.php). Exit 0 si todo bien o si no tocaba; 1 si la copia fallo (queda en la campana y en la pantalla de copias).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Services\ActivityView;
use Captacion\Services\Backup;
use Captacion\Services\Storage;

try {
    \Captacion\Db::setPdo(\Captacion\Db::connect());
    $force = in_array('--force', $argv, true);
    if (!$force && !Backup::due()) {
        fwrite(STDOUT, "skip: no toca copia ahora\n");
        exit(0);
    }
    $r = Backup::run($force ? 'manual' : 'cron', null);
    fwrite(STDOUT, ($r['ok'] ? 'ok: ' : 'ERROR: ') . $r['msg'] . ($r['file'] ? ' (' . $r['file'] . ', ' . $r['size'] . " bytes)\n" : "\n"));
    $purged = ActivityView::purge();
    if ($purged > 0) {
        fwrite(STDOUT, "registro de actividad: $purged filas purgadas\n");
    }
    exit($r['ok'] ? 0 : 1);
} catch (Throwable $t) {
    Storage::error('backup_db aborted', get_class($t) . ': ' . $t->getMessage());
    fwrite(STDERR, 'backup_db: ' . Storage::redact($t->getMessage()) . "\n");
    exit(1);
}
