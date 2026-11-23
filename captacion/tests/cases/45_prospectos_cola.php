<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Prospects;
use Captacion\Services\UsTimeZones;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase AO (revisión visual del panel, 2026-11-23): Prospectos como cola de llamadas (filas compactas, llamables primero, filtro «Solo llamables ahora»),
 * paginación con estilos en TODAS las pantallas que la usan y la hoja de atajos que se abría con cualquier clic (regresión de la fase AM).
 */
return static function (array $ctx): void {
    T::reset(true);
    $root = $ctx['root'];
    $assets = $root . '/public/assets';

    // ------------------------------------------------------------------ Regresión: cualquier clic abría la hoja de atajos
    $layout = (string) file_get_contents($root . '/views/layout.php');
    $ux = (string) file_get_contents($assets . '/ux.js');
    T::ok(!preg_match('/<html[^\n]*data-keys=/', $layout), 'la preferencia de atajos NO va en <html data-keys> (ux.js engancha «abrir atajos» a [data-keys] y cualquier clic llegaba a <html>)');
    T::ok(str_contains($layout, 'data-kbd="') && str_contains($ux, "H.getAttribute('data-kbd')"), '  va en data-kbd y ux.js la lee de ahí');
    T::ok(!str_contains($ux, "\$\$('[data-keys]')") && str_contains($ux, "\$\$('button[data-keys]')"), '  el botón «Ver los atajos» se engancha solo a botones');

    // ------------------------------------------------------------------ Paginación: estilos compartidos, no por pantalla
    $panelCss = (string) file_get_contents($assets . '/panel.css');
    T::ok(str_contains($panelCss, '.pg ul{display:flex'), 'paginación: sus estilos viven en panel.css (los carga toda pantalla con sesión)');
    foreach (['leads', 'dashboard', 'prospectos', 'actividad'] as $sheet) {
        T::ok(!str_contains((string) @file_get_contents("$assets/$sheet.css"), '.pg ul{'), "  y no se duplican en $sheet.css");
    }

    // ------------------------------------------------------------------ Llamables ahora: estados en horario y orden
    $now = Clock::time();
    $ok = Prospects::callableStates($now);
    foreach (['TX', 'CA', 'NY', 'FL', 'HI'] as $st) {
        if (isset(UsTimeZones::ZONES[$st])) {
            T::eq(in_array($st, $ok, true), UsTimeZones::check($st, $now)['allowed'], "callableStates coincide con el horario de $st");
        }
    }
    // dos prospectos: uno en un estado en horario y otro fuera (si a esta hora existen ambos casos)
    $out = array_values(array_diff(array_keys(UsTimeZones::ZONES), $ok));
    $mk = static fn(string $dot, ?string $st, ?string $phone, string $granted) => (int) Db::insert('prospects_fmcsa', ['usdot' => $dot, 'legal_name' => "Cola $dot LLC", 'state' => $st, 'phone' => $phone,
        'authority_granted_at' => $granted, 'status' => 'to_call', 'listed_on' => '2026-11-20', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    if ($ok && $out) {
        $late = $mk('9200001', $out[0], '+13055550101', '2026-11-21');   // autoridad más nueva pero fuera de horario
        $now1 = $mk('9200002', $ok[0], '+13055550102', '2026-11-01');    // más vieja pero llamable ya
        $noPh = $mk('9200003', $ok[0], null, '2026-11-22');              // en horario pero sin teléfono: no cuenta como llamable
        $f = Prospects::filters([]);
        $ids = array_map(static fn($r) => (int) $r['id'], Prospects::page($f, $now, 1, 25)['rows']);
        T::ok(array_search($now1, $ids, true) < array_search($late, $ids, true) && array_search($now1, $ids, true) < array_search($noPh, $ids, true), 'orden: primero los que se pueden llamar ya, aunque su autoridad sea más vieja');
        $only = array_map(static fn($r) => (int) $r['id'], Prospects::page(Prospects::filters(['now' => '1']), $now, 1, 25)['rows']);
        T::ok(in_array($now1, $only, true) && !in_array($late, $only, true) && !in_array($noPh, $only, true), 'filtro «Solo llamables ahora»: solo en horario y con teléfono');
        T::eq(Prospects::callableCount($f, $now), 1, '  y el conteo de llamables de la pestaña coincide');
    } else {
        T::ok(true, 'orden y filtro: a esta hora todos los estados están dentro (o fuera) del horario; se omite la comparación');
    }

    // ------------------------------------------------------------------ Pantalla
    $admin = Panel::user('admin');
    $s = Panel::start($ctx, 'panel-cola');
    T::ok($s->login($admin['email']), 'login del administrador');
    for ($i = 0; $i < 30; $i++) {
        $mk('93' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'TX', '+1305555' . str_pad((string) (2000 + $i), 4, '0', STR_PAD_LEFT), '2026-11-10');
    }
    $p = $s->get('/prospectos');
    $b = $p['body'];
    T::ok($p['status'] === 200 && str_contains($b, '<details class="pr" data-pr>') && str_contains($b, 'class="pr__s"') && !str_contains($b, '<article'), 'lista: una fila desplegable por prospecto (sin tarjetas)');
    T::ok(str_contains($b, 'class="pr-head"') && str_contains($b, 'Ubicación y hora local'), '  con cabecera de columnas');
    T::ok(str_contains($b, 'class="pg"') || str_contains($b, '<nav class="pg'), '  con paginación (más de 25)');
    T::ok(str_contains($b, 'data-per') && str_contains($b, 'Prospectos por página'), '  selector de prospectos por página');
    T::ok(str_contains($b, '<div class="filters__a"><button class="btn btn--secondary btn--sm"'), 'filtros: «Aplicar» ya no es botón primario (el primario es «Llamar»)');
    T::ok(str_contains($s->get('/prospectos?now=1')['body'], 'aria-current="page">Solo llamables ahora') || !$ok, '«Solo llamables ahora» marcado cuando está activo');
    T::ok(str_contains((string) file_get_contents($assets . '/prospectos.js'), 'ns-prospect-next'), 'al registrar un resultado se abre sola la fila siguiente');
};
