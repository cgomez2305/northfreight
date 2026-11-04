<?php
/** Alta del administrador (primer uso). Ver Captacion\Controllers\SetupController. Tras el alta responde 404 para siempre. */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Controllers\SetupController;
use Captacion\Services\Storage;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
Storage::ensure(Storage::dir());
ini_set('error_log', Storage::dir('error.log'));

set_exception_handler(static function (Throwable $t): void {
    Storage::error('setup ' . get_class($t), $t->getMessage() . ' @' . basename($t->getFile()) . ':' . $t->getLine());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'La instalacion no pudo continuar. Revisa storage/error.log y que la base de datos este migrada (php captacion/bin/migrate.php).';
});

SetupController::handle();
