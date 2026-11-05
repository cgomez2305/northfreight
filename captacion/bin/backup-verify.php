<?php
/**
 * Verifica un archivo de copia (.sql.gz): se descomprime entero, cada tabla cierra con su marcador de filas que coincide con las filas escritas y termina con END OK.
 * No necesita la base. Uso: php captacion/bin/backup-verify.php RUTA.sql.gz   (exit 0 = bueno)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Uso: php captacion/bin/backup-verify.php RUTA.sql.gz\n");
    exit(2);
}
$r = \Captacion\Services\Backup::verifyStandalone($file);
fwrite(STDOUT, $r['ok'] ? "OK: el archivo se descomprime y todas las tablas cuadran.\n" : 'MAL: ' . implode(' ', $r['problems']) . "\n");
exit($r['ok'] ? 0 : 1);
