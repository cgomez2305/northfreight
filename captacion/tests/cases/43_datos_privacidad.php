<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;
use Captacion\Services\ActivityLog;
use Captacion\Services\Consent;
use Captacion\Services\Csv;
use Captacion\Services\Dedupe;
use Captacion\Services\Inbound;
use Captacion\Services\LeadActions;
use Captacion\Services\LeadErasure;
use Captacion\Services\LeadImport;
use Captacion\Services\LeadIngest;
use Captacion\Services\LeadMerge;
use Captacion\Services\LeadSend;
use Captacion\Services\Queue;
use Captacion\Services\Settings;
use Captacion\Services\SmsInbound;
use Captacion\Services\Suppression;
use Captacion\Services\Templates;
use Captacion\Services\Totp;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase AM · datos: lista de supresion y STOP de desconocidos, anonimizacion a peticion, fusion de duplicados, envio manual de plantillas e importacion CSV.
 */
return static function (array $ctx): void {
    T::reset(true);
    Db::pdo()->exec('DELETE FROM email_templates');
    Templates::resetEnsure();
    $work = $ctx['work'];
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $viewerU = Panel::user('viewer');
    $count = static fn(string $t, string $where = '1=1', array $p = []): int => (int) Db::value("SELECT COUNT(*) FROM `$t` WHERE $where", $p);
    $flagsOf = static fn(int $id): array => Db::decode(Db::value('SELECT flags FROM leads WHERE id = ?', [$id]));
    $ingest = static fn(array $in, array $ctxx = []): array => LeadIngest::handle($in, 'web_careers', $ctxx + ['skip_antispam' => true, 'quiet' => true, 'fmcsa_sync' => false]);
    $consent = ['given' => true, 'text' => 'Texto exacto del consentimiento', 'version' => 'v1', 'ip' => '203.0.113.9', 'user_agent' => 'UA-secreto'];
    $sms = static fn(string $from, string $body): array => SmsInbound::handle(['MessageSid' => 'SM' . bin2hex(random_bytes(12)), 'From' => $from, 'To' => '+18635550000', 'Body' => $body]);
    $now = Clock::now();

    // ================================================================== 1. Lista de supresion y STOP de desconocidos
    $ph = '+13055550310';
    T::eq($count('leads'), 0, 'base vacia (ningun lead con ese telefono)');
    $r = $sms($ph, 'STOP');
    T::ok($r['status'] === 'processed' && $count('suppression', "kind = 'phone' AND reason = 'sms_stop'") === 1, 'STOP de un numero que NO es lead: se anota en la lista de supresion (antes solo se ignoraba)');
    $row = Db::one('SELECT * FROM suppression');
    T::ok(strlen((string) $row['value_hash']) === 64 && !str_contains(json_encode($row), '3055550310'), '  solo se guarda un hash de 64 caracteres: el telefono no esta en claro');
    T::eq($sms($ph, 'stop')['status'], 'processed', '  otro STOP del mismo numero: no falla');
    T::eq($count('suppression'), 1, '  y no duplica la fila');
    T::eq(Suppression::reason('phone', '(305) 555-0310'), 'sms_stop', '  se reconoce el telefono en cualquier formato');
    $res = $ingest(['name' => 'Tomas Stop', 'phone' => '305-555-0310', 'profile' => 'owner_operator', 'consent' => $consent]);
    $L1 = (int) $res['lead_id'];
    T::ok($res['status'] === 'created' && in_array('stop_sms', $flagsOf($L1), true), 'un lead nuevo con ese telefono nace con la bandera stop_sms');
    T::ok(!Consent::isGranted($L1, 'sms') && !Consent::isGranted($L1, 'whatsapp') && Consent::isGranted($L1, 'email') && Consent::isGranted($L1, 'call'), '  sin consentimiento de SMS aunque venia marcado; llamada y correo se conservan');
    T::eq($count('consents', "lead_id = $L1 AND channel = 'sms'"), 0, '  ni siquiera se guarda la fila del consentimiento de SMS');
    T::eq($count('lead_events', "lead_id = $L1 AND type = 'suppressed'"), 1, '  y queda anotado el motivo en la linea de tiempo');
    $re = $ingest(['name' => 'Tomas Stop', 'phone' => '+13055550310', 'profile' => 'owner_operator', 'email' => 'tomas@example.com', 'consent' => $consent]);
    T::ok($re['status'] === 'reentry' && !Consent::isGranted($L1, 'sms') && $count('lead_events', "lead_id = $L1 AND type = 'suppressed'") === 2, 'el reingreso del mismo lead tampoco concede SMS (y anota el motivo otra vez)');
    // START por SMS de un numero que no es lead lo quita de la lista
    $ph2 = '+13055550311';
    $sms($ph2, 'STOP');
    T::eq(Suppression::reason('phone', $ph2), 'sms_stop', 'STOP de otro desconocido: en la lista');
    $sms($ph2, 'START');
    T::eq(Suppression::reason('phone', $ph2), null, 'START del desconocido: sale de la lista (la razon era sms_stop)');
    $L2 = (int) $ingest(['name' => 'Rosa Start', 'phone' => $ph2, 'profile' => 'owner_operator', 'consent' => $consent])['lead_id'];
    T::ok(Consent::isGranted($L2, 'sms') && !in_array('stop_sms', $flagsOf($L2), true), '  y el lead nuevo con ese telefono recibe su consentimiento normal');
    // STOP de un telefono que SI es lead tambien va a la lista (por si se crea otro lead con ese numero)
    $ph3 = '+13055550312';
    $L3 = (int) $ingest(['name' => 'Pablo Lead', 'phone' => $ph3, 'profile' => 'owner_operator', 'consent' => $consent])['lead_id'];
    $sms($ph3, 'STOP');
    T::ok(Suppression::reason('phone', $ph3) === 'sms_stop' && !Consent::isGranted($L3, 'sms'), 'STOP de un lead existente: revoca (como antes) y ademas queda en la lista');
    // erasure manda sobre sms_stop
    $ph4 = '+13055550313';
    Suppression::add('phone', $ph4, Suppression::ERASURE);
    Suppression::add('phone', $ph4, Suppression::SMS_STOP);
    $sms($ph4, 'START');
    T::eq(Suppression::reason('phone', $ph4), 'erasure', 'una supresion por borrado no se rebaja a sms_stop ni la quita un START');
    $L4 = (int) $ingest(['name' => 'Elena Borrada', 'phone' => $ph4, 'profile' => 'owner_operator', 'consent' => $consent])['lead_id'];
    T::ok(in_array('stop', $flagsOf($L4), true) && $count('consents', "lead_id = $L4") === 0 && !Consent::isGranted($L4, 'email'), 'un telefono borrado a peticion: el lead nace con STOP total y sin ningun consentimiento');
    // correo suprimido (solo hash)
    Suppression::add('email', 'Borrado@Example.com', Suppression::ERASURE);
    T::ok(!str_contains(json_encode(Db::all('SELECT * FROM suppression')), 'orrado@'), 'el correo suprimido tampoco esta en claro');
    $L5 = (int) $ingest(['name' => 'Luis Correo', 'email' => 'borrado@example.com', 'profile' => 'owner_operator', 'consent' => $consent])['lead_id'];
    T::ok(in_array('stop', $flagsOf($L5), true) && $count('consents', "lead_id = $L5") === 0, 'un correo borrado a peticion (sin importar mayusculas): lead con STOP total y sin consentimientos');
    T::ok(Suppression::erasedPhones([$ph4, $ph, '+13055550999']) === [$ph4 => true], 'erasedPhones: solo devuelve los telefonos borrados a peticion');

    // ================================================================== 2. Anonimizacion a peticion
    T::reset(true);
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $viewerU = Panel::user('viewer');
    $smtpLog = "$work/smtp43.log";
    $smtpMode = "$work/smtp43.mode";
    @unlink($smtpLog);
    @unlink($smtpMode);
    $port = Infra::smtpMock('smtp43', $smtpLog, $smtpMode);
    $mails = static fn(): array => Infra::readLog($smtpLog);
    $smtpEnv = ['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string) $port, 'SMTP_USER' => 'crm@example.com', 'SMTP_PASS' => 'pw-smtp-prueba', 'SMTP_SECURE' => 'none'];
    $panel = Panel::start($ctx, 'panel43', $smtpEnv);
    T::ok($panel->login($admin['email']), 'login del administrador');
    $anon = $panel->another();
    $viewer = $panel->another();
    T::ok($viewer->login($viewerU['email']), 'login del lector');

    $needles = ['zoraida', '3055550411', 'priv@example.com'];
    $fm = ['found' => true, 'legal_name' => 'ZORAIDA TRUCKING LLC', 'authority_status' => 'active', 'insured' => true, 'authority_age_months' => 14, 'red_flags' => [], 'phone' => '3055550411'];
    $res = $ingest([
        'name' => 'Zoraida Perez', 'phone' => '305-555-0411', 'email' => 'zoraida.priv@example.com', 'state' => 'TX', 'city' => 'Houston', 'profile' => 'owner_operator', 'equipment' => ['dry-van'], 'trucks' => 3, 'usdot' => '1234567',
        'attribution' => ['utm_source' => 'meta', 'utm_medium' => 'cpc', 'utm_campaign' => 'nov', 'utm_term' => 'zoraida-term', 'utm_content' => 'zoraida-ad'],
        'landing_page' => 'https://northstar.test/careers/owner-operators/?ref=ABC&email=zoraida.priv@example.com', 'referrer' => 'https://google.com/search?q=zoraida', 'consent' => $consent,
    ], ['fmcsa_data' => $fm]);
    $Z = (int) $res['lead_id'];
    LeadActions::addNote($Z, 'Hablo con Zoraida sobre su camion', $aid);
    LeadActions::addTask($Z, 'Llamar a Zoraida manana', '', 2, $aid);
    LeadActions::addTask($Z, 'Pedir seguro de Zoraida', '', 2, $aid);
    $doneTask = (int) Db::value("SELECT id FROM tasks WHERE lead_id = $Z AND title LIKE 'Pedir%'");
    LeadActions::completeTask($Z, $doneTask, $aid);
    LeadActions::logCall($Z, 'answered', 3, 'Zoraida dijo que llame el lunes', $aid);
    Db::insert('appointments', ['lead_id' => $Z, 'starts_at' => Clock::shift(172800), 'status' => 'booked', 'title' => 'Llamada con Zoraida', 'meeting_url' => 'https://meet.example/zoraida', 'calcom_id' => 'uid-z1']);
    $apptZ = (int) Db::pdo()->lastInsertId();
    Inbound::record($Z, 'whatsapp', 'Hola soy Zoraida, quiero informacion', null, 'wamid.ZOR');
    Db::insert('messages', ['lead_id' => $Z, 'channel' => 'email', 'direction' => 'out', 'body' => 'Hola Zoraida, aqui el caso real', 'subject' => 'Para Zoraida', 'template_key' => 'a6_d1', 'status' => 'sent', 'created_at' => $now]);
    Db::insert('sequence_enrollments', ['lead_id' => $Z, 'sequence_key' => 'a6_warm', 'step' => 0, 'status' => 'active', 'next_run_at' => Clock::shift(3600)]);
    Db::insert('handoffs', ['lead_id' => $Z, 'attempt' => 1, 'version' => 1, 'status' => 'pending', 'nonce' => bin2hex(random_bytes(16)), 'expires_at' => Clock::shift(86400), 'reject_note' => 'Zoraida no contesto']);
    $hoZ = (int) Db::pdo()->lastInsertId();
    Db::insert('referral_codes', ['owner_name' => 'Zoraida Perez', 'owner_lead_id' => $Z, 'code' => 'ZOR-1', 'active' => 1, 'note' => 'de Zoraida']);
    Db::insert('prospects_fmcsa', ['usdot' => '7777777', 'legal_name' => 'Zoraida Trucking', 'phone' => '+13055550411', 'email' => 'zoraida.priv@example.com', 'dba' => 'Zoraida DBA', 'state' => 'TX', 'status' => 'interested', 'attempts' => 1, 'converted_lead_id' => $Z]);
    $prospZ = (int) Db::pdo()->lastInsertId();
    Db::insert('prospect_calls', ['prospect_id' => $prospZ, 'result' => 'talked', 'note' => 'Zoraida pidio una llamada']);
    ActivityLog::record('lead_note_add', $aid, 'lead', $Z, ['body' => 'Zoraida: nota larga']);
    Queue::push('telegram_alert', ['lead_id' => $Z, 'kind' => 'hot'], Clock::shift(900), 'k-zor-1');
    Queue::push('appointment_reminder', ['appointment_id' => $apptZ, 'which' => 'rem24', 'starts_at' => '2030-01-01 10:00:00'], Clock::shift(900), 'k-zor-2');
    Queue::push('handoff_deliver', ['handoff_id' => $hoZ, 'target' => 'email'], Clock::shift(900), 'k-zor-3');
    Db::insert('jobs', ['type' => 'email_notify', 'payload' => json_encode(['lead_id' => $Z]), 'status' => 'failed', 'run_at' => $now, 'dedupe_key' => 'k-zor-4', 'last_error' => 'SMTP rechazo zoraida.priv@example.com']);
    T::ok($count('lead_events', "lead_id = $Z") >= 5 && $count('consents', "lead_id = $Z") >= 4, 'preparado: el lead tiene eventos, consentimientos, notas, mensajes y demas');
    $consentsBefore = $count('consents', "lead_id = $Z");
    $before = Db::one('SELECT score, temperature, stage, source, created_at, profile, truck_count, state, site_lead_id FROM leads WHERE id = ?', [$Z]);

    // --- seguridad de la accion
    $fichaA = $panel->get('/leads/' . $Z);
    T::ok($fichaA['status'] === 200 && str_contains($fichaA['body'], 'Anonimizar a petición del titular') && str_contains($fichaA['body'], 'id="enviar"'), 'ficha del administrador: trae «Privacidad y datos» y «Enviar plantilla»');
    T::ok(!preg_match('/\sstyle="/i', $fichaA['body']) && !preg_match('/\son[a-z]+="/i', $fichaA['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $fichaA['body']) === 0, '  sin estilos ni handlers en linea, scripts con nonce');
    $post = static fn($s, string $p, array $f) => $s->post($p, ['_csrf' => $s->csrf] + $f);
    T::eq($anon->post('/leads/' . $Z . '/anonimizar', ['_csrf' => 'x', 'confirm' => 'ANONIMIZAR'])['status'], 303, 'anonimo: POST anonimizar redirige a /login');
    T::eq(Infra::header($anon->post('/leads/' . $Z . '/anonimizar', ['_csrf' => 'x']), 'location'), '/login', '  exactamente a /login');
    T::eq($viewer->post('/leads/' . $Z . '/anonimizar', ['_csrf' => $viewer->csrf, 'confirm' => 'ANONIMIZAR', 'password' => Panel::PASSWORD])['status'], 403, 'lector: POST anonimizar responde 403');
    T::eq($count('leads', "id = $Z AND anonymized_at IS NOT NULL"), 0, '  y no se toco nada');
    $r = $panel->post('/leads/' . $Z . '/anonimizar', ['_csrf' => 'falso', 'confirm' => 'ANONIMIZAR', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    T::ok($r['status'] === 303 && $count('leads', "id = $Z AND anonymized_at IS NOT NULL") === 0, 'CSRF falso: no anonimiza');
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$aid]);
    $r = $post($panel, '/leads/' . $Z . '/anonimizar', ['confirm' => 'si', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    T::ok($r['status'] === 303 && $count('leads', "id = $Z AND anonymized_at IS NOT NULL") === 0 && str_contains($panel->get('/leads/' . $Z)['body'], 'ANONIMIZAR'), 'sin escribir ANONIMIZAR: no anonimiza y lo explica');
    $r = $post($panel, '/leads/' . $Z . '/anonimizar', ['confirm' => 'ANONIMIZAR', 'password' => 'mala-contrasena-1', 'code' => Totp::code(Panel::SECRET)]);
    T::ok($count('leads', "id = $Z AND anonymized_at IS NOT NULL") === 0 && str_contains($panel->get('/leads/' . $Z)['body'], 'no son correctos'), 'contraseña incorrecta (sin reautenticacion reciente): no anonimiza');
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$aid]);
    $r = $post($panel, '/leads/' . $Z . '/anonimizar', ['confirm' => 'ANONIMIZAR', 'password' => Panel::PASSWORD, 'code' => '000000']);
    T::eq($count('leads', "id = $Z AND anonymized_at IS NOT NULL"), 0, 'codigo 2FA incorrecto: no anonimiza');

    // --- la accion buena
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$aid]);
    $r = $post($panel, '/leads/' . $Z . '/anonimizar', ['confirm' => ' anonimizar ', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    T::eq([$r['status'], Infra::header($r, 'location')], [303, '/leads/' . $Z], 'confirmacion correcta (sin importar mayusculas ni espacios) + contraseña + 2FA: anonimiza y vuelve a la ficha');
    $L = Db::one('SELECT * FROM leads WHERE id = ?', [$Z]);
    T::ok($L['anonymized_at'] !== null && $L['first_name'] === 'Anonimizado' && $L['last_name'] === '' && $L['phone_e164'] === null && $L['phone_raw'] === null && $L['email'] === null && $L['city'] === null && $L['usdot'] === null
        && $L['fmcsa_data'] === null && $L['referrer'] === null && $L['external_crm_id'] === null, 'el lead pierde nombre, telefono, correo, ciudad, USDOT, datos de FMCSA y referrer');
    T::eq($L['landing_page'], '/careers/owner-operators/', '  la pagina de llegada queda solo con la ruta (sin parametros)');
    T::eq(Db::decode($L['utm']), ['utm_source' => 'meta', 'utm_medium' => 'cpc', 'utm_campaign' => 'nov'], '  del UTM solo quedan fuente, medio y campaña');
    $after = Db::one('SELECT score, temperature, stage, source, created_at, profile, truck_count, state, site_lead_id FROM leads WHERE id = ?', [$Z]);
    T::eq($after, $before, '  se CONSERVAN puntuacion, temperatura, etapa, fuente, fecha de alta, perfil, camiones, estado y site_lead_id');
    T::ok(in_array('stop', $flagsOf($Z), true) && in_array('anonymized', $flagsOf($Z), true), '  banderas stop y anonymized');
    T::ok($count('lead_notes', "lead_id = $Z AND body <> ''") === 0 && $count('lead_notes', "lead_id = $Z AND deleted_at IS NULL") === 0, 'notas: vaciadas y fuera de la ficha');
    T::ok($count('messages', "lead_id = $Z AND (body IS NOT NULL OR subject IS NOT NULL)") === 0 && $count('messages', "lead_id = $Z") >= 3, 'mensajes: se conservan las filas (fecha, canal, direccion) sin cuerpo ni asunto');
    T::ok($count('consents', "lead_id = $Z") === $consentsBefore && $count('consents', "lead_id = $Z AND (ip IS NOT NULL OR user_agent IS NOT NULL OR revoked_at IS NULL)") === 0, 'consentimientos: las MISMAS filas (prueba de que consintio y cuando), revocadas y sin IP ni user agent');
    T::eq((string) Db::value("SELECT consent_text FROM consents WHERE lead_id = $Z AND channel = 'whatsapp_inbound'"), 'El lead escribió primero por WhatsApp (contenido eliminado por anonimización).', '  y sin el texto del primer WhatsApp');
    T::eq((string) Db::value("SELECT consent_text FROM consents WHERE lead_id = $Z AND channel = 'sms'"), 'Texto exacto del consentimiento', '  el texto legal aceptado SI se conserva');
    T::ok($count('tasks', "lead_id = $Z AND deleted_at IS NULL") === 1 && $count('tasks', "lead_id = $Z AND title LIKE '%Zoraida%'") === 0, 'tareas: las abiertas se cancelan, la completada se conserva y ningun titulo conserva el nombre');
    T::ok($count('appointments', "lead_id = $Z AND (title IS NOT NULL OR meeting_url IS NOT NULL)") === 0 && Db::value('SELECT status FROM appointments WHERE id = ?', [$apptZ]) === 'cancelled', 'citas: sin titulo ni enlace y la futura queda cancelada');
    T::ok(Db::value("SELECT status FROM sequence_enrollments WHERE lead_id = $Z") === 'stopped' && Db::value("SELECT paused_reason FROM sequence_enrollments WHERE lead_id = $Z") === 'erasure', 'secuencias: detenidas con el motivo erasure');
    T::eq([$count('jobs', "status = 'pending' AND dedupe_key IN ('k-zor-1','k-zor-2','k-zor-3')"), $count('jobs', "status = 'cancelled' AND dedupe_key IN ('k-zor-1','k-zor-2','k-zor-3')")], [0, 3], 'trabajos pendientes del lead, de su cita y de su entrega: cancelados');
    T::eq(Db::value("SELECT last_error FROM jobs WHERE dedupe_key = 'k-zor-4'"), null, '  y el error de un trabajo fallido (traia su correo) se limpia');
    T::ok(Db::value('SELECT reject_note FROM handoffs WHERE id = ?', [$hoZ]) === null && Db::value('SELECT confirm_status FROM handoffs WHERE id = ?', [$hoZ]) === 'superseded', 'entregas: sin nota y el enlace de confirmacion pendiente deja de valer');
    T::ok(Db::value("SELECT owner_name FROM referral_codes WHERE code = 'ZOR-1'") === 'Anónimo' && (int) Db::value("SELECT active FROM referral_codes WHERE code = 'ZOR-1'") === 0, 'su codigo de referido: nombre anonimo y desactivado');
    $pr = Db::one('SELECT phone, email, dba, status, do_not_call FROM prospects_fmcsa WHERE id = ?', [$prospZ]);
    T::ok($pr['phone'] === null && $pr['email'] === null && $pr['dba'] === null && $pr['status'] === 'dnc' && (int) $pr['do_not_call'] === 1 && Db::value('SELECT note FROM prospect_calls WHERE prospect_id = ?', [$prospZ]) === null, 'prospecto FMCSA convertido: sin telefono ni correo, «no llamar» y sin notas de llamadas');
    $evs = Db::all('SELECT type, payload FROM lead_events WHERE lead_id = ?', [$Z]);
    $bad = array_filter($evs, static fn($e) => preg_match('/zoraida|landing_page|utm_term|"note"|"title"|"body"/i', (string) $e['payload']) === 1);
    T::ok(!$bad && in_array('anonymized', array_column($evs, 'type'), true), 'linea de tiempo: solo claves estructurales en los eventos y un evento «anonymized»');
    T::eq(Db::one("SELECT user_id, changes FROM activity_log WHERE action = 'lead_anonymize' AND entity_id = '$Z'")['changes'], null, 'activity_log: lead_anonymize guarda quien lo hizo y el id, sin cambios');
    T::eq((int) Db::value("SELECT user_id FROM activity_log WHERE action = 'lead_anonymize' AND entity_id = '$Z'"), $aid, '  con el usuario correcto');
    T::eq(Db::value("SELECT changes FROM activity_log WHERE action = 'lead_note_add' AND entity_id = '$Z'"), null, '  y los cambios que guardaban actividades anteriores de este lead ya no estan');
    T::eq($count('suppression', "reason = 'erasure' AND source_lead_id = $Z"), 2, 'lista de supresion: telefono y correo con la razon erasure (solo hash)');
    T::ok(Dedupe::find('+13055550411', 'zoraida.priv@example.com') === null, 'Dedupe ya no encuentra a este lead por su telefono ni su correo');
    T::eq(LeadIngest::rescore($Z, 'test', false), null, 'rescore de un lead anonimizado: no hace nada (conserva la puntuacion historica)');
    T::eq((int) Db::value('SELECT score FROM leads WHERE id = ?', [$Z]), (int) $before['score'], '  la puntuacion sigue igual');
    // ningun rastro en TODA la base
    $leak = [];
    foreach (Db::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
        if (in_array($tbl, ['schema_migrations', 'suppression', 'users', 'settings'], true)) {
            continue;
        }
        foreach (Db::all("SELECT * FROM `$tbl`") as $row) {
            $txt = strtolower(json_encode($row, JSON_UNESCAPED_UNICODE));
            foreach ($needles as $n) {
                if (str_contains($txt, $n)) {
                    $leak[$tbl . ':' . $n] = true;
                }
            }
        }
    }
    T::ok(!$leak, 'RASTRO: ni el nombre, ni el telefono, ni el correo del titular aparecen en ninguna tabla' . ($leak ? ' · ' . implode(', ', array_keys($leak)) : ''));
    // la ficha
    $ficha = $panel->get('/leads/' . $Z);
    T::ok($ficha['status'] === 200 && str_contains($ficha['body'], 'Lead anonimizado a petición del titular') && !str_contains($ficha['body'], 'href="tel:') && !str_contains($ficha['body'], 'href="mailto:') && !str_contains($ficha['body'], 'wa.me')
        && !str_contains($ficha['body'], 'data-open="#call-dlg"') && !str_contains($ficha['body'], 'id="enviar"') && !str_contains($ficha['body'], 'Anonimizar este lead'), 'ficha de un lead anonimizado: aviso visible y ninguna accion de contacto ni de envio');
    $tplAny = (int) Db::value('SELECT id FROM email_templates ORDER BY id LIMIT 1');
    if ($tplAny === 0) {
        Templates::ensure();
        $tplAny = (int) Db::value('SELECT id FROM email_templates ORDER BY id LIMIT 1');
    }
    $r = $panel->get('/leads/' . $Z . '/enviar?tpl=' . $tplAny);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'El lead está anonimizado') && !str_contains($r['body'], 'value="email"') && !str_contains($r['body'], 'value="whatsapp"'), 'la pagina de envio de un anonimizado explica que no hay a quien escribir y no ofrece enviar');
    $b0 = count($mails());
    $outBefore = $count('messages', "lead_id = $Z AND direction = 'out'");
    $post($panel, '/leads/' . $Z . '/enviar', ['tpl' => (string) $tplAny, 'channel' => 'email']);
    T::ok(count($mails()) === $b0 && $count('messages', "lead_id = $Z AND direction = 'out'") === $outBefore, '  y un POST forzado tampoco envia nada');
    $r = $post($panel, '/leads/' . $Z . '/anonimizar', ['confirm' => 'ANONIMIZAR', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    T::ok(str_contains($panel->get('/leads/' . $Z)['body'], 'ya está anonimizado') || $count('activity_log', "action = 'lead_anonymize' AND entity_id = '$Z'") === 1, 'anonimizar dos veces: la segunda no hace nada');
    // un lead nuevo con el mismo telefono
    $again = $ingest(['name' => 'Otra Persona', 'phone' => '305-555-0411', 'email' => 'zoraida.priv@example.com', 'profile' => 'owner_operator', 'consent' => $consent]);
    $Z2 = (int) $again['lead_id'];
    T::ok($Z2 !== $Z && $again['status'] === 'created' && in_array('stop', $flagsOf($Z2), true) && $count('consents', "lead_id = $Z2") === 0 && $count('lead_events', "lead_id = $Z2 AND type = 'suppressed'") === 1, 'si el telefono o correo borrados vuelven a llegar: lead nuevo con STOP, sin consentimientos y con el motivo anotado');
    // reautenticacion vigente: no pide contraseña otra vez
    $L9 = (int) $ingest(['name' => 'Nueve Prueba', 'phone' => '+13055550419', 'profile' => 'owner_operator'])['lead_id'];
    $fich9 = $panel->get('/leads/' . $L9);
    T::ok(str_contains($fich9['body'], 'Tu identidad está confirmada ahora mismo') && !str_contains($fich9['body'], 'name="password"'), 'con la identidad confirmada hace poco, el formulario no vuelve a pedir contraseña ni codigo');
    $r = $post($panel, '/leads/' . $L9 . '/anonimizar', ['confirm' => 'ANONIMIZAR']);
    T::ok($count('leads', "id = $L9 AND anonymized_at IS NOT NULL") === 1, '  y anonimiza solo con escribir ANONIMIZAR');

    // ================================================================== 3. Fusion
    $P = Fx::lead(['first_name' => 'Pat', 'last_name' => '', 'phone_e164' => '+13055550520', 'phone_raw' => '305 555 0520', 'email' => null, 'city' => null, 'state' => 'TX', 'score' => 40, 'temperature' => 'warm']);
    $S = Fx::lead(['first_name' => 'Patricia', 'last_name' => 'Merge', 'phone_e164' => '+13055550520', 'email' => 'pat.merge@example.com', 'city' => 'Austin', 'state' => 'FL', 'flags' => ['stop_email'], 'usdot' => '7654321',
        'last_inbound_at' => $now, 'score' => 60, 'temperature' => 'warm']);
    LeadActions::addNote($S, 'Nota del secundario 1', $aid);
    LeadActions::addNote($S, 'Nota del secundario 2', $aid);
    LeadActions::addNote($P, 'Nota del principal', $aid);
    LeadActions::addTask($S, 'Tarea del secundario', '', 2, $aid);
    LeadIngest::event($S, 'alert_sent', ['channel' => 'telegram', 'kind' => 'hot']);
    Db::insert('messages', ['lead_id' => $S, 'channel' => 'sms', 'direction' => 'in', 'body' => 'hola desde S', 'status' => 'received', 'created_at' => $now]);
    Fx::consent($S);
    Db::insert('appointments', ['lead_id' => $S, 'starts_at' => Clock::shift(86400), 'status' => 'booked', 'calcom_id' => 'uid-merge-1']);
    Db::insert('sequence_enrollments', ['lead_id' => $P, 'sequence_key' => 'a6_warm', 'step' => 0, 'status' => 'active', 'next_run_at' => Clock::shift(3600)]);
    Db::insert('sequence_enrollments', ['lead_id' => $S, 'sequence_key' => 'a6_warm', 'step' => 0, 'status' => 'active', 'next_run_at' => Clock::shift(3600)]);
    Db::insert('sequence_enrollments', ['lead_id' => $S, 'sequence_key' => 'a12_referral', 'step' => 0, 'status' => 'active', 'next_run_at' => Clock::shift(7200)]);
    Db::insert('referral_codes', ['owner_name' => 'Ana', 'code' => 'ANA-M', 'active' => 1]);
    Db::insert('referrals', ['referral_code_id' => (int) Db::pdo()->lastInsertId(), 'lead_id' => $S, 'status' => 'referred', 'bonus_status' => 'none']);
    Db::insert('referral_codes', ['owner_name' => 'Patricia', 'owner_lead_id' => $S, 'code' => 'PAT-S', 'active' => 1]);
    foreach ([$P, $S] as $x) {
        Db::insert('handoffs', ['lead_id' => $x, 'attempt' => 1, 'version' => 1, 'status' => 'sent', 'nonce' => bin2hex(random_bytes(16)), 'expires_at' => Clock::shift(86400)]);
    }
    Queue::push('telegram_alert', ['lead_id' => $S, 'kind' => 'hot'], Clock::shift(900), 'k-merge-1');
    $eventsS = $count('lead_events', "lead_id = $S");
    $eventsP = $count('lead_events', "lead_id = $P");

    // reglas que bloquean
    $Q = Fx::lead(['phone_e164' => '+13055550999', 'email' => 'otro@example.com']);
    T::ok(!LeadMerge::merge($P, $Q, $aid)['ok'] && $count('leads', "id = $Q AND deleted_at IS NOT NULL") === 0, 'no se fusionan leads que no comparten telefono ni correo');
    T::ok(!LeadMerge::merge($P, $P, $aid)['ok'], 'no se fusiona un lead consigo mismo');
    $D = Fx::lead(['phone_e164' => '+13055550520', 'pipeline' => 'B', 'profile' => 'company_driver']);
    T::ok(!LeadMerge::merge($P, $D, $aid)['ok'], 'no se fusionan leads de pipelines distintos');
    $Sg = Fx::lead(['phone_e164' => '+13055550521', 'stage' => 'signed', 'signed_at' => $now]);
    $Ps = Fx::lead(['phone_e164' => '+13055550521', 'stage' => 'new']);
    $why = LeadMerge::merge($Ps, $Sg, $aid);
    T::ok(!$why['ok'] && str_contains($why['msg'], 'firmado') && $count('leads', "id = $Sg AND deleted_at IS NOT NULL") === 0, 'un lead firmado no se puede fusionar EN uno sin firmar (el firmado tiene que ser el principal)');
    T::ok(LeadMerge::problem(Lead::find($Sg), Lead::find($Ps))['ok'], '  al reves si se puede');
    T::ok(!LeadMerge::merge($P, $Z, $aid)['ok'], 'no se fusiona un lead anonimizado');
    T::ok(!LeadMerge::merge($P, 999999, $aid)['ok'], 'no se fusiona con un lead que no existe');
    T::eq([$count('lead_notes', "lead_id = $S"), $count('leads', "id = $S AND deleted_at IS NOT NULL")], [2, 0], 'los intentos bloqueados no cambian nada');

    // HTTP: seguridad
    T::eq($viewer->post('/leads/' . $P . '/fusionar', ['_csrf' => $viewer->csrf, 'other' => (string) $S, 'primary' => (string) $P, 'confirm' => '1'])['status'], 403, 'lector: POST fusionar responde 403');
    T::eq(Infra::header($anon->post('/leads/' . $P . '/fusionar', ['_csrf' => 'x']), 'location'), '/login', 'anonimo: POST fusionar redirige a /login');
    $post($panel, '/leads/' . $P . '/fusionar', ['other' => (string) $S, 'primary' => (string) $P]);
    T::eq($count('leads', "id = $S AND deleted_at IS NOT NULL"), 0, 'sin marcar la confirmacion: no fusiona');
    $post($panel, '/leads/' . $P . '/fusionar', ['other' => (string) $S, 'primary' => '12345', 'confirm' => '1']);
    T::eq($count('leads', "id = $S AND deleted_at IS NOT NULL"), 0, 'un principal que no es ninguno de los dos: no fusiona');
    $ficha = $panel->get('/leads/' . $P);
    T::ok(str_contains($ficha['body'], 'Fusionar duplicados') && str_contains($ficha['body'], 'name="primary"'), 'la ficha ofrece «Fusionar duplicados» con la eleccion del principal');
    $r = $post($panel, '/leads/' . $P . '/fusionar', ['other' => (string) $S, 'primary' => (string) $P, 'confirm' => '1']);
    T::eq([$r['status'], Infra::header($r, 'location')], [303, '/leads/' . $P . '#linea'], 'fusion confirmada: vuelve a la ficha del principal');
    T::ok($count('leads', "id = $S AND deleted_at IS NOT NULL AND merged_into = $P") === 1 && Lead::find($S) === null, 'el secundario queda con borrado logico y merged_into');
    T::eq($count('lead_notes', "lead_id = $P"), 3, 'las notas pasan al principal (1 + 2)');
    T::eq($count('lead_notes', "lead_id = $S"), 0, '  y no queda ninguna en el secundario');
    T::eq([$count('tasks', "lead_id = $P"), $count('messages', "lead_id = $P"), $count('appointments', "lead_id = $P")], [1, 1, 1], 'tareas, mensajes y citas pasan al principal');
    T::ok($count('consents', "lead_id = $P") === 3 && $count('consents', "lead_id = $S") === 0, 'los consentimientos pasan al principal');
    T::ok($count('lead_events', "lead_id = $P") >= $eventsP + $eventsS + 1 && $count('lead_events', "lead_id = $P AND type = 'merged'") === 1 && $count('lead_events', "lead_id = $S AND type = 'merged'") === 1, 'los eventos pasan al principal y hay un evento «merged» en cada lead');
    T::eq($count('lead_events', "lead_id = $S"), 1, '  el secundario solo conserva su evento de fusion');
    $seq = Db::all('SELECT lead_id, sequence_key, status FROM sequence_enrollments ORDER BY id');
    $activeA6 = array_filter($seq, static fn($e) => $e['sequence_key'] === 'a6_warm' && $e['status'] === 'active');
    T::ok(count($activeA6) === 1 && (int) reset($activeA6)['lead_id'] === $P, 'secuencias: nunca dos activas de la misma secuencia (la del secundario se detiene)');
    T::eq((int) Db::value("SELECT lead_id FROM sequence_enrollments WHERE sequence_key = 'a12_referral'"), $P, '  y la que el principal no tenia (a12) se mueve');
    T::eq((string) Db::value("SELECT paused_reason FROM sequence_enrollments WHERE lead_id = $S AND sequence_key = 'a6_warm'"), 'merged', '  con el motivo «merged»');
    T::eq([(int) Db::value("SELECT lead_id FROM referrals LIMIT 1"), (int) Db::value("SELECT owner_lead_id FROM referral_codes WHERE code = 'PAT-S'")], [$P, $P], 'referido y codigos de referido del secundario pasan al principal');
    T::eq(array_map('intval', array_column(Db::all('SELECT attempt FROM handoffs WHERE lead_id = ? ORDER BY attempt', [$P]), 'attempt')), [1, 2], 'entregas a NorthStar: las dos pasan al principal sin chocar (attempt 1 y 2)');
    T::eq($count('jobs', "dedupe_key = 'k-merge-1' AND status = 'cancelled'"), 1, 'trabajos pendientes del secundario: cancelados');
    $Pn = Db::one('SELECT * FROM leads WHERE id = ?', [$P]);
    T::ok($Pn['email'] === 'pat.merge@example.com' && $Pn['city'] === 'Austin' && $Pn['last_name'] === 'Merge' && $Pn['usdot'] === '7654321', 'los campos VACIOS del principal se rellenan con los del secundario (correo, ciudad, apellido, USDOT)');
    T::ok($Pn['state'] === 'TX' && $Pn['first_name'] === 'Pat', '  y los que ya tenian valor NO se pisan (estado TX, nombre)');
    T::ok(in_array('stop_email', $flagsOf($P), true) && !Consent::isGranted($P, 'email'), 'las banderas se unen: el STOP de correo del secundario manda sobre el principal');
    T::eq((string) Db::value("SELECT action FROM activity_log WHERE action = 'lead_merge' AND entity_id = '$P'"), 'lead_merge', 'activity_log registra la fusion');
    $r = $panel->get('/leads/' . $S);
    T::eq([$r['status'], Infra::header($r, 'location')], [302, '/leads/' . $P], 'la ficha del secundario redirige a la del principal');
    T::eq($count('leads', "id IN ($P, $S)"), 2, 'no se borro ninguna fila de leads (el secundario se conserva con borrado logico)');
    T::eq(LeadMerge::merge($S, $P, $aid)['ok'], false, 'fusionar otra vez (el secundario ya no existe): rechazado');

    // ================================================================== 4. Envio manual de una plantilla
    $address = 'NorthStar Freight Solutions LLC, 100 Main St, Lakeland, FL 33801';
    $cfg = static function (array $over = []) use ($address): void {
        Settings::set('mail', $over + ['enabled' => false, 'address' => $address, 'from_name' => 'NorthStar Freight Solutions', 'cap_hour' => 20, 'cap_lead' => 3, 'rates_source' => '']);
        Settings::clear();
    };
    $cfg();
    $mk = static function (string $name, string $body, string $lang = 'en') use ($aid): int {
        $v = Templates::validate(['name' => $name, 'lang' => $lang, 'subject' => 'Hello {nombre}', 'body' => $body, 'tkey' => ''], true);
        return Templates::create($v['values'], $aid);
    };
    $T1 = $mk('Prueba manual', "Hi {nombre},\n\nHere is the calculator: {link_calculadora}\n\nNorthStar Freight Solutions");
    $T2 = $mk('Con pendiente', "Hi {nombre},\n\nOur rate is [[CONFIRMAR: tarifa]] per mile and that is all we have.\n\nNorthStar");
    $SL = Fx::lead(['first_name' => 'Sara', 'email' => 'sara.send@example.com', 'phone_e164' => '+13055550610', 'language' => 'en']);
    Fx::consent($SL);
    $page = $panel->get('/leads/' . $SL . '/enviar?tpl=' . $T1);
    T::ok($page['status'] === 200 && str_contains($page['body'], 'Hello Sara') && str_contains($page['body'], 'Hi Sara,') && str_contains($page['body'], 'rate-per-mile-calculator') && str_contains($page['body'], 'Enviar correo') && str_contains($page['body'], 'Abrir WhatsApp con este texto'), 'vista previa: variables resueltas (nombre, enlace) y los dos canales');
    T::ok(str_contains($page['body'], $address) && str_contains($page['body'], '/u/'), '  la vista previa del correo incluye el pie obligatorio (direccion fisica y baja de un clic)');
    T::eq($count('messages', "lead_id = $SL"), 0, '  ver la vista previa no envia ni escribe nada');
    $pg2 = $panel->get('/leads/' . $SL . '/enviar?tpl=' . $T2);
    T::ok(str_contains($pg2['body'], 'Texto pendiente de confirmar') && !str_contains($pg2['body'], 'value="email"') && !str_contains($pg2['body'], 'value="whatsapp"'), 'plantilla con [[CONFIRMAR]]: avisa y no ofrece enviar ni abrir WhatsApp');
    $before = count($mails());
    $post($panel, '/leads/' . $SL . '/enviar', ['tpl' => (string) $T2, 'channel' => 'email']);
    T::ok(count($mails()) === $before && $count('messages', "lead_id = $SL") === 0, '  y si se fuerza el POST, el servidor tampoco la envia');
    T::eq($viewer->get('/leads/' . $SL . '/enviar?tpl=' . $T1)['status'], 403, 'lector: GET de la pagina de envio responde 403');
    T::eq($viewer->post('/leads/' . $SL . '/enviar', ['_csrf' => $viewer->csrf, 'tpl' => (string) $T1, 'channel' => 'email'])['status'], 403, 'lector: POST enviar responde 403');
    T::eq(Infra::header($anon->post('/leads/' . $SL . '/enviar', ['_csrf' => 'x']), 'location'), '/login', 'anonimo: POST enviar redirige a /login');
    $r = $panel->post('/leads/' . $SL . '/enviar', ['_csrf' => 'falso', 'tpl' => (string) $T1, 'channel' => 'email']);
    T::ok($r['status'] === 303 && count($mails()) === $before, 'CSRF falso: no envia');
    $r = $post($panel, '/leads/' . $SL . '/enviar', ['tpl' => (string) $T1, 'channel' => 'email']);
    $sent = $mails();
    T::ok($r['status'] === 303 && count($sent) === $before + 1, 'enviar: sale UN correo por el SMTP');
    $raw = quoted_printable_decode((string) $sent[count($sent) - 1]['raw']);
    T::ok(str_contains($raw, 'sara.send@example.com') && str_contains($raw, 'Hello Sara') && str_contains($raw, 'List-Unsubscribe') && str_contains($raw, $address), '  al correo del lead, con asunto, cabecera de baja y pie con la direccion');
    $m = Db::one("SELECT * FROM messages WHERE lead_id = $SL AND channel = 'email'");
    T::ok($m !== null && $m['direction'] === 'out' && $m['status'] === 'sent' && (int) $m['user_id'] === $aid && str_contains((string) $m['template_key'], 'prueba_manual') && $m['sequence_key'] === null, '  queda en messages (saliente, enviado, con el usuario y sin secuencia)');
    T::eq($count('lead_events', "lead_id = $SL AND type = 'template_sent'"), 1, '  y en la linea de tiempo');
    T::eq($count('activity_log', "action = 'lead_template_sent' AND entity_id = '$SL'"), 1, '  y en activity_log');
    // topes
    $cfg(['cap_lead' => 1]);
    $post($panel, '/leads/' . $SL . '/enviar', ['tpl' => (string) $T1, 'channel' => 'email']);
    T::ok(count($mails()) === $before + 1 && str_contains($panel->get('/leads/' . $SL . '/enviar?tpl=' . $T1)['body'], 'ya se le enviaron'), 'tope por lead (1 cada 24 h): el segundo correo no sale y la pagina lo explica');
    $cfg(['cap_lead' => 3, 'cap_hour' => 1]);
    $SL2 = Fx::lead(['first_name' => 'Hugo', 'email' => 'hugo.send@example.com']);
    Fx::consent($SL2);
    $post($panel, '/leads/' . $SL2 . '/enviar', ['tpl' => (string) $T1, 'channel' => 'email']);
    T::ok(count($mails()) === $before + 1 && str_contains($panel->get('/leads/' . $SL2 . '/enviar?tpl=' . $T1)['body'], 'Tope de envíos por hora'), 'tope global por hora: tambien lo respeta (cuenta los correos de secuencias y manuales)');
    $cfg();
    // consentimiento o escribio primero
    $NC = Fx::lead(['first_name' => 'Nora', 'email' => 'nora.nc@example.com']);
    T::ok(LeadSend::emailBlock(Lead::find($NC)) !== null && str_contains((string) LeadSend::emailBlock(Lead::find($NC)), 'consentimiento'), 'sin consentimiento de correo y sin haber escrito primero: bloqueado');
    $b0 = count($mails());
    $post($panel, '/leads/' . $NC . '/enviar', ['tpl' => (string) $T1, 'channel' => 'email']);
    T::ok(count($mails()) === $b0 && $count('messages', "lead_id = $NC") === 0, '  y el servidor no lo envia');
    Db::run('UPDATE leads SET last_inbound_at = ? WHERE id = ?', [$now, $NC]);
    $post($panel, '/leads/' . $NC . '/enviar', ['tpl' => (string) $T1, 'channel' => 'email']);
    T::ok(count($mails()) === $b0 + 1, 'si el lead escribio primero: se puede (conversacion iniciada por el)');
    Db::run("UPDATE leads SET flags = '[\"stop_email\"]' WHERE id = $NC");
    T::ok(LeadSend::emailBlock(Lead::find($NC)) !== null, 'con la baja de correo (stop_email): bloqueado aunque haya escrito primero');
    Db::run("UPDATE leads SET flags = '[\"stop\"]' WHERE id = $NC");
    T::ok(LeadSend::emailBlock(Lead::find($NC)) !== null && LeadSend::whatsappBlock(Lead::find($NC) + ['phone_e164' => '+13055550620']) !== null, 'con STOP total: ni correo ni WhatsApp');
    Suppression::add('email', 'sup.send@example.com', Suppression::ERASURE);
    $SU = Fx::lead(['first_name' => 'Sup', 'email' => 'sup.send@example.com']);
    Fx::consent($SU);
    T::ok(str_contains((string) LeadSend::emailBlock(Lead::find($SU)), 'supresión'), 'correo en la lista de supresion: bloqueado');
    $cfg(['address' => '']);
    T::ok(str_contains((string) LeadSend::emailBlock(Lead::find($SL)), 'dirección física'), 'sin direccion fisica configurada: bloqueado');
    $cfg();
    // sin SMTP
    $panelNs = Panel::start($ctx, 'panel43b');
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$aid]);
    T::ok($panelNs->login($admin['email']), 'login (servidor sin SMTP)');
    Db::run('UPDATE leads SET last_inbound_at = NULL WHERE id = ?', [$SL2]);
    $b1 = count($mails());
    $pgN = $panelNs->get('/leads/' . $SL2 . '/enviar?tpl=' . $T1);
    T::ok(str_contains($pgN['body'], 'No hay SMTP configurado') && !str_contains($pgN['body'], 'value="email"'), 'sin SMTP: la pagina dice que no se enviaria y no ofrece el boton');
    $panelNs->post('/leads/' . $SL2 . '/enviar', ['_csrf' => $panelNs->csrf, 'tpl' => (string) $T1, 'channel' => 'email']);
    T::ok(count($mails()) === $b1 && $count('messages', "lead_id = $SL2 AND channel = 'email'") === 0 && $count('lead_events', "lead_id = $SL2 AND type = 'template_sent'") === 0, '  y un POST forzado NO lo da por enviado (ni mensaje ni evento)');
    Infra::stop('panel43b');
    // WhatsApp
    $r = $post($panel, '/leads/' . $SL . '/enviar', ['tpl' => (string) $T1, 'channel' => 'whatsapp']);
    T::ok($r['status'] === 200 && preg_match('#<meta http-equiv="refresh" content="0;url=https://wa\.me/13055550610\?text=([^"]+)">#', $r['body'], $mm) === 1 && str_contains(rawurldecode(html_entity_decode($mm[1])), 'Hi Sara') && str_contains($r['body'], 'Abrir WhatsApp'), 'WhatsApp: la respuesta abre wa.me con el texto resuelto (refresco de cabecera + enlace de respaldo)');
    T::ok(!str_contains(rawurldecode(html_entity_decode($mm[1])), 'List-Unsubscribe') && !str_contains(rawurldecode(html_entity_decode($mm[1])), $address), '  sin el pie del correo');
    T::eq($count('lead_events', "lead_id = $SL AND type = 'whatsapp_opened'"), 1, '  y anota «WhatsApp abierto con plantilla» en la linea de tiempo');
    T::eq($count('messages', "lead_id = $SL AND channel = 'whatsapp'"), 0, '  sin crear un mensaje (no se sabe si se envio)');
    $r = $post($panel, '/leads/' . $SL . '/enviar', ['tpl' => (string) $T2, 'channel' => 'whatsapp']);
    T::ok($r['status'] === 303 && $count('lead_events', "lead_id = $SL AND type = 'whatsapp_opened'") === 1, 'WhatsApp con texto pendiente de confirmar: no se abre');
    Db::run("UPDATE leads SET flags = '[\"stop_sms\"]' WHERE id = $SL");
    $r = $post($panel, '/leads/' . $SL . '/enviar', ['tpl' => (string) $T1, 'channel' => 'whatsapp']);
    T::ok($r['status'] === 303 && $count('lead_events', "lead_id = $SL AND type = 'whatsapp_opened'") === 1, 'WhatsApp con STOP de SMS/WhatsApp: no se abre');
    Db::run("UPDATE leads SET flags = '[]' WHERE id = $SL");
    // clases CSS de las pantallas nuevas
    $assets = $ctx['root'] . '/public/assets/';
    $defs = static function (array $sheets) use ($assets): array {
        $set = [];
        foreach ($sheets as $sh) {
            preg_match_all('/\.(-?[_a-zA-Z][\w-]*)/', preg_replace('#/\*.*?\*/#s', '', (string) @file_get_contents($assets . $sh . '.css')) ?? '', $m);
            foreach ($m[1] as $c) {
                $set[$c] = true;
            }
        }
        return $set;
    };
    $classesOf = static function (string $html): array {
        preg_match_all('/\sclass="([^"]*)"/', $html, $cm);
        $used = [];
        foreach ($cm[1] as $a) {
            foreach (preg_split('/\s+/', trim(html_entity_decode($a))) ?: [] as $c) {
                if ($c !== '') {
                    $used[$c] = true;
                }
            }
        }
        return array_keys($used);
    };
    $hooks = ['btn__t', 'app', 'js', 'srch', 'dlg--confirm'];
    $sheetsOf = static function (string $html): array {
        preg_match_all('#<link rel="stylesheet" href="/assets/([a-z0-9-]+)\.css\?v=#', $html, $m);
        return array_merge($m[1], str_contains($html, 'src="/assets/ux.js') ? ['ux'] : [], str_contains($html, 'src="/assets/ctl.js') ? ['ctl'] : []);
    };
    $sendPage = $panel->get('/leads/' . $SL . '/enviar?tpl=' . $T1);
    $miss = array_values(array_filter($classesOf($sendPage['body']), static fn($c) => !isset($defs($sheetsOf($sendPage['body']))[$c]) && !in_array($c, $hooks, true)));
    T::ok($miss === [], 'pagina de envio: toda clase usada tiene regla en las hojas que carga' . ($miss ? ' · FALTAN: ' . implode(', ', $miss) : ''));
    $fichaSL = $panel->get('/leads/' . $P);
    preg_match_all('#<section class="card" id="(?:enviar|fusion|privacidad)".*?</section>#s', $fichaSL['body'], $secs);
    $miss = array_values(array_filter($classesOf(implode('', $secs[0])), static fn($c) => !isset($defs(array_merge($sheetsOf($fichaSL['body'])))[$c]) && !in_array($c, $hooks, true)));
    T::ok(count($secs[0]) >= 2 && $miss === [], 'ficha: las tarjetas de enviar, fusionar y privacidad solo usan clases con regla' . ($miss ? ' · FALTAN: ' . implode(', ', $miss) : ''));

    // ================================================================== 5. Importacion CSV
    T::reset(true);
    Db::pdo()->exec('DELETE FROM email_templates');
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $viewerU = Panel::user('viewer');
    $panel2 = Panel::start($ctx, 'panel43c', $smtpEnv);
    T::ok($panel2->login($admin['email']), 'login (importacion)');
    $v2 = $panel2->another();
    $an2 = $panel2->another();
    T::ok($v2->login($viewerU['email']), 'login del lector (importacion)');
    foreach (['/importar', '/importar/1'] as $pth) {
        T::eq($v2->get($pth)['status'], 403, "lector: GET $pth responde 403");
        T::eq(Infra::header($an2->get($pth), 'location'), '/login', "anonimo: GET $pth redirige a /login");
    }
    T::eq($v2->post('/importar', ['_csrf' => $v2->csrf])['status'], 403, 'lector: POST /importar responde 403');
    $multi = static function (string $content, string $filename, array $fields, string $csrf) use ($panel2): array {
        $b = '----nsb' . bin2hex(random_bytes(6));
        $body = '';
        foreach (['_csrf' => $csrf] + $fields as $k => $v) {
            $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        }
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"csv\"; filename=\"$filename\"\r\nContent-Type: text/csv\r\n\r\n$content\r\n--$b--\r\n";
        return Infra::http('POST', $panel2->base . '/importar', ['raw' => $body, 'jar' => $panel2->jar, 'headers' => ["Content-Type: multipart/form-data; boundary=$b"]]);
    };
    Suppression::add('phone', '+13055550704', Suppression::SMS_STOP);
    $existing = (int) $ingest(['name' => 'Existente Uno', 'phone' => '+13055550705', 'profile' => 'owner_operator'])['lead_id'];
    $csv1252 = mb_convert_encoding("Nombre;Teléfono;Correo;Estado;Equipo;Consentimiento\r\n"
        . "José Pérez;(305) 555-0701;jose.csv@example.com;TX;dry-van;si\r\n"
        . "María Núñez;305-555-0702;;FL;;si\r\n"
        . "Sin Telefono;no-es-telefono;;TX;;\r\n"
        . "Suprimido Stop;305-555-0704;;TX;;\r\n"
        . "Repetido Uno;305-555-0701;;TX;;\r\n"
        . "Ya Existe;305-555-0705;;TX;;\r\n"
        . ";305-555-0706;;TX;;\r\n"
        . "=HYPERLINK(\"http://x\");305-555-0707;;TX;;\r\n", 'Windows-1252', 'UTF-8');
    $r = $multi($csv1252, 'Leads Nov 2026.csv', [], $panel2->csrf);
    $impId = (int) Db::value('SELECT id FROM lead_imports ORDER BY id DESC LIMIT 1');
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/importar/' . $impId && $impId > 0, 'subir el CSV: 303 a la pantalla de mapeo');
    $imp = Db::one('SELECT * FROM lead_imports WHERE id = ?', [$impId]);
    T::ok($imp['status'] === 'uploaded' && $imp['encoding'] === 'Windows-1252' && $imp['delimiter_char'] === ';' && (int) $imp['total_rows'] === 8 && $imp['tag'] === 'import-leads-nov-2026', 'se detecta Windows-1252, el separador «;», 8 filas y la etiqueta del archivo');
    $file = LeadImport::path((string) $imp['token']);
    T::ok(is_file($file) && (fileperms($file) & 0777) === 0600, 'el archivo queda en storage/imports con permisos 0600');
    T::ok(str_contains((string) file_get_contents($file), 'José Pérez') && mb_check_encoding((string) file_get_contents($file), 'UTF-8'), '  guardado ya como UTF-8 (los acentos se leen bien)');
    $map = $panel2->get('/importar/' . $impId);
    T::ok($map['status'] === 200 && str_contains($map['body'], '<option value="phone" selected>') && str_contains($map['body'], '<option value="email" selected>') && str_contains($map['body'], '<option value="name" selected>') && str_contains($map['body'], '<option value="state" selected>'), 'mapeo: las columnas se autodetectan por el nombre del encabezado (en español)');
    T::ok(!str_contains($map['body'], 'value="consent') , '  y no existe ningun campo de consentimiento para mapear');
    $leadsBefore = $count('leads');
    $jobsBefore = $count('jobs');
    $form = ['map' => [0 => 'name', 1 => 'phone', 2 => 'email', 3 => 'state', 4 => 'equipment'], 'profile' => 'owner_operator', 'dup' => 'skip'];
    $sim = $post($panel2, '/importar/' . $impId . '/simular', $form);
    T::ok($sim['status'] === 200 && str_contains($sim['body'], 'No se escribió nada') && str_contains($sim['body'], 'Se crearían'), 'simular: responde con el resultado');
    $simData = LeadImport::simulate($imp, [0 => 'name', 1 => 'phone', 2 => 'email', 3 => 'state', 4 => 'equipment'], ['profile' => 'owner_operator', 'skip_duplicates' => true]);
    T::eq([$simData['create'], $simData['duplicate'], $simData['reject']], [3, 2, 3], 'simulacion: 3 se crearian (José, María y la fórmula), 2 duplicados (en el archivo y existente) y 3 rechazados');
    T::ok(isset($simData['reasons']['Teléfono inválido']) && isset($simData['reasons']['Falta el nombre']) && isset($simData['reasons']['En la lista de supresión (STOP o borrado a petición)']) && isset($simData['reasons']['Repetido en el archivo']) && isset($simData['reasons']['Ya existe un lead con ese teléfono o correo']), '  cuenta los motivos: telefono invalido, sin nombre, supresion, repetido en el archivo y ya existe');
    $dupEx = array_values(array_filter($simData['problems'], static fn($p) => (int) $p['lead_id'] === $existing));
    T::ok(count($dupEx) === 1 && $dupEx[0]['row'] === 7, '  y dice de que lead es el duplicado y en que fila');
    T::eq([$count('leads'), $count('jobs'), $count('lead_events'), $count('consents')], [$leadsBefore, $jobsBefore, $count('lead_events'), 0], 'la simulacion NO escribe nada (leads, trabajos, consentimientos)');
    // validaciones del formulario de inicio
    $r = $post($panel2, '/importar/' . $impId . '/start', ['map' => [0 => 'name', 1 => 'phone'], 'profile' => 'owner_operator']);
    T::ok($count('lead_imports', "id = $impId AND status = 'uploaded'") === 1 && $count('jobs', "type = 'lead_import'") === 0, 'importar sin marcar la confirmacion: no encola nada');
    $r = $post($panel2, '/importar/' . $impId . '/start', ['map' => [0 => 'name'], 'profile' => 'owner_operator', 'confirm' => '1']);
    T::ok($count('lead_imports', "id = $impId AND status = 'uploaded'") === 1 && str_contains($r['body'], 'teléfono o'), 'sin columna de telefono ni de correo: no importa y lo explica');
    $r = $post($panel2, '/importar/' . $impId . '/start', ['map' => [0 => 'name', 1 => 'phone', 2 => 'phone'], 'confirm' => '1']);
    T::ok($count('lead_imports', "id = $impId AND status = 'uploaded'") === 1, 'dos columnas al mismo campo / sin perfil: no importa');
    // importar de verdad
    $r = $post($panel2, '/importar/' . $impId . '/start', $form + ['confirm' => '1']);
    T::eq([$r['status'], Infra::header($r, 'location')], [303, '/importar/' . $impId], 'importar: queda en cola');
    T::eq($count('jobs', "type = 'lead_import' AND status = 'pending'"), 1, '  un job de importacion');
    $post($panel2, '/importar/' . $impId . '/start', $form + ['confirm' => '1']);
    T::eq($count('jobs', "type = 'lead_import'"), 1, '  un segundo POST (doble clic) no encola otro');
    Fx::runJobs(5);
    $imp = Db::one('SELECT * FROM lead_imports WHERE id = ?', [$impId]);
    T::ok($imp['status'] === 'done' && (int) $imp['processed'] === 8 && (int) $imp['created_count'] === 3 && (int) $imp['duplicate_count'] === 2 && (int) $imp['rejected_count'] === 3, 'la cola importa: 8 procesadas, 3 creadas, 2 duplicadas, 3 rechazadas');
    T::ok(!is_file($file), 'el archivo se borra al terminar');
    $created = Db::all("SELECT * FROM leads WHERE source = 'csv_import' ORDER BY id");
    T::eq(count($created), 3, 'se crearon 3 leads con la fuente csv_import');
    $jose = Db::one("SELECT * FROM leads WHERE source = 'csv_import' AND phone_e164 = '+13055550701'");
    T::ok($jose !== null && $jose['first_name'] === 'José' && $jose['last_name'] === 'Pérez' && $jose['email'] === 'jose.csv@example.com' && $jose['state'] === 'TX' && $jose['profile'] === 'owner_operator' && Db::decode($jose['equipment_types']) === ['dry-van'], 'los datos se mapean y se normalizan (acentos bien, telefono E.164, equipo)');
    T::eq([in_array('import:leads-nov-2026', Db::decode($jose['flags']), true), (int) $jose['score'] > 0], [true, true], '  con la etiqueta del archivo y puntuacion calculada por el motor');
    T::eq($count('consents', 'lead_id IN (' . implode(',', array_column($created, 'id')) . ')'), 0, 'los importados NO tienen consentimiento (la columna «Consentimiento» se ignora)');
    T::ok(!Consent::isGranted((int) $jose['id'], 'sms') && !Consent::isGranted((int) $jose['id'], 'email'), '  ni de SMS ni de correo');
    T::eq($count('jobs', "type IN ('telegram_alert','telegram_alert_followup','email_notify')"), 0, 'sin alertas de Telegram ni correos de aviso por cada importado');
    T::eq($count('leads', "phone_e164 = '+13055550704'"), 0, 'el telefono de la lista de supresion NO se importo');
    T::eq($count('leads', "phone_e164 = '+13055550705'"), 1, 'el duplicado de un lead existente no creo otro');
    $formula = Db::one("SELECT * FROM leads WHERE phone_e164 = '+13055550707'");
    T::ok($formula !== null && str_starts_with($formula['first_name'], '=HYPERLINK'), 'la celda con formula se guarda tal cual (no se interpreta)');
    $csvOut = Csv::leads(Db::all('SELECT l.*, l.id FROM leads l WHERE l.id = ?', [(int) $formula['id']]));
    T::ok(str_contains($csvOut, "'=HYPERLINK") && !preg_match('/(^|,)=HYPERLINK/m', $csvOut), '  y al EXPORTAR sale con el apostrofo (proteccion contra formulas)');
    $done = $panel2->get('/importar/' . $impId);
    T::ok($done['status'] === 200 && str_contains($done['body'], 'Terminada') && str_contains($done['body'], 'Filas con novedad') && str_contains($done['body'], 'Teléfono inválido'), 'la pantalla de la importacion muestra el resultado y las filas con novedad');
    T::ok(count(Db::decode($imp['problems'])) === 5, '  5 filas con novedad (3 rechazadas y 2 duplicadas)');
    $rec = $panel2->get('/importar');
    T::ok($rec['status'] === 200 && str_contains($rec['body'], 'Leads Nov 2026.csv') && str_contains($rec['body'], 'enctype="multipart/form-data"'), '/importar lista las importaciones recientes y el formulario de subida');
    $miss = array_values(array_filter($classesOf($done['body'] . $rec['body'] . $map['body'] . $sim['body']), static fn($c) => !isset($defs($sheetsOf($rec['body']))[$c]) && !in_array($c, $hooks, true)));
    T::ok($miss === [], 'las pantallas de importacion solo usan clases con regla' . ($miss ? ' · FALTAN: ' . implode(', ', $miss) : ''));
    // completar duplicados
    $csvB = "name,phone,email,city\nExistente Uno,305-555-0705,existente.uno@example.com,Dallas\n";
    $r = $multi($csvB, 'completar.csv', [], $panel2->csrf);
    $id2 = (int) Db::value('SELECT id FROM lead_imports ORDER BY id DESC LIMIT 1');
    $post($panel2, '/importar/' . $id2 . '/start', ['map' => [0 => 'name', 1 => 'phone', 2 => 'email', 3 => 'city'], 'profile' => 'owner_operator', 'dup' => 'fill', 'confirm' => '1']);
    Fx::runJobs(5);
    $ex = Db::one('SELECT * FROM leads WHERE id = ?', [$existing]);
    T::ok($ex['email'] === 'existente.uno@example.com' && $ex['city'] === 'Dallas' && $count('lead_events', "lead_id = $existing AND type = 'reentry'") === 1 && $count('leads', "phone_e164 = '+13055550705'") === 1, 'duplicados con «completar»: rellena los campos vacios del lead existente (reingreso) sin crear otro');
    // limites y formatos (en proceso, sin red)
    $tmp = static function (string $content) use ($work): array {
        $f = $work . '/imp-' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($f, $content);
        return ['tmp_name' => $f, 'name' => 'x.csv', 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    };
    $rows = "name,phone\n";
    for ($i = 0; $i < 2001; $i++) {
        $rows .= 'N' . $i . ',305-555-' . str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT) . "\n";
    }
    $x = LeadImport::store($tmp($rows), $aid, true);
    T::ok(!$x['ok'] && str_contains($x['msg'], '2.000'), 'mas de 2.000 filas: rechazado con el motivo');
    $okRows = "name,phone\n";
    for ($i = 0; $i < 2000; $i++) {
        $okRows .= 'N' . $i . ',305-555-' . str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT) . "\n";
    }
    $x = LeadImport::store($tmp($okRows), $aid, true);
    T::ok($x['ok'], 'exactamente 2.000 filas: aceptado');
    $big = $tmp("name,phone\n" . str_repeat("Abcdefghij,305-555-0100\n", 100000));
    T::ok(!LeadImport::store($big, $aid, true)['ok'], 'mas de 2 MB: rechazado');
    $badExt = $tmp("name,phone\nA,1\n");
    $badExt['name'] = 'datos.exe';
    T::ok(!LeadImport::store($badExt, $aid, true)['ok'], 'extension que no es .csv: rechazada');
    T::ok(!LeadImport::store($tmp("name,phone\n\0\0\0binario\n"), $aid, true)['ok'], 'contenido binario: rechazado');
    T::ok(!LeadImport::store($tmp("name,phone\n"), $aid, true)['ok'], 'solo encabezados: rechazado');
    T::ok(!LeadImport::store(['error' => UPLOAD_ERR_NO_FILE], $aid)['ok'], 'sin archivo: rechazado');
    T::ok(!LeadImport::store($tmp("name,phone\nA,305-555-0100\n"), $aid)['ok'], 'un archivo que no es una subida HTTP real (is_uploaded_file): rechazado');
    $bom = $tmp("\xEF\xBB\xBFNombre\tTel\tEmail\nÁngel\t305-555-0801\ta@example.com\n");
    $x = LeadImport::store($bom, $aid, true);
    $ibom = LeadImport::find((int) $x['id']);
    $dbom = LeadImport::read(LeadImport::path((string) $ibom['token']), (string) $ibom['delimiter_char']);
    T::ok($x['ok'] && $ibom['delimiter_char'] === "\t" && $dbom['headers'] === ['Nombre', 'Tel', 'Email'] && $dbom['rows'][0]['cells'][0] === 'Ángel', 'UTF-8 con BOM y tabulador: encabezados sin BOM, separador detectado y acentos correctos');
    T::eq(LeadImport::suggest(['Full Name', 'Phone Number', 'E-mail', 'ST', 'Years Experience']), [0 => 'name', 1 => 'phone', 2 => 'email', 3 => 'state', 4 => 'years'], 'autodeteccion de encabezados en ingles');
    $fresh = Db::one('SELECT * FROM lead_imports WHERE status = ? ORDER BY id DESC LIMIT 1', ['uploaded']);
    Db::run("UPDATE lead_imports SET created_at = ? WHERE status = 'uploaded'", [Clock::shift(-90000)]);
    T::ok(LeadImport::cleanup() >= 1 && !is_file(LeadImport::path((string) $fresh['token'])), 'las subidas abandonadas de mas de 24 h se borran del disco');
    $r = $multi("name,phone\nA,305-555-0100\n", 'descartar.csv', [], $panel2->csrf);
    $id3 = (int) Db::value('SELECT id FROM lead_imports ORDER BY id DESC LIMIT 1');
    $f3 = LeadImport::path((string) Db::value('SELECT token FROM lead_imports WHERE id = ?', [$id3]));
    $post($panel2, '/importar/' . $id3 . '/discard', []);
    T::ok(!is_file($f3) && $count('lead_imports', "id = $id3 AND status = 'cancelled'") === 1, 'descartar una subida: borra el archivo');
    Infra::stop('panel43c');
    Infra::stop('panel43');
    Infra::stop('smtp43');
    T::reset(true);
};
