<?php
declare(strict_types=1);

use Captacion\Db;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Pulido transversal (Q2b-F): hojas de estilo por pantalla (≤ 45 KB sin comprimir con fuentes), barra lateral por rol, migas y titulos, estados vacios y sin estilos en linea. */
return static function (array $ctx): void {
    T::reset(true);
    $assets = $ctx['root'] . '/public/assets';
    $size = static fn(string $f): int => (int) @filesize($assets . '/' . $f . '.css');
    // ------------------------------------------------------------------ Hojas por pantalla
    $screens = [
        '/' => ['charts', 'dashboard'], '/leads' => ['leads', 'views'], '/pipeline' => ['kanban', 'views'], '/tareas' => ['tareas'], '/actividad' => ['config', 'actividad'],
        '/config' => ['config', 'cfg-puntuacion'], '/config?tab=alertas' => ['config', 'cfg-alertas'], '/config?tab=costos' => ['config', 'cfg-costos'],
        '/config?tab=integraciones' => ['config', 'cfg-integraciones'], '/config?tab=horario' => ['config', 'cfg-horario'], '/config?tab=copias' => ['config', 'cfg-copias'],
        // Q3
        '/config?tab=entrega' => ['config', 'cfg-entrega'], '/config?tab=correo' => ['config', 'cfg-correo'], '/config?tab=usuarios' => ['config', 'cfg-usuarios'],
        // Q4a
        '/prospectos' => ['prospectos'], '/referidos' => ['referidos'], '/vacantes' => ['vacantes'], '/vacantes/new' => ['vacantes'], '/config?tab=prospectos' => ['config', 'cfg-prospectos'],
        '/reportes' => ['charts', 'reportes'], '/resultados' => ['charts', 'reportes'], '/informes' => ['reportes'], '/comunicaciones' => ['comms'], '/comunicaciones/inscripciones' => ['comms'], '/comunicaciones/envios' => ['comms'],
    ];
    $s = Panel::start($ctx, 'panel-pol');
    $admin = Panel::user('admin');
    $viewer = Panel::user('viewer');
    T::ok($s->login($admin['email']), 'login del administrador');
    $lead = Db::insert('leads', ['profile' => 'owner_operator', 'first_name' => 'Pulido', 'last_name' => 'Prueba', 'phone_e164' => '+13055550777', 'state' => 'TX', 'score' => 50, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'meta', 'attempt' => 1]);
    $screens['/leads/' . $lead] = ['charts', 'lead'];
    foreach ($screens as $route => $sheets) {
        $r = $s->get($route);
        preg_match_all('#<link rel="stylesheet" href="/assets/([a-z0-9-]+)\.css\?v=#', $r['body'], $m);
        T::eq($m[1], array_merge(['fonts', 'panel'], $sheets), "hojas de {$route}: " . implode(' + ', array_merge(['fonts', 'panel'], $sheets)));
        $tot = array_sum(array_map($size, $m[1]));
        T::ok($r['status'] === 200 && $tot > 0 && $tot <= 45000 && count(array_filter($m[1], static fn($f) => $size($f) === 0)) === 0, "  {$route}: CSS total {$tot} bytes (≤ 45.000 con fuentes) y todas las hojas existen");
        T::ok(!preg_match('/\sstyle="/i', $r['body']) && !preg_match('/\son[a-z]+="/i', $r['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $r['body']) === 0, "  {$route}: sin estilos ni handlers en linea, scripts con nonce");
    }
    $lg = Infra::http('GET', $s->base . '/login', []);
    preg_match_all('#<link rel="stylesheet" href="/assets/([a-z0-9-]+)\.css\?v=#', $lg['body'], $m);
    T::eq($m[1], ['fonts', 'panel', 'auth'], 'pantalla de acceso: fonts + panel + auth');
    $nf = Infra::http('GET', $s->base . '/no-existe', ['jar' => $s->jar]);
    preg_match_all('#<link rel="stylesheet" href="/assets/([a-z0-9-]+)\.css\?v=#', $nf['body'], $m);
    T::ok($nf['status'] === 404 && $m[1] === ['fonts', 'panel', 'auth'] && str_contains($nf['body'], 'No encontrado'), 'pagina 404: carga auth.css y muestra el mensaje');
    $sum = $size('fonts') + $size('panel');
    T::ok($size('panel') < 35000, 'panel.css (base) mide menos de 35 KB: ' . $size('panel') . ' bytes (antes de Q2b-F: 51.353)');
    // ------------------------------------------------------------------ Barra lateral y migas por rol
    $r = $s->get('/');
    T::ok(str_contains($r['body'], 'href="/pipeline"') && str_contains($r['body'], 'href="/tareas"') && str_contains($r['body'], 'href="/config"') && str_contains($r['body'], 'href="/actividad"') && str_contains($r['body'], 'Administración'), 'administrador: la barra lateral trae Pipeline, Tareas, Configuración y Actividad');
    T::ok(str_contains($r['body'], 'href="/reportes"') && str_contains($r['body'], 'href="/informes"') && str_contains($r['body'], 'href="/comunicaciones"'), 'administrador: la barra lateral trae Reportes, Informes y Comunicaciones (Q3)');
    $v = $s->another();
    T::ok($v->login($viewer['email']), 'login del lector');
    $r = $v->get('/');
    T::eq([$r['status'], Infra::header($r, 'location')], [303, '/resultados'], 'lector: / lo lleva a /resultados');
    $r = $v->get('/resultados');
    T::ok($r['status'] === 200 && str_contains($r['body'], 'href="/resultados"') && str_contains($r['body'], 'href="/informes"') && !str_contains($r['body'], 'href="/pipeline"') && !str_contains($r['body'], 'href="/tareas"') && !str_contains($r['body'], 'href="/leads') && !str_contains($r['body'], 'href="/config"') && !str_contains($r['body'], 'href="/actividad"') && !str_contains($r['body'], 'data-search') && !str_contains($r['body'], 'data-notes'), 'lector: la barra lateral trae solo Resultados e Informes (sin Pipeline, Tareas, Leads, buscador ni campana)');
    $crumbs = ['/pipeline' => 'Tablero', '/tareas' => 'Tareas', '/actividad' => 'Actividad', '/config?tab=copias' => 'Copias de seguridad', '/config?tab=alertas' => 'Alertas', '/reportes' => 'Últimos 30 días', '/informes' => 'Informes', '/comunicaciones' => 'Plantillas', '/config?tab=entrega' => 'Entrega a NorthStar', '/config?tab=usuarios' => 'Usuarios', '/prospectos' => 'Prospectos', '/referidos' => 'Referidos', '/vacantes' => 'Vacantes', '/config?tab=prospectos' => 'Prospectos'];
    foreach ($crumbs as $route => $last) {
        $r = $s->get($route);
        T::ok(preg_match('#<li aria-current="page">' . preg_quote($last, '#') . '</li>#', $r['body']) === 1 && str_contains($r['body'], '<title>'), "migas de {$route}: termina en «{$last}»");
        T::ok(preg_match('#<a class="nav-i is-on"[^>]*aria-current="page"#', $r['body']) === 1, "  y marca su ítem de la barra lateral");
    }
    // ------------------------------------------------------------------ Estados vacios (sin leads, sin tareas, sin actividad filtrada)
    Db::pdo()->exec('DELETE FROM lead_events');
    Db::pdo()->exec('DELETE FROM tasks');
    Db::pdo()->exec('DELETE FROM leads');
    foreach (['/informes' => 'Todavía no hay informes', '/comunicaciones/inscripciones' => 'Nadie está inscrito', '/comunicaciones/envios' => 'Aún no se ha enviado ningún correo', '/config?tab=entrega' => 'Aún no se ha presentado ningún lead', '/resultados' => 'Sin leads recibidos', '/' => 'Aún no hay', '/leads' => 'empty', '/pipeline' => 'empty', '/tareas' => 'empty', '/actividad?q=zzzz-nada' => 'Nada coincide', '/config?tab=copias' => 'Aún no hay copias', '/prospectos' => 'Todavía no hay prospectos', '/referidos' => 'Todavía no hay códigos de referido', '/vacantes' => 'Todavía no hay vacantes'] as $route => $needle) {
        $r = $s->get($route);
        T::ok($r['status'] === 200 && str_contains($r['body'], $needle) && !str_contains($r['body'], 'Warning') && !str_contains($r['body'], 'Fatal error'), "estado vacio de {$route}: 200 con su aviso y sin errores de PHP");
    }
    Infra::stop('panel-pol');
    T::reset(true);
};
