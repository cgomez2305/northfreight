<?php
declare(strict_types=1);

use Captacion\Services\ActivityLog;
use CaptacionTests\Fx;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase AR (revisión de funcionalidad y ruido visual, 2026-11-25): menú sin accesos repetidos, pestañas de sección (Prospectos: llamadas y correos; Reportes: métricas e
 * informes), barra de filtros única en Leads, Tabla, Calendario, Cronología y Actividad, menú «Más» y «Mostrar», selects y calendario propios (ctl.css + ctl.js),
 * Responsable que se guarda al elegir, plantillas sin columna «Editar» y acciones del registro en español.
 */
return static function (array $ctx): void {
    T::reset(true);
    $root = $ctx['root'];
    $assets = $root . '/public/assets';

    // ------------------------------------------------------------------ Hojas y scripts
    $css = (string) file_get_contents($assets . '/ctl.css');
    $js = (string) file_get_contents($assets . '/ctl.js');
    T::ok(str_contains($css, '@supports (appearance:base-select)') && str_contains($css, '::picker(select)') && str_contains($css, 'option:checked'), 'ctl.css: lista desplegable propia de los selects (base-select) solo donde el navegador la soporta');
    T::ok(!preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|gradient|uppercase/', (string) preg_replace('#/\*.*?\*/#s', '', $css)), '  solo tokens: sin colores fijos, degradados ni mayúsculas forzadas');
    T::ok(str_contains($js, "input[type=date],input[type=datetime-local]") && str_contains($js, 'showPopover') && str_contains($js, "'PageUp'") && str_contains($js, "'Escape'") && str_contains($js, 'Object.defineProperty(inp, \'value\''), 'ctl.js: calendario propio para fechas y fecha y hora, con teclado, en la capa superior, y el campo nativo sigue siendo la fuente del valor');
    T::ok(str_contains($js, "'enero'") && str_contains($js, "['L', 'M', 'X', 'J', 'V', 'S', 'D']") && str_contains($js, "'Elegir fecha'"), '  en español, semana de lunes a domingo');
    $pj = (string) file_get_contents($assets . '/panel.js');
    T::ok(str_contains($pj, "[data-fpop]") && str_contains($pj, 'form[data-autosubmit]') && str_contains($pj, 'form[data-fclean]'), 'panel.js: panel «Filtros», formularios que se guardan al elegir y sin parámetros vacíos, comunes a todas las pantallas');
    T::ok(!str_contains((string) file_get_contents($assets . '/prospectos.js'), '[data-fpop]'), '  prospectos.js ya no lleva su propia copia del panel');

    // ------------------------------------------------------------------ Menú
    $admin = Panel::user('admin');
    $s = Panel::start($ctx, 'panel-ar50');
    T::ok($s->login($admin['email']), 'login del administrador');
    $d = $s->get('/')['body'];
    T::ok(preg_match('#<script nonce="[^"]+" src="/assets/ctl\.js\?v=[^"]+" defer data-css-v="#', $d) === 1, 'el layout carga ctl.js con nonce (y ctl.css diferida)');
    $side = (string) preg_replace('#^.*?<aside class="side"(.*?)</aside>.*$#s', '$1', $d);
    preg_match_all('#<a class="nav-i(?! nav-i--view)[^"]*" href="([^"]+)"#', $side, $m);
    T::eq($m[1], ['/', '/leads', '/tareas', '/prospectos', '/vacantes', '/reportes', '/comunicaciones', '/config', '/actividad'], 'menú del administrador: 9 accesos, uno por sección (antes 15 más la Bandeja; Referidos sale con el módulo apagado, fase AU)');
    T::ok(!str_contains($side, 'data-inbox') && str_contains($d, 'data-notes'), '  sin botón «Bandeja»: la campana de arriba ya es la bandeja');
    $p = $s->get('/pipeline')['body'];
    T::ok(preg_match('#<a class="nav-i is-on" href="[^"]+" data-tip="Leads"#', $p) === 1, '  en el Tablero se marca Leads (el Tablero es un modo de Leads)');
    T::ok(str_contains($p, 'href="/prospectos"><svg') && !str_contains($p, '<span>Pipeline</span>'), '  barra inferior del móvil: Prospectos en lugar del Tablero repetido');

    // ------------------------------------------------------------------ Pestañas de sección
    $pr = $s->get('/prospectos')['body'];
    $pm = $s->get('/prospectos/correos')['body'];
    T::ok(str_contains($pr, 'aria-label="Secciones de prospectos"') && str_contains($pm, 'aria-label="Secciones de prospectos"') && preg_match('#<a class="utab is-on" href="/prospectos/correos" aria-current="page">Correos en lote#', $pm) === 1, 'Prospectos: pestañas «Llamadas» y «Correos en lote»');
    T::ok(preg_match('#<a class="nav-i is-on" href="/prospectos"#', $pm) === 1 && !str_contains($pm, 'Lista de llamadas'), '  en Correos se marca Prospectos en el menú y sobra el botón «Lista de llamadas»');
    $rp = $s->get('/reportes')['body'];
    $inf = $s->get('/informes')['body'];
    T::ok(str_contains($rp, 'aria-label="Secciones de reportes"') && str_contains($inf, 'aria-label="Secciones de reportes"') && preg_match('#<a class="nav-i is-on" href="/reportes"#', $inf) === 1, 'Reportes: pestañas «Métricas» e «Informes mensuales»; Informes marca Reportes en el menú');
    T::ok(str_contains($rp, 'Otras fechas') && str_contains($rp, 'id="rng-p"') && !str_contains($rp, 'Aplicar rango'), '  el rango propio va en el panel «Otras fechas», junto a los rangos rápidos');
    $rc = $s->get('/reportes?from=2026-11-01&to=2026-11-10')['body'];
    T::ok(preg_match('#fpop has-on#', $rc) === 1 && str_contains($rc, '1 nov a 10 nov'), '  con un rango propio el botón lo muestra');

    // ------------------------------------------------------------------ Barras de filtros
    Fx::lead();
    $l = $s->get('/leads')['body'];
    T::ok(str_contains($l, 'class="lrow"') && str_contains($l, 'class="fbar lbar"') && str_contains($l, 'data-fpop') && str_contains($l, 'data-apply') && !str_contains($l, 'filters-toggle'), 'Leads: pestañas de temperatura, búsqueda y «Filtros» en una sola fila');
    T::ok(!str_contains($l, 'Guardar vista</button>'), '  sin filtros no se ofrece «Guardar vista» (no habría nada que guardar)');
    T::ok(str_contains($l, 'aria-label="Más acciones"') && str_contains($l, 'Exportar CSV') && str_contains($l, '>Mostrar</button>') && !str_contains($l, 'aria-label="Densidad"><button'), '  Exportar e Importar en «Más», densidad y columnas en «Mostrar»');
    $lf = $s->get('/leads?pf=owner_operator&from=2026-11-01')['body'];
    T::ok(str_contains($lf, 'Guardar vista</button>') && str_contains($lf, 'Desde el 1 nov 2026') && preg_match('#fpop fpop--wide has-on.*?<b class="badge">2</b>#s', $lf) === 1, '  con filtros: «Guardar vista», chips legibles («Desde el 1 nov 2026») y el botón cuenta 2');
    foreach (['/tabla', '/calendario', '/cronologia'] as $route) {
        $b = $s->get($route)['body'];
        T::ok(str_contains($b, 'class="fbar lbar"') && !str_contains($b, 'filters-toggle'), "  {$route}: la misma barra, sin botón «Filtros» suelto en la cabecera");
    }
    $a = $s->get('/actividad')['body'];
    T::ok(str_contains($a, 'aria-controls="act-p"') && !str_contains($a, 'class="act-f'), 'Actividad: búsqueda a la vista y tipo, usuario y fechas en «Filtros»');

    // ------------------------------------------------------------------ Clics con propósito
    $lead = Fx::lead();
    $lp = $s->get('/leads/' . $lead)['body'];
    T::ok(preg_match('#<form class="asg"[^>]*data-autosubmit>#', $lp) === 1 && str_contains($lp, 'type="submit" data-apply>Asignar'), 'ficha: el responsable se guarda al elegirlo (el botón «Asignar» solo queda sin JS)');
    $cm = $s->get('/comunicaciones')['body'];
    T::ok(!str_contains($cm, '>Editar</a>') && str_contains($cm, 'class="cm-key"'), 'plantillas: sin botón «Editar» repetido (el nombre ya abre la plantilla) y la clave como nota bajo el nombre');

    // ------------------------------------------------------------------ Registro en español
    ActivityLog::record('prospect_campaign_launch', (int) $admin['id'], 'prospect_campaign', 7, ['recipients' => 12, 'segment' => ['fl' => '6']]);
    $ac = $s->get('/actividad')['body'];
    T::ok(str_contains($ac, 'Campaña lanzada') && str_contains($ac, 'Campaña #7 · 12 destinatarios') && !str_contains($ac, 'Prospect campaign'), 'Actividad: las acciones nuevas salen en español y sin JSON crudo');
};
