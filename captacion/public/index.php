<?php
/**
 * Front controller del panel (subdominio captacion.). Rutas: / (dashboard), /pipeline, /tareas, /config, /actividad, /leads, /leads/{id}, /leads/{id}/{accion}, /leads/bulk, /leads/export, /ajax/search, /ajax/notifications/read, /login, /login/2fa, /logout.
 * setup.php y api/lead.php son puntos de entrada propios. Cualquier otra ruta: 404.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Controllers\ActividadController;
use Captacion\Controllers\AjaxController;
use Captacion\Controllers\AuthController;
use Captacion\Controllers\ConfigController;
use Captacion\Controllers\DashboardController;
use Captacion\Controllers\LeadActionsController;
use Captacion\Controllers\LeadsController;
use Captacion\Controllers\PipelineController;
use Captacion\Controllers\TareasController;
use Captacion\Services\Storage;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
Storage::ensure(Storage::dir());
ini_set('error_log', Storage::dir('error.log'));

set_exception_handler(static function (Throwable $t): void {
    Storage::error('panel ' . get_class($t), $t->getMessage() . ' @' . basename($t->getFile()) . ':' . $t->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        \Captacion\Middleware\SecurityHeaders::send();
        header('Content-Type: text/html; charset=utf-8');
    }
    echo \Captacion\View::render('message', ['title' => 'Error', 'heading' => 'Algo falló', 'text' => 'El panel no pudo completar la petición. Revisa storage/error.log en el servidor.']);
});

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';
$path = '/' . trim((string) preg_replace('#/{2,}#', '/', $path), '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit;
}

if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /\n";
    exit;
}

switch (true) {
    case $path === '/':
        DashboardController::index();
    case $path === '/leads':
        LeadsController::index();
    case $path === '/leads/bulk':
        LeadsController::bulk();
    case $path === '/leads/export':
        LeadsController::export();
    case $path === '/ajax/search':
        AjaxController::search();
    case $path === '/ajax/notifications/read':
        AjaxController::notificationsRead();
    case $path === '/pipeline':
        PipelineController::index();
    case $path === '/ajax/pipeline/cards':
        PipelineController::cards();
    case $path === '/ajax/pipeline/move':
        PipelineController::move();
    case $path === '/tareas':
        TareasController::index();
    case $path === '/tareas/new':
        TareasController::create();
    case preg_match('#^/tareas/(\d{1,12})/([a-z]{4,8})$#', $path, $m) === 1:
        TareasController::handle((int) $m[1], $m[2]);
    case preg_match('#^/config(?:/([a-z]{3,12})(?:/([a-z]{3,12}))?)?$#', $path, $m) === 1:
        ConfigController::route($m[1] ?? null, $m[2] ?? null);
    case preg_match('#^/actividad(?:/([a-z]{3,12}))?$#', $path, $m) === 1:
        ActividadController::route($m[1] ?? null);
    case $path === '/login':
        AuthController::login();
    case $path === '/login/2fa':
        AuthController::twofa();
    case $path === '/logout':
        AuthController::logout();
    case preg_match('#^/leads/(\d{1,12})$#', $path, $m) === 1:
        LeadsController::show((int) $m[1]);
    case preg_match('#^/leads/(\d{1,12})/([a-z-]{3,20})$#', $path, $m) === 1:
        LeadActionsController::handle((int) $m[1], $m[2]);
    default:
        http_response_code(404);
        \Captacion\Middleware\SecurityHeaders::send();
        header('Content-Type: text/html; charset=utf-8');
        echo \Captacion\View::render('message', ['title' => 'No encontrado', 'heading' => 'No encontrado', 'text' => 'Esta página no existe.']);
}
