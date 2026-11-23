<?php
declare(strict_types=1);

use Captacion\Db;
use Captacion\Services\Templates;
use CaptacionTests\T;

/** Fase AO: regresiones de la revision visual (chips, botones, inputs de fecha, orden de plantillas, columnas, avisos). */
return static function (array $ctx): void {
    $root = $ctx['root'];
    $css = static fn(string $n): string => (string) file_get_contents($root . '/public/assets/' . $n . '.css');
    $view = static fn(string $n): string => (string) file_get_contents($root . '/views/' . $n . '.php');

    // 3: las variantes de chip llevan relleno y radio, definidas una sola vez en panel.css
    $panel = $css('panel');
    T::ok(preg_match('/\.chip--bad,\.chip--plain,\.chip--ok,\.chip--warm-o\{padding:0 8px;border-radius:var\(--r-pill\)\}/', $panel) === 1, 'chips: ok y warm-o comparten relleno y radio con bad y plain');
    foreach (['referidos', 'vacantes', 'prospectos', 'config', 'cfg-reglas'] as $n) {
        T::ok(!str_contains($css($n), '.chip--ok{') && !str_contains($css($n), '.chip--warm-o{'), "chips: $n no redefine .chip--ok ni .chip--warm-o");
    }
    T::eq(substr_count($panel, '.chip--ok{'), 1, 'chips: .chip--ok se define una vez en panel.css');
    T::eq(substr_count($panel, '.pg__n{'), 1, 'paginacion: .pg__n se define una vez en panel.css');
    // 1: el boton de nueva tarea lleva icono (en movil se oculta el texto)
    T::ok(str_contains($view('tareas'), "Ui::icon('plus') ?><span class=\"btn__t\">Nueva tarea"), 'tareas: «Nueva tarea» lleva icono');
    // 5, 7
    T::ok(str_contains($css('lead'), '.lead__back .link{display:inline-flex'), 'ficha: «Volver a la lista» en una sola linea');
    T::ok(str_contains($css('comms'), '.tbl--cm tbody th.c-n{order:-1}'), 'comunicaciones: el nombre de la plantilla va primero en movil');
    // 2: columnas de leads
    $leads = $css('leads');
    T::ok(str_contains($leads, '@container (max-width:1360px){') && str_contains($leads, '.c-resp{display:none}'), 'leads: umbrales de columnas con la columna Responsable');
    // 4
    T::ok(str_contains($view('config/_puntuacion'), '<span>Los tramos de experiencia est'), 'puntuacion: el aviso provisional envuelve el texto en un span');
    // 8, 9
    $cfg = $css('config');
    T::ok(str_contains($cfg, '.cfg-fs[disabled] .tgl input{opacity:.55') && !str_contains($cfg, '.cfg-fs[disabled] .btn,.cfg-fs[disabled] .tgl{opacity'), 'config bloqueada: la opacidad va al interruptor, no al texto de ayuda');
    T::ok(str_contains($cfg, '.cfg-fs[disabled] ::placeholder{'), 'config bloqueada: los placeholders se distinguen de los valores');
    // 12/13: «Aplicar» y «Confirmar» no son primarios; orden de la cabecera de leads
    foreach (['_lfilters' => 'Aplicar', '_tcols' => 'Aplicar', 'config' => 'Confirmar', 'actividad' => 'Guardar retención'] as $v => $t) {
        T::ok(preg_match('/btn--secondary[^>]*><span class="btn__t">' . preg_quote($t, '/') . '/u', $view($v)) === 1, "$v: «{$t}» es secundario");
    }
    $l = $view('leads');
    T::ok(strpos($l, 'Exportar CSV') < strpos($l, 'Importar CSV') && strpos($l, 'Importar CSV') < strpos($l, 'data-new-lead'), 'leads: Exportar, Importar y al final «Nuevo lead»');
    // 14: fechas en espanol
    $bad = [];
    foreach (glob($root . '/views/{,config/,comms/}*.php', GLOB_BRACE) as $f) {
        if (preg_match_all('/<input [^>]*type="(?:date|month|time|datetime-local)"[^>]*>/', (string) file_get_contents($f), $m)) {
            foreach ($m[0] as $tag) {
                if (!str_contains($tag, 'lang="es"')) {
                    $bad[] = basename($f);
                }
            }
        }
    }
    T::ok($bad === [], 'inputs de fecha, mes y hora llevan lang="es"' . ($bad ? ' · faltan en ' . implode(', ', $bad) : ''));
    // 15, 17, 18, 19, 22, 23, 29
    T::ok(str_contains($cfg, '.check--box input{flex:none'), 'canales: la casilla no se encoge');
    T::ok(str_contains($view('config/_usuarios'), "['Activa', 'ok']"), 'usuarios: «Activa» con punto verde');
    T::ok(str_contains($css('cfg-integraciones'), 'background:var(--sunk)') && str_contains($css('cfg-integraciones'), 'text-overflow:ellipsis'), 'integraciones: la URL del webhook como campo de solo lectura');
    T::ok(!str_contains($view('config/_correo'), "'warn' : 'ok')"), 'correo: el aviso del dia 7 usa la variante neutra');
    T::ok(str_contains($css('reportes'), '.rk{display:flex;flex-wrap:wrap') && str_contains($css('reportes'), '@container (max-width:520px)'), 'reportes: KPI que reparten el ancho y ficha movil solo en contenedores estrechos');
    T::ok(str_contains($panel, '.seg__i{display:inline-flex;align-items:center;gap:6px;white-space:nowrap'), 'segmentados: el texto de una opcion no se parte');
    T::ok(str_contains($css('calendario'), '@media (pointer:coarse){.cal__help{display:none}}'), 'calendario: sin ayuda de teclado en tactil');
    // /reportes sin $cnote: sin avisos de variable indefinida
    T::ok(str_contains($view('rep/_tendencia'), '$cnote = $cnote ??'), 'tendencia: $cnote tiene valor por defecto');
    // 21: orden natural de plantillas
    T::reset(true);
    $keys = array_column(Templates::all(), 'tkey');   // las de fabrica (a5, a6_d1 ... a6_d14, a11_*, a12_*, appt_*)
    $pos = static fn(string $k): int => (int) array_search($k, $keys, true);
    T::ok(array_search('a6_d14', $keys, true) !== false && $pos('a6_d1') < $pos('a6_d3') && $pos('a6_d3') < $pos('a6_d7') && $pos('a6_d7') < $pos('a6_d14'), 'plantillas: a6 en orden natural (d1, d3, d7, d14)');
    T::ok($pos('a6_d14') < $pos('a11_react_1') && $pos('a11_react_2') < $pos('a12_referral'), 'plantillas: a11 despues de a6 y antes de a12');
};
