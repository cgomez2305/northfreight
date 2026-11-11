<?php
/**
 * Inglés y español (fase AE). El sitio es inglés (en-US) en la raíz y español (es-US) bajo /es/.
 *
 * Cómo funciona:
 *  - app/i18n/routes.es.php  mapa  ruta inglesa => ['path' => '/es/…', title, meta, h1, breadcrumb]. routes() clona cada ruta traducida con su path español, `lang` = 'es'
 *    y `en_path`; la ruta inglesa recibe `es_path`. Las plantillas, los datos y los partials son los MISMOS en los dos idiomas.
 *  - app/i18n/es/*.php       diccionario  texto inglés exacto (normalizado: espacios colapsados) => texto español. Un diccionario por área.
 *  - ns_translate_html()     filtro final de render_route(): traduce nodos de texto, atributos con texto (alt, title, aria-label, placeholder, content, data-*)
 *                            y las cadenas del JSON-LD cuyo texto exacto está en el diccionario. Lo que no está se queda en inglés y bin/i18n.php audit lo lista.
 *  - url() y wa() son conscientes del idioma: en una página española los enlaces internos apuntan a la versión española cuando existe y el mensaje de WhatsApp sale en español.
 * Reglas: nada de guiones largos (— –) en el español visible; términos del oficio en inglés (dispatch, owner-operator, Amazon Relay, dry van, BOL, POD, IFTA, load board, rate con,
 * detention); tuteo; los textos son traducción fiel: no se afirma nada que el sitio inglés no afirme.
 */

declare(strict_types=1);

/** Idioma activo de la petición: 'en' | 'es'. */
function ns_lang(): string
{
    return $GLOBALS['__ns_lang'] ?? 'en';
}

function ns_set_lang(string $lang): void
{
    $GLOBALS['__ns_lang'] = $lang === 'es' ? 'es' : 'en';
}

function ns_is_es(): bool
{
    return ns_lang() === 'es';
}

/** Mapa de rutas traducidas: ruta inglesa => metadatos españoles (app/i18n/routes.es.php). */
function ns_es_routes(): array
{
    static $m = null;
    if ($m === null) {
        $m = is_file(NS_ROOT . '/app/i18n/routes.es.php') ? (array) require NS_ROOT . '/app/i18n/routes.es.php' : [];
    }
    return $m;
}

/** Ruta española de una ruta inglesa ('/contact/' => '/es/contacto/') o null si no hay traducción. */
function ns_es_path_of(string $enPath): ?string
{
    $m = ns_es_routes()[$enPath] ?? null;
    return is_array($m) ? (string) $m['path'] : null;
}

/** Ruta inglesa de una ruta española o null. */
function ns_en_path_of(string $esPath): ?string
{
    static $rev = null;
    if ($rev === null) {
        $rev = [];
        foreach (ns_es_routes() as $en => $m) {
            $rev[(string) $m['path']] = $en;
        }
    }
    return $rev[$esPath] ?? null;
}

/** Ruta del idioma pedido para un path cualquiera (idempotente): si no hay traducción devuelve el path tal cual. */
function ns_path_for_lang(string $path, string $lang): string
{
    if ($lang === 'es') {
        return ns_es_path_of($path) ?? $path;
    }
    return ns_en_path_of($path) ?? $path;
}

/** Clave inglesa de una ruta (para datos indexados por ruta: resúmenes, etc.). */
function ns_en_key(array $route): string
{
    return (string) ($route['en_path'] ?? $route['path'] ?? '');
}

/**
 * Añade las rutas españolas al mapa de rutas (llamado por routes()). Clona cada ruta inglesa traducida con title/meta/h1/breadcrumb propios,
 * y marca la inglesa con `es_path`. La clave '404' tiene su gemela '404es'.
 */
function ns_add_es_routes(array $routes): array
{
    $extra = [];
    foreach (ns_es_routes() as $enPath => $m) {
        if (!isset($routes[$enPath])) {
            continue;
        }
        $r = $routes[$enPath];
        $esPath = (string) $m['path'];
        foreach (['title', 'meta', 'h1', 'h1_hl', 'breadcrumb', 'schema_name', 'og_alt'] as $k) {
            if (isset($m[$k])) {
                $r[$k] = $m[$k];
            }
        }
        $r['path'] = $esPath;
        $r['lang'] = 'es';
        $r['en_path'] = $enPath;
        if (isset($r['parent'])) {
            $r['parent'] = ns_es_path_of((string) $r['parent']) ?? $r['parent'];
        }
        if (!empty($r['crumbs']) && is_array($r['crumbs'])) {
            foreach ($r['crumbs'] as $i => $c) {
                $p = $c['path'] ?? $c[1] ?? null;
                if (is_string($p) && ($q = ns_es_path_of($p)) !== null) {
                    $r['crumbs'][$i] = ['label' => $c['label'] ?? $c[0] ?? '', 'path' => $q];
                }
            }
        }
        if ((string) $enPath === '404') {
            $extra['404es'] = $r;
        } else {
            $extra[$esPath] = $r;
            $routes[$enPath]['es_path'] = $esPath;
        }
    }
    foreach ($routes as $k => $r) {
        $routes[$k]['lang'] = $routes[$k]['lang'] ?? 'en';
    }
    return $routes + $extra;
}

// ---------------------------------------------------------------------
// hreflang y selector de idioma
// ---------------------------------------------------------------------

/** Pares hreflang de una ruta: [['hreflang' => 'en-us', 'href' => 'https://…'], …] (vacío si la ruta no tiene versión española o no se indexa). */
function ns_hreflang_links(array $route): array
{
    if (($route['status'] ?? 'live') !== 'live' || !empty($route['noindex']) || ($route['status'] ?? '') === 'error') {
        return [];
    }
    $en = ns_en_key($route);
    $es = ns_es_path_of($en);
    if ($es === null || $en === '404') {
        return [];
    }
    $abs = static fn(string $p): string => 'https://' . DOMAIN . $p;
    return [
        ['hreflang' => 'en-us', 'href' => $abs($en)],
        ['hreflang' => 'es-us', 'href' => $abs($es)],
        ['hreflang' => 'x-default', 'href' => $abs($en)],
    ];
}

/** Destino del selector de idioma: la misma página en el otro idioma o, si no existe, la home del otro idioma. */
function ns_switch_target(?array $route): array
{
    $isEs = (($route['lang'] ?? 'en') === 'es');
    $en = $route !== null ? ns_en_key($route) : '/';
    if ($isEs) {
        $href = ($en !== '' && $en !== '404' && route_for($en) !== null) ? $en : '/';
        return ['lang' => 'en', 'hreflang' => 'en-US', 'label' => 'EN', 'name' => 'English', 'href' => $href];
    }
    $es = ($en !== '' && $en !== '404') ? ns_es_path_of($en) : null;
    return ['lang' => 'es', 'hreflang' => 'es-US', 'label' => 'ES', 'name' => 'Español', 'href' => $es ?? '/es/'];
}

// ---------------------------------------------------------------------
// Diccionario
// ---------------------------------------------------------------------

/** Normaliza un texto para buscarlo en el diccionario: decodifica entidades, colapsa espacios y recorta. */
function ns_norm(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/** Diccionario inglés => español (todas las partes de app/i18n/es/*.php, claves normalizadas). */
function ns_dict(): array
{
    static $d = null;
    if ($d === null) {
        $d = [];
        $files = glob(NS_ROOT . '/app/i18n/es/*.php') ?: [];
        sort($files);
        foreach ($files as $f) {
            $part = require $f;
            if (is_array($part)) {
                foreach ($part as $k => $v) {
                    $d[ns_norm((string) $k)] = (string) $v;
                }
            }
        }
        // Los textos de app/i18n/routes.es.php ya están en español: identidad (así no se auditan como «sin traducir»).
        foreach (ns_es_routes() as $m) {
            foreach (['title', 'meta', 'h1', 'breadcrumb', 'og_alt'] as $f) {
                if (isset($m[$f]) && is_string($m[$f])) {
                    $d[ns_norm($m[$f])] ??= $m[$f];
                }
            }
        }
    }
    return $d;
}

/** Traduce una cadena si la página es española y el texto está en el diccionario; si no, la devuelve igual. */
function ns_t(string $s): string
{
    if (!ns_is_es()) {
        return $s;
    }
    $norm = ns_norm($s);
    $d = ns_dict();
    if (isset($d[$norm])) {
        return $d[$norm];
    }
    if (isset($GLOBALS['__ns_i18n_miss']) && ns_has_letters($norm)) {
        $GLOBALS['__ns_i18n_miss'][$norm] = true;
    }
    return $s;
}

/** true si la cadena tiene letras (las demás no se traducen ni se auditan). */
function ns_has_letters(string $s): bool
{
    return preg_match('/\pL{2,}/u', $s) === 1;
}

/** Atributos cuyo valor es texto visible o accesible. */
const NS_I18N_ATTRS = 'alt|title|aria-label|aria-description|placeholder|content|data-who|data-msg|data-svc|data-label|data-empty|data-note|data-ok|data-err|data-text';

/**
 * Traduce el HTML final de una página española. $missing (opcional) recoge las cadenas con letras que no están en el diccionario
 * (['text' => [...], 'attr' => [...], 'ld' => [...]]).
 */
function ns_translate_html(string $html, ?array &$missing = null): string
{
    $dict = ns_dict();
    $miss = static function (string $kind, string $norm) use (&$missing): void {
        if ($missing !== null && ns_has_letters($norm)) {
            $missing[$kind][$norm] = ($missing[$kind][$norm] ?? 0) + 1;
        }
    };

    // Bloques que NO se traducen (selector de idioma: «English» / «Español» van siempre en su propio idioma).
    $keep = [];
    $html = (string) preg_replace_callback('~<(a|p)\b[^>]*\b(?:data-i18n-skip|data-textfill)\b[^>]*>.*?</\1>~is', static function (array $m) use (&$keep): string {
        $keep[] = $m[0];
        return '<!--NSKEEP' . (count($keep) - 1) . '-->';
    }, $html);

    $parts = preg_split('~(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<!--.*?-->)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return $html;
    }
    foreach ($parts as $i => $p) {
        if ($i % 2 === 1) {
            if (stripos($p, 'application/ld+json') !== false) {
                $parts[$i] = ns_translate_jsonld($p, $miss);
            }
            continue;
        }
        // Atributos con texto
        $p = preg_replace_callback('~(\s(?:' . NS_I18N_ATTRS . ')=")([^"]*)(")~i', static function (array $m) use ($dict, $miss): string {
            $norm = ns_norm($m[2]);
            if ($norm === '' || !ns_has_letters($norm)) {
                return $m[0];
            }
            if (isset($dict[$norm])) {
                return $m[1] . htmlspecialchars($dict[$norm], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[3];
            }
            $miss('attr', $norm);
            return $m[0];
        }, (string) $p);
        // Nodos de texto
        $p = preg_replace_callback('~>([^<>]+)<~', static function (array $m) use ($dict, $miss): string {
            $raw = $m[1];
            $norm = ns_norm($raw);
            if ($norm === '' || !ns_has_letters($norm)) {
                return $m[0];
            }
            if (!isset($dict[$norm])) {
                $miss('text', $norm);
                return $m[0];
            }
            preg_match('/^\s*/u', $raw, $lead);
            preg_match('/\s*$/u', $raw, $trail);
            return '>' . $lead[0] . htmlspecialchars($dict[$norm], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . $trail[0] . '<';
        }, (string) $p);
        $parts[$i] = (string) $p;
    }
    $out = implode('', $parts);
    foreach ($keep as $i => $block) {
        $out = str_replace('<!--NSKEEP' . $i . '-->', $block, $out);
    }
    return $out;
}

/** Traduce las cadenas de un bloque <script type="application/ld+json">. */
function ns_translate_jsonld(string $block, callable $miss): string
{
    if (!preg_match('~^(<script\b[^>]*>)(.*)(</script>)$~is', $block, $m)) {
        return $block;
    }
    $data = json_decode(str_replace('<\\/', '</', $m[2]), true);
    if (!is_array($data)) {
        return $block;
    }
    $dict = ns_dict();
    // Claves cuyo valor es un identificador técnico (tipo schema.org, URL, moneda, código de idioma…): nunca se traducen.
    $skip = ['@type' => 1, '@context' => 1, '@id' => 1, 'url' => 1, 'sameAs' => 1, 'priceCurrency' => 1, 'telephone' => 1, 'email' => 1, 'inLanguage' => 1,
        'datePublished' => 1, 'dateModified' => 1, 'item' => 1, 'image' => 1, 'logo' => 1, 'contentUrl' => 1, 'thumbnailUrl' => 1, 'areaServed' => 1, 'applicationCategory' => 1,
        'operatingSystem' => 1, 'availability' => 1, 'price' => 1, 'position' => 1, 'contactType' => 1, 'availableLanguage' => 1];   // fase AF: identificadores técnicos
    $walk = static function (mixed $n, string $key = '') use (&$walk, $dict, $miss, $skip): mixed {
        if (is_array($n)) {
            foreach ($n as $k => $v) {
                $n[$k] = $walk($v, is_string($k) ? $k : $key);
            }
            return $n;
        }
        if (isset($skip[$key]) || !is_string($n) || str_starts_with($n, 'http') || !ns_has_letters($n)) {
            return $n;
        }
        $norm = ns_norm($n);
        if (isset($dict[$norm])) {
            return $dict[$norm];
        }
        $miss('ld', $norm);
        return $n;
    };
    $data = $walk($data);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return $block;
    }
    return $m[1] . str_replace('</', '<\\/', $json) . $m[3];
}
