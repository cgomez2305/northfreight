<?php
/**
 * Helpers globales del sitio. Se cargan una vez desde index.php (require_once).
 * Compatible con PHP 8.2. Sin estado global salvo cachés estáticas internas.
 *
 * Contrato de render (ver render_route()):
 *   - plantillas de página (templates/pages/*.php): disponibles $route (array de la ruta con 'path'),
 *     $cfg (config) y todos los helpers.
 *   - layout (templates/layout.php): además recibe $content (HTML ya renderizado de la página).
 *   - partials: solo ven lo que se les pasa por partial($name, $vars).
 */

if (!defined('NS_ROOT')) {
    define('NS_ROOT', dirname(__DIR__));
}
require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------
// Entorno y datos
// ---------------------------------------------------------------------

/** true solo si ENV === 'production' (cualquier otro valor se trata como staging). */
function is_production(): bool
{
    return ENV === 'production';
}

/** Mapa de rutas (cada una con 'path' añadido). Incluye la clave especial '404'. */
function routes(): array
{
    static $routes = null;
    if ($routes === null) {
        $routes = require NS_ROOT . '/app/routes.php';
        foreach ($routes as $key => $r) {
            $routes[$key]['path'] = $key === '404' ? '/404/' : $key;
        }
    }
    return $routes;
}

/** Devuelve la ruta de un path exacto ('/contact/') o null. No aplica la lógica draft. */
function route_for(string $path): ?array
{
    if ($path === '404') {
        return null;
    }
    return routes()[$path] ?? null;
}

/** true si la ruta existe y está en status 'live' (útil para no enlazar a rutas draft). */
function route_is_live(string $path): bool
{
    $r = route_for(url($path));
    return $r !== null && ($r['status'] ?? '') === 'live';
}

/** Carga (y cachea) un archivo de app/data/{name}.php. data('faq'), data('posts')… */
function data(string $name): array
{
    static $cache = [];
    if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
        return [];
    }
    return $cache[$name] ??= (require NS_ROOT . '/app/data/' . $name . '.php');
}

// ---------------------------------------------------------------------
// Escape, URLs, assets, contacto
// ---------------------------------------------------------------------

/** Escapa para HTML (ENT_QUOTES, UTF-8). Todo texto dinámico pasa por aquí. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Ruta interna root-relative con barra final: url('contact') => '/contact/'.
 * Respeta ?query y #hash. Rutas con extensión (sitemap.xml, archivos) no reciben barra.
 */
function url(string $path = '/'): string
{
    $suffix = '';
    if (preg_match('/^([^?#]*)([?#].*)$/', $path, $m)) {
        $path = $m[1];
        $suffix = $m[2];
    }
    $path = '/' . ltrim($path, '/');
    if (!str_ends_with($path, '/') && !preg_match('/\.[A-Za-z0-9]{1,6}$/', $path)) {
        $path .= '/';
    }
    return $path . $suffix;
}

/** URL absoluta https://DOMAIN/... (canonical, OG, sitemap, JSON-LD). */
function abs_url(string $path = '/'): string
{
    return 'https://' . DOMAIN . url($path);
}

/**
 * URL de un asset relativo a /assets/ con versión por filemtime: asset('css/base.css')
 * => '/assets/css/base.css?v=1730000000'. Acepta también 'assets/css/base.css'.
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    if (str_starts_with($path, 'assets/')) {
        $path = substr($path, 7);
    }
    $file = NS_ROOT . '/assets/' . $path;
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return '/assets/' . $path . $v;
}

/**
 * URL wa.me con mensaje. $keyOrMsg: clave de WA_MESSAGES ('relay', 'default'…),
 * un mensaje literal, o null para el mensaje por defecto.
 */
function wa(?string $keyOrMsg = null): string
{
    $messages = WA_MESSAGES;
    $key = $keyOrMsg ?? 'default';
    $msg = $messages[$key] ?? ($keyOrMsg ?? $messages['default']);
    return WA_BASE . '?text=' . rawurlencode($msg);
}

/** Teléfono para href: tel() => 'tel:+18636513322'; tel(false) => '+18636513322'. */
function tel(bool $withScheme = true): string
{
    return ($withScheme ? 'tel:' : '') . TEL;
}

/** Incluye templates/partials/{name}.php en un scope aislado con solo $vars. Imprime. */
function partial(string $name, array $vars = []): void
{
    if (!preg_match('#^[a-z0-9][a-z0-9/_-]*$#', $name)) {
        return;
    }
    $file = NS_ROOT . '/templates/partials/' . $name . '.php';
    if (!is_file($file)) {
        error_log("partial() no encontrado: $name");
        echo '<!-- partial ' . e($name) . ' no encontrado -->';
        return;
    }
    (static function (string $__file, array $__vars): void {
        extract($__vars, EXTR_SKIP);
        include $__file;
    })($file, $vars);
}

// ---------------------------------------------------------------------
// Placeholders (§8.2 / §8.4)
// ---------------------------------------------------------------------

/**
 * Marca un dato pendiente del cliente: <span class="ph">[ texto ]</span> (texto escapado).
 * En ENV=production devuelve '' para que NINGÚN placeholder llegue a producción.
 */
function ph(string $text): string
{
    if (is_production()) {
        return '';
    }
    return '<span class="ph">[ ' . e($text) . ' ]</span>';
}

/** Convierte una cadena con tokens {{ph:TEXTO}} en HTML escapado, tokens vía ph(). */
function phtext(string $s): string
{
    $parts = preg_split('/(\{\{ph:.*?\}\})/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $out = '';
    foreach ($parts as $p) {
        $out .= preg_match('/^\{\{ph:(.*?)\}\}$/u', $p, $m) ? ph($m[1]) : e($p);
    }
    return $out;
}

/** Texto plano (JSON-LD) con los tokens como "[TEXTO]". */
function phplain(string $s): string
{
    return preg_replace_callback('/\{\{ph:(.*?)\}\}/u', static fn(array $m): string => '[' . $m[1] . ']', $s);
}

/** true si la cadena contiene algún token {{ph:...}}. */
function has_ph(string $s): bool
{
    return str_contains($s, '{{ph:');
}

// ---------------------------------------------------------------------
// FAQ
// ---------------------------------------------------------------------

/**
 * Ítems de FAQ de una clave de app/data/faq.php. En producción descarta los ítems
 * con tokens {{ph:...}} (respuestas incompletas). Devuelve [] si la clave no existe.
 */
function faq_items(?string $key): array
{
    if ($key === null || $key === '') {
        return [];
    }
    $items = data('faq')[$key] ?? [];
    return filter_faq($items);
}

/** Filtra ítems con placeholders en producción. */
function filter_faq(array $items): array
{
    if (!is_production()) {
        return array_values($items);
    }
    return array_values(array_filter(
        $items,
        static fn(array $i): bool => !has_ph(($i['q'] ?? '') . ($i['a'] ?? ''))
    ));
}

/** Ítems de FAQ de una ruta: por $route['faq'] o, en páginas de equipo, de equipment.php. */
function route_faq_items(array $route): array
{
    if (!empty($route['equip'])) {
        $eq = data('equipment')[$route['equip']] ?? null;
        return $eq ? filter_faq($eq['faq'] ?? []) : [];
    }
    return faq_items($route['faq'] ?? null);
}

// ---------------------------------------------------------------------
// SEO: canonical, OG, noindex
// ---------------------------------------------------------------------

/** URL canónica absoluta de la ruta. */
function canonical(array $route): string
{
    return abs_url($route['path'] ?? '/');
}

/** Imagen OG 1200x630 absoluta. $route['og'] la fija; si no, assets/img/og/{template}.jpg o og-default.jpg. */
function og_image(array $route): string
{
    $rel = $route['og'] ?? null;
    if ($rel === null) {
        // Imagen OG por plantilla si existe (img/og/<plantilla>.jpg); si no, la genérica.
        $try = 'img/og/' . ($route['template'] ?? 'default') . '.jpg';
        $rel = is_file(NS_ROOT . '/assets/' . $try) ? $try : 'img/og-default.jpg';
    }
    return 'https://' . DOMAIN . asset($rel);
}

/** true => <meta robots noindex> (staging, rutas draft o error). */
function noindex(?array $route = null): bool
{
    if (!is_production()) {
        return true;
    }
    return $route !== null && ($route['status'] ?? 'live') !== 'live';
}

// ---------------------------------------------------------------------
// Migas
// ---------------------------------------------------------------------

/**
 * Migas de la ruta: [['label'=>'Home','path'=>'/'], …, ['label'=>…,'path'=>ruta actual]].
 * Sigue la cadena opcional $route['parent']. El home devuelve solo [Home].
 */
function breadcrumbs(array $route): array
{
    $chain = [];
    $cur = $route;
    $guard = 0;
    while ($cur !== null && $guard++ < 6) {
        $chain[] = ['label' => $cur['breadcrumb'] ?? $cur['h1'], 'path' => $cur['path']];
        $cur = isset($cur['parent']) ? route_for($cur['parent']) : null;
    }
    $chain = array_reverse($chain);
    if (($route['path'] ?? '/') !== '/') {
        array_unshift($chain, ['label' => 'Home', 'path' => '/']);
    }
    return $chain;
}

// ---------------------------------------------------------------------
// JSON-LD
// ---------------------------------------------------------------------

/**
 * Imprime <script type="application/ld+json">. $graph: un nodo (con '@type') o lista de nodos
 * (se envuelven en @graph). Salida con UNESCAPED_SLASHES|UNESCAPED_UNICODE y "</" escapado.
 */
function schema_json(array $graph): void
{
    $graph = array_values(array_filter($graph, static fn($n) => $n !== null));
    if ($graph === []) {
        return;
    }
    $doc = isset($graph['@type'])
        ? ['@context' => 'https://schema.org'] + $graph
        : ['@context' => 'https://schema.org', '@graph' => $graph];
    $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $json = str_replace('</', '<\\/', $json);
    echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
}

/** Nodo Organization (o ProfessionalService con $type). Sin dirección hasta que el cliente la confirme. */
function organization_schema(string $type = 'Organization'): array
{
    return [
        '@type'       => $type,
        '@id'         => abs_url('/') . '#' . strtolower($type),
        'name'        => BRAND,
        'url'         => abs_url('/'),
        'logo'        => 'https://' . DOMAIN . '/assets/img/isotype.svg',
        'telephone'   => PHONE,
        'email'       => EMAIL,
        'areaServed'  => 'US',
        'description' => 'Remote truck dispatch and back-office desk for owner-operators and small fleets.',
    ];
}

/** Nodo Service de una ruta (provider = Organization). */
function service_schema(array $route): array
{
    return [
        '@type'       => 'Service',
        'name'        => $route['h1'],
        'description' => $route['meta'],
        'url'         => canonical($route),
        'serviceType' => $route['breadcrumb'] ?? $route['h1'],
        'areaServed'  => 'US',
        'provider'    => ['@id' => abs_url('/') . '#organization'],
    ];
}

/** Nodo FAQPage a partir de ítems [['q','a'],…]; null si no hay ítems. */
function faq_schema(array $items): ?array
{
    if ($items === []) {
        return null;
    }
    return [
        '@type'      => 'FAQPage',
        'mainEntity' => array_map(static fn(array $i): array => [
            '@type'          => 'Question',
            'name'           => phplain($i['q']),
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => phplain($i['a'])],
        ], $items),
    ];
}

/** Nodo BreadcrumbList de la ruta; null si solo hay una miga (home). */
function breadcrumb_schema(array $route): ?array
{
    $crumbs = breadcrumbs($route);
    if (count($crumbs) < 2) {
        return null;
    }
    $list = [];
    foreach ($crumbs as $i => $c) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['label'], 'item' => abs_url($c['path'])];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** Grafo JSON-LD completo de una ruta según $route['schema']. Pásalo a schema_json(). */
function page_schema(array $route): array
{
    $graph = [];
    foreach ($route['schema'] ?? [] as $type) {
        $graph[] = match ($type) {
            'Organization'        => organization_schema(),
            'ProfessionalService' => organization_schema('ProfessionalService'),
            'Service'             => service_schema($route),
            'FAQPage'             => faq_schema(route_faq_items($route)),
            'BreadcrumbList'      => breadcrumb_schema($route),
            'WebApplication'      => [
                '@type'               => 'WebApplication',
                'name'                => $route['h1'],
                'description'         => $route['meta'],
                'url'                 => canonical($route),
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem'     => 'Any',
                'offers'              => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
                'publisher'           => ['@id' => abs_url('/') . '#organization'],
            ],
            'Article'             => article_schema($route),
            'ItemList'            => item_list_schema($route),
            'DefinedTermSet'      => [
                '@type'           => 'DefinedTermSet',
                'name'            => $route['h1'],
                'url'             => canonical($route),
                'hasDefinedTerm'  => array_map(static fn(array $t): array => [
                    '@type' => 'DefinedTerm', 'name' => $t['term'], 'description' => $t['def'],
                    'url'   => canonical($route) . '#term-' . $t['id'],
                ], data('glossary')),
            ],
            default               => null,
        };
    }
    return array_values(array_filter($graph));
}

/** Nodo Article (autor "NorthStar dispatch desk"). datePublished solo si hay fecha real en posts.php. */
function article_schema(array $route): array
{
    $node = [
        '@type'            => 'Article',
        'headline'         => $route['h1'],
        'description'      => $route['meta'],
        'mainEntityOfPage' => canonical($route),
        'author'           => ['@type' => 'Organization', 'name' => 'NorthStar dispatch desk'],
        'publisher'        => ['@id' => abs_url('/') . '#organization'],
        'image'            => og_image($route),
    ];
    foreach (data('posts') as $p) {
        if (($p['slug'] ?? null) === ($route['slug'] ?? '') && !empty($p['date']) && !has_ph($p['date'])) {
            $node['datePublished'] = $p['date'];
        }
    }
    return $node;
}

/** ItemList para /blog/ (artículos) y /resources/ (tarjetas de resources). */
function item_list_schema(array $route): ?array
{
    $paths = [];
    if (($route['template'] ?? '') === 'blog') {
        foreach (data('posts') as $p) {
            if (($p['type'] ?? '') === 'article') {
                $paths[] = [$p['title'], $p['path']];
            }
        }
    } else {
        foreach (routes() as $key => $r) {
            if (($r['parent'] ?? null) === ($route['path'] ?? '') && ($r['status'] ?? '') === 'live') {
                $paths[] = [$r['breadcrumb'] ?? $r['h1'], $key];
            }
        }
    }
    if ($paths === []) {
        return null;
    }
    $items = [];
    foreach ($paths as $i => [$name, $path]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'url' => abs_url($path)];
    }
    return ['@type' => 'ItemList', 'itemListElement' => $items];
}

// ---------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------

/**
 * Renderiza una ruta: templates/pages/{template}.php -> $content -> templates/layout.php.
 * Equivale a: $content = ob_start/include pagina; include layout. Scope aislado: las plantillas
 * ven $route, $cfg y los helpers; el layout ve además $content.
 */
function render_route(array $route): void
{
    $template = (string) ($route['template'] ?? '');
    $page = NS_ROOT . '/templates/pages/' . $template . '.php';
    if (!preg_match('/^[a-z0-9-]+$/', $template) || !is_file($page)) {
        http_response_code(500);
        error_log("Plantilla no encontrada: $template");
        echo 'Template error.';
        return;
    }
    $cfg = cfg();
    $content = (static function (string $__page, array $route, array $cfg): string {
        ob_start();
        try {
            include $__page;
        } catch (Throwable $t) {
            ob_end_clean();
            throw $t;
        }
        return (string) ob_get_clean();
    })($page, $route, $cfg);

    (static function (array $route, array $cfg, string $content): void {
        include NS_ROOT . '/templates/layout.php';
    })($route, $cfg, $content);
}
