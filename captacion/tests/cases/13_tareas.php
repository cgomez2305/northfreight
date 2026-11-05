<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Fmt;
use Captacion\Services\LeadActions;
use Captacion\Services\Notifications;
use Captacion\Services\Tasks;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Tareas y agenda (Q2b-B): servicio (crear, completar, reabrir, posponer, editar, borrar), pestañas Hoy/Vencidas/Proximas/Hechas/Semana, citas, ficha = misma tabla, y HTTP por rol. */
return static function (array $ctx): void {
    T::reset(true);
    $admin = Panel::user('admin');
    $uid = $admin['id'];
    // Reloj fijo: hoy a las 12:00 en la zona de visualizacion (los limites de dia no dependen de la hora real de la prueba)
    $noon = (new DateTimeImmutable('today 12:00', Fmt::tz()))->getTimestamp();
    Clock::travel($noon - time());
    $now = Clock::time();
    $mkLead = static function (string $first = 'Tarea') use ($now): int {
        static $n = 0;
        $n++;
        return Db::insert('leads', ['profile' => 'owner_operator', 'first_name' => $first, 'last_name' => 'Lead' . $n, 'phone_e164' => '+1305557' . str_pad((string) (2000 + $n), 4, '0', STR_PAD_LEFT), 'state' => 'FL', 'language' => 'en', 'score' => 50,
            'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'web_careers', 'attempt' => 1, 'created_at' => Clock::at($now - 7200), 'updated_at' => Clock::at($now - 7200)]);
    };
    $lead = $mkLead('Ana');
    $local = static fn(int $ts) => Fmt::toEtInput(Clock::at($ts));   // valor de un input datetime-local para un instante

    // ------------------------------------------------------------------ Crear
    T::ok(!Tasks::create('   ', '', 2, null, $uid)['ok'], 'crear: el titulo vacio se rechaza');
    T::ok(!Tasks::create('x', '2026-13-45', 2, null, $uid)['ok'] && !Tasks::create('x', 'manana', 2, null, $uid)['ok'], 'crear: una fecha invalida se rechaza');
    T::ok(!Tasks::create('x', '', 2, 999999, $uid)['ok'], 'crear: un lead inexistente se rechaza');
    $r = Tasks::create("  Llamar a Ana\0  ", $local($now + 3600), 9, $lead, $uid);
    $t1 = $r['id'];
    $row = Db::one('SELECT * FROM tasks WHERE id = ?', [$t1]);
    T::ok($r['ok'] && $row['title'] === 'Llamar a Ana' && (int) $row['priority'] === 2 && (int) $row['lead_id'] === $lead && $row['created_by'] !== null && $row['due_at'] === Clock::at($now + 3600), 'crear: guarda titulo limpio, prioridad normalizada a Media, lead, autor y fecha en UTC');
    T::eq((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'task_created' AND user_id = ?", [$lead, $uid]), 1, '  deja evento en la linea de tiempo del lead');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'task_add'"), 1, '  y registro en activity_log');
    $r = Tasks::create('Revisar el tablero', '', 1, null, $uid);
    $tNoLead = $r['id'];
    T::ok($r['ok'] && Db::value('SELECT lead_id FROM tasks WHERE id = ?', [$tNoLead]) === null && Db::value('SELECT due_at FROM tasks WHERE id = ?', [$tNoLead]) === null, 'crear: una tarea sin lead y sin fecha es valida');
    $r = Tasks::create('Solo fecha', '2099-03-04', 3, null, $uid);
    T::eq(Db::value('SELECT due_at FROM tasks WHERE id = ?', [$r['id']]), Fmt::fromEtInput('2099-03-04T09:00'), 'crear: una fecha sin hora usa el inicio del horario comercial (09:00)');
    // la ficha usa la misma tabla
    LeadActions::addTask($lead, 'Desde la ficha', $local($now + 7200), 2, $uid);
    T::ok(in_array('Desde la ficha', array_column(array_merge(...array_column(Tasks::listTab('today', $now), 'items')), 'title'), true), 'una tarea creada en la ficha aparece en /tareas (misma tabla)');

    // ------------------------------------------------------------------ Pestañas
    $over1 = Tasks::create('Vencida ayer', $local($now - 86400), 1, $lead, $uid)['id'];
    $over2 = Tasks::create('Vencida hace una hora', $local($now - 3600), 2, null, $uid)['id'];
    $tom = Tasks::create('Manana', $local($now + 86400), 2, null, $uid)['id'];
    $doneId = Tasks::create('Ya hecha', $local($now + 1800), 2, $lead, $uid)['id'];
    T::ok(Tasks::complete($doneId, $uid)['ok'] && !Tasks::complete($doneId, $uid)['ok'], 'completar: una vez; la segunda dice que ya estaba completada');
    $names = static fn(array $groups) => array_values(array_map(static fn($i) => $i['title'] ?? 'cita', array_merge([], ...array_column($groups, 'items'))));
    $today = $names(Tasks::listTab('today', $now));
    T::ok(in_array('Llamar a Ana', $today, true) && in_array('Desde la ficha', $today, true) && !in_array('Vencida hace una hora', $today, true) && !in_array('Manana', $today, true) && !in_array('Ya hecha', $today, true), 'pestana Hoy: lo que falta de hoy (ni vencidas, ni mañana, ni hechas)');
    T::eq($names(Tasks::listTab('overdue', $now)), ['Vencida ayer', 'Vencida hace una hora'], 'pestana Vencidas: abiertas con fecha anterior a ahora, la mas antigua primero');
    $up = $names(Tasks::listTab('upcoming', $now));
    T::ok(in_array('Manana', $up, true) && in_array('Solo fecha', $up, true) && in_array('Revisar el tablero', $up, true) && !in_array('Llamar a Ana', $up, true), 'pestana Proximas: de mañana en adelante y sin fecha');
    T::eq(end($up), 'Revisar el tablero', '  las tareas sin fecha van al final');
    T::eq($names(Tasks::listTab('done', $now)), ['Ya hecha'], 'pestana Hechas: completadas');
    $c = Tasks::counts($now);
    T::eq([$c['overdue'], $c['today'], $c['done']], [2, 2, 1], 'conteos de pestanas: 2 vencidas, 2 hoy, 1 hecha');
    T::eq(Tasks::overdueCount(), 2, 'contador de vencidas (barra lateral y campana) = 2');
    $groups = Tasks::listTab('upcoming', $now);
    T::ok($groups[0]['label'] === 'Mañana · ' . Fmt::weekdayShort((int) Fmt::et($now + 86400)->format('N')) . ' ' . Fmt::dayLabel(Fmt::et($now + 86400)) && end($groups)['label'] === 'Sin fecha', 'agrupado por dia: «Mañana · …» primero y «Sin fecha» al final');
    T::eq(Tasks::dayName(Fmt::et($now)->format('Y-m-d'), $now), 'Hoy · ' . Fmt::weekdayShort((int) Fmt::et($now)->format('N')) . ' ' . Fmt::dayLabel(Fmt::et($now)), 'etiqueta de dia: «Hoy · …»');
    // citas
    $ap1 = Db::insert('appointments', ['lead_id' => $lead, 'starts_at' => Clock::at($now + 5400), 'ends_at' => Clock::at($now + 7200), 'status' => 'booked']);
    $ap2 = Db::insert('appointments', ['lead_id' => $lead, 'starts_at' => Clock::at($now + 3 * 86400), 'status' => 'booked']);
    $ap3 = Db::insert('appointments', ['lead_id' => $lead, 'starts_at' => Clock::at($now - 2 * 86400), 'status' => 'completed']);
    Db::insert('appointments', ['lead_id' => $lead, 'starts_at' => Clock::at($now + 600), 'status' => 'booked', 'deleted_at' => Clock::now()]);
    $kinds = static fn(array $groups) => array_map(static fn($i) => $i['kind'], array_merge([], ...array_column($groups, 'items')));
    T::eq(count(array_filter($kinds(Tasks::listTab('today', $now)), static fn($k) => $k === 'appt')), 1, 'citas: la de hoy sale en Hoy (la borrada no)');
    T::eq(count(array_filter($kinds(Tasks::listTab('upcoming', $now)), static fn($k) => $k === 'appt')), 1, 'citas: la futura sale en Proximas');
    T::eq(count(array_filter($kinds(Tasks::listTab('done', $now)), static fn($k) => $k === 'appt')), 1, 'citas: la pasada sale en Hechas');
    $todayItems = array_merge([], ...array_column(Tasks::listTab('today', $now), 'items'));
    $times = array_map(static fn($i) => $i['at'], $todayItems);
    $sortedTimes = $times;
    sort($sortedTimes);
    T::eq($times, $sortedTimes, 'Hoy mezcla tareas y citas ordenadas por hora');
    // semana
    $w = Tasks::week(null, $now);
    T::eq([count($w['days']), $w['days'][0]['short']], [7, 'lun'], 'semana: 7 dias de lunes a domingo');
    $inWeek = array_sum(array_map(static fn($d) => count($d['items']), $w['days']));
    T::ok($inWeek >= 4 && count(array_filter($w['days'], static fn($d) => $d['today'])) === 1, 'semana: incluye tareas y citas de la semana y marca hoy');
    $nw = Tasks::week($w['next'], $now);
    T::ok($nw['days'][0]['ymd'] === $w['next'] && $nw['prev'] === $w['from'], 'semana: navegar a la siguiente');
    T::eq(Tasks::week('basura', $now)['from'], $w['from'], 'semana: una fecha invalida cae en la semana actual');

    // ------------------------------------------------------------------ Posponer, reabrir, editar, borrar
    $before = Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t1]);
    $r = Tasks::snooze($t1, '1h', '', $uid);
    T::eq(Db::value('SELECT due_at FROM tasks WHERE id = ?', [$t1]), Clock::at((int) Clock::parse((string) $before) + 3600), 'posponer +1 h: suma una hora a su fecha (si aun no vencio)');
    Tasks::snooze($over2, '1h', '', $uid);
    T::eq(Db::value('SELECT due_at FROM tasks WHERE id = ?', [$over2]), Clock::at($now + 3600), 'posponer +1 h una vencida: una hora desde ahora');
    Tasks::snooze($over1, 'tomorrow', '', $uid);
    $tmr = Fmt::fromEtInput(Fmt::et($now)->modify('+1 day')->format('Y-m-d') . 'T09:00');
    T::eq(Db::value('SELECT due_at FROM tasks WHERE id = ?', [$over1]), $tmr, 'posponer a mañana: manana a la hora de inicio del horario comercial');
    T::eq(Tasks::overdueCount(), 0, '  ya no hay vencidas');
    T::ok(!Tasks::snooze($tom, 'date', $local($now - 600), $uid)['ok'], 'posponer a una fecha pasada: se rechaza');
    T::ok(!Tasks::snooze($tom, 'date', '', $uid)['ok'] && !Tasks::snooze($tom, 'otra', '', $uid)['ok'] && !Tasks::snooze($doneId, '1h', '', $uid)['ok'], 'posponer: sin fecha, modo invalido o tarea hecha se rechazan');
    $r = Tasks::snooze($tom, 'date', $local($now + 5 * 86400), $uid);
    T::ok($r['ok'] && Db::value('SELECT due_at FROM tasks WHERE id = ?', [$tom]) === Clock::at($now + 5 * 86400), 'posponer a una fecha elegida');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type = 'task_snoozed'") === 2 && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'task_snooze'") === 4, '  deja evento y activity_log en cada posposicion');
    T::ok(Tasks::reopen($doneId, $uid)['ok'] && Db::value('SELECT done_at FROM tasks WHERE id = ?', [$doneId]) === null && !Tasks::reopen($doneId, $uid)['ok'], 'reabrir: vuelve a abierta (y no se reabre dos veces)');
    $r = Tasks::update($t1, 'Llamar a Ana (cambiado)', $local($now + 9000), 1, null, $uid);
    $row = Db::one('SELECT title, priority, lead_id FROM tasks WHERE id = ?', [$t1]);
    T::ok($r['ok'] && $row['title'] === 'Llamar a Ana (cambiado)' && (int) $row['priority'] === 1 && $row['lead_id'] === null, 'editar: cambia titulo, prioridad y desvincula el lead');
    T::ok(!Tasks::update($t1, '', '', 2, null, $uid)['ok'] && !Tasks::update(999999, 'x', '', 2, null, $uid)['ok'], 'editar: titulo vacio o tarea inexistente se rechazan');
    T::ok(Tasks::delete($tNoLead, $uid)['ok'] && Db::value('SELECT deleted_at FROM tasks WHERE id = ?', [$tNoLead]) !== null && !Tasks::delete($tNoLead, $uid)['ok'], 'borrar: es logico (deleted_at) y no se borra dos veces');
    T::ok(!in_array('Revisar el tablero', $names(Tasks::listTab('upcoming', $now)), true), '  la borrada ya no se lista');
    T::ok((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action IN ('task_reopen','task_edit','task_delete','task_done')") >= 4, 'cada accion deja registro en activity_log');
    // campana
    $over = Tasks::create('Vencida para la campana', $local($now - 7200), 2, $lead, $uid);
    T::ok(count(array_filter(Notifications::all($uid), static fn($n) => str_starts_with($n['key'], 'tasks_overdue:1:'))) === 1, 'campana: aviso de tareas vencidas con su conteo');
    Clock::travel(0);
    Tasks::resetCache();

    // ------------------------------------------------------------------ HTTP
    $s = Panel::start($ctx, 'panel-tareas');
    $anon = $s->another();
    T::eq($anon->get('/tareas')['status'], 303, 'sin sesion: /tareas redirige a /login');
    T::eq($anon->post('/tareas/new', ['title' => 'x'])['status'], 303, 'sin sesion: crear tarea redirige a /login');
    T::ok($s->login($admin['email']), 'login del administrador');
    $viewer = Panel::user('viewer');
    $v = $s->another();
    T::ok($v->login($viewer['email']), 'login del lector');
    $pg = $s->get('/tareas');
    T::ok($pg['status'] === 200 && str_contains($pg['body'], 'Nueva tarea') && str_contains($pg['body'], '/assets/tareas.js') && str_contains($pg['body'], 'aria-current="true"'), 'pagina /tareas: pestanas y boton Nueva tarea');
    T::ok(!preg_match('/\sstyle="/i', $pg['body']) && !preg_match('/\son\w+="/i', $pg['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $pg['body']) === 0, '/tareas: sin estilos ni handlers en linea, scripts con nonce');
    foreach (['overdue', 'upcoming', 'done', 'week'] as $tabName) {
        T::eq($s->get('/tareas?tab=' . $tabName)['status'], 200, "pestana $tabName: 200");
    }
    T::eq($s->get('/tareas?tab=%27%3B&w=%00')['status'], 200, 'parametros hostiles: 200');
    $before = (int) Db::value('SELECT COUNT(*) FROM tasks');
    $r = $s->post('/tareas/new', ['title' => 'Sin token']);
    T::ok($r['status'] === 303 && (int) Db::value('SELECT COUNT(*) FROM tasks') === $before, 'crear sin CSRF: no crea');
    $r = $s->post('/tareas/new', ['_csrf' => $s->csrf, 'title' => 'Tarea <b>web</b>', 'due' => '2099-05-05T10:30', 'priority' => '1', 'lead_id' => (string) $lead, 'back' => '/tareas?tab=upcoming']);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/tareas?tab=upcoming' && (int) Db::value('SELECT COUNT(*) FROM tasks') === $before + 1, 'crear con CSRF: crea y vuelve a la misma pestana');
    $webId = (int) Db::value("SELECT id FROM tasks WHERE title = 'Tarea <b>web</b>'");
    $up = $s->get('/tareas?tab=upcoming');
    T::ok(str_contains($up['body'], 'Tarea &lt;b&gt;web&lt;/b&gt;') && !str_contains($up['body'], 'Tarea <b>web</b>'), 'el titulo con HTML sale escapado');
    $r = $s->post('/tareas/new', ['_csrf' => $s->csrf, 'title' => 'x', 'back' => 'https://evil.example/']);
    T::eq(Infra::header($r, 'location'), '/tareas', 'el parametro back solo acepta rutas internas de /tareas');
    $r = $s->post("/tareas/$webId/done", ['_csrf' => $s->csrf]);
    T::ok($r['status'] === 303 && Db::value('SELECT done_at FROM tasks WHERE id = ?', [$webId]) !== null, 'completar por HTTP');
    $s->post("/tareas/$webId/reopen", ['_csrf' => $s->csrf]);
    T::ok(Db::value('SELECT done_at FROM tasks WHERE id = ?', [$webId]) === null, 'reabrir por HTTP');
    $s->post("/tareas/$webId/snooze", ['_csrf' => $s->csrf, 'mode' => 'date', 'date' => '2099-06-06T08:00']);
    T::eq(Db::value('SELECT due_at FROM tasks WHERE id = ?', [$webId]), Fmt::fromEtInput('2099-06-06T08:00'), 'posponer a fecha por HTTP');
    $s->post("/tareas/$webId/edit", ['_csrf' => $s->csrf, 'title' => 'Editada', 'due' => '', 'priority' => '3', 'lead_id' => '']);
    T::ok(Db::value('SELECT title FROM tasks WHERE id = ?', [$webId]) === 'Editada' && Db::value('SELECT due_at FROM tasks WHERE id = ?', [$webId]) === null, 'editar por HTTP (quitar la fecha)');
    T::eq($s->post("/tareas/$webId/inventada", ['_csrf' => $s->csrf])['status'], 404, 'accion desconocida: 404');
    T::eq($s->get("/tareas/$webId/done")['status'], 303, 'GET a una accion: no ejecuta (redirige)');
    // lector: ve pero no escribe
    $vp = $v->get('/tareas');
    T::ok($vp['status'] === 403 && !str_contains($vp['body'], 'Nueva tarea') && !str_contains($vp['body'], 'data-edit'), 'lector: /tareas responde 403 (Q3: solo ve resultados e informes)');
    $r = $v->post("/tareas/$webId/delete", ['_csrf' => $v->csrf]);
    T::ok($r['status'] === 403 && Db::value('SELECT deleted_at FROM tasks WHERE id = ?', [$webId]) === null, 'lector: borrar por HTTP responde 403 y no hace nada');
    $r = $v->post('/tareas/new', ['_csrf' => $v->csrf, 'title' => 'Del lector']);
    T::ok((int) Db::value("SELECT COUNT(*) FROM tasks WHERE title = 'Del lector'") === 0, 'lector: crear por HTTP no hace nada');
    T::ok(str_contains($v->get('/tareas')['body'], 'solo puede ver los resultados'), '  y le explica qué sí puede ver');
    $s->post("/tareas/$webId/delete", ['_csrf' => $s->csrf]);
    T::ok(Db::value('SELECT deleted_at FROM tasks WHERE id = ?', [$webId]) !== null, 'borrar por HTTP (administrador)');
    $sidebar = $s->get('/leads')['body'];
    T::ok(str_contains($sidebar, 'badge--late') && str_contains($sidebar, 'href="/tareas"'), 'la barra lateral muestra Tareas con el contador de vencidas');
    $ex = Db::all("EXPLAIN SELECT t.id FROM tasks t WHERE t.deleted_at IS NULL AND t.done_at IS NULL AND t.due_at < NOW()");
    T::ok(($ex[0]['key'] ?? null) !== null, 'EXPLAIN de vencidas: usa indice (' . ($ex[0]['key'] ?? 'ninguno') . ')');
    Infra::stop('panel-tareas');
};
