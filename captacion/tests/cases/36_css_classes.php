<?php
declare(strict_types=1);

use Captacion\Db;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Q5c-E2: cada clase que usa una pantalla (renderizada con la base VACÍA, solo el administrador) tiene que estar definida en las hojas que esa pantalla carga: fonts + panel + las de la pantalla
 * y las diferidas que su JS pide (ux.css en todas las de administración; peek.css y etiquetas.css donde hay vistazo rápido). Una clase sin regla es un botón sin estilo, un icono sin tamaño
 * o un relleno que falta: justo lo que se escapaba al revisar a ojo. Falla y dice qué clase falta en qué pantalla.
 *
 * Sin regla A PROPÓSITO (ganchos de JS, de pruebas o semánticos; no necesitan estilo): están en $hooks con su motivo.
 */
return static function (array $ctx): void {
    T::reset(true);
    Db::pdo()->exec('DELETE FROM channel_costs');   // otros bloques dejan costos: aquí la base tiene que estar de verdad vacía
    $assets = $ctx['root'] . '/public/assets/';
    $css = static fn(string $f): string => (string) @file_get_contents($assets . $f . '.css');
    $defs = static function (array $sheets) use ($css): array {
        $set = [];
        foreach ($sheets as $sh) {
            preg_match_all('/\.(-?[_a-zA-Z][\w-]*)/', preg_replace('#/\*.*?\*/#s', '', $css($sh)) ?? '', $m);
            foreach ($m[1] as $c) {
                $set[$c] = true;
            }
        }
        return $set;
    };
    /** clase => motivo */
    $hooks = [
        'app' => 'clase de <body> de las pantallas con shell; solo la usan selectores compuestos y el script',
        'js' => 'la pone el script del <head> en <html>',
        'btn__t' => 'texto del botón (span): los estilos van sobre .btn y sus hijos',
        'srch' => 'contenedor del diálogo de búsqueda: sus partes (.srch__bar, .srch__list…) llevan los estilos',
        'dlg--confirm' => 'diálogo de confirmación: usa .dlg tal cual',
        'chart' => 'ganchos de los gráficos SVG que dibuja Charts.php (los estilos van en sus partes)',
        'donut' => 'gancho del gráfico de anillo (ídem)',
        'kq__t' => 'agrupador de la barra rápida del tablero; el estilo va en .kq .seg',
        'wk' => 'contenedor de la semana de /tareas; sus partes (.wk__g, .wk__d…) llevan los estilos',
        'co-form' => 'formulario de costos: usa .field y .btn',
        'us-form' => 'formulario de usuarios: usa .field y .btn',
        'cfg-unlock__t' => 'texto del aviso de edición bloqueada (hereda de .cfg-unlock)',
        'rl-empty' => 'estado vacío de Reglas: usa .card .empty .empty--lg',
        'vstat' => 'resumen de vacantes: usa .pstat',
        'kpi__n' => 'Q6: span del número protagonista (el estilo va en .kpi__v y su <small>)',
        'spark__area' => 'Q6: área de la microlínea (el relleno va en el atributo fill del SVG)',
        'acts' => 'Q6: tarjeta «Siguiente mejor acción»; sus partes (.acts__l, .act…) llevan los estilos',
        'lc__area' => 'Q6: área degradada del gráfico (relleno por atributo fill)',
        'lc__area--ops' => 'Q6: gancho de la serie del área',
        'act--login' => 'etiqueta de filas de acceso en Actividad: la distingue el icono, no el estilo',
    ];
    $admin = Panel::user('admin');
    $s = Panel::start($ctx, 'panel36');
    T::ok($s->login($admin['email']), 'login del administrador (base vacía, sin leads)');
    $routes = ['/', '/?p=week', '/leads', '/leads?q=zzzz', '/pipeline', '/pipeline?pl=B', '/tabla', '/calendario', '/calendario?vm=semana', '/calendario?vm=dia', '/calendario?nueva=2026-11-10', '/cronologia', '/cronologia?tp=calls',
        '/tareas', '/tareas?tab=overdue', '/tareas?tab=upcoming', '/tareas?tab=done', '/tareas?tab=week', '/actividad', '/config?tab=puntuacion', '/config?tab=alertas', '/config?tab=costos', '/config?tab=integraciones', '/config?tab=horario',
        '/config?tab=copias', '/config?tab=entrega', '/config?tab=correo', '/config?tab=usuarios', '/config?tab=prospectos', '/config?tab=canales', '/config?tab=reglas', '/prospectos', '/referidos', '/vacantes', '/vacantes/new', '/reportes',
        '/informes', '/comunicaciones', '/comunicaciones/inscripciones', '/comunicaciones/envios', '/comunicaciones/reactivacion', '/nope'];
    $fail = [];
    $checked = 0;
    foreach ($routes as $route) {
        $r = $s->get($route);
        if ($r['status'] >= 500) {
            $fail[] = "$route -> {$r['status']}";
            continue;
        }
        preg_match_all('#<link rel="stylesheet" href="/assets/([a-z0-9-]+)\.css\?v=#', $r['body'], $m);
        $sheets = $m[1];
        if (str_contains($r['body'], 'src="/assets/ux.js')) {
            $sheets[] = 'ux';
        }
        if (str_contains($r['body'], 'data-live-v=')) {
            $sheets[] = 'dashlive';   // dashboard.js la pide tras la primera pintura (hoja diferida)
        }
        if (str_contains($route, 'tab=reglas')) {
            $sheets[] = 'rules';   // el panel lateral del editor pide rules.css al abrirse
        }
        if (str_contains($r['body'], 'id="peek"')) {
            $sheets[] = 'peek';
            $sheets[] = 'etiquetas';
        }
        $def = $defs($sheets);
        preg_match_all('/\sclass="([^"]*)"/', $r['body'], $cm);
        $used = [];
        foreach ($cm[1] as $attr) {
            foreach (preg_split('/\s+/', trim(html_entity_decode($attr))) ?: [] as $c) {
                if ($c !== '' && !str_contains($c, '<')) {
                    $used[$c] = true;
                }
            }
        }
        $missing = array_values(array_filter(array_keys($used), static fn($c) => !isset($def[$c]) && !isset($hooks[$c])));
        $checked++;
        if ($missing) {
            $fail[] = "$route: " . implode(', ', $missing);
        }
    }
    T::ok(!$fail, "$checked pantallas con la base vacía: toda clase usada está definida en las hojas que la pantalla carga" . ($fail ? ' · FALTAN → ' . implode(' | ', $fail) : ''));
    // la propia prueba detecta una clase sin regla (control negativo)
    $probe = $defs(['panel']);
    T::ok(isset($probe['btn']) && !isset($probe['clase-que-no-existe-xyz']), 'control: el extractor de reglas reconoce .btn y no inventa clases');
    \CaptacionTests\Infra::stop('panel36');
    T::reset(true);
};
