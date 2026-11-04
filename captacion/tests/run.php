<?php
/**
 * Pruebas del mini-CRM de captacion (fase Q1). Runner propio, sin PHPUnit ni Composer.
 *
 *   php captacion/tests/run.php                  todo (levanta un MariaDB temporal; tarda ~1 min por las esperas del formulario del sitio)
 *   php captacion/tests/run.php --only=scoring   solo los bloques cuyo nombre contenga "scoring"
 *   php captacion/tests/run.php --workdir=DIR    carpeta de trabajo (por defecto, una temporal del sistema); --keep no la borra
 *
 * Necesita: mariadbd + mariadb-install-db en el PATH, PHP con pdo_mysql, sodium, curl y mbstring. Los servidores se arrancan con PID guardado y se apagan por PID al terminar.
 * Nunca toca ningun servidor remoto ni el storage/ real del repo: todo va a la carpeta de trabajo.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
require __DIR__ . '/lib/T.php';
require __DIR__ . '/lib/Infra.php';

use Captacion\Db;
use Captacion\Env;
use Captacion\Migrator;
use CaptacionTests\Infra;
use CaptacionTests\T;

$opts = getopt('', ['workdir::', 'only::', 'keep']);
$work = isset($opts['workdir']) && is_string($opts['workdir']) && $opts['workdir'] !== '' ? rtrim($opts['workdir'], '/') : rtrim(sys_get_temp_dir(), '/') . '/captacion-tests-' . getmypid();
$only = isset($opts['only']) && is_string($opts['only']) ? $opts['only'] : '';
Infra::$keep = isset($opts['keep']);
@mkdir($work, 0777, true);
exec('rm -rf ' . escapeshellarg("$work/storage") . ' ' . implode(' ', array_map('escapeshellarg', glob("$work/crm-storage-*") ?: [])) . ' ' . implode(' ', array_map('escapeshellarg', glob("$work/site-*") ?: [])) . ' ' . escapeshellarg("$work/badleads"));   // estado limpio si se reutiliza la carpeta
@mkdir("$work/storage", 0777, true);
putenv('NO_PROXY=127.0.0.1,localhost');
putenv('no_proxy=127.0.0.1,localhost');

register_shutdown_function(static function () use ($work): void {
    Infra::stopAll();
    if (!Infra::$keep && str_contains($work, 'captacion-tests-')) {
        exec('rm -rf ' . escapeshellarg($work));
    }
});
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $sig) {
        pcntl_signal($sig, static function (): void {
            exit(130);
        });
    }
}

fwrite(STDOUT, "Carpeta de trabajo: $work\n");
Infra::startMariaDb($work);
fwrite(STDOUT, 'MariaDB temporal en 127.0.0.1:' . Infra::$dbPort . "\n");

// Entorno del propio runner (pruebas en proceso)
$ctx = [
    'work' => $work, 'root' => $root, 'dbPort' => Infra::$dbPort,
    'appKey' => str_repeat('ab12', 16), 'setupToken' => bin2hex(str_repeat('s', 32)), 'apiToken' => 'tok_' . bin2hex(str_repeat('a', 20)),
    'env' => [
        'APP_ENV' => 'local', 'APP_KEY' => str_repeat('ab12', 16), 'APP_URL' => 'http://panel.test', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) Infra::$dbPort, 'DB_NAME' => 'cap_test',
        'DB_USER' => 'captest', 'DB_PASS' => 'captest-pass', 'SETUP_TOKEN' => bin2hex(str_repeat('s', 32)), 'INTERNAL_API_TOKEN' => 'tok_' . bin2hex(str_repeat('a', 20)),
        'STORAGE_DIR' => "$work/storage", 'MAIL_TO' => 'carlos@example.com',
    ],
];
foreach ($ctx['env'] as $k => $v) {
    Env::set($k, $v);
}
$GLOBALS['CTX'] = $ctx;

$pdo = Db::connect();
Db::setPdo($pdo);
$migrationsApplied = Migrator::run($pdo, $root . '/db/migrations');   // la base de trabajo; el caso 01 prueba ademas "desde cero" en otra base
$ctx['migrationsApplied'] = $migrationsApplied;
$files = glob(__DIR__ . '/cases/[0-9][0-9]_*.php') ?: [];
sort($files);
foreach ($files as $file) {
    $name = basename($file, '.php');
    if ($only !== '' && !str_contains($name, $only)) {
        continue;
    }
    T::section($name);
    try {
        $fn = require $file;
        $fn($ctx);
    } catch (Throwable $t) {
        T::ok(false, 'EXCEPCION en ' . $name . ': ' . get_class($t) . ': ' . $t->getMessage() . ' @' . basename($t->getFile()) . ':' . $t->getLine());
    }
}
exit(T::summary());
