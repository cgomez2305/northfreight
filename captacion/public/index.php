<?php
/**
 * Front controller del panel (subdominio captacion.). Rutas: / (panel), /leads/{id}, /login, /login/2fa, /logout.
 * setup.php y api/lead.php son puntos de entrada propios. Cualquier otra ruta: 404.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Captacion\Controllers\AuthController;
use Captacion\Controllers\PanelController;
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
        PanelController::dashboard();
    case $path === '/login':
        AuthController::login();
    case $path === '/login/2fa':
        AuthController::twofa();
    case $path === '/logout':
        AuthController::logout();
    case preg_match('#^/leads/(\d{1,12})$#', $path, $m) === 1:
        PanelController::lead((int) $m[1]);
    default:
        http_response_code(404);
        \Captacion\Middleware\SecurityHeaders::send();
        header('Content-Type: text/html; charset=utf-8');
        echo \Captacion\View::render('message', ['title' => 'No encontrado', 'heading' => 'No encontrado', 'text' => 'Esta página no existe.']);
}
