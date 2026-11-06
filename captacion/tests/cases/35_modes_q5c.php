<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\QueryTimeout;
use Captacion\Services\Commands;
use Captacion\Services\Fmt;
use Captacion\Services\LeadCalendar;
use Captacion\Services\LeadFeed;
use Captacion\Services\LeadQuery;
use Captacion\Services\LeadTable;
use Captacion\Services\Prefs;
use Captacion\Services\SavedViews;
use Captacion\Services\Tasks;
use Captacion\Services\Undo;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Q5c: modos Tabla, Calendario y Cronología. Preferencias y vistas guardadas con modo, orden y columnas de la Tabla (lista blanca), CSV, calendario por rango (cambio de horario de verano en ET,
 * fin de mes, tope), reprogramar con «Deshacer», cronología con cursor y filtros, autorización por rol (el lector, 403 en cada ruta nueva) y atajos.
 */
return static function (array $ctx): void {
    T::reset(true);
    $admin = Panel::user('admin');
    $viewerU = Panel::user('viewer');
    $uid = (int) $admin['id'];
    $now = Clock::time();
    // Lo que escribe el servidor HTTP no pasa por la caché de Prefs de este proceso: se lee directo de la base.
    $pref = static fn(string $k): ?string => ($v = Db::value('SELECT pvalue FROM user_prefs WHERE user_id = ? AND pkey = ?', [$uid, $k])) === null ? null : (string) $v;
    $tcols = static fn(): array => explode(',', (string) $pref('tcols'));

    // ------------------------------------------------------------------ Preferencias
    T::eq([Prefs::get($uid, 'lmode'), Prefs::get($uid, 'calm'), Prefs::tableColumns($uid)], ['lista', 'mes', explode(',', Prefs::DEFAULTS['tcols'])], 'preferencias Q5c: modo, escala del calendario y columnas de la Tabla traen su valor por defecto');
    T::ok(Prefs::set($uid, 'lmode', 'calendario') && Prefs::get($uid, 'lmode') === 'calendario', 'modo de vista: se guarda por usuario');
    T::ok(!Prefs::set($uid, 'lmode', 'otro') && !Prefs::set($uid, 'calm', 'anio') && Prefs::get($uid, 'lmode') === 'calendario', '  y un modo o escala inventados se rechazan sin tocar lo guardado');
    T::ok(Prefs::set($uid, 'tcols', 'when,prof,inventada,stage,prof') && Prefs::tableColumns($uid) === ['when', 'prof', 'stage'], 'columnas de la Tabla: se conserva el ORDEN elegido, sin repetir y sin claves desconocidas');
    T::ok(!Prefs::set($uid, 'tcols', 'solo,inventadas') && !Prefs::set($uid, 'tcols', '') && !Prefs::set($uid, 'tcols', "when;DROP"), '  y no se puede dejar sin ninguna columna válida');
    Prefs::set($uid, 'tcols', Prefs::DEFAULTS['tcols']);
    Prefs::remember($uid, 'tabla');
    T::eq([Prefs::get($uid, 'lmode'), Prefs::home('tabla'), Prefs::home('zzz')], ['tabla', '/tabla', '/leads'], 'Prefs::remember guarda el último modo y home() lo lleva a su ruta');
    Prefs::set($uid, 'lmode', 'lista');

    // ------------------------------------------------------------------ Vistas guardadas con modo
    $r = SavedViews::create($uid, 'Calientes en tabla', ['t' => 'hot'], 'trucks', 'asc', null, 'tabla');
    T::ok($r['ok'], 'vista guardada con modo: se crea');
    $v = SavedViews::mine($uid)[0];
    T::eq([$v['mode'], $v['sort'], $v['dir']], ['tabla', 'trucks', 'asc'], '  recuerda el modo y el orden propio de la Tabla');
    T::ok(str_starts_with(SavedViews::href($v), '/tabla?') && str_contains(SavedViews::href($v), 'sort=trucks') && str_contains(SavedViews::href($v), 'v=' . $v['id']), '  al abrirla lleva a /tabla con su filtro, su orden y su id');
    T::ok(str_starts_with(SavedViews::href($v, 'leads'), '/tabla?'), '  aunque se pida abrirla en la Lista, manda el modo guardado');
    $r2 = SavedViews::create($uid, 'Sin modo', ['t' => 'warm'], null, null, null, 'inventado');
    $v2 = array_values(array_filter(SavedViews::mine($uid), static fn($x) => $x['name'] === 'Sin modo'))[0];
    T::ok($r2['ok'] && $v2['mode'] === null && str_starts_with(SavedViews::href($v2, 'calendario'), '/calendario?') && str_starts_with(SavedViews::href($v2), '/leads?'), 'un modo inventado se guarda como «sin modo»: se abre en la pantalla actual (o en la Lista)');
    T::ok(SavedViews::update($uid, (int) $v['id'], ['t' => 'hot'], null, null, null, 'calendario')['ok'] && SavedViews::mine($uid)[0]['mode'] === 'calendario', '«Guardar cambios» actualiza también el modo');
    $sb = array_values(array_filter(SavedViews::sidebar($uid), static fn($x) => $x['id'] === 'sys-hot_pending'));
    T::ok($sb && str_starts_with($sb[0]['href'], '/leads?'), 'las vistas del sistema (sin modo) siguen abriéndose en la Lista desde la barra lateral');
    T::eq(LeadTable::cleanSort('trucks'), 'trucks', 'orden guardable: una clave de la Tabla vale');
    T::eq([LeadTable::cleanSort('score'), LeadTable::cleanSort('x;DROP'), LeadTable::cleanSort('')], ['score', null, null], '  la de la Lista también; lo demás, no');

    // ------------------------------------------------------------------ Datos
    $mk = static fn(array $o = []): int => Fx::lead($o);
    $a = $mk(['first_name' => 'Ana', 'last_name' => 'Zeta', 'score' => 90, 'temperature' => 'hot', 'truck_count' => 5, 'years_experience' => 10, 'stage' => 'qualified', 'source' => 'meta', 'created_at' => Clock::at($now - 5000)]);
    $b = $mk(['first_name' => 'Beto', 'last_name' => 'Yepes', 'score' => 40, 'temperature' => 'cold', 'truck_count' => null, 'years_experience' => null, 'stage' => 'new', 'source' => 'web_careers', 'created_at' => Clock::at($now - 4000)]);
    $c = $mk(['first_name' => '=cmd()', 'last_name' => 'Peligro', 'score' => 70, 'temperature' => 'warm', 'truck_count' => 1, 'years_experience' => 3, 'stage' => 'contacted', 'source' => 'meta', 'phone_e164' => '+13055550101', 'email' => '+evil@example.com', 'created_at' => Clock::at($now - 3000)]);
    $d = $mk(['first_name' => 'Dora', 'last_name' => 'Xu', 'profile' => 'company_driver', 'pipeline' => 'B', 'score' => 70, 'temperature' => 'warm', 'truck_count' => 2, 'stage' => 'prequalified', 'source' => 'web_careers', 'created_at' => Clock::at($now - 2000)]);

    // ------------------------------------------------------------------ Tabla: orden validado y estable
    $pg = LeadTable::paging(['sort' => 'pts', 'dir' => 'asc', 'per' => '50', 'page' => '2']);
    T::eq($pg, ['sort' => 'pts', 'dir' => 'asc', 'page' => 2, 'per' => 50], 'Tabla: orden, dirección, página y por página se validan');
    T::eq(LeadTable::paging(['sort' => 'score'])['sort'], 'pts', '  la clave «score» de la Lista es un alias de «pts»');
    $evil = LeadTable::paging(['sort' => 'l.id; DROP TABLE leads', 'dir' => "asc'--", 'per' => '999999', 'page' => '-4']);
    T::eq($evil, ['sort' => 'when', 'dir' => 'desc', 'page' => 1, 'per' => 25], '  una clave de orden inyectada, una dirección rara y valores fuera de rango caen en los valores seguros');
    $order = LeadTable::orderBy(LeadTable::paging(['sort' => 'trucks', 'dir' => 'asc']));
    T::ok(str_contains($order, 'l.truck_count IS NULL') && str_ends_with($order, 'l.id DESC'), '  columna que puede estar vacía: los vacíos al final y desempate por id (orden estable)');
    $ids = static fn(array $p): array => array_map('intval', array_column(LeadTable::list([], LeadTable::paging($p))['rows'], 'id'));
    T::eq($ids(['sort' => 'trucks', 'dir' => 'asc']), [$c, $d, $a, $b], 'orden por camiones ascendente: 1, 2, 5 y el vacío al final');
    T::eq($ids(['sort' => 'trucks', 'dir' => 'desc']), [$a, $d, $c, $b], '  descendente: 5, 2, 1 y el vacío sigue al final');
    T::eq($ids(['sort' => 'pts', 'dir' => 'desc']), [$a, $d, $c, $b], 'orden por puntos: los empatados (70) salen por id descendente, siempre igual');
    T::eq($ids(['sort' => 'pts', 'dir' => 'desc']), $ids(['sort' => 'pts', 'dir' => 'desc']), '  y repetirlo da lo mismo');
    T::eq($ids(['sort' => 'stage', 'dir' => 'asc']), [$b, $c, $a, $d], 'orden por etapa: por posición en el embudo (Nuevo, Contactado, Calificado, Precalificado), no alfabético');
    T::eq($ids(['sort' => 'name', 'dir' => 'asc']), [$c, $a, $b, $d], 'orden por nombre');
    $filtered = LeadTable::list(['src' => 'meta'], LeadTable::paging(['sort' => 'pts']));
    T::eq([$filtered['total'], count($filtered['rows'])], [2, 2], 'la Tabla respeta los mismos filtros que la Lista (fuente = meta)');
    T::eq(LeadTable::cleanCols(['x', 'when', 'when', 'prof']), ['when', 'prof'], 'cleanCols: conserva el orden, sin repetir ni claves desconocidas');

    // ------------------------------------------------------------------ CSV de la Tabla
    $csv = LeadTable::csv([], LeadTable::paging(['sort' => 'pts', 'dir' => 'desc']), ['pts', 'email', 'phone', 'trucks']);
    $lines = explode("\r\n", rtrim($csv['csv']));
    T::ok(str_starts_with($csv['csv'], "\xEF\xBB\xBF") && $lines[0] === "\xEF\xBB\xBFNombre,Puntos,Correo,Teléfono,Camiones" && $csv['rows'] === 4, 'CSV: BOM, cabecera con las columnas visibles en su orden y una fila por lead');
    T::ok(str_contains($csv['csv'], "'=cmd() Peligro") && str_contains($csv['csv'], "'+evil@example.com") && str_contains($csv['csv'], "'+13055550101"), '  protección contra fórmulas: nombre con =, correo con + y teléfono con + llevan comilla');
    $sel = LeadTable::csv([], LeadTable::paging([]), ['pts'], [$a, $b, 999999, -1]);
    T::eq($sel['rows'], 2, 'CSV de una selección: solo los ids existentes');
    T::eq(LeadTable::csv([], LeadTable::paging([]), ['pts'], [])['rows'], 0, '  y una selección vacía no exporta nada');

    // ------------------------------------------------------------------ HTTP: Tabla
    $s = Panel::start($ctx, 'panel35');
    T::ok($s->login($admin['email']), 'login del administrador');
    $r = $s->get('/tabla');
    T::ok($r['status'] === 200 && str_contains($r['body'], 'tbl--tabla') && str_contains($r['body'], 'data-peek="' . $a . '"') && str_contains($r['body'], 'name="ids[]"') && str_contains($r['body'], 'data-bulkbar'), 'GET /tabla: tabla con filas con vistazo, casillas y barra de acciones masivas');
    preg_match_all('#<th scope="col" class="tc tc-([a-z]+)#', $r['body'], $m);
    T::eq($m[1], explode(',', Prefs::DEFAULTS['tcols']), '  las columnas salen en el orden de las preferencias');
    T::ok(preg_match('#<a class="vtab is-on" href="/tabla[^"]*" aria-current="page">#', $r['body']) === 1 && str_contains($r['body'], 'href="/calendario') && str_contains($r['body'], 'href="/cronologia'), '  la barra de vistas trae Lista, Tablero, Tabla, Calendario y Cronología');
    T::eq($pref('lmode'), 'tabla', '  visitarla recuerda el modo');
    $r = $s->get('/leads');
    T::ok(str_contains($r['body'], 'href="/tabla') && $pref('lmode') === 'lista', '  y volver a la Lista lo cambia de nuevo');
    $r = $s->get('/tabla?sort=pts&dir=asc');
    T::ok(preg_match('#<a class="sort is-on" href="[^"]*" aria-label="Ordenado por Puntos, ascendente#', $r['body']) === 1 && preg_match('/Beto.*Dora.*Peligro.*Ana/s', $r['body']) === 1, 'orden por puntos ascendente: cabecera marcada y filas en ese orden');
    $r = $s->get('/tabla?sort=' . rawurlencode('l.id;DROP TABLE leads') . '&dir=up&per=7&page=999');
    T::ok($r['status'] === 200 && str_contains($r['body'], 'tbl--tabla') && Db::value('SELECT COUNT(*) FROM leads') == 4, 'orden y paginación inválidos: 200 con valores seguros y la base intacta');
    $r = $s->get('/tabla?src=meta&q=Peligro');
    T::ok(str_contains($r['body'], 'Peligro') && !str_contains($r['body'], 'Yepes') && str_contains($r['body'], 'Texto: «Peligro»') && str_contains($r['body'], 'class="fchip"'), 'los filtros de la Lista funcionan en la Tabla y salen como chips');
    $r = $s->get('/leads?v=tabla&src=meta');
    T::eq([$r['status'], Infra::header($r, 'location')], [302, '/tabla?src=meta'], '/leads?v=tabla abre el modo Tabla conservando los filtros (v=calendario y v=cronologia, igual)');
    T::eq(Infra::header($s->get('/leads?v=calendario'), 'location'), '/calendario', '  v=calendario');
    // columnas (formulario, sin JS)
    $r = $s->post('/tabla/cols', ['_csrf' => $s->csrf, 'back' => '/tabla', 'move' => 'src:up']);
    T::ok($r['status'] === 303 && $tcols()[7] === 'src' && $tcols()[8] === 'stage', 'columnas: subir una columna cambia su posición');
    $s->post('/tabla/cols', ['_csrf' => $s->csrf, 'back' => '/tabla', 'move' => 'prof:up']);
    T::eq($tcols()[0], 'prof', '  subir la primera no hace nada');
    $s->post('/tabla/cols', ['_csrf' => $s->csrf, 'back' => '/tabla', 'col' => ['prof', 'usdot', 'inventada', 'when']]);
    $cols3 = $tcols();
    T::eq($cols3, ['prof', 'when', 'usdot'], '  elegir columnas: se conservan las que ya estaban en su orden y las nuevas van al final');
    $r = $s->post('/tabla/cols', ['_csrf' => $s->csrf, 'back' => '/tabla', 'col' => ['inventada']]);
    T::eq($tcols(), ['prof', 'when', 'usdot'], '  sin ninguna columna válida no se guarda una lista vacía y no cambia lo anterior');
    $r = $s->post('/tabla/cols', ['_csrf' => 'falso', 'back' => '/tabla', 'reset' => '1']);
    T::ok($r['status'] === 303 && $tcols() === ['prof', 'when', 'usdot'], '  sin CSRF válido no cambia nada');
    $r = $s->post('/tabla/cols', ['_csrf' => $s->csrf, 'back' => 'https://malo.example/x', 'reset' => '1']);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/tabla' && $tcols() === explode(',', Prefs::DEFAULTS['tcols']), '  restablecer vuelve a las de siempre y un «back» externo se ignora');
    // exportación
    $csrf = $s->csrf;
    $r = $s->post('/tabla/export', ['_csrf' => $csrf, 'scope' => 'filters', 'qs' => 'src=meta&sort=pts&dir=asc']);
    T::ok($r['status'] === 200 && str_contains(Infra::header($r, 'content-type'), 'csv') && str_starts_with($r['body'], "\xEF\xBB\xBFNombre,Perfil") && substr_count($r['body'], "\r\n") === 3 && str_contains($r['body'], "'=cmd()"), 'POST /tabla/export: CSV de lo filtrado con las columnas de la Tabla y protección contra fórmulas');
    T::ok(Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'leads_export' AND entity_type = 'lead' AND changes LIKE '%\"mode\":\"tabla\"%'") == 1, '  y queda registrada en la actividad (modo tabla, filas y filtros)');
    $r = $s->post('/tabla/export', ['_csrf' => $csrf, 'do' => 'export', 'ids' => [$a], 'qs' => '']);
    T::ok($r['status'] === 200 && substr_count($r['body'], "\r\n") === 2, 'exportar la selección desde la barra masiva: solo el lead elegido');
    $r = $s->post('/tabla/export', ['_csrf' => 'falso', 'scope' => 'filters']);
    T::ok($r['status'] === 303 && !str_contains(Infra::header($r, 'content-type'), 'csv'), 'exportar sin CSRF válido: no entrega nada');
    $r = $s->post('/leads/bulk', ['_csrf' => $csrf, 'back' => '/tabla?sort=pts', 'ids' => [$b], 'do' => 'stage', 'stage' => 'contacted']);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/tabla?sort=pts' && Db::value('SELECT stage FROM leads WHERE id = ?', [$b]) === 'contacted', 'la acción masiva existente funciona desde la Tabla y vuelve a ella');
    Db::run("UPDATE leads SET stage = 'new', first_contact_at = NULL WHERE id = ?", [$b]);

    // ------------------------------------------------------------------ Calendario: rango, filtros, horario de verano
    $dayStart = static fn(string $ymd): int => (int) Clock::parse((string) Fmt::fromEtInput($ymd));
    $g = LeadCalendar::grid('mes', '2026-03-15');
    $byDay = array_column($g['days'], null, 'ymd');
    T::eq([$g['days'][0]['ymd'], $g['days'][count($g['days']) - 1]['ymd'], count($g['days'])], ['2026-02-23', '2026-04-05', 42], 'cuadrícula de marzo 2026: lunes 23 feb a domingo 5 abr (6 semanas completas)');
    T::eq([(int) (($byDay['2026-03-08']['end'] - $byDay['2026-03-08']['start']) / 3600), (int) (($byDay['2026-03-07']['end'] - $byDay['2026-03-07']['start']) / 3600)], [23, 24], 'cambio de horario de verano (8 mar 2026, ET): ese día mide 23 horas');
    $g2 = LeadCalendar::grid('mes', '2026-11-15');
    $bd2 = array_column($g2['days'], null, 'ymd');
    T::eq((int) (($bd2['2026-11-01']['end'] - $bd2['2026-11-01']['start']) / 3600), 25, 'fin del horario de verano (1 nov 2026, ET): ese día mide 25 horas');
    T::eq(array_map(static fn($d) => $d['ymd'], LeadCalendar::grid('semana', '2026-11-04')['days']), ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'], 'semana: lunes a domingo');
    T::eq(count(LeadCalendar::grid('dia', '2026-11-04')['days']), 1, 'día: una sola jornada');
    $febG = LeadCalendar::grid('mes', '2027-02-10');
    T::eq([$febG['days'][0]['ymd'], count($febG['days'])], ['2027-02-01', 28], 'febrero 2027 empieza en lunes y cabe en 4 semanas');
    // 23:30 locales del 31 de octubre = 03:30 UTC del 1 de noviembre: cae en SU día (octubre)
    $tOct = Db::insert('tasks', ['title' => 'Fin de mes', 'lead_id' => $a, 'due_at' => Fmt::fromEtInput('2026-10-31T23:30'), 'priority' => 2, 'created_at' => Clock::now()]);
    $tNov = Db::insert('tasks', ['title' => 'Inicio de mes', 'lead_id' => $a, 'due_at' => Fmt::fromEtInput('2026-11-01T00:15'), 'priority' => 2, 'created_at' => Clock::now()]);
    $tSpring = Db::insert('tasks', ['title' => 'Antes del cambio', 'lead_id' => $a, 'due_at' => Fmt::fromEtInput('2026-03-07T23:30'), 'priority' => 2, 'created_at' => Clock::now()]);
    $allTypes = array_keys(LeadCalendar::TYPES);
    $view = LeadCalendar::view('mes', '2026-10-15', [], ['task'], $now);
    $dd = array_column($view['days'], null, 'ymd');
    T::ok(count($dd['2026-10-31']['events']) === 1 && $dd['2026-10-31']['events'][0]['title'] === 'Fin de mes' && !isset($dd['2026-11-01']) === false && count($dd['2026-11-01']['events']) === 1 && $dd['2026-11-01']['events'][0]['title'] === 'Inicio de mes', 'fin de mes: una tarea a las 23:30 locales cae el 31 y la de las 00:15 el 1 (aunque en UTC ambas sean del 1)');
    $v2m = LeadCalendar::view('mes', '2026-03-15', [], ['task'], $now);
    T::eq(count(array_column($v2m['days'], null, 'ymd')['2026-03-07']['events']), 1, 'el día anterior al cambio de horario conserva su tarea de las 23:30');
    $outside = LeadCalendar::view('mes', '2026-06-15', [], ['task'], $now);
    T::eq($outside['total'], 0, 'carga por rango: un mes sin nada no trae tareas de otros meses');
    $tOutRange = Db::value('SELECT COUNT(*) FROM tasks');
    T::ok($tOutRange >= 3, '  (las tareas existen en la base: solo se leen las del rango visible)');
    // tipos de evento
    $apt = Db::insert('appointments', ['lead_id' => $a, 'starts_at' => Fmt::fromEtInput('2026-10-20T10:00'), 'status' => 'booked', 'title' => 'Llamada con Ana']);
    Db::insert('appointments', ['lead_id' => $a, 'starts_at' => Fmt::fromEtInput('2026-10-21T10:00'), 'status' => 'cancelled', 'title' => 'Cancelada']);
    Db::run("UPDATE leads SET created_at = ? WHERE id = ?", [Fmt::fromEtInput('2026-10-22T09:00'), $b]);
    Db::insert('sequence_enrollments', ['lead_id' => $a, 'sequence_key' => 'a6_warm', 'step' => 1, 'status' => 'active', 'next_run_at' => Fmt::fromEtInput('2026-10-23T08:00'), 'created_at' => Clock::now()]);
    $hf = Db::insert('handoffs', ['lead_id' => $c, 'attempt' => 1, 'version' => 1, 'nonce' => bin2hex(random_bytes(16)), 'expires_at' => Clock::shift(86400), 'created_at' => Clock::now()]);
    Db::insert('jobs', ['type' => 'handoff_deliver', 'payload' => ['handoff_id' => $hf, 'target' => 'email'], 'status' => 'pending', 'run_at' => Fmt::fromEtInput('2026-10-24T12:00'), 'created_at' => Clock::now()]);
    $oc = LeadCalendar::view('mes', '2026-10-15', [], $allTypes, $now);
    $kinds = static fn(array $view): array => array_count_values(array_column(array_merge(...array_column($view['days'], 'events')), 'kind'));
    $k = $kinds($oc);
    T::eq([$k['task'] ?? 0, $k['appt'] ?? 0, $k['first'] ?? 0, $k['seq'] ?? 0, $k['handoff'] ?? 0], [2, 1, 1, 1, 1], 'calendario: tareas (la cuadrícula de octubre llega hasta el 1 de noviembre), cita (no la cancelada), primer contacto pendiente, paso de secuencia y entrega programada, cada uno en su día');
    T::eq($kinds(LeadCalendar::view('mes', '2026-10-15', [], ['appt'], $now)), ['appt' => 1], 'chips por tipo: pedir solo citas trae solo citas');
    T::eq(LeadCalendar::types('appt,zzz,seq'), ['appt', 'seq'], '  los tipos inválidos se descartan; vacío o ninguno válido = todos');
    T::eq(LeadCalendar::types('zzz'), $allTypes, '  (ninguno válido = todos)');
    $fv = LeadCalendar::view('mes', '2026-10-15', ['pf' => 'company_driver'], $allTypes, $now);
    T::eq($fv['total'], 0, 'filtros de lead: con un filtro solo entran eventos de leads que lo cumplen (y no las tareas sin lead)');
    $loose = Db::insert('tasks', ['title' => 'Sin lead', 'lead_id' => null, 'due_at' => Fmt::fromEtInput('2026-10-25T10:00'), 'priority' => 3, 'created_at' => Clock::now()]);
    T::eq(array_column(array_merge(...array_column(LeadCalendar::view('mes', '2026-10-15', [], ['task'], $now)['days'], 'events')), 'title'), ['Sin lead', 'Fin de mes', 'Inicio de mes'], 'sin filtros entran también las tareas sin lead');
    T::eq($kinds(LeadCalendar::view('mes', '2026-10-15', ['pf' => 'owner_operator'], ['task'], $now)), ['task' => 2], '  con un filtro de perfil desaparecen las tareas sin lead (quedan las de leads que lo cumplen)');
    // tope por tipo
    $rows = [];
    for ($i = 0; $i < LeadCalendar::CAP + 5; $i++) {
        $rows[] = "('Masiva $i', $a, '" . Fmt::fromEtInput('2026-12-10T10:00') . "', 2, '" . Clock::now() . "')";
    }
    Db::pdo()->exec('INSERT INTO tasks (title, lead_id, due_at, priority, created_at) VALUES ' . implode(',', $rows));
    $big = LeadCalendar::view('mes', '2026-12-15', [], ['task'], $now);
    T::ok($big['truncated'] && $big['total'] === LeadCalendar::CAP, 'tope: con más tareas de las que caben se muestran ' . LeadCalendar::CAP . ' y se avisa (truncated)');
    Db::pdo()->exec("DELETE FROM tasks WHERE title LIKE 'Masiva %'");
    // HTTP calendario
    $r = $s->get('/calendario?vm=mes&d=2026-10-15');
    T::ok($r['status'] === 200 && str_contains($r['body'], 'Octubre 2026') && str_contains($r['body'], 'data-ymd="2026-10-31"') && str_contains($r['body'], 'Fin de mes') && str_contains($r['body'], 'aria-current="date"') === false && str_contains($r['body'], 'ev--appt'), 'GET /calendario (mes): título, días, eventos con su tipo');
    T::eq($pref('lmode'), 'calendario', '  recuerda el modo');
    $r = $s->get('/calendario?vm=semana&d=2026-10-22');
    T::ok(str_contains($r['body'], 'Primer contacto pendiente') && str_contains($r['body'], 'cal__g--w') && str_contains($r['body'], 'Correo programado'), 'semana: tarjetas con hora y tipo');
    T::eq($pref('calm'), 'semana', '  la escala elegida se recuerda por usuario');
    $r = $s->get('/calendario?d=2026-10-24');
    T::ok(str_contains($r['body'], 'cal__g--w') && str_contains($r['body'], 'Entrega a NorthStar'), 'sin vm usa la escala recordada (semana)');
    $r = $s->get('/calendario?vm=dia&d=2026-10-20');
    T::ok(str_contains($r['body'], 'cal__g--d') && str_contains($r['body'], 'Llamada con Ana') && str_contains($r['body'], 'Martes 20 de octubre 2026'), 'día: agenda de la jornada con la cita');
    $r = $s->get('/calendario?vm=zzz&d=2026-02-31&ev=zzz&nueva=basura');
    T::ok($r['status'] === 200 && !str_contains($r['body'], 'id="nueva"'), 'parámetros inválidos (escala, fecha imposible, tipos, día nuevo): 200 con valores seguros');
    $r = $s->get('/calendario?vm=mes&d=2026-10-15&nueva=2026-10-16');
    T::ok(str_contains($r['body'], 'id="nueva"') && str_contains($r['body'], 'action="/tareas/new"') && str_contains($r['body'], 'name="back" value="/calendario?'), 'sin JS: «nueva=día» muestra un formulario para crear la tarea que vuelve al calendario');
    $r = $s->get('/calendario?vm=mes&d=2026-10-15&ev=task');
    T::ok(str_contains($r['body'], 'Fin de mes') && !str_contains($r['body'], 'Llamada con Ana'), 'chips por tipo (ev=task): oculta las citas');
    $r = $s->post('/tareas/new', ['_csrf' => $csrf, 'back' => '/calendario?vm=mes&d=2026-10-15', 'title' => 'Desde el calendario', 'due' => '2026-10-26T09:00', 'priority' => '2']);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/calendario?vm=mes&d=2026-10-15' && Db::value("SELECT COUNT(*) FROM tasks WHERE title = 'Desde el calendario'") == 1, 'crear una tarea desde el calendario vuelve al calendario');
    $r = $s->post('/tareas/new', ['_csrf' => $csrf, 'back' => '//malo.example/x', 'title' => 'Con back malo', 'due' => '']);
    T::eq(Infra::header($r, 'location'), '/tareas', '  un «back» externo vuelve a /tareas');

    // ------------------------------------------------------------------ Reprogramar tarea (servicio)
    $tz = Fmt::tz();
    $t1 = Db::insert('tasks', ['title' => 'Llamar 9', 'lead_id' => $a, 'due_at' => Fmt::fromEtInput('2026-03-05T09:00'), 'priority' => 1, 'created_at' => Clock::now()]);
    $r = Tasks::reschedule($t1, '2026-03-09', $uid);
    $due = (string) Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t1]);
    T::ok($r['ok'] && Fmt::et($due)->format('Y-m-d H:i') === '2026-03-09 09:00' && $due === '2026-03-09 13:00:00', 'reprogramar conserva la hora LOCAL a través del cambio de horario (9:00 ET = 14:00 UTC antes, 13:00 UTC después)');
    T::ok(Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'task_rescheduled'", [$a]) == 1 && Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'task_resched'") == 1, '  deja evento en la ficha y registro en la actividad');
    T::ok(!Tasks::reschedule($t1, '2026-03-09', $uid)['ok'] && !Tasks::reschedule($t1, '2026-02-30', $uid)['ok'] && !Tasks::reschedule($t1, 'mañana', $uid)['ok'] && !Tasks::reschedule($t1, '2031-03-09', $uid)['ok'], '  mismo día, fecha imposible, texto y fecha lejanísima: se rechazan');
    T::ok(!Tasks::reschedule(999999, '2026-03-10', $uid)['ok'], '  una tarea que no existe, no');
    $done = Db::insert('tasks', ['title' => 'Hecha', 'lead_id' => $a, 'due_at' => Fmt::fromEtInput('2026-03-05T09:00'), 'done_at' => Clock::now(), 'priority' => 1, 'created_at' => Clock::now()]);
    T::ok(!Tasks::reschedule($done, '2026-03-10', $uid)['ok'], '  y una tarea ya hecha no se mueve');
    $nodue = Db::insert('tasks', ['title' => 'Sin hora', 'lead_id' => null, 'due_at' => null, 'priority' => 2, 'created_at' => Clock::now()]);
    $rn = Tasks::reschedule($nodue, '2026-10-27', $uid);
    T::eq(Fmt::et((string) Db::value('SELECT due_at FROM tasks WHERE id = ?', [$nodue]))->format('Y-m-d H:i'), '2026-10-27 09:00', 'una tarea sin fecha toma la hora de inicio del horario comercial');
    // deshacer
    $t2 = Db::insert('tasks', ['title' => 'Mover y deshacer', 'lead_id' => $a, 'due_at' => Fmt::fromEtInput('2026-10-26T14:00'), 'priority' => 2, 'created_at' => Clock::now()]);
    $orig = (string) Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t2]);
    $mv = Tasks::reschedule($t2, '2026-10-28', $uid);
    $tok = Undo::resched($uid, $t2, $mv['from'], $mv['due_at']);
    $ur = Undo::apply($tok, $uid);
    T::ok($ur['ok'] && Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t2]) === $orig, 'Deshacer: la tarea vuelve a su fecha y hora originales');
    $mv = Tasks::reschedule($t2, '2026-10-29', $uid);
    $tok = Undo::resched($uid, $t2, $mv['from'], $mv['due_at']);
    Tasks::reschedule($t2, '2026-10-30', $uid);
    T::ok(!Undo::apply($tok, $uid)['ok'] && Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t2]) !== $orig, 'Deshacer tras otro cambio de fecha: no se toca (la tarea cambió otra vez)');
    T::ok(!Undo::apply($tok, $uid + 999)['ok'], '  el token de otro usuario no sirve');
    Db::run('UPDATE tasks SET done_at = ? WHERE id = ?', [Clock::now(), $t2]);
    $mv3 = ['from' => $orig, 'due_at' => (string) Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t2])];
    T::ok(!Undo::apply(Undo::resched($uid, $t2, $mv3['from'], $mv3['due_at']), $uid)['ok'], '  ni si la tarea ya se completó');
    // HTTP: mover
    $t3 = Db::insert('tasks', ['title' => 'Arrastrada', 'lead_id' => $a, 'due_at' => Fmt::fromEtInput('2026-10-26T15:30'), 'priority' => 2, 'created_at' => Clock::now()]);
    $j = $s->json($s->ajax('/ajax/calendar/move', ['task_id' => $t3, 'ymd' => '2026-10-27']));
    T::ok(($j['ok'] ?? false) && $j['ymd'] === '2026-10-27' && $j['time'] === '15:30' && is_string($j['undo'] ?? null), 'POST /ajax/calendar/move: mueve de día, conserva la hora y devuelve el token de Deshacer');
    $u = $s->json($s->ajax('/ajax/undo', ['token' => $j['undo']]));
    T::ok(($u['ok'] ?? false) && Fmt::et((string) Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t3]))->format('Y-m-d H:i') === '2026-10-26 15:30', '  y /ajax/undo lo deshace');
    $j2 = $s->json($s->ajax('/ajax/calendar/move', ['task_id' => $t3, 'mode' => 'tomorrow']));
    T::ok(($j2['ok'] ?? false) && is_string($j2['undo'] ?? null), '«Posponer a mañana» también ofrece Deshacer');
    $bad = $s->ajax('/ajax/calendar/move', ['task_id' => $t3, 'ymd' => 'x']);
    T::eq($bad['status'], 422, 'día inválido: 422');
    T::eq($s->ajax('/ajax/calendar/move', ['task_id' => $t3, 'mode' => 'raro'])['status'], 422, '  modo inválido: 422');
    T::eq($s->ajax('/ajax/calendar/move', ['task_id' => $t3, 'ymd' => '2026-10-27'], 'falso')['status'], 403, '  sin CSRF: 403');
    T::eq(Infra::http('POST', $s->base . '/ajax/calendar/move', ['json' => ['task_id' => $t3, 'ymd' => '2026-10-27']])['status'], 401, '  sin sesión: 401');
    $r = $s->post('/calendario/move', ['_csrf' => $csrf, 'back' => '/calendario?vm=semana&d=2026-10-26', 'task_id' => $t3, 'ymd' => '2026-10-29']);
    T::ok($r['status'] === 303 && str_starts_with(Infra::header($r, 'location'), '/calendario?') && Fmt::et((string) Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t3]))->format('Y-m-d') === '2026-10-29', 'formulario sin JS «Mover a otro día»: mueve y vuelve al calendario');

    // ------------------------------------------------------------------ Cronología
    Db::pdo()->exec('DELETE FROM lead_events');
    $base = $now - 86400 * 3;
    $ins = [];
    for ($i = 0; $i < 70; $i++) {
        $lid = [$a, $b, $c, $d][$i % 4];
        $type = ['created', 'stage_changed', 'note_added', 'call_logged', 'scored', 'alert_sent', 'inbound_message'][$i % 7];
        $ins[] = [$lid, $type, json_encode($type === 'stage_changed' ? ['from' => 'new', 'to' => 'contacted', 'pipeline' => 'A'] : ($type === 'inbound_message' ? ['body' => 'SECRETO-DEL-MENSAJE'] : ($type === 'note_added' ? ['note_id' => 1] : ['source' => 'meta']))), Clock::at($base + $i * 600)];
    }
    foreach ($ins as [$lid, $type, $pl, $at]) {
        Db::run('INSERT INTO lead_events (lead_id, type, payload, created_at) VALUES (?, ?, ?, ?)', [$lid, $type, $pl, $at]);
    }
    Db::run("INSERT INTO lead_notes (lead_id, user_id, body, created_at) VALUES (?, ?, 'TEXTO-DE-LA-NOTA-SECRETA', ?)", [$a, $uid, Clock::now()]);
    $p1 = LeadFeed::page([], LeadFeed::types(null), null, null, $now);
    $n1 = array_sum(array_map(static fn($g) => count($g['items']), $p1['days']));
    T::ok($n1 === LeadFeed::PAGE && $p1['next'] !== null, 'cronología: la primera página trae ' . LeadFeed::PAGE . ' eventos y un cursor');
    $all = [];
    $cur = null;
    $pages = 0;
    do {
        $pg = LeadFeed::page([], LeadFeed::types(null), null, $cur, $now);
        foreach ($pg['days'] as $g) {
            foreach ($g['items'] as $it) {
                $all[] = $it['id'];
            }
        }
        $cur = $pg['next'];
        $pages++;
    } while ($cur !== null && $pages < 10);
    $expected = (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type <> 'scored'");
    T::ok(count($all) === $expected && count(array_unique($all)) === $expected, 'paginando con el cursor se recorren todos los eventos relevantes (' . $expected . ') sin repetir ni saltar (sin «puntuación», que es ruido)');
    $sorted = $all;
    rsort($sorted);
    $times = array_map(static fn($id) => (string) Db::value('SELECT created_at FROM lead_events WHERE id = ?', [$id]), $all);
    $timesSorted = $times;
    rsort($timesSorted);
    T::eq($times, $timesSorted, '  y salen de lo más nuevo a lo más viejo');
    // llegan eventos nuevos entre páginas: el cursor no repite
    $first = LeadFeed::page([], LeadFeed::types(null), null, null, $now);
    Db::run("INSERT INTO lead_events (lead_id, type, payload, created_at) VALUES (?, 'created', '{}', ?)", [$a, Clock::at($now)]);
    $second = LeadFeed::page([], LeadFeed::types(null), null, $first['next'], $now);
    $ids1 = array_merge(...array_map(static fn($g) => array_column($g['items'], 'id'), $first['days']));
    $ids2 = array_merge(...array_map(static fn($g) => array_column($g['items'], 'id'), $second['days']));
    T::ok(!array_intersect($ids1, $ids2), 'cursor estable: un evento nuevo llegado entre dos páginas no duplica ninguna fila');
    $onlyCalls = LeadFeed::page([], ['calls'], null, null, $now);
    $cats = array_unique(array_merge(...array_map(static fn($g) => array_column($g['items'], 'cat'), $onlyCalls['days'])));
    T::eq($cats, ['calls'], 'filtro por tipo: solo llamadas');
    $onlyLead = LeadFeed::page([], LeadFeed::types(null), $c, null, $now);
    $leadsSeen = array_unique(array_merge(...array_map(static fn($g) => array_column($g['items'], 'lead_id'), $onlyLead['days'])));
    T::eq($leadsSeen, [$c], 'filtro por lead: solo los eventos de ese lead');
    $drivers = LeadFeed::page(['pf' => 'company_driver'], LeadFeed::types(null), null, null, $now);
    $dseen = array_unique(array_merge(...array_map(static fn($g) => array_column($g['items'], 'lead_id'), $drivers['days'])));
    T::eq($dseen, [$d], 'los filtros de lead de la Lista también aplican (perfil = company driver)');
    T::eq([LeadFeed::types('calls,zzz'), LeadFeed::types('zzz'), LeadFeed::leadId('12'), LeadFeed::leadId('12;1'), LeadFeed::leadId('0')], [['calls'], array_keys(LeadFeed::TYPES), 12, null, null], 'tipos y lead de la URL: lista blanca y solo ids numéricos');
    T::ok(LeadFeed::decodeCursor('basura') === null && LeadFeed::decodeCursor(LeadFeed::encodeCursor('2026-11-01 10:00:00', 7)) === ['2026-11-01 10:00:00', 7] && LeadFeed::decodeCursor(rtrim(strtr(base64_encode("2026-11-01 10:00:00|7;DROP"), '+/', '-_'), '=')) === null, 'cursor: se valida (fecha e id), lo manipulado se descarta');
    // HTTP cronología
    $r = $s->get('/cronologia');
    T::ok($r['status'] === 200 && str_contains($r['body'], 'class="feed__g"') && str_contains($r['body'], 'data-feed-next=') && str_contains($r['body'], 'Cargar más'), 'GET /cronologia: eventos por día y botón «Cargar más»');
    T::ok(!str_contains($r['body'], 'SECRETO-DEL-MENSAJE') && !str_contains($r['body'], 'TEXTO-DE-LA-NOTA-SECRETA') && !str_contains($r['body'], '+13055550101') && preg_match('/fx\d+@example\.com/', $r['body']) === 0, '  la página no trae cuerpos de mensajes, texto de notas, teléfonos ni correos');
    preg_match('#data-feed-next="([^"]+)"#', $r['body'], $cm);
    $r2 = $s->get('/cronologia?cur=' . ($cm[1] ?? ''));
    T::ok($r2['status'] === 200 && str_contains($r2['body'], 'Volver a lo más reciente'), 'sin JS, «Cargar más» es un enlace a la página siguiente (?cur=)');
    $r3 = $s->get('/cronologia?cur=basura&tp=zzz&ld=abc');
    T::ok($r3['status'] === 200 && !str_contains($r3['body'], 'Volver a lo más reciente'), 'cursor, tipos y lead inválidos: 200 como si no se hubieran pedido');
    $j = $s->json($s->ajax('/ajax/timeline', ['qs' => 'tp=calls', 'cursor' => $cm[1] ?? '']));
    T::ok(($j['ok'] ?? false) && is_string($j['html'] ?? null) && array_key_exists('next', $j), 'POST /ajax/timeline: devuelve el HTML de la siguiente página y el cursor');
    T::eq($s->ajax('/ajax/timeline', ['qs' => '', 'cursor' => 'basura'])['status'], 422, '  cursor inválido: 422');
    T::eq($s->ajax('/ajax/timeline', ['qs' => '', 'cursor' => $cm[1] ?? ''], 'falso')['status'], 403, '  sin CSRF: 403');
    $r = $s->get('/cronologia?ld=' . $c . '&tp=calls');
    T::ok(str_contains($r['body'], 'Solo este lead') && str_contains($r['body'], 'Ver todos'), 'filtro por lead: aviso con «Ver todos»');
    // tope de tiempo
    T::throws(static fn() => Db::withTimeout(1, static fn() => Db::all('SELECT SLEEP(3)')), 'Db::withTimeout corta una consulta lenta y lanza QueryTimeout', 'tardó demasiado');
    T::eq(Db::withTimeout(2, static fn() => Db::value('SELECT 7')), 7, '  y una consulta normal pasa; el tope se restablece después');
    T::eq((int) Db::value('SELECT @@max_statement_time') === 0 || (float) Db::value('SELECT @@max_statement_time') === 0.0, true, '  (max_statement_time vuelve a 0)');

    // ------------------------------------------------------------------ Atajos y paleta
    $r = $s->get('/tabla');
    T::ok(str_contains($r['body'], '<dt><kbd>g</kbd> <kbd>b</kbd></dt><dd>Tabla</dd>') && str_contains($r['body'], '<kbd>g</kbd> <kbd>c</kbd></dt><dd>Calendario') && str_contains($r['body'], '<kbd>g</kbd> <kbd>r</kbd></dt><dd>Cronología') && str_contains($r['body'], 'Mover la tarea enfocada de día'), 'hoja de atajos: g b (Tabla), g c (Calendario) y g r (Cronología), sin chocar con g d, g l, g p y g t');
    $js = (string) file_get_contents($ctx['root'] . '/public/assets/ux.js');
    T::ok(str_contains($js, "b: '/tabla', c: '/calendario', r: '/cronologia'") && str_contains($js, "t: '/tareas'"), 'ux.js: el mapa de «g» lleva a /tabla, /calendario y /cronologia y conserva g t = tareas');
    $cmds = Commands::forUser(['role' => 'admin'], []);
    $titles = array_column($cmds['actions'], 1);
    T::ok(in_array('Ir a Tabla', $titles, true) && in_array('Ir a Calendario', $titles, true) && in_array('Ir a Cronología', $titles, true) && Commands::forUser(['role' => 'viewer'], []) === [], 'paleta: «Ir a Tabla», «Ir a Calendario» e «Ir a Cronología» solo para administradores');
    T::ok(str_contains($r['body'], 'Ir a Calendario') && str_contains($r['body'], '/cronologia'), '  y el catálogo viaja en la página del administrador');
    $r = $s->get('/');
    Prefs::set($uid, 'lmode', 'calendario');
    T::ok(str_contains($s->get('/')['body'], 'class="nav-i" href="/calendario"') || str_contains($s->get('/')['body'], 'href="/calendario" data-tip="Leads"'), 'la barra lateral lleva a «Leads» al último modo usado');
    Prefs::set($uid, 'lmode', 'lista');

    // ------------------------------------------------------------------ Autorización por rol: el lector no entra a NINGUNA ruta nueva
    $v = $s->another();
    T::ok($v->login($viewerU['email']), 'login del lector');
    $leak = ['Ana', 'Beto', 'Peligro', 'Zeta', 'Llamar 9', 'Fin de mes'];
    $badG = [];
    foreach (['/tabla', '/tabla?sort=pts', '/calendario', '/calendario?vm=semana', '/calendario?nueva=2026-10-16', '/cronologia', '/cronologia?tp=notes', '/cronologia?ld=' . $a, '/leads?v=tabla'] as $path) {
        $r = $v->get($path);
        $lk = array_filter($leak, static fn($p) => str_contains($r['body'], $p));
        if (!in_array($r['status'], [403, 302, 303], true) || $lk || str_contains($r['body'], 'tbl--tabla') || str_contains($r['body'], 'class="feed__g"')) {
            $badG[] = "GET $path -> {$r['status']}";
        }
    }
    T::ok(!$badG, 'lector: GET de cada ruta nueva (Tabla, Calendario, Cronología) → 403/redirección y sin datos' . ($badG ? ': ' . implode('; ', $badG) : ''));
    $badP = [];
    foreach (['/tabla/export' => ['scope' => 'filters'], '/tabla/cols' => ['reset' => '1'], '/calendario/move' => ['task_id' => $t3, 'ymd' => '2026-10-30'], '/tareas/new' => ['title' => 'x', 'back' => '/calendario']] as $path => $body) {
        $before = Db::value('SELECT COUNT(*) FROM tasks') . '|' . Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t3]) . '|' . Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'leads_export'");
        $r = $v->post($path, ['_csrf' => $v->csrf] + $body);
        $after = Db::value('SELECT COUNT(*) FROM tasks') . '|' . Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t3]) . '|' . Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'leads_export'");
        if (!in_array($r['status'], [403, 401, 404, 405], true) || str_contains(Infra::header($r, 'content-type'), 'csv') || $before !== $after) {
            $badP[] = "POST $path -> {$r['status']}";
        }
    }
    T::ok(!$badP, 'lector: POST de cada ruta nueva rechazado, sin CSV y sin cambiar nada' . ($badP ? ': ' . implode('; ', $badP) : ''));
    foreach (['/ajax/calendar/move' => ['task_id' => $t3, 'ymd' => '2026-10-30'], '/ajax/timeline' => ['qs' => '', 'cursor' => $cm[1] ?? LeadFeed::encodeCursor('2026-11-01 10:00:00', 7)]] as $path => $body) {
        $r = $v->ajax($path, $body);
        T::ok($r['status'] === 403 && str_contains($r['body'], 'forbidden') && !str_contains($r['body'], 'Ana'), "lector: $path (JSON) responde 403 forbidden");
    }
    $r = $v->ajax('/ajax/prefs', ['key' => 'lmode', 'value' => 'tabla']);
    T::eq($r['status'], 403, 'lector: no puede guardar el modo de vista (solo el tema)');
    T::ok(str_contains($v->get('/resultados')['body'], 'href="/resultados"') && !str_contains($v->get('/resultados')['body'], '/calendario') && !str_contains($v->get('/resultados')['body'], '/cronologia'), 'el shell del lector no trae enlaces a Tabla, Calendario ni Cronología');

    // ------------------------------------------------------------------ Seguridad de la salida
    $r = $s->get('/calendario?vm=mes&d=2026-10-15&q=' . rawurlencode('"><script>alert(1)</script>'));
    T::ok(!str_contains($r['body'], '<script>alert(1)') && !preg_match('/\sstyle="/i', $r['body']) && !preg_match('/\son[a-z]+="/i', $r['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $r['body']) === 0, 'calendario: la búsqueda se escapa, sin estilos ni handlers en línea y scripts con nonce');
    foreach (['/tabla', '/cronologia'] as $path) {
        $r = $s->get($path);
        T::ok(!preg_match('/\sstyle="/i', $r['body']) && !preg_match('/\son[a-z]+="/i', $r['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $r['body']) === 0, "$path: sin estilos ni handlers en línea, scripts con nonce");
    }
    Infra::stop('panel35');
    T::reset(true);
};
