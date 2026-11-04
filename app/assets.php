<?php
/**
 * Assets: qué CSS carga cada ruta y el uso del build (assets/css/dist/*.css) con respaldo a los archivos sueltos.
 *
 * Lo comparten templates/layout.php (en cada petición) y bin/build-assets.php (en el build local). Hostinger solo ejecuta PHP y despliega por git:
 * el build se corre en local y se commitea su resultado (ver bin/build-assets.php). El layout usa un archivo dist SOLO si app/assets-manifest.json lo
 * lista y todas sus fuentes (tamaño + crc32) y el propio dist (tamaño) coinciden con el disco; si no, sirve los archivos sueltos de siempre,
 * así un CSS editado sin rebuild nunca rompe ni sirve CSS viejo. La comprobación cuesta microsegundos (lee unos 100 KB y calcula crc32).
 */

/** Plantilla => grupo de CSS de página (assets/css/pages/<grupo>.css). La ruta puede anularlo con 'css' => ['grupo', …] ([] = sin grupo). */
function ns_css_group_map(): array
{
    return [
        'home' => 'home',
        'owner' => 'services-a', 'relay' => 'services-a', 'dryvan' => 'services-a', 'equipment' => 'services-a',
        'tracking' => 'services-b', 'backoffice' => 'services-b', 'how' => 'services-b', 'desk' => 'services-b', 'contact' => 'services-b',
        'legal' => 'company', '404' => 'company', 'resources' => 'company', 'doc-bol' => 'company', 'doc-agreement' => 'company',
        'glossary' => 'tools', 'tool-rpm' => 'tools', 'tool-ifta' => 'tools',
        'blog' => 'blog', 'post' => 'blog',
        'careers' => 'careers', 'careers-owner' => 'careers', 'apply' => 'careers', 'thanks' => 'careers', 'cdl-hub' => 'careers', 'job' => 'careers',
    ];
}

/**
 * Archivos CSS (relativos a assets/, EN ORDEN DE CASCADA) que necesita una ruta: núcleo común (fuentes, tokens, base, components) + el grupo de página
 * + motion.css si la ruta carga GSAP. Solo incluye archivos que existen.
 *
 * @return string[]
 */
function ns_css_sources(array $route): array
{
    $template = (string) ($route['template'] ?? '');
    $map = ns_css_group_map();
    $groups = isset($route['css']) ? (array) $route['css'] : (isset($map[$template]) ? [$map[$template]] : []);
    $css = ['css/fonts.css', 'css/tokens.css', 'css/base.css', 'css/components.css'];
    foreach ($groups as $g) {
        $g = basename((string) $g, '.css');
        if (preg_match('/^[a-z0-9-]+$/', $g) === 1 && is_file(NS_ROOT . '/assets/css/pages/' . $g . '.css')) {
            $css[] = 'css/pages/' . $g . '.css';
        }
    }
    if (!empty($route['motion']) && is_file(NS_ROOT . '/assets/css/motion.css')) {
        $css[] = 'css/motion.css';   // reglas que necesitan las escenas (todas bajo html.js-motion)
    }
    return $css;
}

/** Nombre del archivo dist de una lista de fuentes CSS: núcleo + grupos + motion, p. ej. "home-motion", "services-b-motion" o "core". */
function ns_css_bundle_name(array $sources): string
{
    $parts = [];
    foreach ($sources as $s) {
        if (str_starts_with($s, 'css/pages/')) {
            $parts[] = basename($s, '.css');
        } elseif ($s === 'css/motion.css') {
            $parts[] = 'motion';
        }
    }
    return $parts ? implode('-', $parts) : 'core';
}

/** crc32 (hex de 8) y tamaño de un archivo de assets/, o null si no existe. */
function ns_asset_fingerprint(string $rel): ?array
{
    $file = NS_ROOT . '/assets/' . $rel;
    if (!is_file($file)) {
        return null;
    }
    $crc = hash_file('crc32b', $file);
    $size = filesize($file);
    return $crc === false || $size === false ? null : ['size' => $size, 'crc32' => $crc];
}

/** Manifiesto del build (app/assets-manifest.json) o [] si falta o no es válido. Se lee una vez por petición. */
function ns_assets_manifest(): array
{
    static $m = null;
    if ($m === null) {
        $m = [];
        $file = NS_ROOT . '/app/assets-manifest.json';
        $raw = is_file($file) ? file_get_contents($file) : false;
        $j = $raw === false ? null : json_decode($raw, true);
        if (is_array($j) && ($j['version'] ?? null) === 1) {
            $m = $j;
        }
    }
    return $m;
}

/**
 * Archivo CSS dist vigente para una lista de fuentes (la de ns_css_sources), o null (=> usar los archivos sueltos).
 *
 * @return array{url:string}|null  url lista para href (con ?v=hash del contenido: estable entre despliegues, a diferencia de filemtime)
 */
function ns_dist_css(array $sources): ?array
{
    $e = ns_assets_manifest()['css'][ns_css_bundle_name($sources)] ?? null;
    if (!is_array($e) || !isset($e['file'], $e['hash'], $e['bytes'], $e['sources']) || !is_array($e['sources']) || count($e['sources']) !== count($sources)) {
        return null;
    }
    foreach ($sources as $i => $s) {
        $m = $e['sources'][$i] ?? null;
        $fp = ns_asset_fingerprint($s);
        if (!is_array($m) || $fp === null || ($m['path'] ?? null) !== $s || ($m['size'] ?? null) !== $fp['size'] || ($m['crc32'] ?? null) !== $fp['crc32']) {
            return null;
        }
    }
    $file = NS_ROOT . '/assets/' . $e['file'];
    if (!is_file($file) || filesize($file) !== $e['bytes']) {
        return null;
    }
    return ['url' => '/assets/' . $e['file'] . '?v=' . $e['hash']];
}

/**
 * Versión minificada vigente de un JS propio (assets/js/<archivo>), o null (=> usar el archivo original).
 * Mismo mecanismo que el CSS: el manifiesto (sección "js") debe listar el archivo, su fuente debe coincidir con el disco (tamaño + crc32) y el
 * .min.js debe existir con el tamaño registrado. Un JS editado sin rebuild nunca sirve código viejo: simplemente se vuelve al original.
 *
 * @return string|null  url lista para src (con ?v=hash del contenido minificado)
 */
function ns_dist_js(string $file): ?string
{
    $e = ns_assets_manifest()['js']['files'][basename($file, '.js')] ?? null;
    if (!is_array($e) || !isset($e['file'], $e['hash'], $e['bytes'], $e['source']) || !is_array($e['source'])) {
        return null;
    }
    $fp = ns_asset_fingerprint('js/' . $file);
    if ($fp === null || ($e['source']['path'] ?? null) !== 'js/' . $file || ($e['source']['size'] ?? null) !== $fp['size'] || ($e['source']['crc32'] ?? null) !== $fp['crc32']) {
        return null;
    }
    $min = NS_ROOT . '/assets/' . $e['file'];
    if (!is_file($min) || filesize($min) !== $e['bytes']) {
        return null;
    }
    return '/assets/' . $e['file'] . '?v=' . $e['hash'];
}
