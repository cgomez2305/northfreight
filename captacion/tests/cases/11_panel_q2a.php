<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Auth;
use Captacion\Services\Catalog;
use Captacion\Services\Crypto;
use Captacion\Services\Csv;
use Captacion\Services\Dashboard;
use Captacion\Services\Fmt;
use Captacion\Services\GlobalSearch;
use Captacion\Services\LeadActions;
use Captacion\Services\LeadQuery;
use Captacion\Services\Notifications;
use Captacion\Services\Queue;
use Captacion\Services\Totp;
use CaptacionTests\Infra;
use CaptacionTests\T;

/** Panel Q2a: dashboard con datos conocidos, bandeja, acciones sobre el lead, buscador, CSV, avisos y seguridad HTTP de cada endpoint nuevo. */
return static function (array $ctx): void {
    T::reset(true);
    $now = Clock::time();
    $mk = static function (array $o = []) use ($now): int {
        static $n = 0;
        $n++;
        $created = $o['created_at'] ?? Clock::at($now - 3600);
        unset($o['created_at']);
        $row = $o + [
            'profile' => 'owner_operator', 'first_name' => 'Test' . $n, 'last_name' => 'Lead' . $n, 'phone_e164' => '+1305555' . str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT), 'email' => "t$n@example.com",
            'state' => 'FL', 'language' => 'en', 'score' => 50, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'web_careers', 'attempt' => 1,
            'equipment_types' => ['dry-van'], 'flags' => [],
        ];
        $row['created_at'] = $created;
        $row['updated_at'] = $created;
        if (($row['profile'] ?? '') === 'company_driver' && !isset($o['pipeline'])) {
            $row['pipeline'] = 'B';
        }
        return Db::insert('leads', $row);
    };

    // ------------------------------------------------------------------ Dashboard con datos conocidos
    $d1 = Clock::at($now - 36 * 3600);   // dentro de los ultimos 7 dias
    $a = $mk(['temperature' => 'hot', 'score' => 80, 'created_at' => Clock::at($now - 600), 'source' => 'web_careers']);                                       // caliente sin contactar, hoy
    $b = $mk(['temperature' => 'hot', 'score' => 75, 'created_at' => $d1, 'first_contact_at' => Clock::at($now - 36 * 3600 + 600), 'stage' => 'contacted', 'source' => 'meta']);      // contactado a los 10 min
    $c = $mk(['temperature' => 'warm', 'created_at' => $d1, 'first_contact_at' => Clock::at($now - 36 * 3600 + 1800), 'stage' => 'contacted', 'source' => 'meta', 'profile' => 'company_driver']);   // 30 min
    $mk(['temperature' => 'cold', 'score' => 20, 'created_at' => $d1, 'source' => 'indeed']);
    $mk(['temperature' => 'disqualified', 'score' => 0, 'created_at' => $d1, 'source' => 'web_careers', 'disqualified_reason' => 'english_no']);
    $mk(['temperature' => 'warm', 'created_at' => Clock::at($now - 9 * 86400), 'source' => 'web_careers']);   // semana previa
    $mk(['temperature' => 'cold', 'created_at' => Clock::at($now - 10 * 86400), 'source' => 'web_careers']);
    $mk(['temperature' => 'hot', 'stage' => 'lost', 'created_at' => Clock::at($now - 200 * 86400), 'source' => 'web_careers']);  // fuera de la ventana de 26 semanas
    $total = (int) Db::value('SELECT COUNT(*) FROM leads');

    $w = Dashboard::build('week', $now);
    T::eq($w['leads']['n'], 5, 'dashboard (semana): 5 leads en los ultimos 7 dias');
    T::eq($w['leads']['prev'], 2, 'dashboard (semana): 2 leads en los 7 dias previos');
    T::eq([$w['leads']['delta']['dir'], $w['leads']['delta']['text']], ['up', '+3 leads'], 'dashboard: base previa de 2 leads (menos de 5): se dice la diferencia, no un porcentaje (Q6)');
    T::eq([$w['qualified']['n'], $w['qualified']['of'], round($w['qualified']['pct'])], [3, 5, 60.0], 'dashboard: calificados = caliente + tibio = 3 de 5 (60 %)');
    T::eq($w['hot']['n'], 1, 'dashboard: calientes sin contactar = 1 (el contactado no cuenta)');
    T::ok($w['hot']['oldest_secs'] !== null && $w['hot']['oldest_secs'] >= 590 && $w['hot']['oldest_secs'] <= 700, 'dashboard: espera del mas antiguo ~10 min');
    T::eq([$w['response']['median'], $w['response']['n']], [1200, 2], 'dashboard: mediana de primera respuesta = 20 min (10 y 30) sobre 2 contactados');
    $srcCounts = array_column($w['sources']['list'], 'n', 'key');
    T::eq([$srcCounts['web_careers'] ?? 0, $srcCounts['meta'] ?? 0, $srcCounts['indeed'] ?? 0], [4, 2, 1], 'dashboard: leads por fuente (90 dias) cuadran con la base (incluye los de la semana previa)');
    T::eq($w['sources']['total'], 7, 'dashboard: total de 90 dias = 7 (el de hace 200 dias no entra)');
    T::eq($w['chart']['weekly']['total'], 7, 'grafico semanal (12 semanas): 7 leads = base menos el de hace 200 dias');
    T::eq(array_sum($w['chart']['weekly']['ops']) + array_sum($w['chart']['weekly']['drv']), 7, '  suma de las dos series = total');
    T::eq(array_sum($w['chart']['weekly']['drv']), 1, '  1 company driver en la serie de drivers');
    T::eq($w['heat']['total'], 7, 'mapa de calor (13 semanas): 7 leads');
    $cellSum = 0;
    foreach ($w['heat']['cells'] as $col) {
        foreach ($col as $cell) {
            $cellSum += $cell['n'];
        }
    }
    T::eq($cellSum, 7, '  suma de celdas = total');
    T::eq($w['strip']['n'], 7, 'franja secundaria: 7 leads en 12 semanas');
    T::ok($w['strip']['cpl'] === null && $w['strip']['has_costs'] === false, 'costo por lead: null y "sin costos" mientras channel_costs este vacia (nunca un numero inventado)');
    $t = Dashboard::build('today', $now);
    T::ok(Fmt::dayStart($now) > $now - 600 || $t['leads']['n'] === 1, 'dashboard (hoy): solo el lead de hace 10 min');
    $m = Dashboard::build('month', $now);
    T::eq([$m['leads']['n'], $m['leads']['prev']], [7, 0], 'dashboard (mes): 7 en 30 dias, 0 en los 30 previos (el de hace 200 d no cuenta)');
    T::eq($m['leads']['delta']['text'], '+7 nuevos', '  sin base previa: "+7 nuevos" (no un porcentaje inventado)');
    Db::insert('channel_costs', ['source' => 'meta', 'period' => Fmt::et($now)->format('Y-m'), 'amount' => 100]);
    $s = Dashboard::build('week', $now)['strip'];
    $mLeads = (int) Db::value("SELECT COUNT(*) FROM leads WHERE source = 'meta' AND deleted_at IS NULL AND created_at >= ?", [Fmt::utc(Fmt::monthStart($now))]);
    T::ok($mLeads > 0 ? ($s['cpl'] !== null && abs($s['cpl'] - 100 / $mLeads) < 0.01) : $s['cpl'] === null, 'costo por lead con costos: 100 USD / leads de meta del mes (' . $mLeads . ')');
    Db::pdo()->exec('DELETE FROM channel_costs');
    T::eq(Dashboard::median([5, 1, 3]), 3, 'mediana impar');
    T::eq(Dashboard::median([1, 3]), 2, 'mediana par');
    T::eq(Dashboard::delta(10, 0)['text'], '+10 nuevos', 'variacion sobre cero sin porcentaje infinito');
    T::eq(Dashboard::delta(0, 0)['dir'], 'flat', 'variacion 0 vs 0: plana');
    T::eq(Dashboard::delta(30, 20, false)['good'], false, 'variacion de una metrica donde bajar es bueno: subir es malo');

    // tareas y citas de hoy
    Db::insert('tasks', ['title' => 'Llamar hoy', 'lead_id' => $a, 'due_at' => Clock::at($now), 'priority' => 1]);
    Db::insert('tasks', ['title' => 'Hecha', 'lead_id' => $a, 'due_at' => Clock::at($now), 'done_at' => Clock::now()]);
    Db::insert('tasks', ['title' => 'Futura', 'lead_id' => $a, 'due_at' => Clock::at($now + 3 * 86400)]);
    $today = Dashboard::today($now);
    T::eq(array_column($today['tasks'], 'title'), ['Llamar hoy'], 'lista "Hoy": solo la tarea abierta de hoy (ni la hecha ni la futura)');

    // ------------------------------------------------------------------ Bandeja: filtros, orden, paginacion
    T::reset();
    for ($i = 1; $i <= 30; $i++) {
        $mk([
            'first_name' => 'Pag' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'last_name' => 'Demo', 'score' => $i, 'temperature' => $i <= 5 ? 'hot' : ($i <= 15 ? 'warm' : 'cold'),
            'created_at' => Clock::at($now - $i * 3600), 'state' => $i % 2 ? 'TX' : 'FL', 'language' => $i % 3 === 0 ? 'es' : 'en', 'source' => $i % 4 === 0 ? 'meta' : 'web_careers',
            'profile' => $i % 5 === 0 ? 'company_driver' : 'owner_operator', 'equipment_types' => $i % 2 ? ['reefer'] : ['dry-van', 'flatbed'], 'usdot' => $i === 7 ? '1234567' : null,
            'phone_e164' => '+1214555' . str_pad((string) (2000 + $i), 4, '0', STR_PAD_LEFT), 'stage' => $i === 3 ? 'lost' : 'new',
        ]);
    }
    $pg = LeadQuery::paging([]);
    $l1 = LeadQuery::list([], $pg);
    T::eq([$l1['total'], count($l1['rows']), $l1['pages']], [30, 25, 2], 'bandeja: 30 leads, 25 por pagina, 2 paginas');
    T::eq(count(LeadQuery::list([], ['page' => 2] + $pg)['rows']), 5, 'bandeja: la pagina 2 trae los 5 restantes');
    T::eq(LeadQuery::list([], ['page' => 99] + $pg)['page'], 2, 'bandeja: una pagina fuera de rango se limita a la ultima');
    T::eq($l1['rows'][0]['first_name'], 'Pag01', 'bandeja: orden por defecto = mas reciente primero');
    T::eq(LeadQuery::list([], ['sort' => 'score', 'dir' => 'desc'] + $pg)['rows'][0]['first_name'], 'Pag30', 'bandeja: orden por puntuacion descendente');
    T::eq(LeadQuery::list([], ['sort' => 'name', 'dir' => 'asc'] + $pg)['rows'][0]['first_name'], 'Pag01', 'bandeja: orden por nombre ascendente');
    $cnt = static fn(array $q): int => LeadQuery::list(LeadQuery::filters($q), $pg)['total'];
    T::eq($cnt(['t' => 'hot']), 5, 'filtro temperatura: 5 calientes');
    T::eq($cnt(['pf' => 'company_driver']), 6, 'filtro perfil: 6 company drivers');
    T::eq($cnt(['src' => 'meta']), 7, 'filtro fuente: 7 de meta');
    T::eq($cnt(['st' => 'lost']), 1, 'filtro etapa: 1 perdido');
    T::eq($cnt(['eq' => 'reefer']), 15, 'filtro equipo: 15 con reefer');
    T::eq($cnt(['eq' => 'flatbed']), 15, 'filtro equipo (JSON con varios): 15 con flatbed');
    T::eq($cnt(['us' => 'tx']), 15, 'filtro estado (acepta minusculas): 15 en TX');
    T::eq($cnt(['lg' => 'es']), 10, 'filtro idioma: 10 en espanol');
    T::eq($cnt(['q' => 'Pag07']), 1, 'texto: por nombre');
    T::eq($cnt(['q' => '1234567']), 1, 'texto: por USDOT');
    T::eq($cnt(['q' => '214 555 2005']), 1, 'texto: por telefono con formato');
    T::eq($cnt(['q' => 't1@example.com']), 0, 'texto: por correo inexistente = 0');
    T::eq($cnt(['q' => '%']), 0, 'texto: el comodin % se trata como texto literal');
    T::eq($cnt(['t' => 'warm', 'src' => 'meta']), 2, 'combinacion de filtros (tibio + meta): 2');
    $dateOf = static fn(int $ts): string => Fmt::et($ts)->format('Y-m-d');
    T::eq($cnt(['from' => $dateOf($now - 3 * 3600), 'to' => $dateOf($now)]) >= 1, true, 'filtro de fechas (ET): incluye los de hoy');
    T::eq($cnt(['from' => '2999-01-01']), 0, 'filtro de fechas futuras: 0');
    T::eq(LeadQuery::filters(['t' => 'x', 'pf' => 'x', 'st' => 'x', 'us' => 'ZZ', 'from' => 'no', 'sort' => 'DROP']), [], 'valores fuera de la lista blanca se descartan');
    $tc = LeadQuery::tempCounts(LeadQuery::filters(['src' => 'meta']));
    T::eq([$tc['all'], $tc['hot'] + $tc['warm'] + $tc['cold'] + $tc['disqualified']], [7, 7], 'conteos por temperatura respetan los demas filtros');
    T::eq(count(LeadQuery::exportRows(LeadQuery::filters(['t' => 'hot']))), 5, 'exportar por filtro: 5 filas');
    T::eq(count(LeadQuery::exportRows(null, [1, 2, 'x', -3])), 2, 'exportar seleccion: ids invalidos se ignoran');
    $ids = array_column($l1['rows'], 'id');

    // ------------------------------------------------------------------ Acciones: etapas
    $u = (int) Db::insert('users', ['name' => 'Admin Q2', 'email' => 'q2@example.com', 'password_hash' => Auth::hashPassword('pw-q2a-larga-123'), 'password_algo' => Auth::algo(), 'totp_secret' => Crypto::encrypt('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'), 'totp_confirmed_at' => Clock::now(), 'role' => 'admin', 'is_active' => 1]);
    T::reset();
    $hot = $mk(['temperature' => 'hot', 'score' => 90, 'created_at' => Clock::at($now - 300)]);
    Queue::push('telegram_alert_followup', ['lead_id' => $hot], Clock::shift(900), 'telegram_followup:' . $hot . ':1');
    $evCount = static fn(int $id, string $type): int => (int) Db::value('SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = ?', [$id, $type]);
    $stage = static fn(int $id): string => (string) Db::value('SELECT stage FROM leads WHERE id = ?', [$id]);
    $r = LeadActions::setStage($hot, 'contacted', [], $u);
    T::ok($r['ok'], 'etapa: Nuevo -> Contactado');
    T::ok(Db::value('SELECT first_contact_at FROM leads WHERE id = ?', [$hot]) !== null, '  "Contactado" fija first_contact_at');
    T::eq((string) Db::value("SELECT status FROM jobs WHERE dedupe_key = ?", ['telegram_followup:' . $hot . ':1']), 'cancelled', '  y cancela la segunda alerta A3 pendiente');
    T::eq([$evCount($hot, 'stage_changed'), $evCount($hot, 'a3_cancelled')], [1, 1], '  deja evento de etapa y de A3 cancelada');
    T::eq((int) Db::value("SELECT user_id FROM lead_events WHERE lead_id = ? AND type = 'stage_changed'", [$hot]), $u, '  el evento lleva el usuario');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'lead_stage' AND entity_id = ?", [(string) $hot]), 1, '  y queda en activity_log');
    T::ok(!LeadActions::setStage($hot, 'contacted', [], $u)['ok'], 'etapa: repetir la misma es un error');
    T::ok(!LeadActions::setStage($hot, 'signed', [], $u)['ok'] && $stage($hot) === 'contacted', 'etapa: "Firmado" desde Contactado se rechaza (solo en orden)');
    T::ok(!LeadActions::setStage($hot, 'presented', [], $u)['ok'], 'etapa: "Presentado" desde Contactado se rechaza sin override');
    T::ok(LeadActions::setStage($hot, 'presented', ['override' => true], $u)['ok'] && $stage($hot) === 'presented', 'etapa: "Presentado" con override explicito pasa');
    T::eq((string) Db::value('SELECT handoff_status FROM leads WHERE id = ?', [$hot]), 'pending', '  Presentado marca handoff pendiente y handed_off_at');
    T::ok(LeadActions::setStage($hot, 'signed', [], $u)['ok'] && Db::value('SELECT signed_at FROM leads WHERE id = ?', [$hot]) !== null, 'etapa: Presentado -> Firmado en orden, fija signed_at');
    T::ok(!LeadActions::setStage($hot, 'contacted', [], $u)['ok'], 'etapa: salir de Firmado exige override');
    T::ok(!LeadActions::setStage($hot, 'lost', [], $u)['ok'], 'etapa: Perdido exige motivo');
    T::ok(!LeadActions::setStage($hot, 'lost', ['reason' => 'inventado', 'override' => true], $u)['ok'], 'etapa: motivo fuera de la lista se rechaza');
    T::ok(!LeadActions::setStage($hot, 'lost', ['reason' => 'other', 'override' => true], $u)['ok'], 'etapa: motivo "Otro" exige nota');
    T::ok(LeadActions::setStage($hot, 'lost', ['reason' => 'price', 'override' => true], $u)['ok'] && $stage($hot) === 'lost', 'etapa: Perdido con motivo valido');
    T::eq([(string) Db::value('SELECT lost_reason FROM leads WHERE id = ?', [$hot]), (string) Db::value('SELECT handoff_status FROM leads WHERE id = ?', [$hot])], ['price', 'rejected'], '  guarda motivo y marca el traspaso como rechazado');
    T::ok(!LeadActions::setStage($hot, 'contacted', ['override' => true], $u)['ok'], 'etapa: un perdido no cambia de etapa sin reabrir');
    T::ok(LeadActions::reopen($hot, $u)['ok'] && $stage($hot) === 'contacted' && Db::value('SELECT lost_reason FROM leads WHERE id = ?', [$hot]) === null, 'reabrir: vuelve a Contactado y limpia el motivo');
    T::ok(!LeadActions::reopen($hot, $u)['ok'], 'reabrir un lead que no esta perdido: error');
    // pipeline B
    $drv = $mk(['profile' => 'company_driver', 'created_at' => Clock::at($now - 300)]);
    T::ok(LeadActions::setStage($drv, 'prequalified', [], $u)['ok'], 'pipeline B: Nuevo -> Precalificado');
    T::ok(!LeadActions::setStage($drv, 'qualified', [], $u)['ok'], 'pipeline B: "Calificado" (de A) no existe');
    T::ok(!LeadActions::setStage($drv, 'signed', ['override' => true], $u)['ok'], 'pipeline B: "Firmado" no existe (es Contratado)');
    T::ok(LeadActions::setStage($drv, 'presented', [], $u)['ok'] && LeadActions::setStage($drv, 'hired', [], $u)['ok'], 'pipeline B: Presentado -> Contratado en orden');
    // masivo
    $b1 = $mk([]);
    $b2 = $mk(['profile' => 'company_driver']);
    $b3 = $mk([]);
    $bulk = LeadActions::bulkStage([$b1, $b2, $b3, 999999], 'qualified', [], $u);
    T::eq([$bulk['changed'], $bulk['skipped']], [3, 1], 'masivo: "Calificado" se traduce a Precalificado en el pipeline B; el id inexistente se omite');
    T::eq($stage($b2), 'prequalified', '  el driver quedo en Precalificado');
    $bl = LeadActions::bulkStage([$b1], 'lost', [], $u);
    T::ok(!$bl['ok'] && $stage($b1) === 'qualified', 'masivo: Perdido sin motivo no cambia nada');
    T::eq(LeadActions::bulkStage([$b1], 'signed', [], $u)['changed'], 0, 'masivo: solo contactado / calificado / perdido');
    // A3 al llamar
    $h2 = $mk(['temperature' => 'hot', 'created_at' => Clock::at($now - 300)]);
    Queue::push('telegram_alert_followup', ['lead_id' => $h2], Clock::shift(900), 'telegram_followup:' . $h2 . ':1');
    T::ok(!LeadActions::logCall($h2, 'inventado', null, '', $u)['ok'], 'llamada: resultado fuera de la lista se rechaza');
    T::ok(LeadActions::logCall($h2, 'no_answer', 2, 'Buzon', $u)['ok'] && $stage($h2) === 'new' && Db::value('SELECT first_contact_at FROM leads WHERE id = ?', [$h2]) === null, 'llamada sin respuesta: queda registrada y NO cuenta como contacto');
    T::ok(LeadActions::logCall($h2, 'answered', 7, '', $u)['ok'] && $stage($h2) === 'contacted' && Db::value('SELECT first_contact_at FROM leads WHERE id = ?', [$h2]) !== null, 'llamada contestada: fija first_contact_at y pasa a Contactado');
    T::eq((string) Db::value("SELECT status FROM jobs WHERE dedupe_key = ?", ['telegram_followup:' . $h2 . ':1']), 'cancelled', '  y cancela la alerta A3');
    T::eq((int) Db::value("SELECT duration_sec FROM messages WHERE lead_id = ? AND status = 'answered'", [$h2]), 420, '  guarda la duracion en segundos');
    T::ok(!LeadActions::logCall($h2, 'answered', 9999, '', $u)['ok'], 'llamada: duracion fuera de rango se rechaza');
    // notas
    T::ok(!LeadActions::addNote($h2, "   ", $u)['ok'], 'nota vacia: error');
    T::ok(LeadActions::addNote($h2, "Hablé con él <b>hoy</b>", $u)['ok'], 'nota: se guarda');
    $nid = (int) Db::value('SELECT id FROM lead_notes WHERE lead_id = ?', [$h2]);
    T::ok(LeadActions::editNote($h2, $nid, 'Nota editada', $u)['ok'] && Db::value('SELECT body FROM lead_notes WHERE id = ?', [$nid]) === 'Nota editada', 'nota: se edita');
    T::ok(!LeadActions::editNote($hot, $nid, 'x', $u)['ok'], 'nota: no se edita desde otro lead');
    T::ok(LeadActions::deleteNote($h2, $nid, $u)['ok'] && Db::value('SELECT deleted_at FROM lead_notes WHERE id = ?', [$nid]) !== null, 'nota: borrado logico (la fila sigue)');
    T::ok(!LeadActions::deleteNote($h2, $nid, $u)['ok'], 'nota: borrar dos veces, error');
    // tareas
    T::ok(!LeadActions::addTask($h2, '', '', 2, $u)['ok'], 'tarea sin titulo: error');
    T::ok(!LeadActions::addTask($h2, 'X', '31/12/2026', 2, $u)['ok'], 'tarea con fecha invalida: error');
    T::ok(LeadActions::addTask($h2, 'Pedir seguro', '2026-12-01T09:30', 1, $u)['ok'], 'tarea: se crea');
    $tid = (int) Db::value('SELECT id FROM tasks WHERE lead_id = ?', [$h2]);
    T::eq((string) Db::value('SELECT due_at FROM tasks WHERE id = ?', [$tid]), '2026-12-01 14:30:00', '  la hora escrita en ET (09:30) se guarda en UTC (14:30)');
    T::ok(LeadActions::completeTask($h2, $tid, $u)['ok'] && !LeadActions::completeTask($h2, $tid, $u)['ok'], 'tarea: se completa una sola vez');
    T::ok(!LeadActions::completeTask($hot, $tid, $u)['ok'], 'tarea: no se completa desde otro lead');
    // consentimiento
    Db::insert('consents', ['lead_id' => $h2, 'channel' => 'sms', 'consent_text' => 'texto', 'consent_version' => 'v1', 'granted_at' => Clock::now()]);
    T::ok(LeadActions::revokeConsent($h2, $u)['ok'] && Db::value('SELECT revoked_at FROM consents WHERE lead_id = ?', [$h2]) !== null, 'revocar consentimiento: marca revoked_at');
    T::ok(in_array('stop', Db::decode(Db::value('SELECT flags FROM leads WHERE id = ?', [$h2])), true), '  y la bandera stop');
    T::ok(!LeadActions::revokeConsent($h2, $u)['ok'], '  revocar de nuevo: no hay nada vigente');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action IN ('note_add','note_edit','note_delete','task_add','task_done','call_log','consent_revoke','lead_reopen','leads_bulk_stage')"), 11, 'activity_log registra cada accion (notas, tareas, llamadas, revocacion, reabrir, masivo)');

    // ------------------------------------------------------------------ Buscador y avisos
    T::reset();
    $s1 = $mk(['first_name' => 'Marina', 'last_name' => 'Ortega', 'phone_e164' => '+13055550142', 'usdot' => '3456789', 'email' => 'marina.ortega@example.com']);
    $mk(['first_name' => 'Otro', 'last_name' => 'Nombre']);
    T::eq(count(GlobalSearch::run('o')['items']), 0, 'buscador: menos de 2 caracteres no busca');
    T::eq(GlobalSearch::run('ortega')['items'][0]['id'], $s1, 'buscador: por apellido');
    T::eq(GlobalSearch::run('305-555-0142')['items'][0]['id'], $s1, 'buscador: por telefono con guiones');
    T::eq(GlobalSearch::run('3456789')['items'][0]['id'], $s1, 'buscador: por USDOT');
    T::eq(GlobalSearch::run('marina.ortega@example')['total'], 1, 'buscador: por correo');
    T::eq(GlobalSearch::run("'; DROP TABLE leads;--")['total'], 0, 'buscador: SQL en la consulta no rompe nada');
    T::ok((int) Db::value('SELECT COUNT(*) FROM leads') === 2, '  la tabla sigue intacta');
    $hotN = $mk(['temperature' => 'hot', 'created_at' => Clock::at($now - 1200)]);
    $items = Notifications::all($u);
    $kinds = array_column($items, 'kind', 'key');
    T::ok(in_array('hot', $kinds, true) && count(array_filter($items, static fn($i) => str_starts_with($i['key'], 'telegram_unconfigured'))) === 1, 'avisos: caliente sin contactar y Telegram sin configurar');
    $before = Notifications::unread($items);
    T::ok($before >= 2, 'avisos: hay sin leer (' . $before . ')');
    T::eq(Notifications::unread(array_map(static fn($i) => $i, Notifications::all($u))), $before, '  el conteo es estable');
    Notifications::markRead($u);
    T::eq(Notifications::unread(Notifications::all($u)), 0, 'avisos: marcar todo como leido se guarda en base');
    $mk(['temperature' => 'hot', 'created_at' => Clock::at($now - 100)]);
    T::ok(Notifications::unread(Notifications::all($u)) >= 1, '  un nuevo lead caliente vuelve a generar aviso (la clave incluye el estado)');
    $fj = Queue::push('t_fail', []);
    Db::update('jobs', (int) $fj, ['status' => 'failed', 'last_error' => 'x', 'attempts' => 4]);
    T::ok(count(array_filter(Notifications::all($u), static fn($i) => str_starts_with($i['key'], 'failed_jobs') && !$i['read'])) === 1, 'avisos: trabajo fallido en la cola');
    Notifications::markRead($u, ['clave-que-no-existe']);
    T::ok(count(array_filter(Notifications::all($u), static fn($i) => str_starts_with($i['key'], 'failed_jobs') && !$i['read'])) === 1, '  marcar una clave inexistente no marca otras');

    // ------------------------------------------------------------------ CSV
    T::eq(Csv::cell('=HYPERLINK("x")'), "\"'=HYPERLINK(\"\"x\"\")\"", 'CSV: una celda que empieza por = se prefija con apostrofo (y se entrecomilla)');
    T::eq([Csv::cell('+1305'), Csv::cell('-5'), Csv::cell('@x'), Csv::cell("\tx")], ["'+1305", "'-5", "'@x", "'\tx"], 'CSV: + - @ y tab tambien');
    T::eq(Csv::cell('a,b'), '"a,b"', 'CSV: comas se entrecomillan');
    T::eq(Csv::cell("l1\nl2"), "\"l1\nl2\"", 'CSV: saltos de linea se entrecomillan');
    $mk(['first_name' => '=cmd|calc', 'last_name' => 'Test']);
    $csv = Csv::leads(LeadQuery::exportRows([]));
    T::ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "'=cmd|calc Test") && !preg_match('/(^|,)=cmd/', $csv), 'CSV de leads: BOM UTF-8 y el nombre con formula queda neutralizado');

    // ------------------------------------------------------------------ HTTP: autorizacion, CSRF y limites de cada endpoint nuevo
    T::reset();
    $leadA = $mk(['first_name' => 'Acceso', 'last_name' => 'Prueba', 'temperature' => 'hot', 'created_at' => Clock::at($now - 300), 'phone_e164' => '+13055550199']);
    Db::insert('consents', ['lead_id' => $leadA, 'channel' => 'call', 'consent_text' => 'texto', 'consent_version' => 'v1', 'granted_at' => Clock::now()]);
    $work = $ctx['work'];
    $envFile = "$work/panel-q2.env";
    file_put_contents($envFile, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($ctx['env']), $ctx['env'])) . "\n");
    $port = Infra::phpServer('panel-q2', $ctx['root'] . '/public', ['CAPTACION_ENV_FILE' => $envFile]);
    $B = "http://127.0.0.1:$port";
    $csrfOf = static fn(string $html) => preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
    $jar = "$work/jar-q2.txt";
    @unlink($jar);
    $get = static fn(string $p, ?string $j = null, array $h = []) => Infra::http('GET', $B . $p, ['jar' => $j ?? $jar, 'headers' => $h]);
    $post = static fn(string $p, array $f, ?string $j = null, array $h = []) => Infra::http('POST', $B . $p, ['form' => $f, 'jar' => $j ?? $jar, 'headers' => $h]);
    $ajax = static fn(string $p, mixed $body, ?string $tok, ?string $j = null) => Infra::http('POST', $B . $p, ['json' => $body, 'jar' => $j ?? $jar, 'headers' => $tok !== null ? ['X-CSRF-Token: ' . $tok] : []]);
    $anon = "$work/jar-q2-anon.txt";
    @unlink($anon);

    T::eq($get('/leads', $anon)['status'], 303, 'sin sesion: /leads redirige a /login');
    T::eq($get("/leads/$leadA", $anon)['status'], 303, 'sin sesion: la ficha redirige a /login');
    T::eq($post("/leads/$leadA/note", ['body' => 'x'], $anon)['status'], 303, 'sin sesion: POST de nota redirige a /login');
    T::eq($post('/leads/export', ['scope' => 'filters'], $anon)['status'], 303, 'sin sesion: exportar redirige a /login');
    T::eq($post('/leads/bulk', ['do' => 'export'], $anon)['status'], 303, 'sin sesion: accion masiva redirige a /login');
    T::eq($ajax('/ajax/search', ['q' => 'abc'], 'x', $anon)['status'], 401, 'sin sesion: /ajax/search responde 401 JSON');
    T::eq($ajax('/ajax/notifications/read', [], 'x', $anon)['status'], 401, 'sin sesion: /ajax/notifications/read responde 401');
    T::eq((int) Db::value('SELECT COUNT(*) FROM lead_notes'), 0, '  ninguna nota creada sin sesion');

    // login con contrasena + 2FA (paso a paso)
    $g = $get('/login');
    $r = $post('/login', ['_csrf' => $csrfOf($g['body']), 'email' => 'q2@example.com', 'password' => 'pw-q2a-larga-123']);
    T::eq($r['status'], 303, 'login (contrasena) correcto');
    $g = $get('/login/2fa');
    $r = $post('/login/2fa', ['_csrf' => $csrfOf($g['body']), 'code' => Totp::code('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP')]);
    T::eq([$r['status'], Infra::header($r, 'location')], [303, '/'], 'login (2FA) correcto');
    $home = $get('/');
    T::eq($home['status'], 200, 'dashboard con sesion: 200');
    $csrf = $csrfOf($home['body']);
    T::ok($csrf !== '' && preg_match('/data-csrf="' . preg_quote($csrf, '/') . '"/', $home['body']) === 1, 'el token CSRF viaja en el formulario y en el script');
    T::ok(preg_match('/<script(?![^>]*\bnonce=")/i', $home['body']) === 0 && !preg_match('/\sstyle="/i', $home['body']), 'dashboard: scripts con nonce y sin style= en linea');
    foreach (['/leads', "/leads/$leadA", '/login'] as $pth) {
        $pr = $get($pth, $pth === '/login' ? $anon : null);
        T::ok(!preg_match('/\sstyle="/i', $pr['body']) && !preg_match('/\son\w+="/i', $pr['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $pr['body']) === 0, "$pth: sin estilos ni handlers en linea, scripts con nonce");
    }
    $lp = $get('/leads?t=hot&q=Acceso');
    T::ok($lp['status'] === 200 && str_contains($lp['body'], 'Acceso Prueba') && str_contains($lp['body'], 'aria-current="true"'), 'bandeja con filtros por URL: 200 y muestra el lead');
    T::eq($get('/leads?t=%27%3Bdrop&page=-5&per=9999&sort=zz')['status'], 200, 'bandeja con parametros hostiles: 200 (se normalizan)');
    $fp = $get("/leads/$leadA");
    T::ok($fp['status'] === 200 && str_contains($fp['body'], 'Revocar consentimiento') && str_contains($fp['body'], 'href="tel:+13055550199"') && str_contains($fp['body'], 'wa.me/13055550199'), 'ficha: botones Llamar (tel:) y WhatsApp, y revocar consentimiento');
    // CSRF en acciones
    $nBefore = (int) Db::value('SELECT COUNT(*) FROM lead_notes');
    $r = $post("/leads/$leadA/note", ['body' => 'sin token']);
    T::ok($r['status'] === 303 && (int) Db::value('SELECT COUNT(*) FROM lead_notes') === $nBefore, 'POST de nota sin CSRF: no crea nada');
    $r = $post("/leads/$leadA/note", ['_csrf' => 'mal', 'body' => 'token malo']);
    T::ok($r['status'] === 303 && (int) Db::value('SELECT COUNT(*) FROM lead_notes') === $nBefore, 'POST de nota con CSRF invalido: no crea nada');
    $r = $post("/leads/$leadA/note", ['_csrf' => $csrf, 'body' => 'Nota <img src=x onerror=alert(1)>']);
    T::ok($r['status'] === 303 && (int) Db::value('SELECT COUNT(*) FROM lead_notes') === $nBefore + 1, 'POST de nota con CSRF: crea la nota');
    $fp = $get("/leads/$leadA");
    T::ok(str_contains($fp['body'], 'Nota &lt;img src=x onerror=alert(1)&gt;') && !str_contains($fp['body'], '<img src=x'), 'la nota con HTML sale escapada');
    $r = $post("/leads/$leadA/stage", ['stage' => 'contacted']);
    T::ok(Db::value('SELECT stage FROM leads WHERE id = ?', [$leadA]) === 'new', 'cambio de etapa sin CSRF: no cambia');
    $r = $post("/leads/$leadA/stage", ['_csrf' => $csrf, 'stage' => 'contacted']);
    T::ok($r['status'] === 303 && Db::value('SELECT stage FROM leads WHERE id = ?', [$leadA]) === 'contacted', 'cambio de etapa con CSRF: cambia');
    $r = $post("/leads/$leadA/stage", ['_csrf' => $csrf, 'stage' => 'lost', 'reason' => '']);
    T::ok(Db::value('SELECT stage FROM leads WHERE id = ?', [$leadA]) === 'contacted', 'perdido sin motivo por HTTP: se rechaza');
    T::eq($post("/leads/$leadA/inventada", ['_csrf' => $csrf])['status'], 404, 'accion desconocida: 404');
    T::eq($get("/leads/$leadA/note")['status'], 303, 'GET a una accion: no ejecuta nada (redirige a la ficha)');
    $r = $post("/leads/$leadA/revoke", ['_csrf' => $csrf]);
    T::ok(Db::value('SELECT revoked_at FROM consents WHERE lead_id = ?', [$leadA]) !== null, 'revocar consentimiento por HTTP con CSRF');
    // exportar
    $r = $post('/leads/export', ['scope' => 'filters', 'qs' => 't=hot']);
    T::ok(!str_contains(Infra::header($r, 'content-type'), 'text/csv'), 'exportar sin CSRF: no entrega CSV');
    $r = $post('/leads/export', ['_csrf' => $csrf, 'scope' => 'filters', 'qs' => 'q=Acceso']);
    T::ok($r['status'] === 200 && str_contains(Infra::header($r, 'content-type'), 'text/csv') && str_contains(Infra::header($r, 'content-disposition'), 'attachment') && str_starts_with($r['body'], "\xEF\xBB\xBF") && str_contains($r['body'], 'Acceso Prueba'), 'exportar con CSRF: CSV adjunto con BOM y el lead filtrado');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'leads_export'"), 1, '  la exportacion queda en activity_log');
    $r = $post('/leads/export', ['_csrf' => $csrf, 'scope' => 'period', 'period' => 'month']);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'ID,'), 'exportar el periodo del dashboard: CSV');
    $r = $post('/leads/bulk', ['_csrf' => $csrf, 'do' => 'export', 'ids' => [(string) $leadA, 'x']]);
    T::ok(str_contains(Infra::header($r, 'content-type'), 'text/csv') && str_contains($r['body'], 'Acceso Prueba'), 'exportar la seleccion (acepta solo ids validos)');
    // AJAX
    T::eq(Infra::http('GET', "$B/ajax/search", ['jar' => $jar])['status'], 405, '/ajax/search con GET: 405');
    T::eq($ajax('/ajax/search', ['q' => 'Acceso'], null)['status'], 403, '/ajax/search sin X-CSRF-Token: 403');
    T::eq($ajax('/ajax/search', ['q' => 'Acceso'], 'incorrecto')['status'], 403, '/ajax/search con token malo: 403');
    $r = $ajax('/ajax/search', ['q' => 'Acceso'], $csrf);
    $j = json_decode($r['body'], true);
    T::ok($r['status'] === 200 && ($j['ok'] ?? false) && ($j['items'][0]['id'] ?? 0) === $leadA && str_contains(Infra::header($r, 'content-type'), 'application/json'), '/ajax/search con CSRF: JSON con el lead');
    $r = Infra::http('POST', "$B/ajax/search", ['raw' => '{no es json', 'jar' => $jar, 'headers' => ['X-CSRF-Token: ' . $csrf, 'Content-Type: application/json']]);
    T::eq($r['status'], 400, '/ajax/search con JSON mal formado: 400');
    $r = $ajax('/ajax/notifications/read', [], $csrf);
    T::ok($r['status'] === 200 && (json_decode($r['body'], true)['ok'] ?? false) === true && (int) Db::value('SELECT COUNT(*) FROM notification_reads') >= 1, '/ajax/notifications/read: guarda lo leido en base');
    $limited = 0;
    for ($i = 0; $i < 95; $i++) {
        if ($ajax('/ajax/search', ['q' => 'zz' . $i], $csrf)['status'] === 429) {
            $limited++;
        }
    }
    T::ok($limited >= 1, '/ajax/search: limite de uso por usuario (429 pasado el tope de ' . \Captacion\Controllers\AjaxController::SEARCH_LIMIT . '/min)');
    Infra::stop('panel-q2');
};
