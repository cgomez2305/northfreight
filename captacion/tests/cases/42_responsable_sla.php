<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Alerts;
use Captacion\Services\Assignment;
use Captacion\Services\DailyDigest;
use Captacion\Services\Fmt;
use Captacion\Services\JobHandlers;
use Captacion\Services\LeadActions;
use Captacion\Services\LeadIngest;
use Captacion\Services\LeadQuery;
use Captacion\Services\Notifications;
use Captacion\Services\Queue;
use Captacion\Services\Reports;
use Captacion\Services\SavedViews;
use Captacion\Services\Settings;
use Captacion\Services\Sla;
use Captacion\Services\Storage;
use Captacion\Services\Undo;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase AM-operacion: responsable del lead (solo admins activos, evento, masivo con Deshacer, reparto automatico), SLA de primer contacto (indicador, campana, tarea automatica, escalado
 * por la cola), resumen diario de las 8:00, metricas por responsable en /reportes (no en /resultados) y accesibilidad de la lista y la ficha.
 */
return static function (array $ctx): void {
    T::reset(true);
    Db::pdo()->exec('DELETE FROM channel_costs');
    $clean = static function (): void {
        Db::pdo()->exec("DELETE FROM settings WHERE `key` LIKE 'sla.%' OR `key` = 'assign' OR `key` = 'alerts'");
        Settings::clear();
    };
    $clean();
    $work = $ctx['work'];
    $a1 = Panel::user('admin');
    $a2 = Panel::user('admin');
    $viewer = Panel::user('viewer');
    $off = Panel::user('admin');
    Db::run('UPDATE users SET is_active = 0 WHERE id = ?', [$off['id']]);
    Db::run("UPDATE users SET name = 'Ana Admin' WHERE id = ?", [$a1['id']]);
    Db::run("UPDATE users SET name = 'Beto Admin' WHERE id = ?", [$a2['id']]);
    $u1 = $a1['id'];
    $u2 = $a2['id'];
    $count = static fn(string $t, string $w = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $w");
    $now = Clock::time();
    $mk = static fn(array $o = []): int => Fx::lead($o + ['created_at' => Clock::at(Clock::time() - 600), 'stage_changed_at' => null]);

    // ------------------------------------------------------------------ Responsable: solo administradores activos
    T::eq(array_keys(Assignment::admins()), [$u1, $u2], 'admins(): solo administradores activos (ni el lector ni el desactivado)');
    $L1 = $mk();
    $r = Assignment::assign($L1, $u1, $u2);
    T::ok($r['ok'] && $r['changed'] && (int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$L1]) === $u1, 'asignar: guarda el responsable');
    $ev = Db::one("SELECT payload, user_id FROM lead_events WHERE lead_id = ? AND type = 'assigned'", [$L1]);
    $pl = json_decode((string) $ev['payload'], true);
    T::ok((int) $ev['user_id'] === $u2 && (int) $pl['to'] === $u1 && $pl['to_name'] === 'Ana Admin', '  deja el evento «assigned» con quién lo hizo y los nombres');
    T::eq($count('activity_log', "action = 'lead_assign' AND entity_id = '$L1'"), 1, '  y un registro en activity_log');
    $again = Assignment::assign($L1, $u1, $u2);
    T::ok($again['ok'] && !$again['changed'] && $count('lead_events', "lead_id = $L1 AND type = 'assigned'") === 1, 'asignar a quien ya lo tiene: sin cambios y sin evento nuevo');
    T::ok(!Assignment::assign($L1, (int) $viewer['id'], $u1)['ok'] && !Assignment::assign($L1, (int) $off['id'], $u1)['ok'] && !Assignment::assign($L1, 999999, $u1)['ok'], 'no se asigna a un lector, a un desactivado ni a un usuario que no existe');
    T::eq((int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$L1]), $u1, '  y el responsable no cambió');
    T::ok(!Assignment::assign(999999, $u1, $u1)['ok'], 'asignar un lead que no existe: error claro');
    $r = Assignment::assign($L1, null, $u2);
    T::ok($r['changed'] && Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$L1]) === null, 'quitar el responsable (sin asignar)');

    // ------------------------------------------------------------------ Masivo + Deshacer
    $B = [$mk(), $mk(), $mk()];
    Assignment::assign($B[0], $u2, $u1);
    $bulk = Assignment::bulkAssign($B, $u1, $u1);
    T::ok($bulk['ok'] && $bulk['changed'] === 3 && count($bulk['changes']) === 3, 'masivo: 3 leads asignados');
    T::eq($count('activity_log', "action = 'leads_bulk_assign'"), 1, '  con UN solo registro en activity_log');
    T::eq($count('lead_events', "type = 'assigned' AND lead_id IN (" . implode(',', $B) . ')'), 4, '  y un evento por lead');
    $again = Assignment::bulkAssign($B, $u1, $u1);
    T::ok($again['changed'] === 0 && $again['skipped'] === 3, 'masivo repetido: nada cambia');
    T::ok(!Assignment::bulkAssign($B, (int) $viewer['id'], $u1)['ok'] && !Assignment::bulkAssign([], $u1, $u1)['ok'], 'masivo: ni a un lector ni sin selección');
    $tok = Undo::assign($u1, $bulk['changes']);
    T::ok($tok !== null && Undo::apply($tok, $u2)['ok'] === false, 'Deshacer: el token es del usuario que lo hizo (otro no puede usarlo)');
    Assignment::assign($B[1], $u2, $u1);   // alguien lo movió después
    $un = Undo::apply($tok, $u1);
    T::ok($un['ok'] && str_contains($un['msg'], '2 asignaciones deshechas') && str_contains($un['msg'], '1 no se pudieron deshacer'), 'Deshacer: revierte solo lo que sigue como se puso (el que se movió después no se toca)');
    T::eq([Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$B[0]]) === null ? 0 : (int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$B[0]]), (int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$B[1]])], [$u2, $u2], '  el primero vuelve a su responsable anterior; el movido se queda');

    // ------------------------------------------------------------------ Reparto automático
    T::eq(Assignment::config()['auto'], false, 'reparto automático: APAGADO por defecto');
    $payload = static fn(string $n, string $phone): array => ['profile' => 'owner-operator', 'name' => $n, 'phone' => $phone, 'email' => strtolower(str_replace(' ', '.', $n)) . '@example.com', 'cdl_standard' => 'yes', 'english' => 'yes', 'years' => '3'];
    $r0 = LeadIngest::handle($payload('Cero Auto', '3055550301'), 'web_careers');
    T::eq(Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$r0['lead_id']]), null, '  apagado: el lead nuevo llega sin responsable');
    $v = Assignment::validate(['assign_auto' => '1']);
    T::ok(!$v['ok'] && isset($v['errors']['assign_users']), 'validar: encender el reparto sin marcar a nadie es un error');
    $v = Assignment::validate(['assign_auto' => '1', 'assign_users' => [(string) $u1, (string) $viewer['id'], (string) $off['id'], 'x']]);
    T::eq($v['values']['users'], [$u1], '  y solo cuentan los administradores activos');
    Settings::set('assign', ['auto' => true, 'users' => [$u1, $u2], 'last' => 0]);
    $ids = [];
    foreach ([['Uno Auto', '3055550311'], ['Dos Auto', '3055550312'], ['Tres Auto', '3055550313'], ['Cuatro Auto', '3055550314']] as [$n, $ph]) {
        $ids[] = (int) LeadIngest::handle($payload($n, $ph), 'web_careers')['lead_id'];
    }
    T::eq(array_map(static fn($i) => (int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$i]), $ids), [$u1, $u2, $u1, $u2], 'reparto encendido: round-robin entre los marcados, en orden');
    $evA = json_decode((string) Db::value("SELECT payload FROM lead_events WHERE lead_id = ? AND type = 'assigned'", [$ids[0]]), true);
    T::ok(($evA['auto'] ?? false) === true && Db::value("SELECT user_id FROM lead_events WHERE lead_id = {$ids[0]} AND type = 'assigned'") === null, '  el evento dice «reparto automático» y no tiene autor');
    LeadIngest::handle($payload('Uno Auto', '3055550311') + ['years' => '5'], 'web_careers');   // reingreso: mismo teléfono
    T::eq([(int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$ids[0]]), $count('leads', "phone_e164 = '+13055550311'")], [$u1, 1], '  un reingreso conserva el responsable y no crea otro lead');
    $q = LeadIngest::handle($payload('Quiet Auto', '3055550315'), 'import', ['quiet' => true]);
    T::eq(Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$q['lead_id']]), null, '  las altas quiet (importación, alta manual) no se reparten');
    Settings::set('assign', ['auto' => true, 'users' => [(int) $off['id']], 'last' => 0]);
    $dead = LeadIngest::handle($payload('Nadie Auto', '3055550316'), 'web_careers');
    T::ok($dead['ok'] && Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$dead['lead_id']]) === null, '  si nadie de la lista es válido, el lead se crea igual, sin responsable');
    $clean();

    // ------------------------------------------------------------------ Filtros y vistas del sistema
    T::reset(true);
    $a1 = Panel::user('admin', 'a1-' . bin2hex(random_bytes(2)) . '@example.com');
    $a2 = Panel::user('admin', 'a2-' . bin2hex(random_bytes(2)) . '@example.com');
    $viewer = Panel::user('viewer');
    $u1 = $a1['id'];
    $u2 = $a2['id'];
    Db::run("UPDATE users SET name = 'Ana Admin' WHERE id = ?", [$u1]);
    Db::run("UPDATE users SET name = 'Beto Admin' WHERE id = ?", [$u2]);
    $m1 = $mk(['first_name' => 'Mia', 'assigned_to' => $u1]);
    $m2 = $mk(['first_name' => 'Noe', 'assigned_to' => $u2]);
    $m3 = $mk(['first_name' => 'Ona']);
    $m4 = $mk(['first_name' => 'Pia', 'assigned_to' => $u1, 'stage' => 'lost', 'lost_reason' => 'price']);
    T::eq(LeadQuery::filters(['rs' => 'me', 'sla' => '1']), ['rs' => 'me', 'sla' => '1'], 'filtros: rs=me y sla=1 son válidos');
    T::eq(LeadQuery::filters(['rs' => '12abc', 'sla' => '2']), [], '  y lo que no es válido se descarta');
    LeadQuery::forUser($u1);
    $ids = static fn(array $f): array => array_map('intval', array_column(Db::all('SELECT l.id FROM leads l WHERE ' . LeadQuery::where($f)[0] . ' ORDER BY l.id', LeadQuery::where($f)[1]), 'id'));
    T::eq($ids(['rs' => 'me']), [$m1, $m4], 'rs=me: los leads del usuario que mira');
    T::eq($ids(['rs' => 'none']), [$m3], 'rs=none: sin asignar');
    T::eq($ids(['rs' => (string) $u2]), [$m2], 'rs=<id>: los de esa persona');
    LeadQuery::forUser(null);
    T::eq($ids(['rs' => 'me']), [], 'rs=me sin usuario que mire: no devuelve nada (nunca los de otro)');
    LeadQuery::forUser($u1);
    $sys = array_column(SavedViews::system(), null, 'id');
    T::ok(isset($sys['sys-mine'], $sys['sys-sla']) && $sys['sys-mine']['name'] === 'Mis leads' && $sys['sys-mine']['filters'] === ['rs' => 'me', 'op' => '1'] && $sys['sys-sla']['filters'] === ['sla' => '1'], 'vistas del sistema «Mis leads» y «SLA vencido»');
    T::eq(LeadQuery::count($sys['sys-mine']['filters']), 1, '  «Mis leads» cuenta los abiertos del usuario (el perdido no)');
    T::eq(LeadQuery::facets()['admins'], [$u1 => 'Ana Admin', $u2 => 'Beto Admin'], 'facets: los administradores para el selector del filtro');

    // ------------------------------------------------------------------ SLA: estado, etiquetas y qué leads cuentan
    $min = 60;
    T::eq(Sla::minutes(), 60, 'SLA: 60 minutos por defecto');
    $t = Clock::time();
    $row = static fn(int $ageSecs, array $o = []): array => $o + ['stage' => 'new', 'temperature' => 'warm', 'first_contact_at' => null, 'created_at' => Clock::at($t - $ageSecs), 'stage_changed_at' => null];
    $st = Sla::status($row(37 * 60), $t);
    T::ok($st !== null && !$st['late'] && $st['label'] === 'vence en 23 min', 'SLA: «vence en 23 min» (texto, no solo color)');
    $st = Sla::status($row(3 * 3600), $t);
    T::ok($st !== null && $st['late'] && $st['label'] === 'vencido hace 2 h', 'SLA: «vencido hace 2 h»');
    T::ok(Sla::status($row(100, ['first_contact_at' => Clock::at($t)]), $t) === null && Sla::status($row(100, ['stage' => 'lost']), $t) === null && Sla::status($row(100, ['temperature' => 'disqualified']), $t) === null, 'SLA: sin indicador si ya hay primer contacto, está perdido o descartado');
    $reopen = Sla::status($row(5 * 86400, ['stage_changed_at' => Clock::at($t - 600)]), $t);
    T::ok($reopen !== null && !$reopen['late'] && $reopen['label'] === 'vence en 50 min', 'SLA: un lead reabierto cuenta desde la reapertura (stage_changed_at), no desde su llegada');
    $html = \Captacion\Ui::sla(Sla::status($row(3 * 3600), $t));
    T::ok(str_contains($html, 'class="dot"') && str_contains($html, 'vencido hace 2 h') && str_contains($html, 'Plazo de primer contacto'), 'Ui::sla: punto + texto + etiqueta para lector de pantalla');

    $late1 = $mk(['first_name' => 'Tarde', 'created_at' => Clock::at($t - 2 * 3600)]);
    $late2 = $mk(['first_name' => 'Tarde2', 'created_at' => Clock::at($t - 3 * 3600), 'assigned_to' => $u2]);
    $fresh = $mk(['first_name' => 'Fresca', 'created_at' => Clock::at($t - 600)]);
    $done = $mk(['first_name' => 'Hecha', 'created_at' => Clock::at($t - 5 * 3600), 'first_contact_at' => Clock::at($t - 4 * 3600), 'stage' => 'contacted']);
    $dq = $mk(['first_name' => 'Descartada', 'created_at' => Clock::at($t - 5 * 3600), 'temperature' => 'disqualified']);
    $lost = $mk(['first_name' => 'Perdida', 'created_at' => Clock::at($t - 5 * 3600), 'stage' => 'lost']);
    $slaIds = $ids(['sla' => '1']);
    T::ok(!in_array($fresh, $slaIds, true) && !in_array($done, $slaIds, true) && !in_array($dq, $slaIds, true) && !in_array($lost, $slaIds, true) && in_array($late1, $slaIds, true) && in_array($late2, $slaIds, true), '  y no incluye a los que aún están a tiempo, contactados, descartados ni perdidos');

    // ------------------------------------------------------------------ Campana
    $nt = static function (int $uid): ?array {
        foreach (Notifications::all($uid) as $n) {
            if (str_starts_with($n['key'], 'sla_overdue:')) {
                return $n;
            }
        }
        return null;
    };
    $n1 = $nt($u1);
    $n2 = $nt($u2);
    $overAll = count($ids(['sla' => '1']));
    $mineU2 = (int) Db::value("SELECT COUNT(*) FROM leads l WHERE " . Sla::overdueSql('l')[0] . ' AND l.assigned_to = ?', [...Sla::overdueSql('l')[1], $u2]);
    $unassigned = (int) Db::value("SELECT COUNT(*) FROM leads l WHERE " . Sla::overdueSql('l')[0] . ' AND l.assigned_to IS NULL', Sla::overdueSql('l')[1]);
    $ttl = static fn(int $n): string => $n === 1 ? '1 lead con el plazo de primer contacto vencido' : $n . ' leads con el plazo de primer contacto vencido';
    T::ok($n1 !== null && $n1['title'] === $ttl($overAll - $mineU2) && $n1['href'] === '/leads?sla=1', 'campana: el responsable ve los suyos y los que no tienen responsable (no los de otro)');
    T::ok($n2 !== null && $n2['title'] === $ttl($mineU2 + $unassigned), '  y el otro administrador, los suyos más los sin responsable');
    T::eq($unassigned > 0 && $mineU2 === 1, true, '  (datos de la prueba: 1 vencido de Beto y varios sin responsable)');

    // ------------------------------------------------------------------ Tarea automática «Primer contacto»
    T::reset(true);
    $a1 = Panel::user('admin');
    $clean();
    $p1 = LeadIngest::handle($payload('Tarea Uno', '3055550321'), 'web_careers');
    $tk = Db::all("SELECT * FROM tasks WHERE lead_id = ?", [$p1['lead_id']]);
    $created = (string) Db::value('SELECT created_at FROM leads WHERE id = ?', [$p1['lead_id']]);
    T::ok(count($tk) === 1 && $tk[0]['title'] === 'Primer contacto con Tarea Uno' && $tk[0]['kind'] === 'sla_first_contact' && (int) Clock::parse((string) $tk[0]['due_at']) === (int) Clock::parse($created) + 3600, 'al crear un lead se crea «Primer contacto con <nombre>» con vencimiento = llegada + 60 min');
    T::eq($count('lead_events', "lead_id = {$p1['lead_id']} AND type = 'task_created'"), 0, '  (sin evento en la línea de tiempo: la crea el sistema en cada alta)');
    LeadIngest::handle($payload('Tarea Uno', '3055550321') + ['years' => '6'], 'web_careers');
    T::eq($count('tasks', "lead_id = {$p1['lead_id']}"), 1, 'un reingreso no duplica la tarea abierta');
    $q1 = LeadIngest::handle($payload('Tarea Quiet', '3055550322'), 'manual', ['quiet' => true]);
    T::eq($count('tasks', "lead_id = {$q1['lead_id']}"), 0, 'las altas quiet no crean tarea');
    Settings::set('sla.auto_task', false);
    $p2 = LeadIngest::handle($payload('Tarea Dos', '3055550323'), 'web_careers');
    T::eq($count('tasks', "lead_id = {$p2['lead_id']}"), 0, 'con sla.auto_task apagado no se crea la tarea');
    Settings::set('sla.auto_task', true);
    Settings::set('sla.first_contact', 30);
    $p3 = LeadIngest::handle($payload('Tarea Tres', '3055550324'), 'web_careers');
    $c3 = (string) Db::value('SELECT created_at FROM leads WHERE id = ?', [$p3['lead_id']]);
    T::eq((int) Clock::parse((string) Db::value('SELECT due_at FROM tasks WHERE lead_id = ?', [$p3['lead_id']])), (int) Clock::parse($c3) + 1800, 'el plazo configurado (30 min) se aplica al vencimiento de la tarea');
    Settings::set('sla.first_contact', 60);

    // tick: cierra la tarea al contactar y escala UNA vez por lead e intento
    $LA = LeadActions::logCall((int) $p1['lead_id'], 'answered', null, '', $a1['id']);
    T::ok($LA['ok'], 'llamada contestada: el lead queda contactado');
    $tick = Sla::tick();
    T::ok($tick['closed'] >= 1 && Db::value('SELECT done_at FROM tasks WHERE lead_id = ?', [$p1['lead_id']]) !== null, 'tick: cierra la tarea «Primer contacto» de un lead ya contactado');
    Queue::runBatch(JobHandlers::all(), 5);   // vacía lo que dejaron las altas
    Db::pdo()->exec("DELETE FROM jobs");
    $tgLog = "$work/tg42.log";
    @unlink($tgLog);
    $modeFile = "$work/tg42.mode";
    file_put_contents($modeFile, 'ok');
    $port = Infra::phpServer('tgmock42', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $tgLog, 'MOCK_MODE_FILE' => $modeFile], $ctx['root'] . '/tests/lib/telegram_mock.php');
    Env::set('TELEGRAM_BOT_TOKEN', '123456789:FAKE_TEST_TOKEN_not_real_0123456789');
    Env::set('TELEGRAM_CHAT_ID', '555000');
    Env::set('TELEGRAM_API_BASE', "http://127.0.0.1:$port");
    $reqs = static fn(): array => Infra::readLog($tgLog);
    $E = $mk(['first_name' => 'Escala', 'last_name' => 'Uno', 'created_at' => Clock::at(Clock::time() - 2 * 3600), 'assigned_to' => $a1['id']]);
    $old = $mk(['first_name' => 'Vieja', 'created_at' => Clock::at(Clock::time() - 40 * 3600)]);
    $tick = Sla::tick();
    T::eq($tick['escalated'], 1, 'tick: encola la alerta del lead vencido (el vencido hace más de 24 h no se escala)');
    $job = Db::one("SELECT * FROM jobs WHERE type = 'sla_breach'");
    T::eq($job['dedupe_key'], "sla_breach:$E:1", '  con clave por lead e intento');
    T::eq(Sla::tick()['escalated'], 0, 'tick repetido: no avisa dos veces el mismo lead e intento');
    Queue::runBatch(JobHandlers::all(), 5);
    $rq = $reqs();
    $text = (string) (($rq[0]['body'] ?? [])['text'] ?? '');
    T::ok(count($rq) === 1 && str_contains($text, 'PLAZO VENCIDO') && str_contains($text, 'Escala Uno') && str_contains($text, 'Responsable: ' . Db::value('SELECT name FROM users WHERE id = ?', [$a1['id']])), 'la alerta de Telegram dice «PLAZO VENCIDO», el lead y el responsable');
    T::eq($count('lead_events', "lead_id = $E AND type = 'sla_breached'"), 1, '  y deja el evento «sla_breached»');
    Sla::tick();
    T::eq($count('jobs', "type = 'sla_breach'"), 1, 'un tick posterior sigue sin duplicar el job (clave única)');
    // el lead es contactado antes de que corra el job
    Db::pdo()->exec('DELETE FROM jobs');
    @unlink($tgLog);
    Db::pdo()->exec("UPDATE leads SET first_contact_at = UTC_TIMESTAMP(), stage = 'contacted'");   // los anteriores ya no cuentan
    $E2 = $mk(['first_name' => 'Escala', 'last_name' => 'Dos', 'created_at' => Clock::at(Clock::time() - 2 * 3600)]);
    Sla::tick();
    Db::update('leads', $E2, ['first_contact_at' => Clock::now(), 'stage' => 'contacted']);
    Queue::runBatch(JobHandlers::all(), 5);
    T::eq(count($reqs()), 0, 'si lo contactaron antes de que corra el aviso, no se manda nada');
    Settings::set('sla.escalate', false);
    Db::pdo()->exec('DELETE FROM jobs');
    $E3 = $mk(['first_name' => 'Escala', 'last_name' => 'Tres', 'created_at' => Clock::at(Clock::time() - 2 * 3600)]);
    T::eq(Sla::tick()['escalated'], 0, 'con el escalado apagado no se encola nada (la campana y el indicador siguen)');
    Settings::set('sla.escalate', true);
    // horario de silencio: el aviso no urgente espera
    Settings::set('alerts', Alerts::get() + []);
    $qs = Fmt::et(Clock::time());
    $qStart = $qs->modify('-1 hour')->format('H:i');
    $qEnd = $qs->modify('+3 hours')->format('H:i');
    Settings::set('alerts', ['quiet' => ['enabled' => true, 'start' => $qStart, 'end' => $qEnd]] + Alerts::get());
    Settings::clear();
    Db::pdo()->exec('DELETE FROM jobs');
    Sla::tick();
    $qj = Db::one("SELECT run_at FROM jobs WHERE type = 'sla_breach' ORDER BY id LIMIT 1");
    T::ok($qj !== null && (int) Clock::parse((string) $qj['run_at']) > Clock::time() + 3600, 'horario de silencio: el aviso del SLA espera a que termine (no es urgente)');
    $clean();
    Db::pdo()->exec('DELETE FROM jobs');
    Infra::stop('tgmock42');
    Env::set('TELEGRAM_BOT_TOKEN', null);
    Env::set('TELEGRAM_CHAT_ID', null);

    // ------------------------------------------------------------------ Resumen diario
    T::reset(true);
    $clean();
    $adm = Panel::user('admin');
    $smtpLog = "$work/smtp42.log";
    @unlink($smtpLog);
    $smtpPort = Infra::smtpMock('smtp42', $smtpLog);
    Fx::smtp($smtpPort);
    $mails = static fn(): array => Infra::readLog($smtpLog);
    $d0 = new DateTimeImmutable('today', Fmt::tz());
    $at = static fn(string $hm): int => $d0->modify($hm)->getTimestamp();
    Clock::travel($at('07:30') - time());
    $yest = Clock::at($at('00:00') - 3600 * 5);
    Fx::lead(['first_name' => 'Ayer', 'last_name' => 'Caliente', 'temperature' => 'hot', 'created_at' => $yest, 'phone_e164' => '+13055559001', 'email' => 'secreto-ayer@example.com']);
    Fx::lead(['first_name' => 'Ayer', 'last_name' => 'Tibio', 'temperature' => 'warm', 'created_at' => $yest]);
    Fx::lead(['first_name' => 'Vencida', 'last_name' => 'Hace', 'created_at' => Clock::at(Clock::time() - 4 * 3600)]);
    $lid = Fx::lead(['first_name' => 'Cita', 'last_name' => 'Hoy', 'created_at' => $yest, 'first_contact_at' => $yest, 'stage' => 'contacted']);
    Db::insert('appointments', ['lead_id' => $lid, 'starts_at' => Clock::at($at('15:00')), 'status' => 'booked']);
    \Captacion\Services\Tasks::create('Vence hoy', Fmt::toEtInput(Clock::at($at('17:00'))), 2, $lid, $adm['id']);
    \Captacion\Services\Tasks::create('Ya vencida', Fmt::toEtInput(Clock::at($at('06:00'))), 2, $lid, $adm['id']);
    T::eq(DailyDigest::tick($at('07:30'))['reason'], 'early', 'resumen diario: antes de las 8:00 no se envía');
    Clock::travel($at('08:05') - time());
    $r = DailyDigest::tick($at('08:05'));
    T::ok($r['sent'] && $r['channels'] === ['email'], 'resumen diario: a las 8:05 se envía por correo (Telegram no está configurado)');
    $mail = $mails();
    $body = $mail ? quoted_printable_decode($mail[0]['raw']) : '';
    T::ok(count($mail) === 1 && in_array('carlos@example.com', $mail[0]['to'], true), '  al destinatario de las alertas');
    T::ok(str_contains($body, 'Leads de ayer: 3 (1 calientes, 2 tibios)'), '  con los leads de ayer, calientes y tibios (' . (preg_match('/Leads de ayer[^\n]*/', $body, $mm) ? trim($mm[0]) : 'sin línea') . ')');
    T::ok(str_contains($body, 'Tareas vencidas: 1') && str_contains($body, 'para hoy: 1') && str_contains($body, 'Citas de hoy: 1') && str_contains($body, 'Siguiente mejor acción'), '  tareas vencidas y de hoy, citas de hoy y la siguiente mejor acción');
    T::ok(str_contains($body, 'plazo de primer contacto vencido: 3') && str_contains($body, 'Vencida Hace'), '  leads con el SLA vencido, con nombre');
    T::ok(!str_contains($body, 'secreto-ayer@example.com') && !str_contains($body, '+13055559001'), '  solo conteos y nombres: ni correos ni teléfonos');
    $r = DailyDigest::tick($at('08:05') + 600);
    T::ok(!$r['sent'] && $r['reason'] === 'already' && count($mails()) === 1, 'idempotente: una segunda pasada el mismo día no manda otro');
    Clock::travel($at('23:30') - time());
    T::eq(count($mails()) + (DailyDigest::tick($at('23:30'))['sent'] ? 1 : 0), 1, '  ni tarde ese mismo día');
    Clock::travel($at('08:05') - time() + 86400);
    T::ok(DailyDigest::tick($at('08:05') + 86400)['sent'] && count($mails()) === 2, 'al día siguiente vuelve a enviarse');
    Settings::set('alerts', ['daily_digest' => false] + Alerts::get());
    Settings::clear();
    Clock::travel($at('08:05') - time() + 2 * 86400);
    T::eq(DailyDigest::tick($at('08:05') + 2 * 86400)['reason'], 'off', 'con el interruptor apagado no se envía');
    T::eq(Alerts::DEFAULTS['daily_digest'], true, '  y el interruptor viene ENCENDIDO por defecto');
    Settings::set('alerts', ['daily_digest' => true, 'email' => true, 'mail_to' => ''] + Alerts::get());
    Env::set('MAIL_TO', '');
    Settings::clear();
    Db::pdo()->exec("DELETE FROM settings WHERE `key` = 'cron.digest'");
    Settings::clear();
    $r = DailyDigest::tick($at('08:05') + 2 * 86400);
    T::ok(!$r['sent'] && $r['reason'] === 'no_channel', 'sin correo de avisos ni Telegram: no se marca como enviado (se reintenta en la siguiente pasada)');
    Env::set('MAIL_TO', 'carlos@example.com');
    Infra::stop('smtp42');
    Fx::noSmtp();
    $clean();
    Clock::travel(0);

    // ------------------------------------------------------------------ Métricas por responsable
    T::reset(true);
    $clean();
    $ra = Panel::user('admin');
    $rb = Panel::user('admin');
    Db::run("UPDATE users SET name = 'Ana Metrica' WHERE id = ?", [$ra['id']]);
    Db::run("UPDATE users SET name = 'Beto Metrica' WHERE id = ?", [$rb['id']]);
    $tn = Clock::time();
    $from = $tn - 5 * 86400;
    $c = static fn(int $ago): string => Clock::at($tn - $ago);
    // Ana: 3 leads; contactados a los 10 min (a tiempo), a las 2 h (tarde) y uno sin contactar con el plazo vencido (tarde); 1 calificado, 1 firmado
    $x1 = Fx::lead(['assigned_to' => $ra['id'], 'created_at' => $c(86400), 'first_contact_at' => $c(86400 - 600), 'stage' => 'signed', 'signed_at' => $c(3600), 'handed_off_at' => $c(7200)]);
    $x2 = Fx::lead(['assigned_to' => $ra['id'], 'created_at' => $c(86400), 'first_contact_at' => $c(86400 - 7200), 'stage' => 'qualified']);
    $x3 = Fx::lead(['assigned_to' => $ra['id'], 'created_at' => $c(86400)]);
    // Beto: 1 lead sin contactar pero aún a tiempo (no cuenta en el plazo); sin asignar: 1 lead
    $y1 = Fx::lead(['assigned_to' => $rb['id'], 'created_at' => $c(600)]);
    $z1 = Fx::lead(['created_at' => $c(86400), 'first_contact_at' => $c(86400 - 300), 'stage' => 'contacted']);
    Db::insert('tasks', ['title' => 'Vencida de Ana', 'lead_id' => $x2, 'due_at' => $c(3600)]);
    Db::insert('tasks', ['title' => 'Futura de Ana', 'lead_id' => $x2, 'due_at' => Clock::at($tn + 3600)]);
    $rows = Reports::byAssignee($from, $tn + 60, $tn);
    $by = array_column($rows, null, 'label');
    $A = $by['Ana Metrica'] ?? [];
    T::eq([$A['leads'] ?? null, $A['contacted'] ?? null, $A['qualified'] ?? null, $A['presented'] ?? null, $A['signed'] ?? null, $A['overdue'] ?? null], [3, 2, 2, 1, 1, 1], 'por responsable (Ana): asignados, contactados, calificados, presentados, firmados y tareas vencidas');
    T::eq([$A['median'] ?? null, $A['sla_ok'] ?? null, $A['sla_total'] ?? null, $A['sla_pct'] ?? null], [3900, 1, 3, 33.3], '  mediana a primer contacto (65 min), dentro del plazo 1 de 3 (33,3 %)');
    $B = $by['Beto Metrica'] ?? [];
    T::eq([$B['leads'] ?? null, $B['contacted'] ?? null, $B['sla_total'] ?? null, $B['sla_pct'] ?? 'x', array_key_exists('median', $B) ? $B['median'] : 'x'], [1, 0, 0, 'x', null], 'por responsable (Beto): un lead aún a tiempo no cuenta en el plazo');
    T::eq(end($rows)['label'], 'Sin asignar', '  y «Sin asignar» va al final');
    $tab = Reports::table('responsables', ['assignees' => $rows]);
    T::ok($tab['header'][0] === 'Responsable' && count($tab['header']) === 9 && count($tab['rows']) === 3 && Reports::table('responsables', [])['rows'] === [], 'tabla «responsables» para el CSV (9 columnas; vacía sin datos)');
    T::ok(!array_key_exists('assignees', Reports::compute($from, $tn + 60)), 'compute() NO lleva nombres de usuarios (alimenta los informes guardados y al lector)');

    // ------------------------------------------------------------------ HTTP: ficha, listas, masivo, reportes, lector
    T::reset(true);
    Db::pdo()->exec('DELETE FROM channel_costs');
    $clean();
    $adm = Panel::user('admin');
    $adm2 = Panel::user('admin');
    $vw = Panel::user('viewer');
    Db::run("UPDATE users SET name = 'Ana Http' WHERE id = ?", [$adm['id']]);
    Db::run("UPDATE users SET name = 'Beto Http' WHERE id = ?", [$adm2['id']]);
    $s = Panel::start($ctx, 'panel42');
    T::ok($s->login($adm['email']), 'login del administrador');
    $H1 = $mk(['first_name' => 'Hugo', 'last_name' => 'Http', 'created_at' => Clock::at(Clock::time() - 2 * 3600), 'temperature' => 'hot']);
    $H2 = $mk(['first_name' => 'Irma', 'last_name' => 'Http', 'assigned_to' => $adm2['id']]);
    $H3 = $mk(['first_name' => 'Juan', 'last_name' => 'Http']);
    $nid = (int) Db::insert('lead_notes', ['lead_id' => $H1, 'body' => 'Nota de prueba', 'user_id' => $adm['id'], 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    // ficha
    $q = '/leads/' . $H1;
    $g = $s->get($q . '?back=' . rawurlencode('/leads?t=hot&page=2&v=sys-hot_pending'));
    T::eq($g['status'], 200, 'ficha: abre');
    T::ok(str_contains($g['body'], 'Volver a la lista') && substr_count($g['body'], 'href="/leads?t=hot&amp;page=2&amp;v=sys-hot_pending"') >= 2, 'ficha: «Volver a la lista» y la miga «Leads» conservan filtros, página y vista guardada');
    T::ok(str_contains($g['body'], 'name="to"') && str_contains($g['body'], 'Ana Http') && str_contains($g['body'], 'Beto Http') && !str_contains($g['body'], 'Viewer Prueba'), 'ficha: selector de responsable con los administradores (sin el lector)');
    T::ok(str_contains($g['body'], 'vencido hace 1 h') && str_contains($g['body'], 'Plazo de primer contacto'), 'ficha: el indicador del plazo vencido (texto)');
    T::ok(preg_match('/aria-label="Editar la nota de Ana Http del [^"]+"/', $g['body']) === 1 && preg_match('/aria-label="Eliminar la nota de Ana Http del [^"]+"/', $g['body']) === 1, 'ficha: editar y eliminar nota con autor y fecha en el nombre accesible');
    $evil = $s->get($q . '?back=' . rawurlencode('//evil.example/x'));
    T::ok(!str_contains($evil['body'], 'evil.example') && str_contains($evil['body'], 'href="/leads">'), 'ficha: un back externo o con // se ignora (vuelve a /leads)');
    $evil2 = $s->get($q . '?back=' . rawurlencode('/config?tab=alertas'));
    T::ok(str_contains($evil2['body'], 'Volver a la lista') && !str_contains($evil2['body'], 'href="/config?tab=alertas">Volver'), 'ficha: un back a otra pantalla que no es una lista se ignora');
    // asignar desde la ficha
    $r = $s->post($q . '/assign', ['_csrf' => $s->csrf, 'to' => (string) $adm2['id']]);
    T::ok($r['status'] === 303 && (int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H1]) === $adm2['id'] && str_contains(Infra::header($r, 'location'), '#responsable'), 'POST /leads/{id}/assign: cambia el responsable y vuelve a la ficha');
    $g = $s->get($q);
    T::ok(str_contains($g['body'], 'Lead asignado a Beto Http') && str_contains($g['body'], 'name="token"') && str_contains($g['body'], 'Deshacer'), '  avisa y ofrece «Deshacer»');
    T::ok(str_contains($g['body'], 'href="/leads?t=hot&amp;page=2&amp;v=sys-hot_pending"'), '  y tras la acción la ficha sigue recordando la lista de la que venías');
    $tok = preg_match('/name="token" value="([^"]+)"/', $g['body'], $mm) === 1 ? html_entity_decode($mm[1]) : '';
    $u = $s->post('/undo', ['_csrf' => $s->csrf, 'token' => $tok, 'back' => $q]);
    T::ok($u['status'] === 303 && Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H1]) === null, 'Deshacer desde el aviso: vuelve a sin asignar');
    $r = $s->post($q . '/assign', ['_csrf' => $s->csrf, 'to' => (string) $vw['id']]);
    T::ok(Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H1]) === null, 'POST assign a un lector: rechazado, sin cambios');
    $r = $s->post($q . '/assign', ['_csrf' => 'mal', 'to' => (string) $adm['id']]);
    T::ok(Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H1]) === null, 'POST assign sin CSRF válido: no hace nada');
    $r = $s->post($q . '/assign', ['_csrf' => $s->csrf, 'to' => 'abc']);
    T::ok(Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H1]) === null, 'POST assign con un valor que no es un id: no hace nada');
    // lector
    $sv = $s->another();
    T::ok($sv->login($vw['email']), 'login del lector');
    T::eq($sv->post($q . '/assign', ['_csrf' => $sv->csrf, 'to' => (string) $adm['id']])['status'], 403, 'el lector recibe 403 al asignar');
    T::eq($sv->post('/leads/bulk', ['_csrf' => $sv->csrf, 'do' => 'assign', 'assignee' => (string) $adm['id'], 'ids' => [(string) $H1]])['status'], 403, 'el lector recibe 403 en la asignación masiva');
    T::eq($sv->ajax('/ajax/lead/act', ['id' => $H1, 'act' => 'assign', 'to' => (string) $adm['id']])['status'], 403, 'el lector recibe 403 en la acción rápida del vistazo');
    T::eq($sv->get('/reportes')['status'], 403, 'el lector no ve /reportes');
    T::ok(!str_contains($sv->get('/resultados')['body'], 'Por responsable'), 'y /resultados no tiene la tabla por responsable');
    $inf = $sv->get('/informes');
    T::ok($inf['status'] === 200 && !str_contains($inf['body'], 'Por responsable'), '  ni /informes');
    // acción rápida del vistazo
    $pk = $s->ajax('/ajax/lead/act', ['id' => $H3, 'act' => 'assign', 'to' => (string) $adm['id']]);
    $pj = $s->json($pk);
    T::ok($pk['status'] === 200 && $pj['ok'] && !empty($pj['undo']) && (int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H3]) === $adm['id'], 'vistazo: asignar por la acción rápida (con «Deshacer»)');
    $pk = $s->ajax('/ajax/peek', ['id' => $H3]);
    $pj = $s->json($pk);
    T::ok(str_contains($pj['html'], 'data-pk-assign') && str_contains($pj['html'], 'Responsable') && str_contains($pj['html'], '(abre en otra pestaña)') === (str_contains($pj['html'], 'wa.me')), 'vistazo: selector de responsable y WhatsApp con «(abre en otra pestaña)»');
    $pk = $s->ajax('/ajax/lead/act', ['id' => $H3, 'act' => 'assign', 'to' => (string) $vw['id']]);
    T::ok($pk['status'] === 200 || $pk['status'] === 422, '  y a un lector no se asigna');
    T::eq((int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H3]), $adm['id'], '  (el responsable sigue siendo el anterior)');
    // lista
    $l = $s->get('/leads');
    T::ok(str_contains($l['body'], 'aria-sort="none"') && str_contains($l['body'], 'aria-sort="descending"'), 'lista: aria-sort en las columnas ordenables (la ordenada, descendente)');
    T::ok(str_contains($l['body'], '<span class="sr">Estado</span>') && str_contains($l['body'], '<span class="sr">Puntos</span>') && str_contains($l['body'], '<span class="sr">Temperatura</span>'), 'lista: «Est.», «Pts» y «Temp.» llevan el texto completo para el lector de pantalla');
    T::ok(str_contains($l['body'], 'aria-current="page"') && !str_contains($l['body'], 'class="ptab is-on" href="/leads" aria-current="true"'), 'lista: la pestaña activa lleva aria-current="page"');
    T::ok(preg_match('#href="/leads/' . $H1 . '\?back=%2Fleads"#', $l['body']) === 1, 'lista: cada lead enlaza a la ficha con el back de esta lista');
    T::ok(str_contains($l['body'], 'Responsable') && str_contains($l['body'], 'Sin asignar') && str_contains($l['body'], 'Beto Http') && str_contains($l['body'], 'vencido hace 1 h'), 'lista: columna Responsable y el indicador del plazo');
    T::ok(str_contains($l['body'], 'name="assignee"') && str_contains($l['body'], 'value="assign"'), 'lista: la barra de selección tiene «Asignar»');
    T::ok(str_contains($l['body'], '<select name="rs">') && str_contains($l['body'], 'Mis leads'), 'lista: filtro Responsable con «Mis leads» y «Sin asignar»');
    $mine = $s->get('/leads?rs=me');
    T::ok(str_contains($mine['body'], 'Juan Http') && !str_contains($mine['body'], 'Irma Http') && !str_contains($mine['body'], 'Hugo Http'), '/leads?rs=me: solo los míos');
    $none = $s->get('/leads?rs=none');
    T::ok(str_contains($none['body'], 'Hugo Http') && !str_contains($none['body'], 'Irma Http') && !str_contains($none['body'], 'Juan Http'), '/leads?rs=none: solo los sin asignar');
    $slv = $s->get('/leads?sla=1');
    T::ok(str_contains($slv['body'], 'Hugo Http') && !str_contains($slv['body'], 'Juan Http'), '/leads?sla=1: solo los vencidos');
    $sysv = $s->get('/leads?v=sys-mine');
    T::ok($sysv['status'] === 200 && str_contains($sysv['body'], 'Mis leads'), 'la vista del sistema «Mis leads» se abre');
    // tabla y tablero
    $tb = $s->get('/tabla');
    T::ok($tb['status'] === 200 && str_contains($tb['body'], 'aria-sort=') && str_contains($tb['body'], '<span class="sr">Estado</span>') && str_contains($tb['body'], 'Responsable') && preg_match('#href="/leads/' . $H1 . '\?back=%2Ftabla"#', $tb['body']) === 1, 'tabla: aria-sort, cabeceras con texto completo, columna Responsable y back a la tabla');
    $tb2 = $s->get('/tabla?sort=resp&dir=asc');
    T::ok($tb2['status'] === 200 && str_contains($tb2['body'], 'aria-sort="ascending"'), 'tabla: ordenar por Responsable');
    $kb = $s->get('/pipeline');
    T::ok($kb['status'] === 200 && str_contains($kb['body'], 'Hugo Http') && str_contains($kb['body'], 'vencido hace 1 h') && str_contains($kb['body'], 'name="rs"') && preg_match('#href="/leads/' . $H1 . '\?back=%2Fpipeline"#', $kb['body']) === 1, 'tablero: indicador del plazo, filtro Responsable y back al tablero');
    T::ok(str_contains($kb['body'], 'Responsable: Beto Http'), 'tablero: iniciales del responsable en la tarjeta (con texto accesible)');
    $csv = $s->post('/tabla/export', ['_csrf' => $s->csrf, 'scope' => 'filters', 'qs' => '']);
    T::ok($csv['status'] === 200 && str_contains($csv['body'], 'Responsable') && str_contains($csv['body'], 'Beto Http'), 'tabla: el CSV incluye la columna Responsable');
    // masivo
    $bk = $s->post('/leads/bulk', ['_csrf' => $s->csrf, 'back' => '/leads?t=hot', 'do' => 'assign', 'assignee' => (string) $adm['id'], 'ids' => [(string) $H1, (string) $H2]]);
    T::ok($bk['status'] === 303 && (int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H1]) === $adm['id'] && (int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H2]) === $adm['id'], 'masivo HTTP: asigna la selección');
    $g = $s->get('/leads?t=hot');
    T::ok(str_contains($g['body'], '2 leads asignados a Ana Http') && str_contains($g['body'], 'Deshacer'), '  avisa con «Deshacer»');
    $bk = $s->post('/leads/bulk', ['_csrf' => $s->csrf, 'back' => '/leads', 'do' => 'assign', 'assignee' => '', 'ids' => [(string) $H1]]);
    T::ok((int) Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H1]) === $adm['id'], 'masivo sin elegir a quién: no cambia nada');
    $bk = $s->post('/leads/bulk', ['_csrf' => $s->csrf, 'back' => '/leads', 'do' => 'assign', 'assignee' => '0', 'ids' => [(string) $H1]]);
    T::ok(Db::value('SELECT assigned_to FROM leads WHERE id = ?', [$H1]) === null, 'masivo con «Sin asignar»: quita el responsable');
    // reportes
    $rp = $s->get('/reportes');
    T::ok($rp['status'] === 200 && str_contains($rp['body'], 'Por responsable') && str_contains($rp['body'], 'Ana Http'), '/reportes: tabla por responsable');
    $ex = $s->post('/reportes/export', ['_csrf' => $s->csrf, 'table' => 'responsables', 'r' => '30d']);
    T::ok($ex['status'] === 200 && str_contains($ex['body'], 'Responsable') && str_contains($ex['body'], 'Mediana a primer contacto (min)') && str_contains($ex['body'], 'Ana Http'), '/reportes/export: CSV de la tabla por responsable');
    // campana en la barra superior
    $home = $s->get('/leads');
    T::ok(str_contains($home['body'], 'plazo de primer contacto vencido'), 'campana: aviso del SLA vencido en el panel');

    // ------------------------------------------------------------------ Configuración > Alertas
    $cfg = $s->get('/config?tab=alertas');
    T::ok($s->unlock((int) $adm['id'], 'alertas'), 'desbloquea Configuración con la contraseña y el código');
    $cfg = $s->get('/config?tab=alertas');
    T::ok(str_contains($cfg['body'], 'name="sla_minutes"') && str_contains($cfg['body'], 'name="sla_escalate"') && str_contains($cfg['body'], 'name="sla_auto_task"') && str_contains($cfg['body'], 'name="daily_digest"') && str_contains($cfg['body'], 'name="assign_auto"') && str_contains($cfg['body'], 'name="assign_users[]"'), 'Alertas: campos del SLA, resumen diario y reparto');
    T::ok(preg_match('/name="daily_digest" value="1" checked/', $cfg['body']) === 1 && preg_match('/name="sla_escalate" value="1" checked/', $cfg['body']) === 1 && preg_match('/name="assign_auto" value="1" checked/', $cfg['body']) === 0, '  por defecto: resumen y escalado encendidos, reparto apagado');
    $post = ['_csrf' => $s->csrf, 'am_form' => '1', 'daily_digest' => '1', 'followup_minutes' => '15', 'warm_telegram' => 'auto', 'mail_to' => '', 'sla_minutes' => '2', 'sla_escalate' => '1', 'sla_auto_task' => '1', 'assign_auto' => '1'];
    $bad = $s->post('/config/alerts', $post);
    T::ok($bad['status'] === 422 && str_contains($bad['body'], 'entre 5 y 1440') && str_contains($bad['body'], 'Marca al menos un administrador'), 'Alertas: minutos fuera de rango y reparto sin nadie marcado = errores, y no se guarda nada');
    T::eq(Sla::minutes(), 60, '  (el plazo sigue en 60)');
    $ok = $s->post('/config/alerts', ['sla_minutes' => '45', 'assign_users' => [(string) $adm['id'], (string) $adm2['id'], (string) $vw['id']]] + $post);
    Settings::clear();
    T::ok($ok['status'] === 303 && Sla::minutes() === 45 && Sla::escalate() && Sla::autoTask() && Assignment::config()['auto'] === true && Assignment::config()['users'] === [min($adm['id'], $adm2['id']), max($adm['id'], $adm2['id'])], 'Alertas: guarda el plazo y el reparto (el lector no puede estar en la lista)');
    T::ok($count('activity_log', "action = 'settings_change' AND entity_id IN ('sla','assign')") === 2, '  y deja el cambio en activity_log');
    $old = $s->post('/config/alerts', ['_csrf' => $s->csrf, 'followup_minutes' => '20', 'warm_telegram' => 'auto', 'mail_to' => '']);
    Settings::clear();
    T::ok($old['status'] === 303 && Sla::minutes() === 45 && Assignment::config()['auto'] === true && Alerts::get()['daily_digest'] === true && Alerts::get()['followup_minutes'] === 20, 'Alertas: un POST sin el marcador del formulario nuevo no toca el plazo, el reparto ni el resumen');
    $off = $s->post('/config/alerts', ['_csrf' => $s->csrf, 'am_form' => '1', 'followup_minutes' => '15', 'warm_telegram' => 'auto', 'mail_to' => '', 'sla_minutes' => '45']);
    Settings::clear();
    T::ok($off['status'] === 303 && !Sla::escalate() && !Sla::autoTask() && Assignment::config()['auto'] === false && Alerts::get()['daily_digest'] === false, 'Alertas: desmarcar apaga el escalado, la tarea, el reparto y el resumen');
    $clean();

    // ------------------------------------------------------------------ Cron: process_jobs hace el tick del SLA
    T::reset(true);
    $envFile = "$work/cron42.env";
    file_put_contents($envFile, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($ctx['env']), $ctx['env'])) . "\n");
    $cl = $mk(['first_name' => 'Cron', 'last_name' => 'Sla', 'created_at' => Clock::at(Clock::time() - 3 * 3600)]);
    $tkid = Db::insert('tasks', ['title' => 'Primer contacto con Cron Sla', 'lead_id' => $cl, 'due_at' => Clock::at(Clock::time() - 7200), 'kind' => 'sla_first_contact']);
    Db::update('leads', $cl, ['first_contact_at' => Clock::now(), 'stage' => 'contacted']);
    $cr = Infra::cli([$ctx['root'] . '/cron/process_jobs.php'], ['CAPTACION_ENV_FILE' => $envFile, 'STORAGE_DIR' => "$work/storage"]);
    Settings::clear();
    T::ok($cr['code'] === 0 && is_array(Settings::get('cron.sla')) && Db::value('SELECT done_at FROM tasks WHERE id = ?', [$tkid]) !== null, 'process_jobs.php hace la pasada del SLA (cierra la tarea del lead ya contactado y deja su marca cron.sla)');
    $cr2 = Infra::cli([$ctx['root'] . '/cron/reports_cron.php'], ['CAPTACION_ENV_FILE' => $envFile, 'STORAGE_DIR' => "$work/storage"]);
    T::ok($cr2['code'] === 0 && str_contains($cr2['out'], '"digest"'), 'reports_cron.php incluye el resumen diario en su salida');
    $clean();
    Db::pdo()->exec("DELETE FROM settings WHERE `key` LIKE 'cron.%'");
    Settings::clear();
    @unlink(Storage::dir('mail.log'));
};
