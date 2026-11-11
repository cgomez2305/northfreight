<?php
/**
 * Herramienta del español (fase AE). Solo CLI; bin/ está bloqueado en la web.
 *
 *   php bin/i18n.php extract [ruta-inglesa ...]   Renderiza las páginas españolas con el diccionario VACÍO y escribe storage/i18n/strings.json:
 *                                                 todo el texto (nodos, atributos, JSON-LD, mensajes de WhatsApp) que hay que traducir, con las páginas donde sale.
 *   php bin/i18n.php audit [--list] [ruta ...]    Renderiza las páginas españolas con el diccionario real y cuenta lo que sigue en inglés. Sale con 1 si queda algo.
 *   php bin/i18n.php merge <archivo.json> <area>  Convierte un JSON {"inglés": "español"} en app/i18n/es/<area>.php (sobrescribe).
 *   php bin/i18n.php check                        Comprueba las rutas españolas: title <= 60, meta <= 155, H1 único, títulos únicos, pares hreflang, sin guiones largos
 *                                                 en el diccionario ni en title/meta, claves del diccionario duplicadas entre áreas.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('NS_ROOT', dirname(__DIR__));
$_SERVER += ['HTTP_HOST' => 'localhost', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SERVER_NAME' => 'localhost'];
require NS_ROOT . '/app/helpers.php';
require NS_ROOT . '/app/forms/lib.php';
error_reporting(E_ALL);
ini_set('display_errors', '1');

$cmd = $argv[1] ?? '';
$args = array_slice($argv, 2);
$list = in_array('--list', $args, true);
$args = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--')));

/** Rutas españolas live (clave => ruta), opcionalmente filtradas por ruta inglesa. */
function es_routes(array $only): array
{
    $out = [];
    foreach (routes() as $key => $r) {
        if (($r['lang'] ?? 'en') !== 'es') {
            continue;
        }
        if ($key !== '404es' && ($r['status'] ?? 'live') !== 'live') {
            continue;
        }
        if ($only && !in_array((string) ($r['en_path'] ?? ''), $only, true)) {
            continue;
        }
        $out[$key] = $r;
    }
    return $out;
}

/** Renderiza una ruta en silencio y devuelve el HTML final. */
function render_quiet(array $route): string
{
    $html = render_route_html($route);
    return $html ?? '';
}

switch ($cmd) {
    case 'extract':
    case 'audit':
        // Renderiza cada página española; render_route_html() recoge en $GLOBALS['__ns_i18n_missing'] lo que no está en el diccionario.
        $total = 0;
        $byString = [];
        foreach (es_routes($args) as $key => $r) {
            $GLOBALS['__ns_i18n_miss'] = [];
            $GLOBALS['__ns_i18n_missing'] = ['text' => [], 'attr' => [], 'ld' => []];
            ns_set_lang('es');
            render_route_html($r);
            $n = 0;
            foreach ($GLOBALS['__ns_i18n_missing'] as $kind => $rows) {
                foreach ($rows as $s => $c) {
                    $byString[$s]['pages'][$key] = true;
                    $byString[$s]['kinds'][$kind] = true;
                    $n++;
                }
            }
            foreach (array_keys($GLOBALS['__ns_i18n_miss']) as $s) {
                $byString[$s]['pages'][$key] = true;
                $byString[$s]['kinds']['call'] = true;
                $n++;
            }
            $total += $n;
            if ($cmd === 'audit') {
                printf("%-60s %4d sin traducir\n", $key, $n);
            }
        }
        ksort($byString);
        if ($cmd === 'extract') {
            @mkdir(NS_ROOT . '/storage/i18n', 0750, true);
            $out = [];
            foreach ($byString as $s => $m) {
                $out[] = ['en' => $s, 'kinds' => array_keys($m['kinds']), 'pages' => array_keys($m['pages'])];
            }
            file_put_contents(NS_ROOT . '/storage/i18n/strings.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            echo count($out) . " cadenas sin traducir -> storage/i18n/strings.json\n";
            exit(0);
        }
        if ($list) {
            echo "\n";
            foreach ($byString as $s => $m) {
                printf("[%s] %s  (%s)\n", implode('/', array_keys($m['kinds'])), $s, implode(', ', array_slice(array_keys($m['pages']), 0, 3)));
            }
        }
        echo "\nTotal: $total apariciones, " . count($byString) . " cadenas únicas sin traducir\n";
        exit($total > 0 ? 1 : 0);

    case 'merge':
        $file = $args[0] ?? '';
        $area = preg_replace('/[^a-z0-9-]/', '', (string) ($args[1] ?? ''));
        if (!is_file($file) || $area === '') {
            fwrite(STDERR, "uso: php bin/i18n.php merge <archivo.json> <area>\n");
            exit(2);
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            fwrite(STDERR, "JSON inválido\n");
            exit(2);
        }
        ksort($data);
        $php = "<?php\n/** Diccionario inglés => español, área {$area} (fase AE). Claves = texto inglés exacto (normalizado); generado con bin/i18n.php merge. */\n\nreturn [\n";
        foreach ($data as $en => $es) {
            if (!is_string($es) || $es === '') {
                continue;
            }
            $php .= '    ' . var_export((string) $en, true) . ' => ' . var_export($es, true) . ",\n";
        }
        $php .= "];\n";
        @mkdir(NS_ROOT . '/app/i18n/es', 0755, true);
        file_put_contents(NS_ROOT . '/app/i18n/es/' . $area . '.php', $php);
        echo count($data) . " entradas -> app/i18n/es/$area.php\n";
        exit(0);

    case 'check':
        $errors = 0;
        $titles = [];
        $h1s = [];
        $dash = static fn(string $s): bool => preg_match('/[—–]/u', $s) === 1;
        foreach (es_routes([]) as $key => $r) {
            $t = (string) $r['title'];
            $m = (string) $r['meta'];
            if (mb_strlen($t) > 60) { echo "TITLE > 60 ($key): " . mb_strlen($t) . "\n"; $errors++; }
            if (mb_strlen($m) > 155) { echo "META > 155 ($key): " . mb_strlen($m) . "\n"; $errors++; }
            if ($dash($t . $m . (string) $r['h1'])) { echo "guion largo en title/meta/h1 ($key)\n"; $errors++; }
            if (isset($titles[$t])) { echo "TITLE repetido: $t\n"; $errors++; }
            if (isset($h1s[(string) $r['h1']])) { echo "H1 repetido: {$r['h1']}\n"; $errors++; }
            $titles[$t] = true;
            $h1s[(string) $r['h1']] = true;
            if ($key !== '404es' && ns_hreflang_links($r) === [] && empty($r['noindex'])) { echo "sin hreflang: $key\n"; $errors++; }
        }
        $seen = [];
        foreach (glob(NS_ROOT . '/app/i18n/es/*.php') ?: [] as $f) {
            foreach ((array) require $f as $en => $es) {
                $k = ns_norm((string) $en);
                if ($dash((string) $es)) { echo 'guion largo en ' . basename($f) . ': ' . mb_substr((string) $es, 0, 60) . "\n"; $errors++; }
                if (isset($seen[$k]) && $seen[$k] !== basename($f)) { echo "clave repetida en {$seen[$k]} y " . basename($f) . ': ' . mb_substr($k, 0, 60) . "\n"; }
                $seen[$k] = basename($f);
            }
        }
        echo $errors === 0 ? "check OK\n" : "$errors problemas\n";
        exit($errors === 0 ? 0 : 1);

    default:
        fwrite(STDERR, "uso: php bin/i18n.php extract|audit|merge|check\n");
        exit(2);
}
