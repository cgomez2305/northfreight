<?php
/** POST /api/lead.php: entrada de leads para fuentes externas. Ver Captacion\Controllers\ApiController. */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Captacion\Controllers\ApiController;
use Captacion\Services\Storage;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
Storage::ensure(Storage::dir());
ini_set('error_log', Storage::dir('error.log'));

set_exception_handler(static function (Throwable $t): void {
    Storage::error('api ' . get_class($t), $t->getMessage() . ' @' . basename($t->getFile()) . ':' . $t->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"server_error"}';
});

ApiController::lead();
