<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;
use Captacion\Services\LeadActions;
use Captacion\Services\Sequences;
use Captacion\Services\Settings;
use Captacion\Services\Templates;
use Captacion\Services\Unsub;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Q4b-C: reactivacion A11 de leads perdidos hace 60 dias o mas, con reloj simulado y SMTP simulado. */
return static function (array $ctx): void {
    T::reset(true);
    Db::pdo()->exec('DELETE FROM email_templates');
    Templates::resetEnsure();
    foreach (['mail', 'reactivation'] as $k) {
        Settings::set($k, []);
    }
    Settings::clear();
    $work = $ctx['work'];
    $smtpLog = "$work/smtp28.log";
    @unlink($smtpLog);
    $port = Infra::smtpMock('smtp28', $smtpLog);
    Fx::smtp($port);
    $mails = static fn(): array => Infra::readLog($smtpLog);
    $raw = static fn(array $m): string => quoted_printable_decode((string) $m['raw']);
    $address = 'NorthStar Freight Solutions LLC, 100 Main St, Lakeland, FL 33801';
    $mail = static function (bool $on = true) use ($address): void {
        Settings::set('mail', ['enabled' => $on, 'address' => $address, 'from_name' => 'NorthStar Freight Solutions', 'cap_hour' => 50, 'cap_lead' => 1, 'rates_source' => '', 'weekly_to' => 'carlos@example.com', 'monthly_to' => []]);
        Settings::clear();
    };
    $react = static function (bool $on, int $days = 60): void {
        Settings::set('reactivation', ['enabled' => $on, 'min_days' => $days]);
        Settings::clear();
    };
    $tick = static function (): void {
        Sequences::enrollReactivation(25);
        Sequences::enqueueDue();
        Fx::runJobs(10);
    };
    $lost = static function (array $o = [], bool $consent = true) {
        $id = Fx::lead($o + ['stage' => 'lost', 'lost_reason' => 'price', 'lost_at' => Clock::shift(-61 * 86400), 'first_contact_at' => Clock::shift(-70 * 86400), 'temperature' => 'warm', 'language' => 'en', 'first_name' => 'Lola']);
        if ($consent) {
            Fx::consent($id);
        }
        return $id;
    };
    $enr = static fn(int $id) => Db::one("SELECT * FROM sequence_enrollments WHERE lead_id = ? AND sequence_key = 'a11_reactivation'", [$id]);

    // ------------------------------------------------------------ quien es elegible
    $a = $lost(['email' => 'a@example.com', 'language' => 'es', 'first_name' => 'Ana']);
    $b = $lost(['email' => 'b@example.com', 'lost_at' => Clock::shift(-30 * 86400)]);
    $c = $lost(['email' => 'c@example.com', 'lost_reason' => 'rejected']);
    $d = $lost(['email' => 'd@example.com', 'phone_e164' => '+13055550401']);
    Db::insert('dnc_numbers', ['phone_e164' => '+13055550401', 'reason' => 'prospect']);
    $e = $lost(['email' => 'e@example.com'], false);
    $f = $lost(['email' => 'f@example.com', 'flags' => ['stop_email']]);
    $g = $lost(['email' => 'g@example.com', 'temperature' => 'disqualified']);
    $h = $lost(['email' => 'h@example.com', 'signed_at' => Clock::shift(-65 * 86400), 'handoff_status' => 'signed']);
    $i = $lost(['email' => 'i@example.com', 'profile' => 'company_driver', 'pipeline' => 'B']);
    $j = $lost(['email' => null, 'phone_e164' => '+13055550402']);
    $k = Fx::lead(['email' => 'k@example.com', 'stage' => 'contacted']);   // no esta perdido
    Fx::consent($k);
    $scan = Sequences::reactivationScan();
    T::eq($scan['eligible'], [$a], 'solo es elegible el perdido hace mas de 60 dias con correo, consentimiento y sin exclusiones');
    T::eq($scan['excluded'], ['enrolled' => 0, 'signed' => 1, 'disqualified' => 1, 'rejected' => 1, 'dnc' => 1, 'stop' => 1, 'no_email' => 1, 'no_consent' => 1], 'exclusiones: firmado, descartado, rechazado por NorthStar, «No llamar más», STOP/baja, sin correo y sin consentimiento');
    T::eq($scan['total'], 8, '  el reciente (30 dias), el company driver y el que no esta perdido ni se cuentan (8 perdidos de pipeline A)');
    $react(false, 30);
    T::ok(count(Sequences::reactivationScan()['eligible']) === 2 && in_array($b, Sequences::reactivationScan()['eligible'], true), 'los dias minimos son configurables (30: entra tambien el perdido hace 30 dias)');
    $react(false, 60);

    // ------------------------------------------------------------ interruptor y requisitos
    $mail();
    T::eq(Sequences::enrollReactivation(), 0, 'interruptor apagado (por defecto): no se inscribe a nadie');
    T::eq((int) Db::value("SELECT COUNT(*) FROM sequence_enrollments WHERE sequence_key = 'a11_reactivation'"), 0, '  ninguna inscripcion');
    $react(true);
    $mail(false);
    T::eq(Sequences::enrollReactivation(), 0, 'interruptor encendido pero las secuencias de correo apagadas: no se inscribe nadie');
    Settings::set('mail', ['enabled' => true, 'address' => '', 'cap_hour' => 50, 'cap_lead' => 1]);
    Settings::clear();
    T::eq(Sequences::enrollReactivation(), 0, '  ni sin direccion fisica (CAN-SPAM)');
    $mail();
    T::eq(Sequences::enrollReactivation(), 1, 'todo encendido: se inscribe al unico elegible');
    T::ok($enr($a) !== null && $enr($a)['status'] === 'active' && $count = (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = $a AND type = 'sequence_enrolled'") === 1, '  con su evento «inscrito» en la linea de tiempo');
    T::eq(Sequences::enrollReactivation(), 0, 'repetir no inscribe de nuevo (una vez por lead, para siempre)');

    // ------------------------------------------------------------ el marcador bloquea el envio
    $n0 = count($mails());
    $tick();
    T::ok(count($mails()) === $n0 && str_contains((string) $enr($a)['blocked_reason'], 'pendiente de confirmar') && (int) $enr($a)['step'] === 0, 'plantilla con [[CONFIRMAR…]]: NO se envia, el paso queda bloqueado y el motivo se ve');
    $tpl = Templates::byKey('a11_react_1', 'es');
    $cleanBody = str_replace("[[CONFIRMAR: qué ha cambiado en NorthStar o qué puede ofrecer hoy, sin cifras, garantías ni promesas]]", 'Si quieres, cuéntanos cómo va tu operación y vemos si tiene sentido hablar.', (string) $tpl['body']);
    Db::run('UPDATE email_templates SET body = ? WHERE id = ?', [$cleanBody, $tpl['id']]);
    Clock::travel(3700);   // el paso bloqueado se reintenta cada hora
    $tick();
    $m = array_slice($mails(), $n0);
    T::ok(count($m) === 1 && $m[0]['to'] === ['a@example.com'] && str_contains($raw($m[0]), 'Hola Ana') && str_contains($raw($m[0]), $address) && preg_match('/List-Unsubscribe: <http[^>]+\/u\/[A-Za-z0-9_-]+>/', $raw($m[0])) === 1 && str_contains($raw($m[0]), 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'), 'editado el texto: sale el correo en espanol con direccion fisica y baja de un clic (cabeceras List-Unsubscribe)');
    preg_match('/\d+\s?%|\$\s?\d|garant|24\/7/i', strip_tags($raw($m[0])), $mm);
    T::ok($mm === [], '  sin cifras, porcentajes ni garantias ' . json_encode($mm));
    T::ok((int) Db::value("SELECT COUNT(*) FROM messages WHERE lead_id = $a AND sequence_key = 'a11_reactivation' AND status IN ('sent','logged')") === 1 && (int) $enr($a)['step'] === 1 && $enr($a)['status'] === 'active', '  se anota en messages y la inscripcion pasa al mensaje 2');
    $due2 = (int) Clock::parse((string) $enr($a)['next_run_at']);
    T::ok(abs($due2 - (strtotime((string) $enr($a)['created_at'] . ' UTC') + 10 * 86400)) < 5, '  el mensaje 2 toca 10 dias despues de la inscripcion');
    $n1 = count($mails());
    Clock::travel(3700 + 10 * 86400 + 60);
    $tick();
    T::eq(count($mails()), $n1, 'el mensaje 2 tambien lleva marcador: no sale hasta que se edite');
    $t2 = Templates::byKey('a11_react_2', 'es');
    Db::run('UPDATE email_templates SET body = ? WHERE id = ?', [str_replace('[[CONFIRMAR: cierre del mensaje, sin cifras ni promesas]]', 'Gracias por tu tiempo.', (string) $t2['body']), $t2['id']]);
    Clock::travel(3700 + 10 * 86400 + 60 + 3700);
    $tick();
    T::ok(count($mails()) === $n1 + 1 && $enr($a)['status'] === 'completed' && $enr($a)['completed_at'] !== null, 'editado el mensaje 2: sale y la secuencia queda completada');
    $tick();
    T::eq(count($mails()), $n1 + 1, '  y no vuelve a enviar nada');

    // ------------------------------------------------------------ pausas y paradas (reloj nuevo)
    Clock::travel(0);
    T::reset();
    Settings::clear();
    $mail();
    $react(true);
    foreach (['a11_react_1', 'a11_react_2'] as $key) {
        foreach (['en', 'es'] as $lang) {
            $t = Templates::byKey($key, $lang);
            Db::run('UPDATE email_templates SET body = ? WHERE id = ?', [preg_replace('/\[\[CONFIRMAR[^\]]*\]\]/', 'Cuéntanos cómo va todo.', (string) $t['body']), $t['id']]);
        }
    }
    $mk = static fn(string $em, array $o = []) => $lost(['email' => $em] + $o);
    $p1 = $mk('p1@example.com');   // se reabre
    $p2 = $mk('p2@example.com');   // responde
    $p3 = $mk('p3@example.com');   // se da de baja
    $p4 = $mk('p4@example.com');   // pierde el consentimiento antes de enviar
    $p5 = $mk('p5@example.com');   // el interruptor se apaga antes de enviar
    $p6 = $mk('p6@example.com');   // pasa a otra etapa por un reingreso
    T::eq(Sequences::enrollReactivation(25), 6, '6 elegibles inscritos');
    $n0 = count($mails());
    LeadActions::reopen($p1, 1);
    T::ok($enr($p1)['status'] === 'paused' && $enr($p1)['paused_reason'] === 'stage', 'A7: un lead reabierto a mano deja la reactivacion en pausa');
    $resp = Sequences::responded($p2, 1);
    T::eq($enr($p2)['status'], 'paused', 'A7: «El lead respondio» la pausa');
    Unsub::apply($p3, 'link');
    T::eq($enr($p3)['status'], 'stopped', 'la baja de un clic la detiene');
    Db::run("UPDATE consents SET revoked_at = ? WHERE lead_id = ? AND channel = 'email'", [Clock::now(), $p4]);
    Db::run("UPDATE leads SET stage = 'contacted', lost_at = NULL, lost_reason = NULL WHERE id = $p6");
    $react(false);
    $tick();
    T::eq(count($mails()), $n0, 'interruptor apagado despues de inscribir: no sale nada (los pasos esperan)');
    T::ok(str_contains((string) $enr($p5)['blocked_reason'], 'apagada'), '  y el motivo dice que la reactivacion esta apagada');
    $react(true);
    Clock::travel(3700);
    $tick();
    $to = array_map(static fn($x) => $x['to'][0] ?? '', array_slice($mails(), $n0));
    T::eq($to, ['p5@example.com'], 'al volver a encender: solo recibe quien sigue perdido, con consentimiento y sin pausa');
    T::eq($enr($p4)['status'], 'stopped', '  sin consentimiento vigente: la inscripcion se detiene (no_consent)');
    T::eq($enr($p6)['status'], 'paused', '  un lead que ya no esta perdido: se pausa (stage)');
    $tl = Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = $p4 AND type = 'sequence_stopped'");
    T::eq((int) $tl, 1, '  y queda anotado en la linea de tiempo');

    // ------------------------------------------------------------ pantalla
    Clock::travel(0);
    Fx::noSmtp();
    $admin = Panel::user('admin');
    $panel = Panel::start($ctx, 'react28');
    T::ok($panel->login($admin['email']), 'login administrador');
    $pg = $panel->get('/comunicaciones/reactivacion');
    T::ok($pg['status'] === 200 && str_contains($pg['body'], 'QUIÉN ENTRA') && str_contains($pg['body'], 'INTERRUPTOR') && str_contains($pg['body'], 'Lista') && str_contains($pg['body'], 'Teléfono en «No llamar más»'), 'pagina /comunicaciones/reactivacion: interruptor, elegibles, exclusiones y plantillas');
    T::ok(str_contains($pg['body'], 'href="/comunicaciones/reactivacion"') || str_contains($pg['body'], '/comunicaciones/reactivacion'), '  y la pestana «Reactivación» esta en Comunicaciones');
    $before = Sequences::reactivationConfig();
    $r = $panel->post('/comunicaciones/reactivacion', ['_csrf' => $panel->csrf, 'enabled' => '1', 'min_days' => '45']);
    Settings::clear();
    T::ok($r['status'] === 303 && Sequences::reactivationConfig() === $before, 'cambiar el interruptor SIN confirmacion reciente (2FA): no se guarda');
    T::ok($panel->unlock((int) $admin['id'], 'correo'), 'confirmacion de identidad');
    $r = $panel->post('/comunicaciones/reactivacion', ['_csrf' => $panel->csrf, 'enabled' => '1', 'min_days' => '45']);
    Settings::clear();
    T::ok(Sequences::reactivationConfig() === ['enabled' => true, 'min_days' => 45] && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'settings_change' OR action LIKE 'config%'") >= 1, 'con confirmacion: se guarda (encendida, 45 dias) y queda en el registro de actividad');
    $r = $panel->post('/comunicaciones/reactivacion', ['_csrf' => $panel->csrf, 'enabled' => '1', 'min_days' => '5']);
    Settings::clear();
    T::eq(Sequences::reactivationConfig()['min_days'], 45, 'dias fuera de rango (5): se rechaza');
    $r = $panel->post('/comunicaciones/reactivacion', ['_csrf' => 'falso', 'enabled' => '0', 'min_days' => '60']);
    Settings::clear();
    T::eq(Sequences::reactivationConfig()['enabled'], true, 'sin token CSRF: no cambia');
    Infra::stop('react28');
    Infra::stop('smtp28');
    $react(false);
    Settings::set('mail', []);
    Settings::clear();
    Clock::travel(0);
    T::reset(true);
};
