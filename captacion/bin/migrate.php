<?php
/**
 * Aplica las migraciones pendientes de captacion/db/migrations/ (idempotente). Solo CLI.
 *   php captacion/bin/migrate.php            aplica lo que falte
 *   php captacion/bin/migrate.php --status   lista aplicadas y pendientes
 *   php captacion/bin/migrate.php --schema   escribe en db/schema.sql el esquema completo (concatena las migraciones; referencia legible)
 * Lee la conexion de captacion/config/.env (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Db;
use Captacion\Migrator;

$dir = CAPTACION_ROOT . '/db/migrations';
if (in_array('--schema', $argv, true)) {
    $out = "-- Esquema de referencia del mini-CRM (GENERADO por `php captacion/bin/migrate.php --schema`: concatena db/migrations/*.sql). La fuente de verdad son las migraciones.\n\n";
    $files = glob($dir . '/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($files);
    foreach ($files as $f) {
        $out .= '-- ===== ' . basename($f) . " =====\n" . trim((string) file_get_contents($f)) . "\n\n";
    }
    file_put_contents(CAPTACION_ROOT . '/db/schema.sql', $out);
    fwrite(STDOUT, "db/schema.sql escrito (" . count($files) . " migraciones)\n");
    exit(0);
}

try {
    $pdo = Db::connect();
} catch (Throwable $t) {
    fwrite(STDERR, 'No se pudo conectar a la base: ' . $t->getMessage() . "\n");
    exit(1);
}

if (in_array('--status', $argv, true)) {
    $done = [];
    try {
        $done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
    }
    foreach (glob($dir . '/[0-9][0-9][0-9]_*.sql') ?: [] as $f) {
        $v = basename($f, '.sql');
        fwrite(STDOUT, (in_array($v, $done, true) ? '[aplicada]  ' : '[PENDIENTE] ') . $v . "\n");
    }
    exit(0);
}

try {
    $applied = Migrator::run($pdo, $dir);
} catch (Throwable $t) {
    fwrite(STDERR, $t->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, $applied ? 'Aplicadas: ' . implode(', ', $applied) . "\n" : "Nada que aplicar: la base esta al dia.\n");
