<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\Appointments;
use Captacion\Services\Consent;
use Captacion\Services\JobHandlers;
use Captacion\Services\LeadActions;
use Captacion\Services\LeadIngest;
use Captacion\Services\Notifications;
use Captacion\Services\OutboundWebhooks;
use Captacion\Services\PermanentJobFailure;
use Captacion\Services\Prospects;
use Captacion\Services\ReportJobs;
use Captacion\Services\RuleAutomation;
use Captacion\Services\RuleEngine;
use Captacion\Services\RuleRunner;
use Captacion\Services\Rules;
use Captacion\Services\RuleSets;
use Captacion\Services\RuleSim;
use Captacion\Services\Scoring;
use Captacion\Services\Sequences;
use Captacion\Services\Settings;
use Captacion\Services\Templates;
use Captacion\Services\Webhook;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase AM · automatizacion y correcciones. A) correcciones verificadas (secuencias y recordatorios sin SMTP, reintento de eventos de n8n, bloqueo de ingesta hasta el commit, claves de dedupe, zona ET
 * de Prospectos, informe sin texto interno para el lector), B) accesibilidad y UX (comprobaciones estaticas de las hojas y scripts y de las paginas), C) reglas por eventos y por tiempo y webhooks salientes.
 */
return static function (array $ctx): void {
    $work = $ctx['work'];
    T::reset(true);
    Db::pdo()->exec('TRUNCATE TABLE rule_notices');
    Db::pdo()->exec('TRUNCATE TABLE outbound_deliveries');
    Db::pdo()->exec("DELETE FROM settings WHERE `key` IN ('webhooks.out','rules.enabled','mail','display.timezone','alerts')");
    Settings::clear();
    foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'FMCSA_WEBKEY', 'TELEGRAM_API_BASE'] as $k) {
        Env::set($k, null);
    }
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $count = static fn(string $t, string $where = '1=1', array $p = []) => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $where", $p);
    $root = $ctx['root'];
    $read = static fn(string $f): string => (string) @file_get_contents($root . '/' . $f);

    $smtpLog = "$work/smtp44.log";
    @unlink($smtpLog);
    $smtpPort = Infra::smtpMock('smtp44', $smtpLog, "$work/smtp44.mode");
    $hookLog = "$work/hook44.log";
    $hookMode = "$work/hook44.mode";
    @unlink($hookLog);
    @unlink($hookMode);
    $hookPort = Infra::phpServer('hook44', $root . '/tests/lib', ['MOCK_LOG' => $hookLog, 'MOCK_MODE_FILE' => $hookMode], $root . '/tests/lib/webhook_mock.php');
    $hooks = static fn(): array => Infra::readLog($hookLog);
    $hookBody = static fn(array $h): array => json_decode((string) $h['body'], true) ?: [];

    // =============================================================================================== A1 · sin SMTP nada se da por enviado
    T::section('44 · A1 sin SMTP');
    $address = 'NorthStar Freight Solutions LLC, 100 Main St, Lakeland, FL 33801';
    Db::pdo()->exec('DELETE FROM email_templates');
    Templates::resetEnsure();
    Templates::ensure();
    Db::run("UPDATE email_templates SET body = REPLACE(body, '[[CONFIRMAR: caso real aprobado por NorthStar, sin cifras ni garantías sin respaldo]]', 'Caso real aprobado.')");   // sin marcadores pendientes
    Settings::set('mail', ['enabled' => true, 'address' => $address, 'from_name' => 'NorthStar Freight Solutions', 'cap_hour' => 20, 'cap_lead' => 1, 'rates_source' => '', 'weekly_to' => 'carlos@example.com', 'monthly_to' => []]);
    Settings::clear();
    Fx::noSmtp();
    $warm = static function (array $o = []): int {
        $id = Fx::lead($o + ['temperature' => 'warm', 'stage' => 'new', 'equipment_types' => ['dry-van', 'reefer'], 'state' => 'TX', 'first_name' => 'Mike']);
        Fx::consent($id);
        return $id;
    };
    $tick = static function (): int {
        $n = Sequences::enqueueDue();
        Fx::runJobs();
        return $n;
    };
    $ns = $warm(['email' => 'nosmtp44@example.com']);
    Sequences::enrollWarm($ns);
    $en = Db::one('SELECT * FROM sequence_enrollments WHERE lead_id = ?', [$ns]);
    Clock::travel(2 * 86400);
    $tick();
    $en2 = Db::one('SELECT * FROM sequence_enrollments WHERE lead_id = ?', [$ns]);
    T::eq((int) $en2['step'], (int) $en['step'], 'sin SMTP el paso de la secuencia NO avanza como «enviado»');
    T::ok(str_contains((string) $en2['blocked_reason'], 'Sin SMTP') && $count('messages', 'lead_id = ?', [$ns]) === 0, '  queda bloqueado con el motivo «Sin SMTP» y sin mensaje «logged» en messages');
    Fx::smtp($smtpPort);
    Clock::travel(2 * 86400 + 3700);
    $tick();
    $en3 = Db::one('SELECT * FROM sequence_enrollments WHERE lead_id = ?', [$ns]);
    T::ok((int) $en3['step'] > (int) $en['step'] && $count('messages', "lead_id = ? AND status = 'sent'", [$ns]) === 1, 'con SMTP el mismo paso sale de verdad y se cuenta como enviado');
    $logged = Fx::lead(['temperature' => 'warm', 'email' => 'tope44@example.com']);
    Db::insert('messages', ['lead_id' => $logged, 'channel' => 'email', 'direction' => 'out', 'body' => 'x', 'template_key' => 'a6_d1', 'sequence_key' => 'a6_warm', 'status' => 'logged', 'created_at' => Clock::now()]);
    $ref = new ReflectionClass(Sequences::class);
    $m = $ref->getMethod('sentToLead');
    $m->setAccessible(true);
    T::eq($m->invoke(null, $logged), 0, 'los «logged» no cuentan en el tope por lead');
    Clock::travel(0);
    // recordatorio de cita sin SMTP
    Fx::noSmtp();
    $rl = Fx::lead(['email' => 'rem44@example.com', 'first_name' => 'Rita', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    Fx::consent($rl);
    $start = Clock::at(Clock::time() + 30 * 3600);
    $apId = Db::insert('appointments', ['lead_id' => $rl, 'starts_at' => $start, 'calcom_id' => 'bkg-A144', 'status' => 'booked']);
    Appointments::jobReminder(['appointment_id' => $apId, 'which' => '24h', 'starts_at' => $start]);
    T::ok($count('lead_events', "lead_id = ? AND type = 'reminder_skipped' AND payload LIKE '%no_smtp%'", [$rl]) === 1 && $count('lead_events', "lead_id = ? AND type = 'reminder_sent'", [$rl]) === 0 && $count('messages', 'lead_id = ?', [$rl]) === 0,
        'recordatorio de cita sin SMTP: queda «omitido por no_smtp», no «enviado» ni registrado en messages');
    Fx::smtp($smtpPort);

    // =============================================================================================== A2 · un evento de n8n que falla se puede reintentar
    T::section('44 · A2 n8n reintento');
    $n8 = Fx::lead(['email' => 'n8n44@example.com']);
    $api = Panel::start($ctx, 'int44', ['N8N_WEBHOOK_SECRET' => 'n8n-secret-12345']);
    $tok = $ctx['env']['INTERNAL_API_TOKEN'];
    $call = static fn(array $b) => Infra::http('POST', $api->base . "/internal/leads/$n8/events", ['headers' => ['Authorization: Bearer ' . $tok], 'json' => $b, 'timeout' => 20]);
    $r1 = $call(['event_id' => 'evt-retry-44a', 'event' => 'message_sent']);
    T::eq($r1['status'], 422, 'evento con datos incompletos: 422 (campo faltante)');
    $r2 = $call(['event_id' => 'evt-retry-44a', 'event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a1_welcome']);
    T::ok($r2['status'] === 200 && $count('messages', "lead_id = ? AND template_key = 'a1_welcome'", [$n8]) === 1, 'el reintento con el mismo event_id YA corregido se aplica (antes se descartaba como duplicado)');
    $r3 = $call(['event_id' => 'evt-retry-44a', 'event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a1_welcome']);
    T::ok(($r3['status'] === 200) && !empty(json_decode($r3['body'], true)['duplicate']) && $count('messages', "lead_id = ? AND template_key = 'a1_welcome'", [$n8]) === 1, 'un evento ya procesado sigue siendo duplicado (idempotente)');
    $c1 = Webhook::claim('n8n', 'unit-44', null, null, true);
    Webhook::finish((int) $c1, 'error', null, 'fallo');
    T::ok(Webhook::claim('n8n', 'unit-44', null, null, true) !== null && Webhook::claim('n8n', 'unit-44', null, null, false) === null, 'Webhook::claim: un evento en error se vuelve a reclamar solo con retryErrors');
    Infra::stop('int44');

    // =============================================================================================== A3 · el bloqueo de ingesta se suelta tras el commit externo
    T::section('44 · A3 bloqueo de ingesta');
    $held = null;
    Db::transaction(static function () use (&$held): void {
        LeadIngest::handle(['profile' => 'owner_operator', 'name' => 'Lock Prueba', 'email' => 'lock44@example.com', 'phone' => '+1 305 555 4400', 'site_lead_id' => 'cal_lock44'], 'calcom', ['skip_antispam' => true, 'fmcsa_sync' => false]);
        $held = (int) Db::value("SELECT IS_FREE_LOCK('captacion_ingest')");
    });
    T::eq($held, 0, 'dentro de la transaccion externa el bloqueo de ingesta SIGUE tomado (no se suelta antes del commit)');
    T::eq((int) Db::value("SELECT IS_FREE_LOCK('captacion_ingest')"), 1, '  y se suelta al terminar la transaccion');
    try {
        Db::transaction(static function (): void {
            LeadIngest::handle(['profile' => 'owner_operator', 'name' => 'Lock Dos', 'email' => 'lock44b@example.com', 'phone' => '+1 305 555 4401', 'site_lead_id' => 'cal_lock44b'], 'calcom', ['skip_antispam' => true, 'fmcsa_sync' => false]);
            throw new RuntimeException('rollback de prueba');
        });
    } catch (RuntimeException) {
    }
    T::ok((int) Db::value("SELECT IS_FREE_LOCK('captacion_ingest')") === 1 && $count('leads', "email = 'lock44b@example.com'") === 0, 'si la transaccion externa hace rollback, el bloqueo tambien se suelta y no queda el lead');
    $solo = LeadIngest::handle(['profile' => 'owner_operator', 'name' => 'Lock Tres', 'email' => 'lock44c@example.com', 'phone' => '+1 305 555 4402'], 'api', ['skip_antispam' => true, 'fmcsa_sync' => false]);
    T::ok($solo['ok'] && (int) Db::value("SELECT IS_FREE_LOCK('captacion_ingest')") === 1, 'fuera de una transaccion el bloqueo se suelta como siempre');

    // =============================================================================================== A4 · claves de dedupe que impedian reanudar o reprogramar
    T::section('44 · A4 claves de dedupe');
    Clock::travel(0);
    Db::pdo()->exec('DELETE FROM sequence_enrollments');
    $pl = $warm(['email' => 'pausa44@example.com']);
    Sequences::enrollWarm($pl);
    $eid = (int) Db::value('SELECT id FROM sequence_enrollments WHERE lead_id = ?', [$pl]);
    Clock::travel(2 * 86400);
    Sequences::enqueueDue();
    Sequences::manage($eid, 'pause', $aid);
    Fx::runJobs();   // el job vence con la inscripcion en pausa y termina sin hacer nada
    Sequences::manage($eid, 'resume', $aid);
    T::ok(Sequences::enqueueDue() >= 1, 'reanudar una inscripcion pausada vuelve a encolar su paso (antes la clave de dedupe lo impedia para siempre)');
    $b4 = count(Infra::readLog($smtpLog));
    Fx::runJobs();
    T::ok(count(Infra::readLog($smtpLog)) > $b4, '  y el paso reanudado sale');
    Clock::travel(0);
    // recordatorios A > B > A
    $al = Fx::lead(['email' => 'abc44@example.com', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    Fx::consent($al);
    $t0 = time();
    $S1 = $t0 + 4 * 86400;
    $S2 = $t0 + 5 * 86400;
    $ev = static fn(string $trigger, string $uid, int $start, int $n, string $re = ''): array => ['trigger' => $trigger, 'uid' => $uid, 'reschedule_uid' => $re, 'start' => $start, 'end' => null, 'title' => '', 'meeting_url' => '', 'tz' => '',
        'name' => 'Abc', 'email' => 'abc44@example.com', 'phone' => '', 'lang' => 'en', 'event_at' => $t0 + $n];
    Appointments::apply($ev('BOOKING_CREATED', 'bkg-A44A', $S1, 1));
    $ap = (int) Db::value('SELECT id FROM appointments WHERE lead_id = ?', [$al]);
    Appointments::apply($ev('BOOKING_RESCHEDULED', 'bkg-B44B', $S2, 2, 'bkg-A44A'));
    Appointments::apply($ev('BOOKING_RESCHEDULED', 'bkg-C44C', $S1, 3, 'bkg-B44B'));
    T::ok($count('jobs', "dedupe_key = ? AND status = 'pending'", ["appt_rem:$ap:$S1:24h"]) === 1 && $count('jobs', "dedupe_key = ? AND status = 'pending'", ["appt_rem:$ap:$S1:1h"]) === 1,
        'cita A > B > A: los recordatorios de A vuelven a quedar programados (antes el job cancelado con la misma clave los impedia)');
    T::eq($count('jobs', "dedupe_key = ? AND status = 'cancelled'", ["appt_rem:$ap:$S2:24h"]), 1, '  y los de B quedan cancelados');

    // =============================================================================================== A5 · Prospectos usa SIEMPRE la zona ET
    T::section('44 · A5 Prospectos en ET');
    Settings::set('display.timezone', 'Pacific/Honolulu');
    $ts = (int) strtotime('2026-11-18 12:00:00 UTC');   // miercoles
    T::eq(Prospects::nextBusinessDay($ts), '2026-11-19 14:00:00', 'siguiente dia habil: 9:00 a. m. ET = 14:00 UTC aunque la zona de visualizacion sea otra');
    T::eq(Prospects::etToUtc('2026-11-18', true), '2026-11-19 04:59:59', 'fin del dia ET en UTC, sin depender de la zona de visualizacion');
    T::eq(Prospects::etToUtc('2026-11-18T09:00'), '2026-11-18 14:00:00', 'una fecha escrita para el siguiente intento se interpreta en ET');
    T::ok(Prospects::etToUtc('2026-13-40') === null && Prospects::etToUtc('basura') === null, '  y una fecha invalida da null');
    Settings::set('display.timezone', 'America/New_York');

    // =============================================================================================== A6 · el informe publicado no lleva texto operativo interno
    T::section('44 · A6 informe sin texto interno');
    Clock::travel(0);
    $hot = Fx::lead(['temperature' => 'hot', 'score' => 90, 'stage' => 'new', 'first_contact_at' => null, 'created_at' => Clock::at(time() - 3 * 3600)]);
    $odl = Fx::lead(['stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    Db::insert('tasks', ['title' => 'Tarea vencida interna', 'lead_id' => $odl, 'due_at' => Clock::at(time() - 86400), 'priority' => 2, 'created_at' => Clock::now()]);
    $period = ReportJobs::previousMonth(Clock::time());
    $g = ReportJobs::generateMonthly($period, $aid);
    $rep = Db::one("SELECT * FROM reports WHERE kind = 'monthly' AND period = ?", [$period]);
    $acts = json_decode((string) $rep['actions'], true) ?: [];
    $metrics = json_decode((string) $rep['metrics'], true) ?: [];
    $actTxt = implode(' | ', $acts);
    T::ok($g['ok'] && $acts !== [] && !preg_match('/caliente|tarea|Configuración|sin contactar|primera respuesta/iu', $actTxt), 'las acciones precargadas (publicables) no llevan texto operativo interno: ' . $actTxt);
    T::ok(!empty($metrics['internal_actions']) && preg_match('/caliente|tarea/iu', implode(' ', $metrics['internal_actions'])) === 1, 'las sugerencias internas (leads calientes, tareas vencidas…) se guardan aparte, solo para el admin');
    $panel = Panel::start($ctx, 'panel44');
    T::ok($panel->login($admin['email']), 'login del administrador');
    $draftPage = $panel->get('/informes/' . (int) $rep['id']);
    T::ok($draftPage['status'] === 200 && str_contains($draftPage['body'], 'Sugerencias internas') && str_contains($draftPage['body'], 'no se publican'), 'el admin ve las sugerencias internas en el borrador, marcadas «no se publican»');
    ReportJobs::publish((int) $rep['id'], $aid);
    $vUser = Panel::user('viewer');
    $viewer = $panel->another();
    T::ok($viewer->login($vUser['email']), 'login del lector');
    $vp = $viewer->get('/informes/' . (int) $rep['id']);
    T::ok($vp['status'] === 200 && !str_contains($vp['body'], 'Sugerencias internas') && !str_contains($vp['body'], 'internal_actions') && !preg_match('/Cerrar las \d+ tarea|Contactar \d+ lead/u', $vp['body']), 'el lector no ve sugerencias internas ni en el informe publicado');
    $adminPub = $panel->get('/informes/' . (int) $rep['id']);
    T::ok(!str_contains($adminPub['body'], 'Sugerencias internas'), 'un informe ya publicado ya no muestra las internas ni al admin (no se mezclan con lo publicado)');

    // =============================================================================================== A7 · config.js: sin recargas que pierdan lo editado
    T::section('44 · A7 config.js');
    $cfgJs = $read('public/assets/config.js');
    T::ok(!preg_match('/r\s*<=\s*0\)\s*\{\s*location\.reload/', $cfgJs) && str_contains($cfgJs, 'Edición bloqueada: vuelve a confirmar tu identidad') && str_contains($cfgJs, "role', 'alert'"), 'al expirar la cuenta atrás se bloquean los campos con un aviso role=alert (sin location.reload)');
    T::ok(!str_contains($cfgJs, 'setTimeout(function () { location.reload(); }, 6000)') && str_contains($cfgJs, '/ajax/config/recalc'), 'el recálculo en curso pide solo el estado por JSON (no recarga la página cada 6 s)');
    T::ok(str_contains($cfgJs, 'NS.toast(\'Copiado'), '«Copiado» se anuncia con NS.toast');
    $rc = $panel->ajax('/ajax/config/recalc', []);
    $rcj = $panel->json($rc);
    T::ok($rc['status'] === 200 && ($rcj['ok'] ?? false) && isset($rcj['status'], $rcj['pct']), 'POST /ajax/config/recalc (admin): estado del recálculo en JSON');
    T::eq($viewer->ajax('/ajax/config/recalc', [])['status'], 403, '  el lector recibe 403');
    T::ok(in_array(Infra::http('POST', $panel->base . '/ajax/config/recalc', ['json' => [], 'headers' => ['X-CSRF-Token: x']])['status'], [401, 403], true), '  sin sesión: 401 o 403');

    // =============================================================================================== B · accesibilidad y UX
    T::section('44 · B accesibilidad y UX');
    $um = $panel->get('/tareas');
    T::ok(!str_contains($um['body'], 'id="dd-user" role="menu"') && !str_contains($um['body'], 'aria-haspopup="menu" aria-controls="dd-user"') && !str_contains($um['body'], 'role="menuitem"'), 'B8 el menú de usuario es un popover de botones normales (sin role=menu ni menuitem)');
    $panelJs = $read('public/assets/panel.js');
    T::ok(str_contains($panelJs, "addEventListener('focusout'") && str_contains($panelJs, 'closeDd()'), 'B8 los desplegables se cierran al salir el foco (focusout), además del clic fuera y Esc');
    $uxJs = $read('public/assets/ux.js');
    $tareasJs = $read('public/assets/tareas.js');
    T::ok(str_contains($uxJs, "li.setAttribute('role', 'presentation')") && str_contains($tareasJs, "li.setAttribute('role', 'presentation')") && str_contains($tareasJs, 'aria-activedescendant') && str_contains($tareasJs, 'b.tabIndex = -1')
        && str_contains($tareasJs, "$('[data-lp-clear]', root)"), 'B9 listbox con li role=presentation, combobox con aria-activedescendant, opciones no tabulables y foco a «Quitar»');
    T::ok(str_contains($uxJs, 'searching = true') && str_contains($uxJs, 'if (searching) return;') && str_contains($uxJs, "q ? ['Leads', 'Acciones'"), 'B10 con una búsqueda en vuelo Enter no ejecuta y, con texto, los leads van antes que las acciones');
    T::ok(str_contains($um['body'], 'data-keys-toggle') && str_contains($um['body'], 'role="switch"') && str_contains($um['body'], 'data-keys="on"'), 'B11 el menú de usuario trae el interruptor «Atajos de una tecla» (activados por defecto)');
    $kp = $panel->ajax('/ajax/prefs', ['key' => 'keys', 'value' => 'off']);
    $after = $panel->get('/tareas');
    T::ok($kp['status'] === 200 && str_contains($after['body'], 'data-keys="off"') && str_contains($after['body'], 'aria-checked="false"'), '  guardado en user_prefs: desactivados, la página sale con data-keys="off"');
    T::eq($panel->ajax('/ajax/prefs', ['key' => 'keys', 'value' => 'quizas'])['status'], 422, '  un valor que no es on/off se rechaza');
    $panel->ajax('/ajax/prefs', ['key' => 'keys', 'value' => 'on']);
    T::ok(str_contains($uxJs, 'NS.shortcutsOn') && str_contains($read('public/assets/peek.js'), 'NS.shortcutsOn'), '  con ellos desactivados solo funcionan Ctrl/⌘ K y Esc (ux.js y peek.js lo respetan)');
    T::eq($viewer->ajax('/ajax/prefs', ['key' => 'keys', 'value' => 'off'])['status'], 403, '  el lector no puede guardar ese ajuste');
    // B12 · diálogo de confirmación
    $tk = Fx::lead(['stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    Db::insert('tasks', ['title' => 'Llamar a Pepe', 'lead_id' => $tk, 'due_at' => Clock::at(time() + 3600), 'priority' => 2, 'created_at' => Clock::now()]);
    $tp = $panel->get('/tareas');
    T::ok(str_contains($tp['body'], 'data-confirm-title="¿Eliminar la tarea?"') && str_contains($tp['body'], 'data-confirm-label="Eliminar" data-confirm-danger'), 'B12 eliminar una tarea: título y botón propios y botón rojo (destructivo)');
    $ip = $panel->get('/informes/' . (int) $rep['id']);
    $dr = Db::one("SELECT id FROM reports WHERE kind = 'monthly' AND status = 'draft' ORDER BY id DESC LIMIT 1");
    $views = ['informe.php' => $read('views/informe.php')];
    T::ok(str_contains($views['informe.php'], 'data-confirm-title="¿Publicar el informe?" data-confirm-label="Publicar"') && !preg_match('/data-confirm-label="Publicar"[^>]*data-confirm-danger/', $views['informe.php']), '  publicar un informe: confirmación NO destructiva (botón primario)');
    $noDanger = 0;
    $bad = [];
    foreach (glob($root . '/views/*.php') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match_all('/data-confirm="[^"]*"/', $src, $mm) && !str_contains($src, 'Ui::confirm') && !str_contains($src, 'data-confirm-title')) {
            $bad[] = basename($f);
        }
    }
    foreach (glob($root . '/views/*/*.php') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match('/data-confirm="/', $src) && !str_contains($src, 'data-confirm-title') && !str_contains($src, 'Ui::confirm')) {
            $bad[] = basename(dirname($f)) . '/' . basename($f);
        }
    }
    T::ok($bad === [], 'B12 todos los usos de data-confirm llevan título y botón propios' . ($bad ? ': ' . implode(', ', $bad) : ''));
    T::ok(str_contains($panelJs, "getAttribute('data-confirm-title')") && str_contains($panelJs, 'btn--danger') && str_contains($panelJs, 'btn--primary'), '  panel.js usa título, etiqueta y rojo solo con data-confirm-danger');
    T::ok(str_contains(\Captacion\Ui::confirm(['text' => 'x', 'title' => 'T', 'label' => 'L', 'danger' => true]), 'data-confirm-danger') && !str_contains(\Captacion\Ui::confirm('x'), 'data-confirm-danger'), '  Ui::confirm solo marca danger si se pide');
    // B13 / B14 · vistazo e inert
    $peekJs = $read('public/assets/peek.js');
    T::ok(str_contains($peekJs, "emptyErr(field, 'Escribe la nota antes de guardarla.')") && str_contains($peekJs, "setAttribute('role', 'alert')") && str_contains($peekJs, 'focusTitle'), 'B13 guardar una nota o tarea vacía muestra un error role=alert; J/K no repiten el foco al título');
    T::ok(str_contains($panelJs, 'inertOthers') && str_contains($peekJs, 'NS.inertOthers([pk, scrim])') && str_contains($panelJs, 'releaseNav'), 'B14 el cajón móvil y el vistazo en hoja dejan el resto de la página inerte y lo revierten al cerrar');
    // B15 · tablas con nombre
    $unnamed = [];
    foreach (array_merge(glob($root . '/views/*.php') ?: [], glob($root . '/views/*/*.php') ?: []) as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match_all('/<table\b([^>]*)>(.{0,60})/s', $src, $tm, PREG_SET_ORDER)) {
            foreach ($tm as $t) {
                if (!str_contains($t[1], 'aria-label') && !str_contains($t[2], '<caption')) {
                    $unnamed[] = basename($f);
                }
            }
        }
    }
    T::ok($unnamed === [], 'B15 toda <table> de las vistas tiene aria-label o <caption>' . ($unnamed ? ': ' . implode(', ', array_unique($unnamed)) : ''));
    // B16 · táctil
    $uxCss = $read('public/assets/ux.css');
    T::ok(str_contains($uxCss, '@media (pointer:coarse)') && str_contains($uxCss, '.tick::after') && str_contains($uxCss, 'min-height:44px') && str_contains($read('public/assets/calendario.css'), 'border:2px solid var(--field)'), 'B16 bloque pointer:coarse en la hoja diferida (44 px, área ampliada del .tick) y borde del .tick con --field');
    $e404 = $panel->get('/nope-44');
    T::ok($e404['status'] === 404 && str_contains($e404['body'], 'class="shell"') && str_contains($e404['body'], 'Volver al panel'), 'B16 la página 404 con sesión va dentro del shell y «Volver al panel»');
    $v404 = $viewer->get('/nope-44');
    T::ok($v404['status'] === 404 && str_contains($v404['body'], 'class="shell"') && str_contains($v404['body'], 'href="/resultados"') && str_contains($v404['body'], 'Volver a resultados'), '  el lector: dentro del shell y «Volver a resultados»');
    $anon = $panel->another();
    $a404 = $anon->get('/nope-44');
    T::ok($a404['status'] === 404 && !str_contains($a404['body'], 'class="shell"'), '  sin sesión: la tarjeta simple de siempre');

    // =============================================================================================== C17 · reglas por eventos y por tiempo
    T::section('44 · C17 reglas de eventos y tiempo');
    Clock::travel(0);
    $blk = static fn(string $mode, array ...$items): array => ['mode' => $mode, 'items' => $items];
    $cond = static fn(string $f, string $op, mixed $v = null): array => ['field' => $f, 'op' => $op, 'value' => $v];
    $R = static fn(string $id, string $name, array $trigger, array $then, array $o = []): array => $o + ['trigger' => $trigger, 'id' => $id, 'name' => $name, 'enabled' => true, 'stop' => false, 'example' => false, 'when' => ['mode' => 'all', 'items' => []], 'except' => null, 'then' => $then];
    $mkAuto = static function (string $name, array $rules, bool $publish = true) use ($aid): int {
        $s = RuleSets::create('automation', $name, '', $aid);
        $v = Rules::normalize($rules, 'automation');
        if (!$v['ok']) {
            throw new RuntimeException('reglas de prueba no validan: ' . json_encode($v['errors']));
        }
        RuleSets::saveDraft($s['id'], $v['rules'], null, $aid);
        if ($publish) {
            RuleSets::publish($s['id'], $aid);
        }
        return (int) $s['id'];
    };
    // validacion del catalogo
    $bad = Rules::normalize([['id' => 'x1', 'name' => 'Sin evento', 'enabled' => true, 'when' => ['mode' => 'all', 'items' => []], 'then' => [['type' => 'notify_bell', 'text' => 'Hola']]]], 'automation');
    T::ok(!$bad['ok'] && isset($bad['errors']['rules.0.trigger.type']), 'una regla de eventos sin disparador no valida');
    $bad = Rules::normalize([['trigger' => ['type' => 'no_first_contact', 'hours' => '0'], 'id' => 'x2', 'name' => 'Cero horas', 'then' => [['type' => 'notify_bell', 'text' => 'x']]]], 'automation');
    T::ok(!$bad['ok'] && isset($bad['errors']['rules.0.trigger.hours']), '«sin primer contacto» exige de 1 a 720 horas');
    $bad = Rules::normalize([['trigger' => ['type' => 'stage_change', 'to' => 'inventada'], 'id' => 'x3', 'name' => 'Etapa mala', 'then' => [['type' => 'notify_bell', 'text' => 'x']]]], 'automation');
    T::ok(!$bad['ok'] && isset($bad['errors']['rules.0.trigger.to']), 'una etapa desconocida se rechaza');
    $bad = Rules::normalize([['trigger' => ['type' => 'stage_change', 'to' => ''], 'id' => 'x4', 'name' => 'Cambia etapa', 'then' => [['type' => 'add_points', 'points' => 5]]]], 'automation');
    T::ok(!$bad['ok'] && isset($bad['errors']['rules.0.then.0.type']), 'las acciones de puntos no se pueden usar en eventos (y no existe ninguna que cambie etapa o asigne responsable)');
    T::ok(!array_intersect(Rules::KIND_ACTIONS['automation'], ['set_stage', 'move_stage', 'assign_owner', 'assign']), 'el catálogo de eventos no tiene acciones que cambien la etapa ni asignen responsable');
    $ok = Rules::normalize([$R('o1', 'Aviso', ['type' => 'consent_revoked', 'channel' => 'sms'], [['type' => 'notify_bell', 'text' => 'Revocó {nombre}'], ['type' => 'outbound_webhook']])], 'automation');
    T::ok($ok['ok'] && $ok['rules'][0]['trigger'] === ['type' => 'consent_revoked', 'channel' => 'sms'], 'una regla bien formada valida y conserva su disparador');
    $sc = Rules::normalize([array_replace($R('o2', 'x', ['type' => 'stage_change', 'to' => ''], [['type' => 'add_points', 'points' => 5]]), ['when' => $blk('all', $cond('state', 'is', 'TX'))])], 'scoring');
    T::ok($sc['ok'] && !isset($sc['rules'][0]['trigger']), 'en un conjunto de puntuación el disparador se ignora (forma intacta)');
    T::ok(RuleEngine::triggerMatches(['type' => 'stage_change', 'to' => 'qualified'], 'stage_change', ['to' => 'qualified']) && !RuleEngine::triggerMatches(['type' => 'stage_change', 'to' => 'qualified'], 'stage_change', ['to' => 'lost'])
        && RuleEngine::triggerMatches(['type' => 'no_first_contact', 'hours' => 4], 'no_first_contact', ['hours' => 4.5]) && !RuleEngine::triggerMatches(['type' => 'no_first_contact', 'hours' => 4], 'no_first_contact', ['hours' => 3.9])
        && RuleEngine::triggerMatches(['type' => 'consent_revoked', 'channel' => 'sms'], 'consent_revoked', ['channels' => ['all']]) && !RuleEngine::triggerMatches(['type' => 'consent_revoked', 'channel' => 'sms'], 'consent_revoked', ['channels' => ['email']]),
        'RuleEngine::triggerMatches (pura): etapa, horas y canal');

    // equivalencia: un conjunto de automatizacion no toca la puntuacion
    $probe = ['profile' => 'owner_operator', 'state' => 'TX', 'truck_count' => 3, 'years_experience' => 5, 'equipment_types' => ['dry-van'], 'cdl_type' => 'standard', 'english_level' => 'yes', 'source' => 'web_careers', 'language' => 'en', 'stage' => 'new', 'created_at' => Clock::now(), 'flags' => []];
    $before = RuleRunner::score($probe);
    $setA = $mkAuto('Eventos de prueba', [
        $R('e_stage', 'A contactado', ['type' => 'stage_change', 'to' => 'contacted'], [['type' => 'notify_bell', 'text' => '{nombre} pasó a {etapa}'], ['type' => 'create_task', 'title' => 'Seguir a {nombre}', 'due_hours' => 3, 'priority' => 1]]),
        $R('e_consent', 'Revocó SMS', ['type' => 'consent_revoked', 'channel' => 'sms'], [['type' => 'notify_bell', 'text' => 'Revocó el consentimiento: {nombre}']]),
        $R('e_nfc', 'Sin contacto 4 h', ['type' => 'no_first_contact', 'hours' => 4], [['type' => 'notify_bell', 'text' => '{nombre} lleva horas sin contacto']]),
        $R('e_task', 'Tarea vencida 2 h', ['type' => 'task_overdue', 'hours' => 2], [['type' => 'notify_bell', 'text' => 'Tarea vencida de {nombre}']]),
    ]);
    T::eq(RuleRunner::score($probe), $before, 'con un conjunto de eventos publicado la puntuación es EXACTAMENTE la de antes (no entra al motor de puntuación)');
    T::ok(!array_filter(RuleSets::engineSets(), static fn($s) => $s['kind'] === 'automation') && count(RuleSets::automationSets()) === 1, 'engineSets() no incluye los conjuntos de eventos; automationSets() sí');

    // evento: cambio de etapa
    $L1 = Fx::lead(['first_name' => 'Laura', 'last_name' => 'Etapa', 'stage' => 'new']);
    $r = LeadActions::setStage($L1, 'contacted', [], $aid);
    T::ok($r['ok'] && $count('rule_notices', 'lead_id = ?', [$L1]) === 1 && $count('tasks', "lead_id = ? AND title = 'Seguir a Laura Etapa'", [$L1]) === 1, 'cambio de etapa a «contactado»: la regla avisa en la campana y crea la tarea');
    T::eq((string) Db::value('SELECT text FROM rule_notices WHERE lead_id = ?', [$L1]), 'Laura Etapa pasó a Contactado', '  el texto del aviso usa las variables permitidas');
    LeadActions::setStage($L1, 'qualified', [], $aid);
    T::eq($count('rule_notices', 'lead_id = ?', [$L1]), 1, 'pasar a otra etapa distinta de la del disparador no activa la regla');
    T::ok($count('rule_applications', "lead_id = ? AND kind = 'automation'", [$L1]) === 2, 'quedan registradas las aplicaciones (aviso y tarea) en rule_applications');
    // campana
    $items = Notifications::all($aid);
    $keys = array_column(array_filter($items, static fn($i) => str_starts_with($i['key'], 'rule_notice:')), 'key');
    T::ok(count($keys) === 1 && Notifications::markRead($aid, [$keys[0]]) === 1, 'el aviso de la regla sale en la campana y se marca leído por separado');
    // evento: consentimiento
    $L2 = Fx::lead(['first_name' => 'Cora']);
    Fx::consent($L2);
    Consent::revoke($L2, 'email', 'panel');
    T::eq($count('rule_notices', 'lead_id = ?', [$L2]), 0, 'revocar OTRO canal no activa la regla de SMS');
    Consent::revoke($L2, 'sms', 'panel');
    T::eq($count('rule_notices', 'lead_id = ?', [$L2]), 1, 'revocar el consentimiento de SMS activa la regla');
    $L3 = Fx::lead(['first_name' => 'Todos']);
    Fx::consent($L3);
    Consent::revoke($L3, 'all', 'panel');
    T::eq($count('rule_notices', 'lead_id = ?', [$L3]), 1, 'revocar todos los canales también activa la regla de SMS');
    // tiempo: sin primer contacto
    $old = Fx::lead(['first_name' => 'Vieja', 'stage' => 'new', 'created_at' => Clock::at(time() - 5 * 3600)]);
    $fresh = Fx::lead(['first_name' => 'Fresca', 'stage' => 'new', 'created_at' => Clock::at(time() - 3600)]);
    $contacted = Fx::lead(['first_name' => 'Contactada', 'stage' => 'new', 'created_at' => Clock::at(time() - 6 * 3600), 'first_contact_at' => Clock::now()]);
    $t1 = RuleAutomation::tick();
    T::ok($count('rule_notices', 'lead_id = ?', [$old]) === 1 && $count('rule_notices', 'lead_id = ?', [$fresh]) === 0 && $count('rule_notices', 'lead_id = ?', [$contacted]) === 0, 'sin primer contacto: actúa con 5 h, no con 1 h y no si ya hubo contacto');
    RuleAutomation::tick();
    RuleAutomation::tick();
    T::eq($count('rule_notices', 'lead_id = ?', [$old]), 1, 'el job recurrente es idempotente por regla + lead + intento: no repite');
    Clock::travel(4 * 3600);
    RuleAutomation::tick();
    T::eq($count('rule_notices', 'lead_id = ?', [$fresh]), 1, '  y cuando pasan las horas, el lead que faltaba también dispara una sola vez');
    Clock::travel(0);
    // tiempo: tarea vencida
    $L4 = Fx::lead(['first_name' => 'Tarea', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    $dueOld = Db::insert('tasks', ['title' => 'Vence ya', 'lead_id' => $L4, 'due_at' => Clock::at(time() - 3 * 3600), 'priority' => 2, 'created_at' => Clock::now()]);
    $dueNew = Db::insert('tasks', ['title' => 'Vence hace poco', 'lead_id' => $L4, 'due_at' => Clock::at(time() - 3600), 'priority' => 2, 'created_at' => Clock::now()]);
    RuleAutomation::tick();
    T::eq($count('rule_notices', 'lead_id = ?', [$L4]), 1, 'tarea vencida: actúa con 3 h de retraso (≥ 2 h) y no con 1 h');
    RuleAutomation::tick();
    T::eq($count('rule_notices', 'lead_id = ?', [$L4]), 1, '  sin repetirse en la pasada siguiente');
    Db::run('UPDATE tasks SET done_at = ? WHERE id = ?', [Clock::now(), $dueOld]);
    Clock::travel(2 * 3600);
    RuleAutomation::tick();
    T::eq($count('rule_notices', 'lead_id = ?', [$L4]), 2, '  la otra tarea dispara cuando ya lleva 2 h vencida (una vez por tarea)');
    Clock::travel(0);
    // el job y su registro
    T::ok(isset(JobHandlers::all()['rules_time'], JobHandlers::all()['outbound_webhook']), 'process_jobs conoce el job rules_time (cada 15 minutos) y el de webhooks salientes');
    $e1 = RuleAutomation::enqueueTick();
    $e2 = RuleAutomation::enqueueTick();
    T::ok(($e1 !== null || $count('jobs', "type = 'rules_time'") === 1) && $e2 === null, '  enqueueTick() encola una sola vez por tramo de 15 minutos');
    T::ok(str_contains($read('cron/process_jobs.php'), 'RuleAutomation::enqueueTick'), '  y cron/process_jobs.php lo llama en cada pasada');
    // condiciones, excepción, interruptores
    RuleSets::unpublish($setA, $aid);
    $setB = $mkAuto('Con condiciones', [
        $R('c1', 'Solo company drivers', ['type' => 'stage_change', 'to' => 'contacted'], [['type' => 'notify_bell', 'text' => 'Driver contactado']], ['when' => $blk('all', $cond('profile', 'is', 'company_driver'))]),
        $R('c2', 'Owner salvo Florida', ['type' => 'stage_change', 'to' => 'contacted'], [['type' => 'notify_bell', 'text' => 'Owner contactado']], ['when' => $blk('all', $cond('profile', 'is', 'owner_operator')), 'except' => $blk('all', $cond('state', 'is', 'FL'))]),
        $R('c3', 'Apagada', ['type' => 'stage_change', 'to' => 'contacted'], [['type' => 'notify_bell', 'text' => 'No debe salir']], ['enabled' => false]),
    ]);
    $tx = Fx::lead(['profile' => 'owner_operator', 'state' => 'TX']);
    $fl = Fx::lead(['profile' => 'owner_operator', 'state' => 'FL']);
    foreach ([$tx, $fl] as $lid) {
        LeadActions::setStage($lid, 'contacted', [], $aid);
    }
    T::ok($count('rule_notices', "lead_id = ? AND text = 'Owner contactado'", [$tx]) === 1 && $count('rule_notices', 'lead_id = ?', [$fl]) === 0 && $count('rule_notices', "text IN ('Driver contactado','No debe salir')") === 0,
        'las condiciones, la excepción y el interruptor de cada regla se respetan');
    $off = Fx::lead(['profile' => 'owner_operator', 'state' => 'TX']);
    Settings::set('rules.enabled', false);
    RuleSets::clearCache();
    LeadActions::setStage($off, 'contacted', [], $aid);
    T::eq($count('rule_notices', 'lead_id = ?', [$off]), 0, 'con el interruptor general de reglas apagado no actúa nada');
    Settings::set('rules.enabled', true);
    RuleSets::clearCache();
    // simulación y vista previa sin escribir
    $rowsBefore = [$count('rule_notices'), $count('tasks'), $count('rule_applications'), $count('jobs')];
    $target = ['set_id' => $setB, 'name' => 'Con condiciones', 'kind' => 'automation', 'rules' => RuleSets::published($setB)['rules']];
    $pv = RuleSim::preview($target, ['mode' => 'last', 'n' => 500]);
    T::ok(!empty($pv['auto']) && $pv['summary']['scanned'] > 0 && $pv['summary']['matched'] >= 2 && $pv['summary']['bells'] >= 2, 'vista previa de un conjunto de eventos: cuenta los leads donde habría actuado (' . $pv['summary']['matched'] . ') y los avisos');
    $sim = RuleSim::start($setB, null, $target['rules'], ['mode' => 'last', 'n' => 500], $aid);
    $st = RuleSim::status((int) $sim['id']);
    T::ok($sim['ok'] && $st['status'] === 'done' && $st['auto'] && (int) $st['summary']['matched'] >= 2 && (int) $st['summary']['bells'] >= 2 && $st['samples'] !== [], 'la simulación soporta los disparadores: termina, cuenta avisos y deja muestras');
    T::eq([$count('rule_notices'), $count('tasks'), $count('rule_applications'), $count('jobs')], $rowsBefore, 'la vista previa y la simulación NO escriben avisos, tareas, aplicaciones ni jobs');
    $csv = RuleSim::csv((int) $sim['id']);
    T::ok($csv !== null && !str_contains($csv, '@') && !preg_match('/Prueba\d|Fx\d/', $csv), 'el CSV de la simulación sigue sin nombres ni correos');
    // editor (HTTP)
    T::ok($panel->unlock($aid, 'reglas'), 'confirmación 2FA para el editor');
    $ed = $panel->get('/config?tab=reglas&set=' . $setB);
    T::ok($ed['status'] === 200 && str_contains($ed['body'], 'data-trigger') && str_contains($ed['body'], 'Cambia de etapa') && str_contains($ed['body'], 'Pasan horas sin primer contacto') && str_contains($ed['body'], 'Solo si (opcional)')
        && str_contains($ed['body'], 'Se revoca un consentimiento'), 'el editor de un conjunto de eventos muestra el disparador y sus parámetros');
    $row = $panel->ajax('/ajax/rules/row', ['kind' => 'rule', 'np' => 'rules[n9]', 'set' => $setB]);
    T::ok($row['status'] === 200 && str_contains($panel->json($row)['html'] ?? '', 'data-rt="type"'), 'una regla nueva en blanco de este tipo trae el selector de disparador');
    $act = $panel->ajax('/ajax/rules/row', ['kind' => 'act', 'np' => 'rules[n9][then][a1]', 'set' => $setB, 'type' => 'notify_bell']);
    T::ok($act['status'] === 200 && str_contains($panel->json($act)['html'] ?? '', 'Texto del aviso'), '  y la acción «avisar en la campana» se puede añadir');
    $form = ['_csrf' => $panel->csrf, 'set' => (string) $setB, 'name' => 'Con condiciones', 'description' => '', 'note' => 'Prueba', 'op' => 'save',
        'rules' => ['n1' => ['id' => '', 'name' => 'Nueva por formulario', 'enabled' => '1', 'trigger' => ['type' => 'task_overdue', 'hours' => '6'], 'when' => ['mode' => 'all'], 'then' => ['a1' => ['type' => 'notify_bell', 'text' => 'Tarea muy vencida']]]]];
    $sv = $panel->post('/config/rules/save', $form);
    $d = RuleSets::draft($setB);
    T::ok($sv['status'] === 303 && $d !== null && ($d['rules'][0]['trigger'] ?? null) === ['type' => 'task_overdue', 'hours' => 6], 'guardar un borrador con disparador por el formulario clásico lo conserva');
    $form['rules']['n1']['trigger']['hours'] = '9999';
    $bad = $panel->post('/config/rules/save', $form);
    T::ok($bad['status'] === 422 && str_contains($bad['body'], 'Escribe de 0 a 720 horas'), '  y una cifra fuera de rango se rechaza con su mensaje');
    T::ok($count('activity_log', "action = 'rules_publish'") >= 2, 'publicar conjuntos de eventos queda en el registro de actividad (mismo circuito de versiones y 2FA)');
    RuleSets::unpublish($setB, $aid);

    // =============================================================================================== C18 · webhooks salientes genéricos
    T::section('44 · C18 webhooks salientes');
    Clock::travel(0);
    Db::pdo()->exec('TRUNCATE TABLE outbound_deliveries');
    Db::pdo()->exec("DELETE FROM jobs WHERE type = 'outbound_webhook'");
    T::eq(OutboundWebhooks::emit('lead.created', 1, [], 'k1'), null, 'sin configuración no se emite nada');
    $url = "http://127.0.0.1:$hookPort/hook";
    $v = OutboundWebhooks::validate(['enabled' => '1', 'url' => 'ftp://x.example.com/h', 'events' => ['lead.created']]);
    T::ok(!$v['ok'] && isset($v['errors']['url']), 'validar: la URL debe ser http(s) válida (https en producción)');
    $v = OutboundWebhooks::validate(['enabled' => '1', 'url' => $url, 'events' => []]);
    T::ok(!$v['ok'] && isset($v['errors']['events']), 'validar: al activar hay que elegir al menos un evento');
    $secret = OutboundWebhooks::generateSecret();
    $events = ['lead.created', 'lead.hot', 'lead.stage_changed', 'task.overdue', 'consent.revoked', 'lead.anonymized', 'rule.fired'];
    $sv = OutboundWebhooks::saveConfig(['enabled' => true, 'url' => $url, 'events' => $events, 'contact' => false], $secret, $aid);
    T::ok($sv['ok'] && OutboundWebhooks::active(), 'guardar la configuración con un secreto generado');
    $raw = (string) Db::value("SELECT `value` FROM settings WHERE `key` = 'webhooks.out'");
    T::ok(!str_contains($raw, $secret) && str_contains($raw, 'v1:') && !str_contains((string) Db::value("SELECT GROUP_CONCAT(changes) FROM activity_log WHERE action LIKE 'webhooks_out%' OR (action = 'settings_change' AND entity_id = 'webhooks.out')"), $secret), 'el secreto se guarda CIFRADO y no aparece en el registro de actividad');
    T::ok(!isset(OutboundWebhooks::viewConfig()['secret']), 'la configuración para la pantalla no lleva el secreto');
    // alta real de un lead: lead.created y lead.hot
    $r = LeadIngest::handle(['profile' => 'owner_operator', 'name' => 'Hook Prueba', 'phone' => '+1 305 555 4410', 'email' => 'hook44@example.com', 'state' => 'TX', 'cdl_type' => 'standard', 'english_level' => 'yes',
        'years_experience' => 8, 'equipment_types' => ['dry-van'], 'truck_count' => 6, 'language' => 'en', 'consent' => ['given' => true, 'text' => 'Texto', 'version' => 'v1', 'channels' => ['call', 'sms', 'email']],
        'source' => 'web_careers', 'usdot' => ''], 'web_careers', ['skip_antispam' => true, 'fmcsa_sync' => false]);
    $newId = (int) $r['lead_id'];
    $pend = Db::all("SELECT event, status FROM outbound_deliveries WHERE lead_id = ? ORDER BY id", [$newId]);
    T::ok($r['status'] === 'created' && in_array('lead.created', array_column($pend, 'event'), true) && $count('jobs', "type = 'outbound_webhook' AND status = 'pending'") >= 1, 'un lead nuevo emite lead.created (a la cola, con reintentos)');
    if ($r['temperature'] === 'hot') {
        T::ok(in_array('lead.hot', array_column($pend, 'event'), true), '  y, si es caliente, lead.hot');
    }
    Fx::runJobs(10);
    $h = $hooks();
    $first = $h[0] ?? null;
    $body = $first ? $hookBody($first) : [];
    T::ok($first !== null && ($body['event'] ?? '') === 'lead.created' && ($body['schema'] ?? '') === OutboundWebhooks::SCHEMA && (int) ($body['lead']['id'] ?? 0) === $newId, 'el job entrega un POST JSON versionado con el evento y el id del lead');
    $hdr = $first['headers'] ?? [];
    $ts = (string) ($hdr['x-ns-timestamp'] ?? '');
    T::ok(($hdr['x-ns-signature'] ?? '') === OutboundWebhooks::sign($secret, $ts, (string) $first['body']) && abs((int) $ts - time()) < 120 && ($hdr['idempotency-key'] ?? '') === ($body['event_id'] ?? 'x') && ($hdr['x-ns-event'] ?? '') === 'lead.created',
        'firma HMAC-SHA256 con marca de tiempo (recalculable con el secreto), Idempotency-Key = event_id y X-NS-Event');
    $lead = $body['lead'] ?? [];
    T::ok(isset($lead['code'], $lead['stage'], $lead['temperature'], $lead['source'], $lead['score'], $lead['url']) && !isset($lead['contact']) && !str_contains((string) $first['body'], '4410') && !str_contains((string) $first['body'], 'Hook Prueba') && !str_contains((string) $first['body'], 'hook44@'),
        'el cuerpo lleva id, código, etapa, temperatura, fuente, puntuación y enlace; SIN nombre, teléfono ni correo');
    T::ok($count('outbound_deliveries', "status = 'sent'") >= 1 && (int) Db::value("SELECT http_status FROM outbound_deliveries WHERE event = 'lead.created' LIMIT 1") === 200, 'la entrega queda marcada «enviada» con su código HTTP');
    // idempotencia
    $nEv = $count('outbound_deliveries');
    $a = OutboundWebhooks::emit('lead.stage_changed', $newId, ['from' => 'new', 'to' => 'contacted'], 'dup-key-1');
    $b = OutboundWebhooks::emit('lead.stage_changed', $newId, ['from' => 'new', 'to' => 'contacted'], 'dup-key-1');
    T::ok($a !== null && $b === null && $count('outbound_deliveries') === $nEv + 1, 'idempotencia por event_id: el mismo (evento, clave) no se encola dos veces');
    Fx::runJobs(10);
    // contacto opcional
    OutboundWebhooks::saveConfig(['enabled' => true, 'url' => $url, 'events' => $events, 'contact' => true], null, $aid);
    OutboundWebhooks::emit('lead.stage_changed', $newId, ['from' => 'a', 'to' => 'b'], 'contacto-1');
    Fx::runJobs(10);
    $allH = $hooks();
    $withC = $allH ? end($allH) : null;
    $cb = $withC ? $hookBody($withC) : [];
    T::ok(($cb['lead']['contact']['name'] ?? '') === 'Hook Prueba' && str_contains((string) ($cb['lead']['contact']['phone'] ?? ''), '4410'), 'con «incluir contacto» el cuerpo lleva nombre y teléfono');
    // anonimización: nunca PII, aunque se pase y aunque esté el contacto activado
    OutboundWebhooks::emit('lead.anonymized', $newId, ['name' => 'Hook Prueba', 'phone' => '+13055554410', 'email' => 'hook44@example.com', 'reason' => 'solicitud'], 'anon-44');
    Fx::runJobs(10);
    $an = array_values(array_filter($hooks(), static fn($x) => ($hookBody($x)['event'] ?? '') === 'lead.anonymized'))[0] ?? null;
    $anb = $an ? $hookBody($an) : [];
    T::ok($an !== null && array_keys($anb['lead'] ?? []) === ['id', 'code', 'url'] && !str_contains((string) $an['body'], 'Hook Prueba') && !str_contains((string) $an['body'], '4410') && !str_contains((string) $an['body'], 'hook44@') && ($anb['data']['reason'] ?? '') === 'solicitud',
        'lead.anonymized solo lleva id, código y enlace: nunca nombre, teléfono ni correo (aunque se pasen y «incluir contacto» esté activo)');
    OutboundWebhooks::saveConfig(['enabled' => true, 'url' => $url, 'events' => $events, 'contact' => false], null, $aid);
    // reintentos y fallo final
    file_put_contents($hookMode, 'fail');
    $rid = (int) OutboundWebhooks::emit('lead.hot', $newId, [], 'fail-1');
    $failedRun = Fx::runJobs(5);
    $row = Db::one('SELECT * FROM outbound_deliveries WHERE id = ?', [$rid]);
    $job = Db::one("SELECT * FROM jobs WHERE dedupe_key = ?", ['outbound:' . $rid]);
    T::ok($row['status'] === 'pending' && (int) $row['attempts'] === 1 && $job['status'] === 'pending' && strtotime((string) $job['run_at']) > time() + 30 && str_contains((string) $row['last_error'], '500'), 'un fallo del destino reintenta con el espaciado de Queue::BACKOFF (1 min) y anota el error');
    try {
        OutboundWebhooks::deliver(['delivery_id' => $rid], ['attempts' => 4]);
    } catch (RuntimeException) {
    }
    T::eq(Db::value('SELECT status FROM outbound_deliveries WHERE id = ?', [$rid]), 'failed', '  agotados los reintentos de la cola la entrega queda «fallida»');
    file_put_contents($hookMode, 'ok');
    // SSRF revalidado al enviar
    $sid = (int) OutboundWebhooks::emit('lead.hot', $newId, [], 'ssrf-1');
    Env::set('APP_ENV', 'production');
    $blocked = false;
    try {
        OutboundWebhooks::deliver(['delivery_id' => $sid], ['attempts' => 1]);
    } catch (PermanentJobFailure) {
        $blocked = true;
    }
    Env::set('APP_ENV', 'local');
    T::ok($blocked && Db::value('SELECT status FROM outbound_deliveries WHERE id = ?', [$sid]) === 'failed' && str_contains((string) Db::value('SELECT last_error FROM outbound_deliveries WHERE id = ?', [$sid]), 'bloqueado'), 'el filtro SSRF se REVALIDA al enviar: una URL interna en producción no sale (fallo permanente)');
    $hn = count($hooks());
    // probar
    $tr = OutboundWebhooks::sendTest($aid);
    $tl = $hooks();
    T::ok($tr['ok'] && count($tl) === $hn + 1 && ($hookBody(end($tl))['event'] ?? '') === 'webhook.test' && ($hookBody(end($tl))['lead']['code'] ?? '') === 'LD-0000', 'el botón «Probar» manda un webhook.test con un lead ficticio');
    // eventos del flujo real
    $LS = Fx::lead(['first_name' => 'Etapa', 'stage' => 'new']);
    LeadActions::setStage($LS, 'contacted', [], $aid);
    Fx::runJobs(10);
    T::ok(count(array_filter($hooks(), static fn($x) => ($hookBody($x)['event'] ?? '') === 'lead.stage_changed' && (int) ($hookBody($x)['lead']['id'] ?? 0) === $LS && ($hookBody($x)['data']['to'] ?? '') === 'contacted')) === 1, 'lead.stage_changed sale al cambiar de etapa (con de/a)');
    $LC = Fx::lead(['first_name' => 'Cons']);
    Fx::consent($LC);
    Consent::revoke($LC, 'sms', 'panel');
    Fx::runJobs(10);
    T::ok(count(array_filter($hooks(), static fn($x) => ($hookBody($x)['event'] ?? '') === 'consent.revoked' && (int) ($hookBody($x)['lead']['id'] ?? 0) === $LC)) === 1, 'consent.revoked sale al revocar');
    $LT = Fx::lead(['first_name' => 'Venc', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    $tkid = Db::insert('tasks', ['title' => 'Vencida webhook', 'lead_id' => $LT, 'due_at' => Clock::at(time() - 600), 'priority' => 2, 'created_at' => Clock::now()]);
    RuleAutomation::tick();
    RuleAutomation::tick();
    Fx::runJobs(10);
    T::ok(count(array_filter($hooks(), static fn($x) => ($hookBody($x)['event'] ?? '') === 'task.overdue' && (int) ($hookBody($x)['data']['task']['id'] ?? 0) === $tkid)) === 1, 'task.overdue sale una sola vez por tarea vencida (el job recurrente es idempotente)');
    // rule.fired desde la acción de las reglas
    $setW = $mkAuto('Webhook por regla', [$R('w1', 'Manda webhook', ['type' => 'stage_change', 'to' => 'qualified'], [['type' => 'outbound_webhook']])]);
    $LW = Fx::lead(['first_name' => 'Regla', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    LeadActions::setStage($LW, 'qualified', [], $aid);
    Fx::runJobs(10);
    $fired = array_values(array_filter($hooks(), static fn($x) => ($hookBody($x)['event'] ?? '') === 'rule.fired' && (int) ($hookBody($x)['lead']['id'] ?? 0) === $LW));
    T::ok(count($fired) === 1 && ($hookBody($fired[0])['data']['rule_name'] ?? '') === 'Manda webhook', 'la acción «enviar webhook saliente» de una regla manda rule.fired con la regla que actuó');
    RuleSets::unpublish($setW, $aid);
    // eventos sin suscripción
    OutboundWebhooks::saveConfig(['enabled' => true, 'url' => $url, 'events' => ['lead.created'], 'contact' => false], null, $aid);
    T::eq(OutboundWebhooks::emit('lead.hot', $newId, [], 'nosub-1'), null, 'un evento al que no se está suscrito no se emite');
    OutboundWebhooks::saveConfig(['enabled' => false, 'url' => $url, 'events' => ['lead.created'], 'contact' => false], null, $aid);
    T::eq(OutboundWebhooks::emit('lead.created', $newId, [], 'off-1'), null, '  ni con el interruptor apagado');
    // pantalla y rutas (HTTP)
    T::ok($panel->unlock($aid, 'integraciones'), 'confirmación 2FA para Integraciones');
    $pg = $panel->get('/config?tab=integraciones');
    T::ok($pg['status'] === 200 && str_contains($pg['body'], 'id="webhooks-out"') && str_contains($pg['body'], 'Webhooks salientes') && str_contains($pg['body'], 'lead.anonymized') && str_contains($pg['body'], 'name="secret_mode"'), 'Configuración > Integraciones tiene la tarjeta «Webhooks salientes»');
    $bad = $panel->post('/config/webhooks/save', ['_csrf' => $panel->csrf, 'enabled' => '1', 'url' => 'ftp://nada', 'events' => ['lead.created'], 'secret_mode' => 'keep']);
    T::ok($bad['status'] === 422 && str_contains($bad['body'], 'whout'), 'guardar con una URL inválida: 422 y no cambia nada');
    $good = $panel->post('/config/webhooks/save', ['_csrf' => $panel->csrf, 'enabled' => '1', 'url' => $url, 'events' => ['lead.created', 'lead.hot'], 'contact' => '', 'secret_mode' => 'generate']);
    T::eq($good['status'], 303, 'guardar con «generar un secreto nuevo»');
    Settings::clear();
    $once = $panel->get('/config?tab=integraciones');
    preg_match('/<code>([0-9a-f]{64})<\/code>/', $once['body'], $sm);
    $sec2 = $sm[1] ?? '';
    T::ok($sec2 !== '' && str_contains($once['body'], 'se muestra una sola vez') && OutboundWebhooks::config()['secret'] === $sec2, 'el secreto nuevo se muestra UNA vez');
    $again = $panel->get('/config?tab=integraciones');
    T::ok(!str_contains($again['body'], $sec2) && str_contains($again['body'], 'configurado'), '  y después solo dice «configurado»');
    $tres = $panel->post('/config/webhooks/test', ['_csrf' => $panel->csrf]);
    T::ok($tres['status'] === 303 && ($hookBody(end($tl))['event'] ?? '') !== '' && count($hooks()) > count($tl), 'el botón «Probar» de la pantalla entrega un evento de prueba');
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$aid]);
    $locked = $panel->another();
    T::ok($locked->login($admin['email']), 'segunda sesión del admin (sin confirmación reciente)');
    $deny = $locked->post('/config/webhooks/save', ['_csrf' => $locked->csrf, 'enabled' => '', 'url' => '', 'secret_mode' => 'clear']);
    T::ok($deny['status'] === 303 && OutboundWebhooks::config()['secret_set'], 'sin confirmación 2FA reciente no se guarda ni se borra el secreto');
    T::eq($viewer->post('/config/webhooks/save', ['_csrf' => $viewer->csrf, 'url' => $url])['status'], 403, 'el lector recibe 403 en las rutas de webhooks');
    Db::pdo()->exec("DELETE FROM settings WHERE `key` IN ('webhooks.out')");
    Settings::clear();
    Infra::stop('panel44');
    Infra::stop('hook44');
    Infra::stop('smtp44');
    Clock::travel(0);
};
