<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Settings;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Q4b-A.4: API interna para n8n (/internal/*): autorizacion, lista blanca, consentimiento, pasos por WhatsApp, recordatorios, idempotencia, aviso de la campana y boton «Probar». */
return static function (array $ctx): void {
    T::reset(true);
    foreach (['n8n', 'calcom', 'mail', 'meta'] as $k) {
        Settings::set($k, []);
    }
    Settings::clear();
    $work = $ctx['work'];
    $tgLog = "$work/tg27.log";
    @unlink($tgLog);
    $tgPort = Infra::phpServer('tg27', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $tgLog, 'MOCK_MODE_FILE' => "$work/tg27.mode"], $ctx['root'] . '/tests/lib/telegram_mock.php');
    foreach (['TELEGRAM_BOT_TOKEN' => '123456789:FAKE_TEST_TOKEN_not_real_0123456789', 'TELEGRAM_CHAT_ID' => '42', 'TELEGRAM_API_BASE' => "http://127.0.0.1:$tgPort"] as $k => $v) {
        Env::set($k, $v);
    }
    $secrets = ['META_APP_SECRET' => 'meta-app-secret-123', 'META_VERIFY_TOKEN' => 'verify-token-xyz', 'META_PAGE_TOKEN' => 'EAAB-page-token-SECRETO-999', 'CALCOM_WEBHOOK_SECRET' => 'cal-secret-123', 'TWILIO_TOKEN' => 'twilio-token-abc123', 'N8N_WEBHOOK_SECRET' => 'n8n-secret-123', 'INTERNAL_RATE_LIMIT' => '400'];
    $api = Panel::start($ctx, 'int27', $secrets, true);
    $tok = $ctx['env']['INTERNAL_API_TOKEN'];
    $auth = ['Authorization: Bearer ' . $tok];
    $call = static fn(string $m, string $p, ?array $body = null, ?array $h = null) => Infra::http($m, $api->base . $p, ['headers' => $h ?? $auth, 'timeout' => 20] + ($body !== null ? ['json' => $body] : []));
    $js = static fn(array $r): array => json_decode($r['body'], true) ?: [];
    $count = static fn(string $t, string $w = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $w");

    // ------------------------------------------------------------ autorizacion
    T::eq($call('GET', '/internal/ping', null, [])['status'], 401, 'sin token: 401');
    T::ok(Infra::header($call('GET', '/internal/ping', null, []), 'www-authenticate') !== '', '  con WWW-Authenticate');
    T::eq($call('GET', '/internal/ping', null, ['Authorization: Bearer ' . $tok . 'x'])['status'], 401, 'token incorrecto: 401');
    T::eq($call('GET', '/internal/ping?token=' . $tok, null, [])['status'], 401, 'token en la URL: no vale');
    T::eq($call('GET', '/internal/ping', null, ['Authorization: Basic ' . base64_encode('a:b')])['status'], 401, 'esquema Basic: 401');
    $r = $call('GET', '/internal/ping');
    T::ok($r['status'] === 200 && str_contains(Infra::header($r, 'cache-control'), 'no-store') && Infra::header($r, 'access-control-allow-origin') === '', 'con token: 200, no-store y sin CORS');
    T::eq($call('GET', '/internal/nada')['status'], 404, 'ruta que no existe: 404');
    T::eq($call('DELETE', '/internal/ping')['status'], 405, 'metodo no permitido: 405');
    $noTok = Panel::start($ctx, 'int27n', ['INTERNAL_API_TOKEN' => '']);
    T::eq(Infra::http('GET', $noTok->base . '/internal/ping', ['headers' => $auth])['status'], 503, 'sin INTERNAL_API_TOKEN en el .env: 503 (nunca acepta tokens vacios)');
    foreach (glob("$work/storage/ratelimit/*.json") ?: [] as $f) {
        @unlink($f);
    }
    $lim = Panel::start($ctx, 'int27r', ['INTERNAL_RATE_LIMIT' => '10']);
    $codes = [];
    for ($i = 0; $i < 12; $i++) {
        $codes[] = Infra::http('GET', $lim->base . '/internal/ping', ['headers' => $auth])['status'];
    }
    T::ok(array_slice($codes, 0, 10) === array_fill(0, 10, 200) && $codes[11] === 429, 'limite de tasa por IP: despues de 10 peticiones por hora, 429');
    foreach (glob("$work/storage/ratelimit/*.json") ?: [] as $f) {
        @unlink($f);
    }
    $bad = [];
    for ($i = 0; $i < 12; $i++) {
        $bad[] = Infra::http('GET', $api->base . '/internal/ping', ['headers' => ['Authorization: Bearer mala']])['status'];
    }
    T::ok(array_slice($bad, 0, 10) === array_fill(0, 10, 401) && $bad[10] === 429, 'fuerza bruta: 10 tokens malos y bloqueo (aunque llegue el bueno)');
    T::eq($call('GET', '/internal/ping')['status'], 429, '  tambien el token bueno queda bloqueado hasta que pase la ventana');
    foreach (glob("$work/storage/ratelimit/*.json") ?: [] as $f) {
        @unlink($f);
    }
    // el panel (sesion) no vale para la API interna
    $adm = Panel::user('admin');
    $ap = $api->another();
    $ap->login($adm['email']);
    T::eq(Infra::http('GET', $api->base . '/internal/ping', ['jar' => $ap->jar])['status'], 401, 'una sesion del panel NO vale para /internal (solo el token de servicio)');

    // ------------------------------------------------------------ PATCH con lista blanca
    $L = Fx::lead(['phone_e164' => '+13055550301', 'email' => 'patch@example.com', 'first_name' => 'Pedro', 'state' => null, 'truck_count' => null, 'equipment_types' => [], 'language' => 'en', 'score' => 10, 'temperature' => 'cold', 'usdot' => null, 'cdl_type' => 'standard', 'english_level' => 'yes']);
    $r = $call('PATCH', "/internal/leads/$L", ['name' => 'Otro', 'score' => 100]);
    T::ok($r['status'] === 422 && ($js($r)['error'] ?? '') === 'fields_not_allowed' && in_array('score', $js($r)['fields'], true) && Db::value('SELECT first_name FROM leads WHERE id = ?', [$L]) === 'Pedro' && Db::value('SELECT score FROM leads WHERE id = ?', [$L]) === 10, 'PATCH con campos fuera de la lista blanca (name, score): 422 y NO se aplica nada');
    foreach (['temperature', 'stage', 'source', 'flags', 'consent', 'fmcsa_data', 'id', 'deleted_at'] as $f) {
        T::eq($call('PATCH', "/internal/leads/$L", [$f => 'x'])['status'], 422, "  $f no es actualizable");
    }
    T::eq($call('PATCH', "/internal/leads/$L", [])['status'], 422, 'PATCH vacio: 422');
    $r = $call('PATCH', "/internal/leads/$L", ['state' => 'Texas', 'truck_count' => 4, 'equipment_types' => ['Dry Van', 'reefer'], 'language' => 'es', 'years_experience' => 6, 'best_time' => 'morning']);
    $j = $js($r);
    $row = Db::one('SELECT * FROM leads WHERE id = ?', [$L]);
    T::ok($r['status'] === 200 && $row['state'] === 'TX' && (int) $row['truck_count'] === 4 && json_decode($row['equipment_types'], true) === ['dry-van', 'reefer'] && $row['language'] === 'es' && (int) $row['years_experience'] === 6, 'PATCH valido: estado normalizado, camiones, equipo, idioma y experiencia actualizados');
    T::ok(($j['score'] ?? 0) > 10 && (int) $row['score'] === $j['score'] && $count('lead_events', "lead_id = $L AND type = 'scored'") >= 1 && $count('lead_events', "lead_id = $L AND type = 'lead_updated'") === 1, '  recalcula la puntuacion (sube de 10) y deja los eventos «scored» y «lead_updated»');
    $evp = (string) Db::value("SELECT payload FROM lead_events WHERE lead_id = $L AND type = 'lead_updated'");
    T::ok(str_contains($evp, 'state') && !str_contains($evp, 'TX') && !str_contains($evp, 'dry-van'), '  el evento lista los NOMBRES de los campos, no sus valores');
    $r = $call('PATCH', "/internal/leads/$L", ['state' => 'ZZ', 'truck_count' => 'muchos', 'english_level' => 'perfecto', 'cdl_type' => 'rara']);
    $j = $js($r);
    T::ok($r['status'] === 200 && ($j['rejected']['state'] ?? '') === 'invalid' && ($j['rejected']['truck_count'] ?? '') === 'invalid' && ($j['rejected']['english_level'] ?? '') === 'invalid' && $j['updated'] === [] && Db::value('SELECT state FROM leads WHERE id = ?', [$L]) === 'TX', 'valores invalidos: se rechazan campo a campo y no pisan el dato bueno');
    $r = $call('PATCH', "/internal/leads/$L", ['email' => 'otro@example.com', 'phone' => '+13055550399']);
    T::ok(($js($r)['rejected']['email'] ?? '') === 'already_set' && ($js($r)['rejected']['phone'] ?? '') === 'already_set' && Db::value('SELECT email FROM leads WHERE id = ?', [$L]) === 'patch@example.com', 'correo y telefono solo se completan si estaban vacios (no se pisan)');
    $L2 = Fx::lead(['phone_e164' => null, 'email' => 'sinfono@example.com']);
    T::eq($js($call('PATCH', "/internal/leads/$L2", ['phone' => '+13055550301']))['rejected']['phone'] ?? '', 'phone_in_use', 'telefono que ya tiene OTRO lead: rechazado (phone_in_use)');
    $r = $call('PATCH', "/internal/leads/$L2", ['phone' => '(305) 555-0302']);
    T::ok(Db::value('SELECT phone_e164 FROM leads WHERE id = ?', [$L2]) === '+13055550302' && $js($r)['updated'] === ['phone'], 'telefono nuevo en un lead que no tenia: normalizado a E.164');
    $r = $call('PATCH', "/internal/leads/$L", ['usdot' => '1234567']);
    T::ok(in_array('fmcsa_pending', Db::decode(Db::value('SELECT flags FROM leads WHERE id = ?', [$L])), true) && $count('jobs', "type = 'fmcsa_enrich' AND payload LIKE '%\"lead_id\":$L%'") === 1, 'USDOT nuevo: queda «FMCSA pendiente» y se encola la consulta');
    $r = $call('PATCH', '/internal/leads/99999999', ['state' => 'TX']);
    T::eq($r['status'], 404, 'PATCH a un lead inexistente: 404');
    T::ok(!preg_match('/patch@example|Pedro|3055550301/', $call('PATCH', "/internal/leads/$L", ['city' => 'Houston'])['body']), 'la respuesta del PATCH no devuelve datos personales');
    T::eq($call('PATCH', "/internal/leads/$L", null)['status'], 422, 'PATCH sin cuerpo: 422');
    $r = Infra::http('PATCH', $api->base . "/internal/leads/$L", ['raw' => '{no json', 'headers' => array_merge($auth, ['Content-Type: application/json'])]);
    T::eq($r['status'], 400, 'PATCH con JSON malformado: 400');
    $r = Infra::http('PATCH', $api->base . "/internal/leads/$L", ['raw' => json_encode(['city' => str_repeat('x', 20000)]), 'headers' => array_merge($auth, ['Content-Type: application/json'])]);
    T::eq($r['status'], 413, 'PATCH enorme (mas de 16 KB): 413');

    // ------------------------------------------------------------ consent-check
    $C = Fx::lead(['phone_e164' => '+13055550311', 'email' => 'cc@example.com']);
    Fx::consent($C);
    $cc = static fn(int $id, string $ch) => $js($call('POST', "/internal/leads/$id/consent-check", ['channel' => $ch]));
    $x = $cc($C, 'email');
    T::ok($x['ok'] && $x['allowed'] && $x['basis'] === 'opt_in', 'consent-check email con consentimiento del formulario: permitido');
    T::ok($cc($C, 'sms')['allowed'] && $cc($C, 'call')['allowed'], '  sms y llamada tambien (el texto del sitio los cubre)');
    $x = $cc($C, 'whatsapp');
    T::ok(!$x['allowed'] && $x['reason'] === 'no_whatsapp_consent', '  WhatsApp NO: CONSENT_TEXT_V1 no lo cubre y el lead no escribio primero');
    T::eq($call('POST', "/internal/leads/$C/consent-check", ['channel' => 'telegram'])['status'], 422, 'canal desconocido: 422');
    T::eq($call('POST', '/internal/leads/99999999/consent-check', ['channel' => 'sms'])['status'], 404, 'lead inexistente: 404');
    $x = $call('POST', "/internal/leads/$C/consent-check", ['channel' => 'sms']);
    T::ok(!preg_match('/cc@example|3055550311|Fx/', $x['body']), 'la respuesta no trae datos personales');
    Captacion\Services\Consent::revokeChannels($C, ['sms'], 'stop_sms');
    T::ok(!$cc($C, 'sms')['allowed'] && $cc($C, 'sms')['reason'] === 'stop' && $cc($C, 'email')['allowed'], 'tras STOP de SMS: sms bloqueado, correo no');
    $nb = Fx::lead();
    T::ok(!$cc($nb, 'email')['allowed'] && $cc($nb, 'email')['reason'] === 'no_consent', 'lead sin ningun consentimiento: nada permitido');

    // ------------------------------------------------------------ events (REST)
    $E = Fx::lead(['phone_e164' => '+13055550320', 'email' => 'ev@example.com']);
    $ev = static fn(array $b, ?string $key = null) => $call('POST', "/internal/leads/$E/events", $b, $key !== null ? array_merge($auth, ['Idempotency-Key: ' . $key]) : null);
    T::eq($ev(['event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a1_welcome'])['status'], 422, 'events sin event_id ni Idempotency-Key: 422');
    $r = $ev(['event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a1_welcome', 'event_id' => 'ev-aaaaaaaa']);
    T::ok($r['status'] === 200 && $count('messages', "lead_id = $E AND direction = 'out'") === 1, 'events message_sent: guarda el mensaje');
    $r = $ev(['event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a1_welcome', 'event_id' => 'ev-aaaaaaaa']);
    T::ok(!empty($js($r)['duplicate']) && $count('messages', "lead_id = $E AND direction = 'out'") === 1, '  el mismo event_id: no se repite');
    $ev(['event' => 'message_sent', 'channel' => 'sms', 'template' => 'x1'], 'idem-key-12345');
    $ev(['event' => 'message_sent', 'channel' => 'sms', 'template' => 'x1'], 'idem-key-12345');
    T::eq($count('messages', "lead_id = $E AND channel = 'sms'"), 1, '  la cabecera Idempotency-Key tambien vale');
    T::eq($ev(['event' => 'hackear', 'event_id' => 'ev-bbbbbbbb'])['status'], 422, 'evento desconocido: 422');

    // ------------------------------------------------------------ by-phone y handoff-ready
    $r = $js($call('GET', '/internal/leads/by-phone?phone=' . rawurlencode('(305) 555-0320')));
    T::ok($r['found'] === true && $r['lead_id'] === $E && array_keys($r) === ['ok', 'found', 'lead_id', 'language'], 'by-phone: devuelve solo id e idioma');
    T::eq($js($call('GET', '/internal/leads/by-phone?phone=3055559999'))['found'], false, '  numero desconocido: found false');
    $r = $call('POST', "/internal/leads/$E/handoff-ready", ['summary' => 'Tiene 3 camiones reefer y quiere hablar hoy']);
    T::ok($r['status'] === 200 && $js($r)['task_created'] === true && $count('tasks', "lead_id = $E AND title LIKE 'Llamar a %'") === 1 && $count('lead_events', "lead_id = $E AND type = 'handoff_ready'") === 1 && $count('jobs', "type = 'telegram_notice' AND payload LIKE '%handoff_ready%'") === 1, 'handoff-ready: tarea de llamada, evento y aviso a Carlos');
    $r = $call('POST', "/internal/leads/$E/handoff-ready", ['summary' => 'otra vez']);
    T::eq($count('tasks', "lead_id = $E AND title LIKE 'Llamar a %'"), 1, '  repetirlo no acumula tareas');
    T::eq((string) Db::value("SELECT stage FROM leads WHERE id = $E"), 'new', '  y no cambia la etapa (eso lo decide Carlos)');

    // ------------------------------------------------------------ sequences/due
    $due = static fn(string $q = 'channel=whatsapp') => $js($call('GET', '/internal/sequences/due?' . $q));
    $W = Fx::lead(['phone_e164' => '+13055550330', 'email' => 'due@example.com', 'first_name' => 'Dora', 'equipment_types' => ['reefer'], 'state' => 'FL', 'temperature' => 'warm']);
    Db::insert('consents', ['lead_id' => $W, 'channel' => 'whatsapp', 'consent_text' => 'Aviso del formulario con WhatsApp', 'consent_version' => 'meta-form', 'granted_at' => Clock::now()]);
    $nw = Fx::lead(['phone_e164' => '+13055550331', 'email' => 'nowa@example.com', 'first_name' => 'Nora']);
    Fx::consent($nw);   // sms, correo y llamada, pero NO WhatsApp
    T::eq($due()['items'], [], 'sequences/due sin pasos habilitados: lista vacia (n8n no envia nada por defecto)');
    T::eq($call('GET', '/internal/sequences/due?channel=email')['status'], 400, 'channel=email: 400 (el correo lo envia el CRM)');
    Settings::set('n8n', ['wa_steps' => ['a1_welcome', 'a6_d3', 'a4_booking', 'appt_reminder'], 'sms_enabled' => false]);
    Settings::clear();
    $items = $due()['items'];
    $ids = array_column($items, 'lead_id');
    T::ok(in_array($W, $ids, true) && !in_array($nw, $ids, true) && !in_array($C, $ids, true), 'con A1 habilitado: solo entran leads con consentimiento de WhatsApp vigente (Dora si; Nora, que solo tiene SMS, no)');
    $it = $items[array_search($W, $ids, true)];
    T::ok($it['sequence'] === 'a1_welcome' && $it['template'] === 'a1_welcome' && $it['to'] === '+13055550330' && $it['vars']['nombre'] === 'Dora' && $it['vars']['equipo'] === 'Reefer' && $it['basis'] === 'opt_in', '  item con plantilla, telefono E.164, variables y base del consentimiento');
    T::ok(!isset($it['email']) && !isset($it['score']) && !isset($it['temperature']) && array_diff(array_keys($it), ['lead_id', 'channel', 'to', 'language', 'basis', 'free_text_allowed', 'vars', 'sequence', 'step', 'template', 'due_at']) === [], '  datos minimos: sin correo, puntuacion ni temperatura');
    $ev2 = $call('POST', "/internal/leads/$W/events", ['event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a1_welcome', 'event_id' => 'sent-a1-dora1']);
    T::ok(!in_array($W, array_column($due()['items'], 'lead_id'), true), 'tras reportar message_sent (a1_welcome), Dora ya no sale en la lista');
    Fx::consent($W);
    Settings::set('mail', ['enabled' => true, 'address' => 'NorthStar LLC, 100 Main St, Lakeland, FL 33801', 'cap_hour' => 20, 'cap_lead' => 1]);
    Settings::clear();
    $enr = (int) Captacion\Services\Sequences::enrollWarm($W);
    T::ok($enr > 0 && !in_array($W, array_column($due()['items'], 'lead_id'), true), 'A6 inscrita pero el dia 1 no esta habilitado y el dia 3 aun no toca: nada');
    Db::run('UPDATE sequence_enrollments SET created_at = ? WHERE id = ?', [Clock::shift(-4 * 86400), $enr]);
    $x = array_values(array_filter($due()['items'], static fn($i) => $i['lead_id'] === $W));
    T::ok(count($x) === 1 && $x[0]['sequence'] === 'a6_warm' && $x[0]['template'] === 'a6_d3' && $x[0]['step'] === 1 && str_contains($x[0]['vars']['link_calculadora'], 'rate-per-mile-calculator'), 'pasados 4 dias: sale el paso del dia 3 de A6 (el dia 1 no esta habilitado y se salta), con el enlace de la calculadora');
    $call('POST', "/internal/leads/$W/events", ['event' => 'sequence_step_sent', 'sequence' => 'a6_warm', 'step' => 1, 'channel' => 'whatsapp', 'event_id' => 'seq-d3-dora1']);
    T::ok((int) Db::value('SELECT wa_step FROM sequence_enrollments WHERE id = ?', [$enr]) === 2 && !in_array($W, array_column($due()['items'], 'lead_id'), true), '  reportado el envio: wa_step avanza y deja de salir (el correo de la secuencia sigue su propio paso)');
    $call('POST', "/internal/leads/$W/events", ['event' => 'sequence_step_sent', 'sequence' => 'a6_warm', 'step' => 0, 'channel' => 'whatsapp', 'event_id' => 'seq-d1-dora1']);
    T::eq((int) Db::value('SELECT wa_step FROM sequence_enrollments WHERE id = ?', [$enr]), 2, '  un paso anterior reportado tarde no hace retroceder wa_step');
    T::eq($call('POST', "/internal/leads/$W/events", ['event' => 'sequence_step_sent', 'sequence' => 'inventada', 'step' => 0, 'channel' => 'whatsapp', 'event_id' => 'seq-x-dora1'])['status'], 422, 'secuencia inventada: 422');
    Captacion\Services\Sequences::pauseForLead($W, 'responded', null);
    Db::run('UPDATE sequence_enrollments SET wa_step = 0 WHERE id = ?', [$enr]);
    T::ok(!in_array($W, array_column($due()['items'], 'lead_id'), true), 'una inscripcion en pausa (el lead respondio) no sale en la lista');
    // WhatsApp cerrado por STOP
    $S = Fx::lead(['phone_e164' => '+13055550332', 'email' => 's@example.com']);
    Db::insert('consents', ['lead_id' => $S, 'channel' => 'whatsapp', 'consent_text' => 'x', 'consent_version' => 'meta-form', 'granted_at' => Clock::now()]);
    T::ok(in_array($S, array_column($due()['items'], 'lead_id'), true), 'otro lead con consentimiento de WhatsApp sale');
    Captacion\Services\Consent::revokeChannels($S, ['sms', 'whatsapp', 'whatsapp_inbound'], 'stop_sms');
    T::ok(!in_array($S, array_column($due()['items'], 'lead_id'), true), '  tras STOP deja de salir');
    $dq = Fx::lead(['phone_e164' => '+13055550333', 'email' => 'dq@example.com', 'temperature' => 'disqualified']);
    Db::insert('consents', ['lead_id' => $dq, 'channel' => 'whatsapp', 'consent_text' => 'x', 'consent_version' => 'meta-form', 'granted_at' => Clock::now()]);
    T::ok(!in_array($dq, array_column($due()['items'], 'lead_id'), true), 'los descartados no reciben la bienvenida (A1)');
    $old = Fx::lead(['phone_e164' => '+13055550334', 'email' => 'old@example.com', 'created_at' => Clock::shift(-10 * 86400)]);
    Db::insert('consents', ['lead_id' => $old, 'channel' => 'whatsapp', 'consent_text' => 'x', 'consent_version' => 'meta-form', 'granted_at' => Clock::now()]);
    T::ok(!in_array($old, array_column($due()['items'], 'lead_id'), true), 'un lead de hace mas de 3 dias no recibe una «bienvenida» tardia al encender A1');
    T::eq($js($call('GET', '/internal/sequences/due?channel=sms'))['items'], [], 'channel=sms sin permiso (A2P sin registrar): lista vacia');
    foreach ([1, 2] as $n) {
        $z = Fx::lead(['phone_e164' => '+1305555036' . $n, 'email' => "lim$n@example.com"]);
        Db::insert('consents', ['lead_id' => $z, 'channel' => 'whatsapp', 'consent_text' => 'x', 'consent_version' => 'meta-form', 'granted_at' => Clock::now()]);
    }
    T::ok(count($due('channel=whatsapp')['items']) >= 2 && count($due('channel=whatsapp&limit=1')['items']) === 1, 'limit=1 se respeta');
    // A4
    $Q = Fx::lead(['phone_e164' => '+13055550335', 'email' => 'q@example.com', 'stage' => 'qualified', 'stage_changed_at' => Clock::now(), 'first_contact_at' => Clock::now(), 'first_name' => 'Quin']);
    Db::insert('consents', ['lead_id' => $Q, 'channel' => 'whatsapp', 'consent_text' => 'x', 'consent_version' => 'meta-form', 'granted_at' => Clock::now()]);
    Db::insert('messages', ['lead_id' => $Q, 'channel' => 'whatsapp', 'direction' => 'out', 'template_key' => 'a1_welcome', 'status' => 'sent', 'created_at' => Clock::now()]);
    T::ok(!in_array($Q, array_column(array_filter($due()['items'], static fn($i) => $i['sequence'] === 'a4_booking'), 'lead_id'), true), 'A4 sin enlace de Cal.com configurado: no sale nada');
    Settings::set('calcom', ['booking_url' => 'https://cal.com/northstar/intro', 'default_profile' => 'owner_operator']);
    Settings::clear();
    $a4 = array_values(array_filter($due()['items'], static fn($i) => $i['sequence'] === 'a4_booking' && $i['lead_id'] === $Q));
    T::ok(count($a4) === 1 && $a4[0]['vars']['booking_url'] === 'https://cal.com/northstar/intro', 'A4 con enlace: el lead Calificado con consentimiento sale con el enlace en las variables');
    $call('POST', "/internal/leads/$Q/events", ['event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a4_booking', 'event_id' => 'a4-quin-0001']);
    T::ok(array_values(array_filter($due()['items'], static fn($i) => $i['sequence'] === 'a4_booking' && $i['lead_id'] === $Q)) === [], '  enviado el mensaje, no se repite');

    // ------------------------------------------------------------ reminders/due
    $R = Fx::lead(['phone_e164' => '+13055550340', 'email' => 'rem@example.com', 'first_name' => 'Remi']);
    Db::insert('consents', ['lead_id' => $R, 'channel' => 'whatsapp', 'consent_text' => 'x', 'consent_version' => 'meta-form', 'granted_at' => Clock::now()]);
    $start = Clock::time() + 20 * 3600;
    $aid = Db::insert('appointments', ['lead_id' => $R, 'starts_at' => Clock::at($start), 'status' => 'booked', 'calcom_id' => 'rem-uid-001', 'source' => 'calcom', 'created_at' => Clock::shift(-3 * 86400)]);
    $rem = static fn() => $js($call('GET', '/internal/reminders/due'))['items'];
    T::ok(count($rem()) === 1 && $rem()[0]['which'] === '24h' && $rem()[0]['appointment_id'] === $aid && $rem()[0]['to'] === '+13055550340' && str_contains($rem()[0]['when_text'], 'America/New_York'), 'reminders/due: a 20 h de la cita, sale el recordatorio de 24 h con la hora en texto');
    $call('POST', "/internal/leads/$R/events", ['event' => 'reminder_sent', 'appointment_id' => $aid, 'which' => '24h', 'channel' => 'whatsapp', 'event_id' => 'rem-24-0001']);
    T::eq($rem(), [], '  reportado el envio, no se repite');
    Db::run('UPDATE appointments SET starts_at = ? WHERE id = ?', [Clock::at(Clock::time() + 2100), $aid]);   // faltan ~35 min: toca el de 1 h (el servidor no ve el reloj simulado, asi que se mueve la cita)
    T::ok(count($rem()) === 1 && $rem()[0]['which'] === '1h', 'a 35 min de la cita sale el de 1 h');
    $aid2 = Db::insert('appointments', ['lead_id' => $R, 'starts_at' => Clock::at(Clock::time() + 20 * 3600), 'status' => 'booked', 'calcom_id' => 'rem-uid-002', 'source' => 'calcom', 'created_at' => Clock::now()]);
    T::ok(!in_array($aid2, array_column($rem(), 'appointment_id'), true), 'cita agendada hace un momento para dentro de 20 h: NO sale un «24 h antes» (no habia tiempo)');
    Db::run("UPDATE appointments SET status = 'cancelled' WHERE id = $aid");
    T::ok(!in_array($aid, array_column($rem(), 'appointment_id'), true), 'cita cancelada: no sale');
    Settings::set('n8n', ['wa_steps' => []]);
    Settings::clear();
    T::eq($rem(), [], 'con A5 deshabilitado para WhatsApp: lista vacia');

    // ------------------------------------------------------------ campana y «Probar»
    Settings::set('n8n', []);
    Settings::clear();
    $relogin = static function (Panel $p) use ($adm): bool {
        Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$adm['id']]);
        return $p->login($adm['email']);
    };
    $ap = $api->another();
    T::ok($relogin($ap), 'login del administrador de prueba');
    $bellHas = static function (string $needle) use ($ap): bool {
        $r = $ap->get('/leads');
        return str_contains($r['body'], $needle);
    };
    foreach (glob("$work/storage/ratelimit/*.json") ?: [] as $f) {
        @unlink($f);
    }
    foreach (['meta', 'calcom', 'twilio', 'n8n'] as $s) {
        foreach (range(1, 3) as $_) {
            Captacion\Services\Integrations::webhook($s, 'err', 'Error de prueba ' . $s);
        }
    }
    T::ok($bellHas('El webhook de Meta Lead Ads falla (3 errores seguidos)') && $bellHas('El webhook de n8n falla'), 'campana: aviso cuando un webhook configurado lleva 3 errores seguidos');
    foreach (['meta', 'calcom', 'twilio', 'n8n'] as $s) {
        Captacion\Services\Integrations::webhook($s, 'ok', 'recuperado');
    }
    T::ok(!$bellHas('El webhook de Meta Lead Ads falla'), '  y el aviso desaparece cuando vuelve a funcionar');
    foreach (range(1, 5) as $_) {
        Captacion\Services\Integrations::webhook('calcom', 'sig');
    }
    T::ok(preg_match('/Firma rechazada \d+ veces en Cal\.com/', $ap->get('/leads')['body']) === 1 && $bellHas('Posible clave mal puesta'), 'campana: aviso de firma rechazada repetidamente («posible clave mal puesta»)');
    $pg = $ap->get('/config?tab=integraciones');
    T::ok($pg['status'] === 200 && str_contains($pg['body'], 'id="int-meta"') && str_contains($pg['body'], '/api/webhooks/meta.php') && str_contains($pg['body'], '/api/webhooks/sms.php') && str_contains($pg['body'], 'data-copy="http://panel.test/api/webhooks/calcom.php"'), 'Integraciones: tarjetas de Meta, Cal.com, Twilio y n8n con la URL del webhook lista para copiar');
    foreach ($secrets as $v) {
        if (strlen($v) > 8) {
            T::ok(!str_contains($pg['body'], $v), '  la pagina no muestra el secreto «' . substr($v, 0, 6) . '…»');
        }
    }
    T::ok(!str_contains($pg['body'], $tok) && !str_contains($ap->get('/config?tab=canales')['body'], 'EAAB-page-token'), '  ni el token interno ni el de pagina de Meta (tampoco en Canales externos)');
    $before = [$count('leads'), $count('appointments')];
    foreach (['meta' => 'Verificación', 'calcom' => 'PING', 'twilio' => 'SMS de prueba', 'n8n' => 'ping'] as $name => $word) {
        $ap->post('/config/integrations/test', ['_csrf' => $ap->csrf, 'name' => $name]);
        $pg2 = $ap->get('/config?tab=integraciones');
        T::ok(str_contains($pg2['body'], 'Firma aceptada') && str_contains($pg2['body'], $word), "«Probar» $name: firma y envia un evento de prueba al propio endpoint y muestra «Firma aceptada»");
        $ap->get('/config?tab=integraciones');   // consume el resultado de la sesion
    }
    T::eq([$count('leads'), $count('appointments')], $before, 'las pruebas no crean leads ni citas');
    T::ok($count('webhook_events', "event_key LIKE 'nstest_%' AND status = 'ignored'") >= 1, '  el evento de prueba de Meta se registra como «ignored»');
    // la URL propia no responde: la prueba lo explica en claro
    $x = Panel::start($ctx, 'int27x', $secrets + ['SELF_BASE_URL' => 'http://127.0.0.1:1']);
    $x2 = $x->another();
    $relogin($x2);
    $x2->post('/config/integrations/test', ['_csrf' => $x2->csrf, 'name' => 'calcom']);
    $pgx = $x2->get('/config?tab=integraciones');
    T::ok(str_contains($pgx['body'], 'no se pudo llegar a http://127.0.0.1:1/api/webhooks/calcom.php') && !str_contains($pgx['body'], 'Firma aceptada'), '«Probar» con la URL propia inalcanzable: dice que no se pudo llegar y que revises APP_URL, no un falso «ok»');
    $none = Panel::start($ctx, 'int27c', ['META_APP_SECRET' => '', 'META_VERIFY_TOKEN' => '', 'META_PAGE_TOKEN' => ''], true);
    $n2 = $none->another();
    $relogin($n2);
    $n2->post('/config/integrations/test', ['_csrf' => $n2->csrf, 'name' => 'meta']);
    $pg4 = $n2->get('/config?tab=integraciones');
    T::ok(str_contains($pg4['body'], 'Sin configurar') && !str_contains($pg4['body'], 'Firma aceptada'), 'sin variables: la tarjeta dice «Sin configurar» y no hay boton «Probar» que mienta');

    foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'TELEGRAM_API_BASE'] as $k) {
        Env::set($k, null);
    }
    foreach (['tg27', 'int27', 'int27n', 'int27r', 'int27x', 'int27c'] as $n) {
        Infra::stop($n);
    }
    foreach (['n8n', 'calcom', 'mail'] as $k) {
        Settings::set($k, []);
    }
    Clock::travel(0);
    Settings::clear();
    T::reset(true);
};
