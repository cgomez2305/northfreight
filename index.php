<?php
/**
 * Front controller de NorthStar Freight Solutions.
 *
 * Flujo: normaliza la URI -> bloquea rutas internas -> sirve /robots.txt y /sitemap.xml ->
 * resuelve la ruta en app/routes.php (barra final 301, draft => 404 salvo ?preview=TOKEN) ->
 * POST a una ruta con formulario (contact, descargas) delega en app/forms/<handler>.php -> render (plantilla + layout).
 *
 * Funciona como router de `php -S` (assets reales se sirven con `return false`) y bajo Apache/LiteSpeed
 * (.htaccess manda aquí todo lo que no sea un archivo real).
 *
 * Desarrollo local:  php -S 127.0.0.1:8000 -t . index.php
 */

declare(strict_types=1);

define('NS_ROOT', __DIR__);
require NS_ROOT . '/app/helpers.php';   // carga también app/config.php
require NS_ROOT . '/app/sitemap.php';
require NS_ROOT . '/app/forms/lib.php';   // csrf_token() y utilidades de formularios: SIEMPRE antes de renderizar

error_reporting(E_ALL);
if (is_production()) {
    ini_set('display_errors', '0');
}

/** Respuesta de error mínima en texto plano (assets inexistentes, 400, 405). */
function ns_plain(int $status, string $message, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    foreach ($headers as $h) {
        header($h);
    }
    echo $message;
    exit;
}

/** Redirección permanente (301 para GET/HEAD, 308 para el resto) preservando la query. */
function ns_redirect(string $path, string $method): never
{
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . $path . ($qs !== '' ? '?' . $qs : ''), true, in_array($method, ['GET', 'HEAD'], true) ? 301 : 308);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$rawPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (!is_string($rawPath) || $rawPath === '') {
    ns_plain(400, 'Bad request');
}
$path = rawurldecode($rawPath);
if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('#(^|/)\.\.(/|$)#', $path)) {
    ns_plain(400, 'Bad request');
}
$collapsed = preg_replace('#/{2,}#', '/', $path);
if ($path[0] !== '/') {
    $collapsed = '/' . $collapsed;
}

if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    ns_plain(405, 'Method not allowed', ['Allow: GET, HEAD, POST']);
}

// --- Rutas internas / archivos que nunca se sirven por web (equivale al bloqueo de .htaccess) ---
$first = explode('/', ltrim($collapsed, '/'), 2)[0];
$blocked = in_array($first, ['app', 'templates', 'storage', 'vendor', 'bin', 'node_modules', '.git', '.claude'], true)
    || ($first !== '' && $first[0] === '.' && $first !== '.well-known')
    || preg_match('#(^|/)(composer\.(json|lock)|config\.local(\.example)?\.php|index\.php|\.htaccess|\.gitignore)$#', $collapsed)
    || preg_match('#\.(md|php|lock|log|ini|sh)$#i', $collapsed);
if ($blocked) {
    if ($collapsed === '/index.php') {
        ns_redirect('/', $method);
    }
    http_response_code(404);
    render_route(routes()['404'] + ['path' => '/404/']);
    exit;
}

// --- Assets reales: php -S los sirve con return false; en Apache ya los sirvió el servidor ---
if (str_starts_with($collapsed, '/assets/')) {
    $file = realpath(NS_ROOT . $collapsed);
    $assetsDir = realpath(NS_ROOT . '/assets');
    if ($file !== false && $assetsDir !== false && is_file($file) && str_starts_with($file, $assetsDir . DIRECTORY_SEPARATOR)) {
        if (PHP_SAPI === 'cli-server') {
            return false;
        }
        // Fuera de cli-server no debería llegar aquí; sirve el archivo por seguridad.
        header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
        readfile($file);
        exit;
    }
    ns_plain(404, 'Not found');
}

// --- Archivos generados ---
if ($collapsed === '/robots.txt' || $collapsed === '/sitemap.xml') {
    if ($method === 'POST') {
        ns_plain(405, 'Method not allowed', ['Allow: GET, HEAD']);
    }
    if ($collapsed === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo robots_txt();
    } else {
        header('Content-Type: application/xml; charset=utf-8');
        echo sitemap_xml();
    }
    exit;
}

// --- Resolución de ruta ---
$previewOk = PREVIEW_TOKEN !== ''
    && isset($_GET['preview']) && is_string($_GET['preview'])
    && hash_equals(PREVIEW_TOKEN, $_GET['preview']);

$candidate = strtolower(str_ends_with($collapsed, '/') ? $collapsed : $collapsed . '/');
$route = route_for($candidate);
if ($route !== null && ($route['status'] ?? 'live') === 'draft' && !$previewOk) {
    $route = null;   // draft: indistinguible de una ruta inexistente
}

if ($route !== null && $candidate !== $path) {
    ns_redirect($candidate, $method);   // barra final y minúsculas (301)
}

if ($route === null) {
    http_response_code(404);
    header('Cache-Control: no-store');
    if (!is_production()) {
        header('X-Robots-Tag: noindex, nofollow');
    }
    render_route(routes()['404']);
    exit;
}

// --- Cabeceras comunes ---
$isDraft = ($route['status'] ?? 'live') !== 'live';
if (!is_production() || $isDraft) {
    header('X-Robots-Tag: noindex, nofollow');
}
header($isDraft ? 'Cache-Control: no-store' : (is_production() ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-cache'));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// --- Formularios: lista blanca de handlers (app/forms/<handler>.php) ---
// El handler sale de $route['form'] ('contact' | 'download'); si la ruta aun no lo declara, respaldo por ruta.
$formHandlers = ['contact', 'download'];
$formByPath = [
    '/contact/'                                => 'contact',
    '/resources/bol-template/'                 => 'download',
    '/resources/dispatcher-carrier-agreement/' => 'download',
];
$form = $route['form'] ?? ($formByPath[$candidate] ?? null);
if (!is_string($form) || !in_array($form, $formHandlers, true) || !is_file(NS_ROOT . '/app/forms/' . $form . '.php')) {
    $form = null;
}

if ($method === 'POST') {
    if ($form === null) {
        ns_plain(405, 'Method not allowed', ['Allow: GET, HEAD']);
    }
    ns_post_headers();   // no-store + noindex en toda respuesta a un POST
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > NS_MAX_POST_BYTES || count($_POST) > 30) {
        ns_plain(413, 'Request too large');
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'null') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ':' . $p : ''));
        if ($originHost !== strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
            ns_plain(403, 'Forbidden');
        }
    }
    $cfg = cfg();
    try {
        require NS_ROOT . '/app/forms/' . $form . '.php';   // recibe $route y $cfg; responde por si mismo y termina
    } catch (Throwable $t) {
        ns_log_error('form-' . $form . ' ' . get_class($t), $t->getMessage() . ' @' . basename($t->getFile()) . ':' . $t->getLine());
        ns_render_page($route, ns_flash_error($form, [], [], 'Something went wrong on our side. Please message us on WhatsApp or email ' . ns_mail_to() . '.'), 500);
    }
    exit;
}

// --- GET ?sent=1 en una ruta con formulario: mensaje de exito tras PRG (sin sesiones; cache-friendly) ---
if ($form !== null && ($_GET['sent'] ?? null) === '1') {
    header('X-Robots-Tag: noindex, nofollow');
    ns_render_page($route, ns_flash_ok($form), 200);
}

render_route($route);
