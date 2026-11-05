<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\Consent;
use Captacion\Services\Handoff;
use Captacion\Services\LeadActions;
use Captacion\Services\LeadIngest;
use Captacion\Services\Mailer;
use Captacion\Services\Sequences;
use Captacion\Services\Settings;
use Captacion\Services\Storage;
use Captacion\Services\Templates;
use Captacion\Services\Unsub;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Q3-E: plantillas EN/ES, secuencias A6/A12 (consentimiento, direccion fisica, marcadores, topes, pausa A7, baja de un clic) con un SMTP simulado. */
return static function (array $ctx): void {
    T::reset(true);
    Db::pdo()->exec('DELETE FROM email_templates');
    Templates::resetEnsure();
    Settings::set('mail', []);
    Settings::clear();
    $work = $ctx['work'];
    $smtpLog = "$work/smtp21.log";
    $smtpMode = "$work/smtp21.mode";
    @unlink($smtpLog);
    @unlink($smtpMode);
    $port = Infra::smtpMock('smtp21', $smtpLog, $smtpMode);
    Fx::smtp($port);
    $mails = static fn(): array => Infra::readLog($smtpLog);
    $raw = static fn(array $m): string => quoted_printable_decode((string) $m['raw']);
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $address = 'NorthStar Freight Solutions LLC, 100 Main St, Lakeland, FL 33801';
    $cfg = static function (array $over = []) use ($address): void {
        Settings::set('mail', $over + ['enabled' => true, 'address' => $address, 'from_name' => 'NorthStar Freight Solutions', 'cap_hour' => 20, 'cap_lead' => 1, 'rates_source' => '', 'weekly_to' => 'carlos@example.com', 'monthly_to' => []]);
        Settings::clear();
    };
    $warm = static function (array $o = []): int {
        $id = Fx::lead($o + ['temperature' => 'warm', 'stage' => 'new', 'equipment_types' => ['dry-van', 'reefer'], 'state' => 'TX', 'first_name' => 'Mike']);
        Fx::consent($id);
        return $id;
    };
    $enr = static fn(int $lead, string $key = 'a6_warm') => Db::one('SELECT * FROM sequence_enrollments WHERE lead_id = ? AND sequence_key = ?', [$lead, $key]);
    $tick = static function (): int {
        $n = Sequences::enqueueDue();
        Fx::runJobs();
        return $n;
    };

    // ------------------------------------------------------------------ Plantillas de fabrica
    Templates::ensure();
    T::eq((int) Db::value('SELECT COUNT(*) FROM email_templates'), 10, 'se siembran 10 plantillas de fábrica (A6 día 1, 3, 7, 14 y A12, en inglés y español)');
    Db::run("UPDATE email_templates SET subject = 'Editado por Carlos' WHERE tkey = 'a6_d3' AND lang = 'en'");
    Templates::resetEnsure();
    Templates::ensure();
    T::ok((int) Db::value('SELECT COUNT(*) FROM email_templates') === 10 && Db::value("SELECT subject FROM email_templates WHERE tkey = 'a6_d3' AND lang = 'en'") === 'Editado por Carlos', 'sembrar otra vez no duplica ni pisa lo que Carlos editó');
    Templates::restore((int) Db::value("SELECT id FROM email_templates WHERE tkey = 'a6_d3' AND lang = 'en'"), $aid);
    T::ok(Db::value("SELECT subject FROM email_templates WHERE tkey = 'a6_d3' AND lang = 'en'") !== 'Editado por Carlos', 'restaurar devuelve el texto de fábrica');
    $all = implode("\n", array_map(static fn($t) => $t['subject'] . "\n" . $t['body'], \Captacion\Services\TemplateDefaults::all()));
    T::ok(!preg_match('/\d+\s?%|\$\s?\d|guarantee|garant[ií]a|24\/7|within \d+ (hours|minutes)|en menos de/i', $all), 'los textos de fábrica no traen cifras, porcentajes, garantías ni tiempos de respuesta');
    T::ok(substr_count($all, '[[CONFIRMAR') === 4, 'solo dos plantillas (día 1 y día 7, en 2 idiomas) llevan marcadores [[CONFIRMAR…]] pendientes');
    // validacion
    $v = Templates::validate(['name' => 'ab', 'lang' => 'fr', 'subject' => 'x', 'body' => 'corto'], true);
    T::ok(!$v['ok'] && isset($v['errors']['name'], $v['errors']['lang'], $v['errors']['subject'], $v['errors']['body']), 'validar: nombre, idioma, asunto y mensaje inválidos se rechazan');
    $v = Templates::validate(['name' => 'Con HTML', 'lang' => 'en', 'subject' => 'Hola <b>mundo</b>', 'body' => str_repeat('texto ', 10)], true);
    T::ok(!$v['ok'] && isset($v['errors']['body']), 'validar: sin HTML en asunto ni mensaje');
    $v = Templates::validate(['name' => 'Promoción de verano', 'lang' => 'es', 'subject' => 'Una promoción', 'body' => str_repeat('Texto largo de prueba. ', 4)], true);
    T::ok($v['ok'] && $v['values']['tkey'] === 'promocion_de_verano', 'la clave se genera del nombre sin acentos');
    $cid = Templates::create($v['values'], $aid);
    T::ok(!Templates::validate(['name' => 'Promoción de verano', 'lang' => 'es', 'subject' => 'Una promoción', 'body' => str_repeat('Texto largo de prueba. ', 4)], true)['ok'], 'la misma clave en el mismo idioma se rechaza');
    T::ok(Templates::delete($cid, $aid)['ok'] && !Templates::delete((int) Db::value("SELECT id FROM email_templates WHERE tkey = 'a6_d3' AND lang = 'en'"), $aid)['ok'], 'se borran las propias; las de fábrica no');
    // render
    $cfg();
    $lead = Fx::lead(['first_name' => '<script>x</script>', 'state' => 'TX', 'equipment_types' => ['dry-van'], 'language' => 'en']);
    $leadRow = Lead::find($lead);
    $tpl = ['subject' => "Hola {nombre}\r\nBcc: evil@example.com", 'body' => "Hola {nombre}, tu {equipo} en {estado}.\n\nBaja: {link_baja}\n\n{inventada} y [[CONFIRMAR: algo]]"];
    $r = Templates::render($tpl, $leadRow, 'en', 'http://panel.test/u/abc');
    T::ok(!str_contains($r['subject'], "\n") && str_contains($r['text'], 'Baja: http://panel.test/u/abc') && str_contains($r['text'], 'Dry van en TX') && $r['unresolved'] === ['[[CONFIRMAR: algo]]', '{inventada}'] || $r['unresolved'] === ['{inventada}', '[[CONFIRMAR: algo]]'], 'render: variables, asunto en una línea y marcadores/variables sin resolver detectados');
    T::ok(str_contains($r['html'], '&lt;script&gt;x&lt;/script&gt;') && !str_contains($r['html'], '<script>'), 'render: el HTML escapa el nombre del lead (sin inyección)');
    T::ok(str_contains($r['text'], $address) && str_contains($r['text'], 'http://panel.test/u/abc') && str_contains($r['html'], 'Unsubscribe in one click'), 'pie obligatorio: dirección física y baja de un clic');
    $es = Templates::render($tpl, $leadRow, 'es', 'http://panel.test/u/abc');
    T::ok(str_contains($es['text'], 'Darte de baja con un clic') && str_contains($es['text'], 'Recibes este correo porque'), 'pie en español para leads en español');
    Settings::set('mail', []);
    Settings::clear();
    T::ok(str_contains(Templates::render($tpl, $leadRow, 'en', 'http://x/u/a')['text'], '[physical address not configured]'), 'sin dirección configurada el pie lo dice (y las secuencias no salen)');
    $cfg();
    // Mailer: sin inyeccion de cabeceras
    $b = count($mails());
    Mailer::deliver(['to' => 'inj@example.com', 'subject' => "Asunto\r\nBcc: evil@example.com", 'text' => 'cuerpo', 'headers' => ['X-Test' => "a\r\nBcc: evil2@example.com"]]);
    $last = $mails()[$b] ?? ['raw' => '', 'to' => []];
    T::ok(count($mails()) === $b + 1 && $last['to'] === ['inj@example.com'] && !preg_match('/^Bcc:/mi', $last['raw']), 'Mailer: saltos de línea en asunto y cabeceras no inyectan destinatarios');

    // ------------------------------------------------------------------ Interruptores: apagado o sin direccion
    Settings::set('mail', ['enabled' => false, 'address' => $address]);
    Settings::clear();
    $l0 = $warm();
    T::ok(Sequences::enrollWarm($l0) === null && !Sequences::enabled(), 'secuencias apagadas: no se inscribe a nadie');
    Settings::set('mail', ['enabled' => true, 'address' => '']);
    Settings::clear();
    T::ok(Sequences::enrollWarm($l0) === null && !Sequences::enabled() && Sequences::warnings()[0]['level'] === 'bad' && str_contains(Sequences::warnings()[0]['text'], 'dirección física'), 'sin dirección física: deshabilitadas, con aviso visible');
    Db::insert('sequence_enrollments', ['lead_id' => $l0, 'sequence_key' => 'a6_warm', 'step' => 0, 'status' => 'active', 'next_run_at' => Clock::shift(-60)]);
    T::eq(Sequences::enqueueDue(), 0, 'sin dirección física: ni siquiera se encola un paso vencido');
    $jid = \Captacion\Services\Queue::push('sequence_step', ['enrollment_id' => (int) $enr($l0)['id'], 'step' => 0], null, 'forzado-1');
    $b = count($mails());
    Fx::runJobs();
    T::ok(count($mails()) === $b && str_contains((string) $enr($l0)['blocked_reason'], 'dirección') && (int) $enr($l0)['step'] === 0, '  y un job forzado tampoco envía: queda bloqueado con el motivo');
    Db::run('DELETE FROM sequence_enrollments WHERE lead_id = ?', [$l0]);
    $cfg();

    // ------------------------------------------------------------------ A6: inscripcion
    $a = $warm(['first_name' => 'Mike', 'email' => 'mike@example.com']);
    T::ok(Sequences::enrollWarm($a) !== null && Sequences::enrollWarm($a) === null && (int) Db::value('SELECT COUNT(*) FROM sequence_enrollments WHERE lead_id = ?', [$a]) === 1, 'un lead tibio con consentimiento de correo se inscribe a A6 (una sola vez)');
    $e = $enr($a);
    T::ok($e['status'] === 'active' && (int) $e['step'] === 0 && abs((int) Clock::parse((string) $e['next_run_at']) - (Clock::time() + 86400)) <= 2, '  primer paso a las 24 h (día 1)');
    $noCons = Fx::lead(['temperature' => 'warm', 'stage' => 'new']);
    Fx::consent($noCons, ['call', 'sms']);
    $hot = Fx::lead(['temperature' => 'hot', 'stage' => 'new']);
    Fx::consent($hot);
    $contacted = Fx::lead(['temperature' => 'warm', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    Fx::consent($contacted);
    $noMail = Fx::lead(['temperature' => 'warm', 'stage' => 'new', 'email' => null]);
    Fx::consent($noMail);
    $stop = $warm();
    Consent::revoke($stop, 'all', 'stop');
    T::ok(!Sequences::enrollWarm($noCons) && !Sequences::enrollWarm($hot) && !Sequences::enrollWarm($contacted) && !Sequences::enrollWarm($noMail) && !Sequences::enrollWarm($stop), 'no se inscribe: sin consentimiento de correo, caliente, ya contactado, sin correo o con STOP');
    $viaIngest = $warm(['email' => 'ing@example.com']);
    Db::run('DELETE FROM sequence_enrollments WHERE lead_id = ?', [$viaIngest]);
    LeadIngest::enqueueFor($viaIngest, 'warm', 1);
    T::ok($enr($viaIngest) !== null, 'el motor de captación inscribe a los leads tibios (LeadIngest::enqueueFor)');
    LeadIngest::enqueueFor($hot, 'hot', 1);
    T::ok($enr($hot) === null, '  y no a los calientes');
    Db::run('DELETE FROM sequence_enrollments WHERE lead_id = ?', [$viaIngest]);
    Db::pdo()->exec('DELETE FROM jobs');   // los avisos a Carlos que encolo el motor no son parte de esta prueba

    // ------------------------------------------------------------------ A6: los cuatro pasos, con marcador, omision del dia 7 y reintento
    $b = count($mails());
    Clock::travel(86405);
    $tick();
    $e = $enr($a);
    T::ok(count($mails()) === $b && (int) $e['step'] === 0 && (int) $e['blocks'] === 1 && str_contains((string) $e['blocked_reason'], '[[CONFIRMAR'), 'día 1: la plantilla trae un marcador sin resolver, NO se envía y la inscripción avisa por qué');
    T::ok(abs((int) Clock::parse((string) $e['next_run_at']) - (Clock::time() + 3600)) <= 2, '  y reintenta en 1 hora');
    $tick();
    T::eq(count($mails()), $b, '  repetir la pasada no envía nada (sigue bloqueada)');
    $before = Db::value("SELECT body FROM email_templates WHERE tkey = 'a6_d1' AND lang = 'en'");
    Db::run("UPDATE email_templates SET body = REPLACE(body, '[[CONFIRMAR: caso real aprobado por NorthStar, sin cifras ni garantías sin respaldo]]', 'Un owner-operator nos pidió ayuda con una ruta de ida y vuelta y la cuadramos juntos.') WHERE tkey = 'a6_d1' AND lang = 'en'");
    T::ok(!str_contains((string) Db::value("SELECT body FROM email_templates WHERE tkey = 'a6_d1' AND lang = 'en'"), '[[CONFIRMAR'), 'Carlos reemplaza el marcador por texto real');
    Clock::travel(86405 + 3601);
    $tick();
    $m = $mails();
    $d1 = $m[$b] ?? null;
    T::ok(count($m) === $b + 1 && $d1['to'] === ['mike@example.com'], 'día 1: con el marcador resuelto, sale el correo al lead');
    $body = $raw($d1);
    T::ok(str_contains($body, 'Hi Mike') && str_contains($body, 'Dry van, Reefer') && str_contains($body, $address) && preg_match('#List-Unsubscribe: <http://panel\.test/u/[A-Za-z0-9_-]{40,}>#', $body) === 1 && str_contains($body, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), '  en inglés, con {nombre} y {equipo}, dirección física y las cabeceras List-Unsubscribe');
    preg_match('#<(http://panel\.test/u/([A-Za-z0-9_-]{40,}))>#', $body, $um);
    T::ok(Unsub::verify($um[2] ?? '') === $a, '  el enlace de baja es auténtico y apunta a este lead');
    $msg = Db::one("SELECT * FROM messages WHERE lead_id = ? AND channel = 'email'", [$a]);
    T::ok($msg !== null && $msg['direction'] === 'out' && $msg['template_key'] === 'a6_d1' && $msg['sequence_key'] === 'a6_warm' && $msg['status'] === 'sent' && $msg['subject'] !== null, '  y queda en messages (plantilla, secuencia y estado «sent»)');
    $e = $enr($a);
    T::ok((int) $e['step'] === 1 && (int) $e['blocks'] === 0 && $e['blocked_reason'] === null && $e['last_sent_at'] !== null && abs((int) Clock::parse((string) $e['next_run_at']) - ((int) Clock::parse((string) $e['created_at']) + 3 * 86400)) <= 2, '  avanza al día 3 (3 días desde la inscripción)');
    $tick();
    T::eq(count($mails()), $b + 1, 'pasar la cola de nuevo no repite el día 1 (idempotente)');
    \Captacion\Services\Queue::push('sequence_step', ['enrollment_id' => (int) $e['id'], 'step' => 0], null, 'duplicado-manual');
    Fx::runJobs();
    T::eq(count($mails()), $b + 1, '  ni un job duplicado del paso ya enviado');
    Clock::travel(3 * 86400 + 60);
    $tick();
    $m = $mails();
    T::ok(count($m) === $b + 2 && str_contains($raw($m[$b + 1]), 'https://northstarfreightsolutions.us/resources/rate-per-mile-calculator/') && str_contains($raw($m[$b + 1]), 'estimate'), 'día 3: calculadora de RPM del sitio, con aviso de estimación');
    Clock::travel(7 * 86400 + 60);
    $tick();
    T::ok(count($mails()) === $b + 2 && (int) $enr($a)['step'] === 3 && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'sequence_step_skipped'", [$a]) === 1, 'día 7 (tarifas de la semana): se OMITE porque no hay fuente real; queda anotado en la línea de tiempo');
    Clock::travel(14 * 86400 + 60);
    $tick();
    $m = $mails();
    T::ok(count($m) === $b + 3 && str_contains($raw($m[$b + 2]), 'last note') && $enr($a)['status'] === 'completed' && $enr($a)['completed_at'] !== null && $enr($a)['next_run_at'] === null, 'día 14: último intento, y la secuencia queda completada');
    Clock::travel(40 * 86400);
    $tick();
    T::eq(count($mails()), $b + 3, 'una secuencia completada no vuelve a enviar');
    Clock::travel(0);

    // ------------------------------------------------------------------ Idioma
    $b = count($mails());
    $spa = $warm(['first_name' => 'Luis', 'email' => 'luis@example.com', 'language' => 'es']);
    Sequences::enrollWarm($spa);
    Clock::travel(2 * 86400);
    $tick();
    $m = array_slice($mails(), $b);
    T::ok(count($m) === 0 && str_contains((string) $enr($spa)['blocked_reason'], 'CONFIRMAR'), 'la plantilla en español con marcador tampoco se envía');
    Clock::travel(0);
    Db::run("UPDATE email_templates SET body = REPLACE(body, '[[CONFIRMAR: caso real aprobado por NorthStar, sin cifras ni garantías sin respaldo]]', 'Caso real aprobado.') WHERE tkey = 'a6_d1' AND lang = 'es'");
    Clock::travel(2 * 86400 + 4000);
    $tick();
    $m = array_slice($mails(), $b);
    T::ok(count($m) === 1 && $m[0]['to'] === ['luis@example.com'] && str_contains($raw($m[0]), 'Hola Luis') && str_contains($raw($m[0]), 'Darte de baja con un clic'), 'un lead en español recibe la plantilla y el pie en español');
    Clock::travel(0);

    // ------------------------------------------------------------------ Plantilla desactivada y topes
    $dis = $warm(['email' => 'dis@example.com']);
    Sequences::enrollWarm($dis);
    Db::run("UPDATE email_templates SET active = 0 WHERE tkey = 'a6_d1' AND lang = 'en'");
    $b = count($mails());
    Clock::travel(86400 + 100);
    $tick();
    T::ok(count($mails()) === $b && str_contains((string) $enr($dis)['blocked_reason'], 'desactivada'), 'plantilla desactivada: la secuencia espera y lo dice');
    Db::run("UPDATE email_templates SET active = 1 WHERE tkey = 'a6_d1' AND lang = 'en'");
    Clock::travel(0);
    // tope por hora
    Db::pdo()->exec("DELETE FROM sequence_enrollments");
    Db::pdo()->exec("DELETE FROM messages");
    $cfg(['cap_hour' => 2]);
    $leads = [];
    for ($i = 0; $i < 3; $i++) {
        $leads[$i] = $warm(['email' => "cap$i@example.com"]);
        Sequences::enrollWarm($leads[$i]);
    }
    $b = count($mails());
    Clock::travel(2 * 86400);
    Sequences::enqueueDue();
    Fx::runJobs();
    $sentNow = count($mails()) - $b;
    $blocked = array_values(array_filter($leads, static fn($l) => str_contains((string) ($enr($l)['blocked_reason'] ?? ''), 'por hora')));
    T::ok($sentNow === 2 && count($blocked) === 1, 'tope por hora (2): salen 2 correos y el tercero espera con «Tope de envíos por hora»');
    T::ok(abs((int) Clock::parse((string) $enr($blocked[0])['next_run_at']) - (Clock::time() + 900)) <= 2, '  y se reintenta en 15 minutos');
    Clock::travel(2 * 86400 + 3700);
    Sequences::enqueueDue();
    Fx::runJobs();
    T::eq(count($mails()) - $b, 3, '  pasada una hora el tercero sale');
    Clock::travel(0);
    // tope por lead: A6 y A12 del mismo lead vencen a la vez
    Db::pdo()->exec("DELETE FROM sequence_enrollments");
    Db::pdo()->exec("DELETE FROM messages");
    $cfg(['cap_hour' => 50, 'cap_lead' => 1]);
    $both = $warm(['email' => 'both@example.com']);
    Sequences::enrollWarm($both);
    Db::insert('sequence_enrollments', ['lead_id' => $both, 'sequence_key' => 'a12_referral', 'step' => 0, 'status' => 'active', 'next_run_at' => Clock::at(Clock::time() + 86400), 'created_at' => Clock::now()]);
    $b = count($mails());
    Clock::travel(86400 + 10);
    Sequences::enqueueDue();
    Fx::runJobs();
    $sentBoth = count($mails()) - $b;
    $blk = Db::value("SELECT COUNT(*) FROM sequence_enrollments WHERE lead_id = ? AND blocked_reason LIKE '%por lead%'", [$both]);
    T::ok($sentBoth === 1 && (int) $blk === 1, 'tope por lead (1 cada 24 h): de dos pasos vencidos del mismo lead sale uno y el otro espera');
    Clock::travel(0);

    // ------------------------------------------------------------------ Consentimiento
    Db::pdo()->exec("DELETE FROM sequence_enrollments");
    Db::pdo()->exec("DELETE FROM messages");
    $cfg();
    $rev = $warm(['email' => 'rev@example.com']);
    Sequences::enrollWarm($rev);
    Consent::revoke($rev, 'email', 'manual');
    $b = count($mails());
    Clock::travel(86400 + 100);
    $tick();
    T::ok(count($mails()) === $b && $enr($rev)['status'] === 'stopped' && $enr($rev)['paused_reason'] === 'no_consent', 'consentimiento de correo revocado antes del envío: la inscripción se detiene y no sale nada');
    Clock::travel(0);

    // ------------------------------------------------------------------ A7: pausa automatica y manual
    $p1 = $warm(['email' => 'p1@example.com']);
    Sequences::enrollWarm($p1);
    Db::insert('sequence_enrollments', ['lead_id' => $p1, 'sequence_key' => 'a12_referral', 'step' => 0, 'status' => 'active', 'next_run_at' => Clock::at(Clock::time() + 30 * 86400), 'created_at' => Clock::now()]);
    LeadActions::setStage($p1, 'contacted', [], $aid);
    T::ok($enr($p1)['status'] === 'paused' && $enr($p1)['paused_reason'] === 'stage' && $enr($p1, 'a12_referral')['status'] === 'active', 'A7: al pasar a Contactado, A6 se pausa sola (A12 no)');
    $b = count($mails());
    Clock::travel(5 * 86400);
    $tick();
    T::eq(count($mails()), $b, '  y una inscripción en pausa no envía');
    Clock::travel(0);
    foreach (['qualified' => 'new', 'lost' => 'new', 'signed' => 'presented'] as $to => $from) {
        $x = $warm(['email' => "x$to@example.com", 'pipeline' => 'A']);
        Sequences::enrollWarm($x);
        Db::update('leads', $x, ['stage' => $from]);
        $r = LeadActions::setStage($x, $to, ['reason' => 'no_answer', 'override' => true], $aid);
        T::ok($r['ok'] && $enr($x)['status'] === 'paused', "A7: al pasar a «{$to}» también se pausa");
    }
    // boton «Respondió» y reanudar a mano (HTTP)
    $panel = Panel::start($ctx, 'panel21', ['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string) $port, 'SMTP_USER' => 'crm@example.com', 'SMTP_PASS' => 'pw-smtp-prueba', 'SMTP_SECURE' => 'none']);
    T::ok($panel->login($admin['email']), 'login del administrador');
    $r1 = $warm(['email' => 'r1@example.com', 'first_name' => 'Resp']);
    Sequences::enrollWarm($r1);
    $ficha = $panel->get('/leads/' . $r1);
    T::ok(str_contains($ficha['body'], 'SECUENCIAS DE CORREO') && str_contains($ficha['body'], 'El lead respondió') && str_contains($ficha['body'], 'Pausar'), 'la ficha muestra las secuencias del lead con Pausar y «El lead respondió»');
    $csrf = $panel->csrfOf($ficha['body']);
    $x = $panel->post('/leads/' . $r1 . '/responded', ['_csrf' => $csrf]);
    T::ok($x['status'] === 303 && $enr($r1)['status'] === 'paused' && $enr($r1)['paused_reason'] === 'responded', 'botón «El lead respondió»: pausa la secuencia');
    $eid = (int) $enr($r1)['id'];
    $x = $panel->post("/comunicaciones/inscripciones/$eid/resume", ['_csrf' => $csrf]);
    T::ok($enr($r1)['status'] === 'active' && $enr($r1)['paused_reason'] === null, 'reanudar a mano desde Inscripciones');
    $panel->post("/comunicaciones/inscripciones/$eid/pause", ['_csrf' => $csrf]);
    Consent::revoke($r1, 'email', 'manual');
    $panel->post("/comunicaciones/inscripciones/$eid/resume", ['_csrf' => $csrf]);
    T::ok($enr($r1)['status'] === 'paused', '  sin consentimiento de correo vigente no se puede reanudar');
    $panel->post("/leads/$r1/seq-remove", ['_csrf' => $csrf, 'enrollment_id' => (string) $eid]);
    T::ok($enr($r1)['status'] === 'stopped' && $enr($r1)['paused_reason'] === 'removed', '«Sacar» detiene la inscripción');
    $other = $warm();
    Sequences::enrollWarm($other);
    $panel->post("/leads/$r1/seq-pause", ['_csrf' => $csrf, 'enrollment_id' => (string) $enr($other)['id']]);
    T::eq($enr($other)['status'], 'active', 'una inscripción ajena al lead de la URL no se toca');
    T::eq($panel->post("/comunicaciones/inscripciones/$eid/pause", ['_csrf' => 'falso'])['status'], 303, 'sin CSRF válido las acciones no hacen nada');

    // ------------------------------------------------------------------ Baja de un clic
    $u = $warm(['email' => 'unsub@example.com', 'first_name' => 'Baja']);
    Sequences::enrollWarm($u);
    Sequences::enrollReferral($u, Clock::time());
    $url = Unsub::url($u);
    $tok = substr($url, strrpos($url, '/u/') + 3);
    $g = Infra::http('GET', $panel->base . '/u/' . $tok);
    T::ok($g['status'] === 200 && str_contains($g['body'], 'You are unsubscribed') && str_contains(Infra::header($g, 'x-robots-tag'), 'noindex') && str_contains(Infra::header($g, 'content-security-policy'), "default-src 'none'") && Infra::header($g, 'set-cookie') === '' && !str_contains($g['body'], 'Baja'), 'GET /u/{token}: baja inmediata, sin cookies, noindex, CSP y sin datos del lead');
    $emailConsent = Db::one("SELECT * FROM consents WHERE lead_id = ? AND channel = 'email'", [$u]);
    $smsConsent = Db::one("SELECT * FROM consents WHERE lead_id = ? AND channel = 'sms'", [$u]);
    T::ok($emailConsent['revoked_at'] !== null && $emailConsent['revoked_via'] === 'unsubscribe_link' && $smsConsent['revoked_at'] === null && in_array('stop_email', Lead::find($u)['flags'], true), 'revoca el consentimiento de correo (registrado en consents) sin tocar llamada ni SMS');
    T::eq(Db::all("SELECT status, paused_reason FROM sequence_enrollments WHERE lead_id = ? ORDER BY id", [$u]), [['status' => 'stopped', 'paused_reason' => 'unsubscribed'], ['status' => 'stopped', 'paused_reason' => 'unsubscribed']], '  y detiene TODAS las secuencias del lead (A6 y A12)');
    T::ok((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'unsubscribe' AND entity_id = ?", [(string) $u]) === 1 && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'consent_revoked'", [$u]) === 1, '  queda en activity_log y en la línea de tiempo (una vez)');
    $g2 = Infra::http('GET', $panel->base . '/u/' . $tok);
    $p2 = Infra::http('POST', $panel->base . '/u/' . $tok, ['form' => ['List-Unsubscribe' => 'One-Click']]);
    T::ok($g2['status'] === 200 && $p2['status'] === 200 && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'consent_revoked'", [$u]) === 1, 'repetir la baja (GET o POST «One-Click» del cliente de correo) es idempotente');
    T::eq(Infra::http('GET', $panel->base . '/u/' . substr($tok, 0, -1) . 'x')['status'], 404, 'token de baja manipulado: 404');
    T::eq(Infra::http('GET', $panel->base . '/u/' . str_repeat('a', 60))['status'], 404, 'token de baja inventado: 404');
    $b = count($mails());
    Clock::travel(40 * 86400);
    $tick();
    T::ok(count($mails()) === $b || !array_filter(array_slice($mails(), $b), static fn($m) => in_array('unsub@example.com', $m['to'], true)), 'tras la baja no sale ningún correo más a ese lead');
    Clock::travel(0);

    // ------------------------------------------------------------------ A12: pedido de referidos a los 30 dias de firmar
    $sg = $warm(['email' => 'firma@example.com', 'first_name' => 'Firma', 'stage' => 'presented', 'language' => 'en']);
    Sequences::pauseForLead($sg, 'stage', null);
    LeadActions::setStage($sg, 'signed', [], $aid);
    $a12 = $enr($sg, 'a12_referral');
    $signedAt = (int) Clock::parse((string) Db::value('SELECT signed_at FROM leads WHERE id = ?', [$sg]));
    T::ok($a12 !== null && abs((int) Clock::parse((string) $a12['next_run_at']) - ($signedAt + 30 * 86400)) <= 2, 'al firmar se programa A12 a los 30 días');
    $b = count($mails());
    Clock::travel(29 * 86400);
    $tick();
    T::eq(count(array_filter(array_slice($mails(), $b), static fn($m) => in_array('firma@example.com', $m['to'], true))), 0, 'a los 29 días no sale');
    Clock::travel(30 * 86400 + 120);
    $tick();
    $fm = array_values(array_filter(array_slice($mails(), $b), static fn($m) => in_array('firma@example.com', $m['to'], true)));
    $fb = isset($fm[0]) ? $raw($fm[0]) : '';
    T::ok(count($fm) === 1 && str_contains($fb, 'Hi Firma') && str_contains($fb, 'another owner-operator') && !preg_match('/bonus|\$|reward|discount|código|code/i', $fb), 'a los 30 días sale el pedido de referidos, sin promesas de bono, dinero ni códigos');
    T::eq($enr($sg, 'a12_referral')['status'], 'completed', '  y la inscripción queda completada');
    Clock::travel(0);

    // ------------------------------------------------------------------ Sin SMTP: todo queda «registrado»
    Fx::noSmtp();
    Db::pdo()->exec("DELETE FROM sequence_enrollments");
    Db::pdo()->exec("DELETE FROM messages");
    @unlink(Storage::dir('mail.log'));
    $ns = $warm(['email' => 'nosmtp@example.com']);
    Sequences::enrollWarm($ns);
    Clock::travel(2 * 86400);
    $b = count($mails());
    $tick();
    Clock::travel(0);
    $ml = (string) @file_get_contents(Storage::dir('mail.log'));
    T::ok(count($mails()) === $b && str_contains($ml, '[logged: SMTP sin configurar]') && str_contains($ml, 'nosmtp@example.com') && str_contains($ml, 'List-Unsubscribe'), 'sin SMTP el correo queda en storage/mail.log (con destinatario y cabeceras) y no sale a nadie');
    T::eq(Db::value("SELECT status FROM messages WHERE lead_id = ?", [$ns]), 'logged', '  y en messages queda «logged», no «sent»');
    $page = $panel->get('/comunicaciones/envios');
    $panelNoSmtp = Panel::start($ctx, 'panel21b');
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$aid]);
    T::ok($panelNoSmtp->login($admin['email']), 'login (servidor sin SMTP)');
    $pageNs = $panelNoSmtp->get('/comunicaciones/envios');
    T::ok(str_contains($pageNs['body'], 'Solo registrado') && str_contains($pageNs['body'], 'Sin SMTP'), 'Correos enviados: lo marca como «Solo registrado» y avisa «Sin SMTP»');
    Infra::stop('panel21b');
    Fx::smtp($port);

    // ------------------------------------------------------------------ Pantallas de /comunicaciones
    $cfg();
    Db::pdo()->exec("DELETE FROM sequence_enrollments");
    $pa = $warm(['first_name' => 'Pantalla', 'last_name' => 'Uno', 'email' => 'pa@example.com']);
    Sequences::enrollWarm($pa);
    $ls = $panel->get('/comunicaciones');
    T::ok($ls['status'] === 200 && str_contains($ls['body'], 'A6 · Día 1 · Caso real') && str_contains($ls['body'], 'Texto pendiente de confirmar') && str_contains($ls['body'], 'Lista para enviar') && !preg_match('/\sstyle="/i', $ls['body']) && str_contains($ls['body'], '/assets/comms.css'), 'Comunicaciones: lista de plantillas con su estado (pendiente / lista) y sin estilos en línea');
    $tid = (int) Db::value("SELECT id FROM email_templates WHERE tkey = 'a6_d1' AND lang = 'en'");
    Db::run("UPDATE email_templates SET body = REPLACE(body, 'Un owner-operator nos pidió ayuda con una ruta de ida y vuelta y la cuadramos juntos.', '[[CONFIRMAR: caso real]]') WHERE id = ?", [$tid]);
    $ed = $panel->get("/comunicaciones/plantillas/$tid");
    T::ok($ed['status'] === 200 && str_contains($ed['body'], 'No se enviará') && str_contains($ed['body'], '<mark class="mk">[[CONFIRMAR: caso real]]</mark>') && str_contains($ed['body'], 'VISTA PREVIA') && str_contains($ed['body'], 'ENVIAR PRUEBA'), 'editor: vista previa con el marcador resaltado y aviso «No se enviará»');
    $x = $panel->post("/comunicaciones/plantillas/$tid/save", ['_csrf' => $csrf, 'name' => 'A6 · Día 1 · Caso real', 'subject' => 'Nuevo asunto', 'body' => "Hola {nombre},\n\nTexto real de la plantilla, sin marcadores.\n\nGracias.", 'active' => '1']);
    T::ok($x['status'] === 303 && Db::value('SELECT subject FROM email_templates WHERE id = ?', [$tid]) === 'Nuevo asunto' && !str_contains((string) Db::value('SELECT body FROM email_templates WHERE id = ?', [$tid]), '[['), 'guardar la plantilla desde el editor');
    $x = $panel->post("/comunicaciones/plantillas/$tid/save", ['_csrf' => $csrf, 'name' => 'x', 'subject' => '', 'body' => 'corto']);
    T::ok($x['status'] === 422 && Db::value('SELECT subject FROM email_templates WHERE id = ?', [$tid]) === 'Nuevo asunto', 'guardar con datos inválidos: 422 y no cambia');
    $b = count($mails());
    $x = $panel->post("/comunicaciones/plantillas/$tid/test", ['_csrf' => $csrf, 'to' => 'prueba@example.com']);
    $tm = array_slice($mails(), $b);
    T::ok(count($tm) === 1 && $tm[0]['to'] === ['prueba@example.com'] && str_contains($raw($tm[0]), 'PRUEBA') && str_contains($raw($tm[0]), 'Hola Mike'), 'enviar prueba: llega marcada PRUEBA con un lead de ejemplo');
    $x = $panel->post("/comunicaciones/plantillas/$tid/test", ['_csrf' => $csrf, 'to' => 'no-es-correo']);
    T::eq(count($mails()), $b + 1, 'la prueba exige un correo válido');
    for ($i = 0; $i < 7; $i++) {
        $x = $panel->post("/comunicaciones/plantillas/$tid/test", ['_csrf' => $csrf, 'to' => 'prueba@example.com']);
    }
    T::ok(count($mails()) <= $b + 6, 'las pruebas tienen límite por minuto');
    $x = $panel->post('/comunicaciones/plantillas/new', ['_csrf' => $csrf, 'name' => 'Aviso de prueba', 'lang' => 'en', 'subject' => 'Un aviso', 'body' => "Hola {nombre},\n\nUn aviso de prueba largo.\n\nGracias."]);
    $newId = (int) Db::value("SELECT id FROM email_templates WHERE tkey = 'aviso_de_prueba'");
    T::ok($x['status'] === 303 && $newId > 0 && Db::value('SELECT kind FROM email_templates WHERE id = ?', [$newId]) === 'custom', 'crear una plantilla propia desde el panel');
    $x = $panel->post('/comunicaciones/plantillas/new', ['_csrf' => $csrf, 'name' => 'x', 'lang' => 'en', 'subject' => '', 'body' => 'corto']);
    T::eq($x['status'], 422, '  con datos inválidos: 422');
    $x = $panel->post("/comunicaciones/plantillas/$newId/delete", ['_csrf' => $csrf]);
    T::ok($x['status'] === 303 && Db::value('SELECT COUNT(*) FROM email_templates WHERE id = ?', [$newId]) == 0, 'eliminar una plantilla propia');
    $x = $panel->post("/comunicaciones/plantillas/$tid/delete", ['_csrf' => $csrf]);
    T::ok(Db::value('SELECT COUNT(*) FROM email_templates WHERE id = ?', [$tid]) == 1, 'las de fábrica no se eliminan');
    $x = $panel->post("/comunicaciones/plantillas/$tid/restore", ['_csrf' => $csrf]);
    T::ok(str_contains((string) Db::value('SELECT body FROM email_templates WHERE id = ?', [$tid]), '[[CONFIRMAR'), 'restaurar el texto de fábrica (vuelve el marcador)');
    $en = $panel->get('/comunicaciones/inscripciones');
    T::ok($en['status'] === 200 && str_contains($en['body'], 'Pantalla Uno') && str_contains($en['body'], 'Día 1 · Caso real') && str_contains($en['body'], 'Pausar') && str_contains($en['body'], 'TOPES DE ENVÍO') && str_contains($en['body'], 'Se omite: no hay fuente real de tarifas'), 'Inscripciones: quién está en qué paso, acciones, topes y la nota del día 7 omitido');
    T::ok(str_contains($panel->get('/comunicaciones/inscripciones?st=paused')['body'], 'Nadie está inscrito con ese filtro'), '  el filtro por estado funciona (vacío con mensaje)');
    T::eq($panel->get('/comunicaciones/inventada')['status'], 404, 'pestaña inexistente: 404');
    T::eq($panel->get('/comunicaciones/plantillas/99999')['status'], 404, 'plantilla inexistente: 404');
    Infra::stop('panel21');
    Infra::stop('smtp21');
    Fx::noSmtp();
    Settings::set('mail', []);
    Clock::travel(0);
    T::reset(true);
};
