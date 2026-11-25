<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\DashboardActions;
use Captacion\Services\SavedViews;
use Captacion\Services\Webhook;
use CaptacionTests\Fx;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Usabilidad tras la grabación de la reunión con el cliente (2026-11-24): Prospectos con una sola barra (búsqueda + «Filtros» agrupados + chips de filtros activos),
 * atajos de fecha del siguiente intento, barra lateral compacta en pantallas bajas, dashboard sin gráficos vacíos cuando aún no hay leads, acción «Llama a los
 * prospectos nuevos», vista «Calientes por contactar», pantalla de Leads vacía sin jerga técnica y Twilio (SMS) oculto mientras no esté configurado.
 */
return static function (array $ctx): void {
    T::reset(true);
    $root = $ctx['root'];
    $assets = $root . '/public/assets';

    // ------------------------------------------------------------------ Reglas puras
    T::eq(SavedViews::SYSTEM['hot_pending']['name'], 'Calientes por contactar', 'vista del sistema renombrada: «Calientes por contactar» (antes «Calientes pendientes», que el cliente no entendía)');
    $r = DashboardActions::rank(['calls' => 248]);
    T::ok(count($r) === 1 && $r[0]['key'] === 'calls' && $r[0]['impact'] === 22 + 2 * 10 && str_contains($r[0]['href'], 'tab=to_call') && str_contains($r[0]['href'], 'now=1'), 'siguiente mejor acción: con prospectos nuevos llamables propone llamarlos (impacto 42, enlace a la lista filtrada)');
    T::eq(DashboardActions::rank(['calls' => 0]), [], '  sin nadie llamable ahora (de noche, por ejemplo) no la propone');
    $r = DashboardActions::rank(['hot' => 1, 'calls' => 500]);
    T::eq($r[0]['key'], 'hot', '  y nunca pasa por delante de un caliente sin contactar');

    // ------------------------------------------------------------------ Pantallas
    $admin = Panel::user('admin');
    $s = Panel::start($ctx, 'panel-ux48', ['TWILIO_TOKEN' => '']);
    T::ok($s->login($admin['email']), 'login del administrador');

    $d = $s->get('/')['body'];
    T::ok(str_contains($d, 'id="h-first"') && !str_contains($d, 'id="h-chart"') && !str_contains($d, 'id="h-heat"') && !str_contains($d, 'id="h-recent"'), 'dashboard sin ningún lead: una tarjeta de primeros pasos en lugar de gráficos vacíos');
    T::ok(str_contains($d, 'id="h-today"') && str_contains($d, 'id="h-sys"') && str_contains($d, 'href="/prospectos"'), '  conserva «Hoy» y «Estado del sistema» y lleva a Prospectos');
    Fx::lead();
    $d = $s->get('/')['body'];
    T::ok(!str_contains($d, 'id="h-first"') && str_contains($d, 'id="h-chart"') && str_contains($d, 'id="h-heat"'), '  con el primer lead vuelven los gráficos');

    Db::run('DELETE FROM leads');
    $l = $s->get('/leads')['body'];
    T::ok(str_contains($l, 'Formulario de la web') && !str_contains($l, 'CAPTACION_ENABLED') && !str_contains($l, 'import-jsonl') && !str_contains($l, '/api/lead.php'), 'Leads vacía: explica de dónde llegan los leads sin jerga técnica');

    $mk = static fn(string $dot, string $st) => Db::insert('prospects_fmcsa', ['usdot' => $dot, 'legal_name' => "Barra $dot LLC", 'state' => $st, 'phone' => '+1305555' . substr($dot, -4), 'fleet_size' => 1,
        'authority_granted_at' => '2026-11-10', 'status' => 'to_call', 'listed_on' => '2026-11-20', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    for ($i = 0; $i < 3; $i++) {
        $mk('94' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'TX');
    }
    $p = $s->get('/prospectos')['body'];
    T::ok(str_contains($p, '<form class="pbar" id="filters"') && str_contains($p, 'data-fpop') && str_contains($p, 'aria-controls="fpop-p"') && str_contains($p, '<span class="btn__t">Filtros</span>'), 'prospectos: una sola barra con búsqueda y un botón «Filtros» que agrupa estado, flota y autoridad');
    T::ok(str_contains($p, 'name="us"') && str_contains($p, 'name="fl"') && str_contains($p, 'name="ag"') && str_contains($p, '<div class="filters__a"><button class="btn btn--secondary btn--sm"'), '  los tres filtros siguen ahí (con «Aplicar» secundario para teclado y sin JS)');
    T::ok(str_contains($p, 'data-rules') && str_contains($p, 'Reglas de las llamadas en frío') && !str_contains($p, 'class="pq"'), '  reglas en un botón con panel y sin la franja de estado duplicada');
    T::ok(str_contains($p, 'data-qd hidden') && str_contains($p, 'data-days="1"') && str_contains($p, 'data-days="7"'), '  siguiente intento con atajos «Mañana», «En 3 días» y «En 1 semana» (visibles solo con JS)');
    T::ok(!str_contains($p, 'class="fchips"'), '  sin filtros activos no hay chips');
    $p = $s->get('/prospectos?us=TX&fl=1')['body'];
    T::ok(str_contains($p, 'class="fchips"') && str_contains($p, 'Estado: TX') && str_contains($p, 'Flota: 1 unidad') && str_contains($p, 'Quitar todos'), '  con filtros: chips que se quitan de uno en uno o todos');
    T::ok(preg_match('/fpop has-on.*?<b class="badge">2<\/b>/s', $p) === 1, '  y el botón «Filtros» cuenta los filtros del panel activos');

    $js = (string) file_get_contents($assets . '/prospectos.js');
    T::ok(str_contains($js, 'requestSubmit') && str_contains($js, "e.key === 'Escape'") && str_contains($js, ".pbar [data-per]") && str_contains($js, "el.value === '') el.disabled = true"), 'prospectos.js: los filtros se aplican al elegirlos, Esc cierra, «Por página» funciona y no viajan parámetros vacíos');
    $css = (string) file_get_contents($assets . '/panel.css');
    T::ok(str_contains($css, '@media (min-width:761px) and (max-height:820px){.side{'), 'barra lateral compacta en pantallas bajas (portátiles de 690 a 820 px de alto)');

    // ------------------------------------------------------------------ Twilio fuera de la vista mientras no se use
    $prev = Env::get('TWILIO_TOKEN');
    Env::set('TWILIO_TOKEN', null);
    T::ok(!in_array('twilio', Webhook::visible(), true), 'Twilio sin TWILIO_TOKEN: fuera de la lista visible');
    Env::set('TWILIO_TOKEN', 'tok-prueba');
    T::ok(in_array('twilio', Webhook::visible(), true), '  con TWILIO_TOKEN vuelve a mostrarse');
    Env::set('TWILIO_TOKEN', $prev);
    $cfg = $s->get('/config?tab=canales')['body'];
    T::ok(!str_contains($cfg, 'id="cn-twilio"') && !str_contains($cfg, 'name="sms_enabled"'), '  Canales externos no muestra Twilio ni la casilla de SMS');
    $int = $s->get('/config?tab=integraciones')['body'];
    T::ok(!str_contains($int, '/api/webhooks/sms.php') && str_contains($int, '/api/webhooks/meta.php'), '  e Integraciones tampoco (Meta, Cal.com y n8n siguen)');
};
