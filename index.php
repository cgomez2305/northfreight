<?php
/**
 * Front controller de NorthStar Freight Solutions.
 *
 * Flujo: normaliza la URI -> bloquea rutas internas -> sirve /robots.txt, /sitemap.xml, /llms.txt y /llms-full.txt ->
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

// Página de error mínima y amistosa (500): sin rutas ni detalles internos, sin caché y fuera del índice. Se registra ANTES de cargar nada para cubrir también fallos de arranque
// (excepciones no capturadas y errores fatales; un parse error del propio index.php no se puede capturar, para eso sirve el ErrorDocument 500 del servidor).
function ns_error_page(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header_remove('X-Powered-By');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
    }
    $phone = defined('PHONE') ? (string) PHONE : '';
    $wa = defined('WA_BASE') ? (string) WA_BASE : '';
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en-US"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>Something went wrong | NorthStar Freight</title></head>'
        . '<body style="margin:0;background:#04101F;color:#EEF3F8;font:18px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;padding:24px">'
        . '<main style="max-width:520px"><h1 style="font-size:32px;margin:0 0 12px">Something went wrong on our side.</h1>'
        . '<p style="color:#B9C8D8">Please try again in a minute.' . ($phone !== '' ? ' If it is urgent, message us on WhatsApp at ' . ($wa !== '' ? '<a style="color:#6FD3EC" href="' . $esc($wa) . '">' . $esc($phone) . '</a>' : $esc($phone)) . '.' : '') . '</p>'
        . '<p><a style="color:#6FD3EC" href="/">Back to the home page</a></p></main></body></html>';
}
set_exception_handler(static function (Throwable $t): void {
    $detail = get_class($t) . ': ' . $t->getMessage() . ' @' . basename($t->getFile()) . ':' . $t->getLine();
    if (function_exists('ns_log_error')) {
        ns_log_error('uncaught', $detail);
    } else {
        error_log('uncaught ' . $detail);
    }
    ns_error_page();
});
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        ns_error_page();
    }
});

require NS_ROOT . '/app/helpers.php';   // carga también app/config.php
require NS_ROOT . '/app/sitemap.php';
require NS_ROOT . '/app/llms.php';       // /llms.txt y /llms-full.txt (generados desde routes + data)
require NS_ROOT . '/app/forms/lib.php';   // csrf_token() y utilidades de formularios: SIEMPRE antes de renderizar

// Los errores NUNCA se muestran al visitante (revelarían rutas del servidor): van a storage/error.log, también en staging.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', NS_ROOT . '/storage/error.log');

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
// "//ruta/" o "///": parse_url lo leería como host. Se normaliza con un 301 a la ruta limpia (sin URLs duplicadas de la home).
$rawUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
if (str_starts_with($rawUri, '//')) {
    ns_redirect((string) preg_replace('#/{2,}#', '/', explode('?', $rawUri, 2)[0]), $method);
}
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
$blocked = in_array(strtolower($first), ['app', 'templates', 'storage', 'vendor', 'bin', 'captacion', 'cron', 'node_modules', '.git', '.claude'], true)
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

// --- /favicon.ico: los navegadores y bots lo piden siempre; se sirve el .ico de assets/img (si no existe, 404 en texto plano, sin la plantilla HTML) ---
if ($collapsed === '/favicon.ico') {
    $ico = NS_ROOT . '/assets/img/favicon.ico';
    if (!is_file($ico)) {
        ns_plain(404, 'Not found');
    }
    header('Content-Type: image/x-icon');
    header('Cache-Control: public, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    readfile($ico);
    exit;
}

// --- Informes de la CSP (report-uri): se anota un extracto en storage/csp.log (rotación a 1 MB) y se responde 204 ---
if ($collapsed === '/csp-report/' && $method === 'POST') {
    $raw = (string) file_get_contents('php://input', false, null, 0, 4096);
    if ($raw !== '') {
        ns_log('csp', gmdate('c') . ' ' . ns_redact(mb_substr((string) preg_replace('/\s+/', ' ', mb_scrub($raw)), 0, 1500)));
    }
    http_response_code(204);
    header('Cache-Control: no-store');
    exit;
}

// --- Descargas de plantillas: solo salen del formulario (app/forms/download.php); la URL directa no existe ---
if (str_starts_with($collapsed, '/assets/downloads/')) {
    ns_plain(404, 'Not found');
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
if (in_array($collapsed, ['/robots.txt', '/sitemap.xml', '/llms.txt', '/llms-full.txt'], true)) {
    if ($method === 'POST') {
        ns_plain(405, 'Method not allowed', ['Allow: GET, HEAD']);
    }
    if ($collapsed === '/sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8');
        $body = sitemap_xml();
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        $body = match ($collapsed) {
            '/robots.txt'    => robots_txt(),
            '/llms.txt'      => llms_txt(),
            default          => llms_full_txt(),
        };
        if ($collapsed !== '/robots.txt') {
            // Caché corta (el contenido sale de routes + data); en staging no se indexa ni se cita.
            header(is_production() ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-cache');
            if (!is_production()) {
                header('X-Robots-Tag: noindex, nofollow');
            }
            header('X-Content-Type-Options: nosniff');
        }
    }
    echo $body;
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
    render_route(str_starts_with($candidate, '/es/') && isset(routes()['404es']) ? routes()['404es'] : routes()['404']);   // 404 en el idioma de la sección
    exit;
}
ns_set_lang((string) ($route['lang'] ?? 'en'));   // idioma de la petición: url(), wa() y los formularios (redirecciones) lo usan antes del render

// --- Cabeceras comunes ---
$isDraft = ($route['status'] ?? 'live') !== 'live';
if (!is_production() || $isDraft) {
    header('X-Robots-Tag: noindex, nofollow');
} elseif (!empty($route['noindex'])) {
    header('X-Robots-Tag: noindex, follow');   // apply / gracias: live pero fuera del indice (la meta robots del layout dice lo mismo)
}
header($isDraft ? 'Cache-Control: no-store' : (is_production() ? 'Cache-Control: public, max-age=300' : 'Cache-Control: no-cache'));
ns_security_headers();

// --- Pagina de gracias de las solicitudes: solo con el token firmado de un envio real (sin sesiones); sin el, 303 al formulario ---
if (($route['gate'] ?? '') === 'lead-thanks') {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $thanksProfile = ns_lead_thanks_profile($_GET['t'] ?? null);
    if ($thanksProfile === null) {
        header('Location: ' . url('/careers/apply/'), true, 303);
        exit;
    }
    $route['thanks_profile'] = $thanksProfile;
}

// --- Formularios: lista blanca de handlers (app/forms/<handler>.php) ---
// El handler sale de $route['form'] ('contact' | 'download' | 'lead'); si la ruta aun no lo declara, respaldo por ruta.
$formHandlers = ['contact', 'download', 'lead'];
$formByPath = [
    '/contact/'                                => 'contact',
    '/careers/apply/'                          => 'lead',
    '/resources/bol-template/'                 => 'download',
    '/resources/dispatcher-carrier-agreement/' => 'download',
];
$form = $route['form'] ?? ($formByPath[ns_en_key($route)] ?? null);
if (!is_string($form) || !in_array($form, $formHandlers, true) || !is_file(NS_ROOT . '/app/forms/' . $form . '.php')) {
    $form = null;
}

if ($form !== null && $method === 'GET') {
    header('Cache-Control: no-store');   // el HTML de un formulario lleva el token CSRF y la hora de carga: no se cachea (ni en LiteSpeed ni en intermediarios)
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
        ns_render_page($route, ns_flash_error($form, [], [], ns_t('Something went wrong on our side. Please message us on WhatsApp or email') . ' ' . ns_mail_to() . '.'), 500);
    }
    exit;
}

// --- GET ?sent=1 en una ruta con formulario: mensaje de exito tras PRG (sin sesiones; cache-friendly) ---
if ($form !== null && ($_GET['sent'] ?? null) === '1') {
    header('X-Robots-Tag: noindex, nofollow');
    ns_render_page($route, ns_flash_ok($form), 200);
}

render_route($route);
