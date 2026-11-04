<?php
/**
 * Build de assets (CSS) sin servidor: concatena y minifica el CSS de cada familia de plantillas en UN archivo, assets/css/dist/<familia>.css,
 * y escribe app/assets-manifest.json (fuentes con tamaño y crc32, tamaño y hash corto de cada dist).
 *
 * POR QUÉ: Hostinger solo ejecuta PHP y despliega por git (no corre builds). El build se corre en local y se COMMITEA su resultado (dist + manifiesto).
 * Sin dependencias: PHP 8.2+ puro (el minificador es conservador, ver ns_min_css()). templates/layout.php enlaza el dist de la ruta SOLO si el manifiesto
 * lo lista y todas sus fuentes (tamaño + crc32) y el dist (tamaño) coinciden con el disco; si no, cae a los archivos sueltos (app/assets.php).
 * Un CSS editado sin rebuild nunca rompe ni sirve CSS viejo: simplemente se vuelve a los 6 archivos separados hasta el siguiente build.
 *
 * Uso (desde la raíz del repo):
 *   php bin/build-assets.php            genera/actualiza assets/css/dist/*.css y app/assets-manifest.json
 *   php bin/build-assets.php --check    no escribe nada: sale con código 1 si el dist o el manifiesto no están al día (para CI o antes de commitear)
 *
 * Flujo del equipo: editar assets/css/**.css -> php bin/build-assets.php -> git add assets/css/dist app/assets-manifest.json -> commit.
 * Las familias salen de app/routes.php (cada combinación distinta de fuentes que usa alguna ruta, incluida la 404 y los drafts).
 *
 * JS: NO se minifica aquí (no hay una herramienta fiable instalada que el servidor ni el equipo tengan garantizada). Si el equipo acepta usar esbuild en local,
 * el comando medido es: esbuild assets/js/<archivo>.js --minify --target=es2017 --outfile=assets/js/dist/<archivo>.min.js (ver el reporte de la fase M).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('NS_ROOT', dirname(__DIR__));
require NS_ROOT . '/app/helpers.php';   // carga config, rutas y app/assets.php

const NS_DIST_DIR = 'css/dist';   // relativo a assets/

/** Ruta relativa de $to respecto al directorio $fromDir (ambas relativas a assets/, sin barra inicial). */
function ns_rel_path(string $fromDir, string $to): string
{
    $a = $fromDir === '' ? [] : explode('/', $fromDir);
    $b = explode('/', $to);
    while ($a && $b && $a[0] === $b[0] && count($b) > 1) {
        array_shift($a);
        array_shift($b);
    }
    return str_repeat('../', count($a)) . implode('/', $b);
}

/** Resuelve ../ y ./ de una ruta relativa a assets/. */
function ns_norm_path(string $p): string
{
    $out = [];
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($out);
        } else {
            $out[] = $seg;
        }
    }
    return implode('/', $out);
}

/** Reescribe la ruta de un url() RELATIVO (solo esas) del directorio de la fuente al del dist; data:, http(s), // y rutas absolutas no se tocan. */
function ns_rewrite_url(string $u, string $fromDir, string $toDir): string
{
    if ($u === '' || preg_match('#^(data:|https?:|//|/|\#)#i', $u) === 1) {
        return $u;
    }
    $suffix = '';
    if (preg_match('/^([^?#]*)([?#].*)$/', $u, $m) === 1) {
        [$u, $suffix] = [$m[1], $m[2]];
    }
    return ns_rel_path($toDir, ns_norm_path($fromDir . '/' . $u)) . $suffix;
}

/**
 * Minificador CSS CONSERVADOR para este proyecto (sin preprocesador, sin @import ni @charset). Hace solo cuatro cosas seguras:
 *  1. quita los comentarios (menos /*! ... *\/);
 *  2. colapsa cada hueco de espacios a uno solo y lo elimina junto a `{`, `}`, `;`, `,` y después de `:`;
 *  3. quita el último `;` antes de `}`;
 *  4. reescribe SOLO las rutas relativas de url() al nuevo directorio (el dist vive un nivel más abajo que fonts.css).
 * NUNCA toca: cadenas, el contenido de url() (data: URIs incluidas), el espacio antes de `:` (separa `a :hover` de `a:hover`), los espacios alrededor de
 * operadores de calc()/clamp()/min()/max(), los números (0.5 se queda 0.5, 0px se queda 0px), las custom properties ni el orden de las reglas.
 */
function ns_min_css(string $css, string $fromDir, string $toDir): string
{
    $n = strlen($css);
    $toks = [];   // ['t' => 'ws'|'x', 's' => texto]
    $i = 0;
    $last = '';   // último carácter significativo emitido: '{', '}' o ';' (o '' al inicio) significan "empieza una declaración"
    while ($i < $n) {
        $c = $css[$i];
        if ($c === '-' && ($css[$i + 1] ?? '') === '-' && ($last === '' || strpos('{};', $last) !== false) && preg_match('/\G--[A-Za-z0-9_-]+\s*:/', $css, $m, 0, $i) === 1) {
            // Custom property: el valor se copia TAL CUAL (sin tocar espacios), hasta el ';' o '}' que lo cierra fuera de cadenas y paréntesis.
            $j = $i + strlen($m[0]);
            $depth = 0;
            $q = '';
            while ($j < $n) {
                $d = $css[$j];
                if ($q !== '') {
                    if ($d === '\\') {
                        $j++;
                    } elseif ($d === $q) {
                        $q = '';
                    }
                } elseif ($d === '"' || $d === "'") {
                    $q = $d;
                } elseif ($d === '(' || $d === '[' || $d === '{') {
                    $depth++;
                } elseif (($d === ')' || $d === ']' || ($d === '}' && $depth > 0))) {
                    $depth--;
                } elseif ($depth === 0 && ($d === ';' || $d === '}')) {
                    break;
                }
                $j++;
            }
            $toks[] = ['t' => 'x', 's' => preg_replace('/\s*:\z/', ':', $m[0]) . trim(substr($css, $i + strlen($m[0]), $j - $i - strlen($m[0])))];
            $last = 'v';
            $i = $j;
            continue;
        }
        if ($c === '/' && ($css[$i + 1] ?? '') === '*') {
            $end = strpos($css, '*/', $i + 2);
            $end = $end === false ? $n : $end + 2;
            if (($css[$i + 2] ?? '') === '!') {
                $toks[] = ['t' => 'x', 's' => substr($css, $i, $end - $i)];
            } else {
                $toks[] = ['t' => 'ws', 's' => ' '];
            }
            $i = $end;
        } elseif ($c === '"' || $c === "'") {
            $j = $i + 1;
            while ($j < $n && $css[$j] !== $c) {
                $j += $css[$j] === '\\' ? 2 : 1;
            }
            $toks[] = ['t' => 'x', 's' => substr($css, $i, $j + 1 - $i)];
            $i = $j + 1;
        } elseif (($c === 'u' || $c === 'U') && strncasecmp(substr($css, $i, 4), 'url(', 4) === 0 && ($i === 0 || !preg_match('/[A-Za-z0-9_-]/', $css[$i - 1]))) {
            $j = $i + 4;
            while ($j < $n && ctype_space($css[$j])) {
                $j++;
            }
            if ($j < $n && ($css[$j] === '"' || $css[$j] === "'")) {
                $q = $css[$j];
                $k = $j + 1;
                while ($k < $n && $css[$k] !== $q) {
                    $k += $css[$k] === '\\' ? 2 : 1;
                }
                $inner = substr($css, $j + 1, $k - $j - 1);
                $k++;
                while ($k < $n && ctype_space($css[$k])) {
                    $k++;
                }
                $toks[] = ['t' => 'x', 's' => 'url(' . $q . ns_rewrite_url($inner, $fromDir, $toDir) . $q . ')'];
                $i = $k + 1;   // salta el ')'
            } else {
                $k = strpos($css, ')', $j);
                $k = $k === false ? $n : $k;
                $inner = trim(substr($css, $j, $k - $j));
                $toks[] = ['t' => 'x', 's' => 'url(' . ns_rewrite_url($inner, $fromDir, $toDir) . ')'];
                $i = $k + 1;
            }
        } elseif (ctype_space($c)) {
            $j = $i + 1;
            while ($j < $n && ctype_space($css[$j])) {
                $j++;
            }
            $toks[] = ['t' => 'ws', 's' => ' '];
            $i = $j;
        } else {
            $j = $i + 1;
            while ($j < $n && !ctype_space($css[$j]) && strpos("/\"'uU", $css[$j]) === false) {
                $j++;
            }
            $toks[] = ['t' => 'x', 's' => substr($css, $i, $j - $i)];
            $i = $j;
        }
        $t = end($toks);
        if ($t !== false && $t['t'] === 'x') {
            $last = substr($t['s'], -1);
        }
    }
    // Fusiona huecos consecutivos y decide cuáles sobran.
    $out = '';
    $pendingWs = false;
    foreach ($toks as $tk) {
        if ($tk['t'] === 'ws') {
            $pendingWs = true;
            continue;
        }
        $s = $tk['s'];
        if ($pendingWs && $out !== '') {
            $prev = $out[strlen($out) - 1];
            $first = $s[0];
            // huecos que se pueden quitar: tras { } ; , : y antes de { } ; ,   (NO antes de ':' ni alrededor de + - * /)
            $dropAfter = strpos('{};,:', $prev) !== false;
            $dropBefore = strpos('{};,', $first) !== false;
            if (!$dropAfter && !$dropBefore) {
                $out .= ' ';
            }
        }
        $pendingWs = false;
        // un ';' justo antes de '}' sobra
        if ($s[0] === '}' && $out !== '' && substr($out, -1) === ';') {
            $out = substr($out, 0, -1);
        }
        $out .= $s;
    }
    return trim($out);
}

/** Genera el contenido de un dist a partir de su lista de fuentes. */
function ns_build_css(string $name, array $sources): string
{
    $parts = ['/* NorthStar CSS · ' . $name . ' · generado por bin/build-assets.php, no editar a mano (fuentes: ' . implode(', ', array_map(static fn($s) => basename($s), $sources)) . ') */'];
    foreach ($sources as $s) {
        $raw = file_get_contents(NS_ROOT . '/assets/' . $s);
        if ($raw === false) {
            fwrite(STDERR, "No se puede leer assets/$s\n");
            exit(2);
        }
        $parts[] = ns_min_css($raw, dirname($s), NS_DIST_DIR);
    }
    return implode("\n", $parts) . "\n";
}

// ---- Familias: cada lista distinta de fuentes que usa alguna ruta ----
$families = [];
foreach (routes() as $key => $route) {
    $src = ns_css_sources($route);
    $name = ns_css_bundle_name($src);
    if (isset($families[$name]) && $families[$name] !== $src) {
        fwrite(STDERR, "Colisión de nombre de familia '$name' (la ruta $key usa otras fuentes)\n");
        exit(2);
    }
    $families[$name] = $src;
}
ksort($families);

// ---- Resultado esperado ----
$files = [];       // ruta relativa a assets/ => contenido
$manifest = ['version' => 1, 'generated_by' => 'bin/build-assets.php', 'css' => []];
foreach ($families as $name => $src) {
    $body = ns_build_css($name, $src);
    $rel = NS_DIST_DIR . '/' . $name . '.css';
    $files[$rel] = $body;
    $fs = [];
    foreach ($src as $s) {
        $fp = ns_asset_fingerprint($s);
        $fs[] = ['path' => $s, 'size' => $fp['size'], 'crc32' => $fp['crc32']];
    }
    $manifest['css'][$name] = ['file' => $rel, 'hash' => substr(sha1($body), 0, 10), 'bytes' => strlen($body), 'sources' => $fs];
}
$manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$manifestFile = NS_ROOT . '/app/assets-manifest.json';
$distDir = NS_ROOT . '/assets/' . NS_DIST_DIR;

// ---- Archivos sobrantes del dist ----
$stale = [];
foreach (is_dir($distDir) ? (scandir($distDir) ?: []) : [] as $f) {
    if ($f !== '.' && $f !== '..' && !isset($files[NS_DIST_DIR . '/' . $f])) {
        $stale[] = $f;
    }
}

$check = in_array('--check', $argv, true);
$problems = [];
foreach ($files as $rel => $body) {
    $f = NS_ROOT . '/assets/' . $rel;
    if (!is_file($f) || file_get_contents($f) !== $body) {
        $problems[] = "desactualizado: assets/$rel";
    }
}
if (!is_file($manifestFile) || file_get_contents($manifestFile) !== $manifestJson) {
    $problems[] = 'desactualizado: app/assets-manifest.json';
}
foreach ($stale as $f) {
    $problems[] = "sobrante: assets/" . NS_DIST_DIR . "/$f";
}

if ($check) {
    if ($problems) {
        fwrite(STDERR, implode("\n", $problems) . "\nEl dist no está al día: corre `php bin/build-assets.php` y commitea assets/css/dist y app/assets-manifest.json.\n");
        exit(1);
    }
    echo 'dist al día (' . count($files) . " archivos)\n";
    exit(0);
}

if (!is_dir($distDir) && !mkdir($distDir, 0775, true) && !is_dir($distDir)) {
    fwrite(STDERR, "No se puede crear $distDir\n");
    exit(2);
}
foreach ($files as $rel => $body) {
    file_put_contents(NS_ROOT . '/assets/' . $rel, $body);
}
foreach ($stale as $f) {
    unlink($distDir . '/' . $f);
}
file_put_contents($manifestFile, $manifestJson);
foreach ($manifest['css'] as $name => $e) {
    $gz = strlen((string) gzencode($files[$e['file']], 6));
    printf("%-26s %7d bytes  %6d gzip  ?v=%s  (%d fuentes)\n", $name, $e['bytes'], $gz, $e['hash'], count($e['sources']));
}
echo count($files) . ' archivos en assets/' . NS_DIST_DIR . "/, manifiesto en app/assets-manifest.json\n";
