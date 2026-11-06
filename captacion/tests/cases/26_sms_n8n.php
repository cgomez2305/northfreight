<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\Consent;
use Captacion\Services\Messaging;
use Captacion\Services\Sequences;
use Captacion\Services\Settings;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Q4b-A: Twilio (STOP/START/HELP/respuestas/firma) y n8n.php (HMAC, replay, eventos) + WhatsApp entrante en /api/lead.php. Servidores simulados; ningun servicio real. */
return static function (array $ctx): void {
    T::reset(true);
    foreach (['twilio', 'n8n', 'mail', 'reactivation'] as $k) {
        Settings::set($k, []);
    }
    Settings::clear();
    $work = $ctx['work'];
    $tok = 'twilio-token-abc123';
    $tgLog = "$work/tg26.log";
    @unlink($tgLog);
    $tgPort = Infra::phpServer('tg26', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $tgLog, 'MOCK_MODE_FILE' => "$work/tg26.mode"], $ctx['root'] . '/tests/lib/telegram_mock.php');
    foreach (['TELEGRAM_BOT_TOKEN' => '123456789:FAKE_TEST_TOKEN_not_real_0123456789', 'TELEGRAM_CHAT_ID' => '42', 'TELEGRAM_API_BASE' => "http://127.0.0.1:$tgPort"] as $k => $v) {
        Env::set($k, $v);
    }
    $env = ['TWILIO_TOKEN' => $tok, 'N8N_WEBHOOK_SECRET' => 'n8n-secret-123'];
    $panel = Panel::start($ctx, 'wh26', $env);
    $count = static fn(string $t, string $w = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $w");
    $url = 'http://panel.test/api/webhooks/sms.php';   // la URL que Twilio tiene configurada = APP_URL + ruta
    $twSig = static function (array $p, string $u = '', string $t = 'twilio-token-abc123') use ($url): string {
        ksort($p, SORT_STRING);
        $d = $u !== '' ? $u : $url;
        foreach ($p as $k => $v) {
            $d .= $k . $v;
        }
        return base64_encode(hash_hmac('sha1', $d, $t, true));
    };
    $sid = static fn() => 'SM' . bin2hex(random_bytes(12));
    $sms = static function (array $p, ?string $sig = null) use ($panel, $twSig): array {
        return Infra::http('POST', $panel->base . '/api/webhooks/sms.php', ['raw' => http_build_query($p), 'headers' => ['Content-Type: application/x-www-form-urlencoded', 'X-Twilio-Signature: ' . ($sig ?? $twSig($p))]]);
    };
    $inb = static fn(string $from, string $body, array $x = []) => ['MessageSid' => 'SM' . bin2hex(random_bytes(12)), 'From' => $from, 'To' => '+18635550000', 'Body' => $body, 'AccountSid' => 'ACxx'] + $x;
    $phone = '+13055550201';
    $L = Fx::lead(['phone_e164' => $phone, 'email' => 'sms.lead@example.com', 'first_name' => 'Sara']);
    Fx::consent($L);   // call, sms, email
    Db::insert('consents', ['lead_id' => $L, 'channel' => 'whatsapp', 'consent_text' => 'Aviso de un formulario de Meta que cubre WhatsApp', 'consent_version' => 'meta-form', 'granted_at' => Clock::now()]);
    Settings::set('mail', ['enabled' => true, 'address' => 'NorthStar LLC, 100 Main St, Lakeland, FL 33801', 'cap_hour' => 20, 'cap_lead' => 1]);
    Settings::clear();
    $enr = (int) Sequences::enrollWarm($L);

    // ------------------------------------------------------------ firma de Twilio
    $p = $inb($phone, 'Hola, me interesa');
    T::eq(Infra::http('POST', $panel->base . '/api/webhooks/sms.php', ['raw' => http_build_query($p)])['status'], 401, 'Twilio sin X-Twilio-Signature: 401');
    T::eq($sms($p, 'abcd')['status'], 401, 'Twilio con firma incorrecta: 401');
    T::eq($sms($p, $twSig($p, '', 'otro-token'))['status'], 401, 'Twilio firmado con otro token: 401');
    T::eq($sms($p, $twSig($p, 'http://otro.test/api/webhooks/sms.php'))['status'], 401, 'Twilio firmado para OTRA URL: 401 (la URL forma parte de la firma)');
    $p2 = $p;
    $p2['Body'] = 'STOP';
    T::eq($sms($p2, $twSig($p))['status'], 401, 'Twilio con el cuerpo manipulado tras firmar (cambia Body): 401');
    T::eq($count('messages', "channel = 'sms'") + $count('webhook_events'), 0, '  nada de eso dejo rastro');
    T::eq(Infra::http('POST', $panel->base . '/api/webhooks/sms.php', ['raw' => str_repeat('a=b&', 40000), 'headers' => ['X-Twilio-Signature: x']])['status'], 413, 'cuerpo enorme: 413');
    T::eq(Infra::http('GET', $panel->base . '/api/webhooks/sms.php')['status'], 405, 'GET: 405');
    $ping = ['MessageSid' => $sid(), 'From' => '+15005550006', 'To' => '+10000000000', 'Body' => 'NSTEST-PING', 'AccountSid' => 'ACnstest'];
    $r = $sms($ping);
    T::ok($r['status'] === 200 && str_contains($r['body'], '<Response') && $count('messages') === 0 && $count('webhook_events') === 0, 'mensaje de prueba NSTEST-PING firmado: TwiML valido y sin ningun efecto');

    // ------------------------------------------------------------ respuesta normal (A7)
    $reply = $inb($phone, 'Yes please call me tomorrow <b>morning</b>');
    $r = $sms($reply);
    T::ok($r['status'] === 200 && str_contains(Infra::header($r, 'content-type'), 'xml') && str_starts_with($r['body'], '<?xml') && str_contains($r['body'], '<Response'), 'respuesta: 200 con TwiML valido (XML)');
    T::ok($count('messages', "lead_id = $L AND channel = 'sms' AND direction = 'in'") === 1 && $count('lead_events', "lead_id = $L AND type = 'lead_replied'") === 1 && $count('lead_events', "lead_id = $L AND type = 'inbound_message'") === 1, '  guarda el mensaje y deja los eventos «respondio» y «mensaje recibido»');
    T::eq(Db::value('SELECT status FROM sequence_enrollments WHERE id = ?', [$enr]), 'paused', '  A7: las secuencias del lead quedan en pausa');
    T::eq(Db::value('SELECT paused_reason FROM sequence_enrollments WHERE id = ?', [$enr]), 'responded', '  con el motivo «respondio»');
    T::ok(Db::value('SELECT last_inbound_at FROM leads WHERE id = ?', [$L]) !== null && Db::value('SELECT wa_last_inbound_at FROM leads WHERE id = ?', [$L]) === null, '  last_inbound_at se marca; la ventana de WhatsApp NO se abre por un SMS');
    T::eq($count('jobs', "type = 'telegram_notice' AND status = 'pending'"), 1, '  encola el aviso a Carlos por Telegram');
    Fx::runJobs();
    $tg = Infra::readLog($tgLog);
    $txt = (string) ($tg[count($tg) - 1]['body']['text'] ?? '');
    T::ok(str_contains($txt, 'RESPONDIÓ por sms') && str_contains($txt, 'Sara') && str_contains($txt, '&lt;b&gt;morning') && str_contains($txt, '/leads/' . $L), '  el aviso de Telegram dice quien respondio, recorta/escapa el texto y enlaza al lead');
    $r = $sms($reply);
    T::ok($r['status'] === 200 && $count('messages', "lead_id = $L AND direction = 'in'") === 1 && $count('lead_events', "lead_id = $L AND type = 'lead_replied'") === 1, 'el MISMO SMS (mismo MessageSid) dos veces: no duplica mensaje ni eventos');
    $r = $sms($inb('+13055559999', 'hola'));
    T::ok($r['status'] === 200 && $count('messages') === 1, 'respuesta de un numero que no es un lead: 200 y no se guarda nada');

    // ------------------------------------------------------------ STOP (A15)
    $wa = Fx::lead(['phone_e164' => $phone, 'email' => 'sms.dup@example.com', 'first_name' => 'Duplicada']);   // otro lead con el mismo telefono
    Fx::consent($wa);
    Messaging::consentCheck($L, 'whatsapp');
    foreach (['STOP', 'stop', ' Stop. ', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'] as $i => $kw) {
        if ($i > 0) {
            Db::run("UPDATE consents SET revoked_at = NULL, revoked_via = NULL WHERE lead_id IN ($L, $wa)");
            Db::run("UPDATE leads SET flags = '[]' WHERE id IN ($L, $wa)");
        }
        $r = $sms($inb($phone, $kw));
        $rev = $count('consents', "lead_id = $L AND channel IN ('sms','whatsapp') AND revoked_at IS NOT NULL");
        T::ok($r['status'] === 200 && $rev === 2 && !Consent::isGranted($L, 'sms') && !Consent::isGranted($L, 'whatsapp'), '«' . trim($kw) . '»: revoca el consentimiento de SMS y WhatsApp');
    }
    T::ok(Consent::isGranted($L, 'email') && Consent::isGranted($L, 'call') && $count('consents', "lead_id = $L AND channel IN ('email','call') AND revoked_at IS NOT NULL") === 0, '  el correo y la llamada NO se tocan (el correo tiene su propia baja)');
    T::ok(!Consent::isGranted($wa, 'sms') && in_array('stop_sms', Db::decode(Db::value('SELECT flags FROM leads WHERE id = ?', [$wa])), true), '  tambien en los OTROS leads con el mismo telefono');
    T::ok(str_contains($sms($inb($phone, 'STOP'))['body'], '<Response></Response>') || str_contains($sms($inb($phone, 'STOP'))['body'], '<Response/>'), '  respuesta TwiML vacia (Twilio manda su propia confirmacion)');
    $cc = Messaging::consentCheck($L, 'sms');
    T::ok(!$cc['allowed'] && $cc['reason'] === 'stop' && $count('lead_events', "lead_id = $L AND type = 'consent_revoked'") >= 1, '  consent-check: sms no permitido por STOP, y hay evento de revocacion');
    $r = $sms($inb($phone, 'please stop calling me'));
    T::ok(Consent::isGranted($L, 'call') && $count('lead_events', "lead_id = $L AND type = 'lead_replied'") >= 2, '«please stop calling me» (frase, no palabra sola) es una respuesta normal: pausa y avisa, y Carlos decide');
    $r = $sms($inb($phone, 'whatever', ['OptOutType' => 'STOP']));
    T::eq($count('consents', "lead_id = $L AND channel = 'sms' AND revoked_at IS NOT NULL"), 1, 'OptOutType=STOP de Twilio se obedece aunque el texto sea otro');

    // ------------------------------------------------------------ START solo SMS
    Db::run("UPDATE consents SET revoked_at = NULL WHERE lead_id = $L AND channel = 'sms'");
    $m = static fn() => Db::all("SELECT channel FROM consents WHERE lead_id = $L AND revoked_at IS NULL ORDER BY channel");
    $sms($inb($phone, 'STOP'));
    $r = $sms($inb($phone, 'START'));
    T::ok(Consent::isGranted($L, 'sms') && !Consent::isGranted($L, 'whatsapp') && $count('lead_events', "lead_id = $L AND type = 'consent_restored'") === 1, 'START: restablece SOLO el SMS (WhatsApp sigue revocado) y deja el evento');
    T::ok($count('consents', "lead_id = $L AND channel = 'sms' AND consent_version = 'sms-start-1'") === 1, '  con un registro de consentimiento nuevo para auditoria');
    $sms($inb($phone, 'STOP'));
    foreach (['YES', 'UNSTOP'] as $kw) {
        $sms($inb($phone, $kw));
        T::ok(Consent::isGranted($L, 'sms'), "$kw tambien restablece el SMS");
        $sms($inb($phone, 'STOP'));
    }
    $np = Fx::lead(['phone_e164' => '+13055550202', 'email' => 'nunca@example.com']);   // nunca dio consentimiento de SMS
    $sms($inb('+13055550202', 'START'));
    T::ok($count('consents', "lead_id = $np") === 0 && $count('lead_events', "lead_id = $np AND type = 'consent_restored'") === 0, 'START de un lead que NUNCA dio consentimiento de SMS: no crea consentimiento');
    $L2 = Fx::lead(['phone_e164' => '+13055550203', 'email' => 'stopall@example.com']);
    Fx::consent($L2);
    Db::run("UPDATE leads SET flags = '[\"stop\"]' WHERE id = $L2");
    $sms($inb('+13055550203', 'START'));
    T::ok(!Consent::isGranted($L2, 'sms'), 'START no levanta un STOP total («stop») puesto a mano');

    // ------------------------------------------------------------ HELP
    $r = $sms($inb($phone, 'HELP'));
    T::ok($r['status'] === 200 && str_contains($r['body'], '<Message>') && str_contains($r['body'], 'Reply STOP to opt out') && str_contains($r['body'], '863-651-3322'), 'HELP: TwiML con el texto de ayuda por defecto (baja con STOP y contacto)');
    Settings::set('twilio', ['help_text' => 'Texto <propio> & "ayuda"']);
    Settings::clear();
    $r = $sms($inb($phone, 'help'));
    T::ok(str_contains($r['body'], 'Texto &lt;propio&gt; &amp; &quot;ayuda&quot;') && !str_contains($r['body'], '<propio>'), 'HELP con texto configurado: se escapa para XML');
    $r = $sms($inb('+13055559998', 'HELP'));
    T::ok($r['status'] === 200 && str_contains($r['body'], '<Message>'), 'HELP de un numero desconocido tambien se contesta');

    // ------------------------------------------------------------ Estado de entrega
    Db::insert('messages', ['lead_id' => $L, 'channel' => 'sms', 'direction' => 'out', 'provider_id' => 'SMdeliver0001', 'status' => 'sent', 'created_at' => Clock::now()]);
    $st = ['MessageSid' => 'SMdeliver0001', 'MessageStatus' => 'delivered', 'To' => $phone];
    $r = $sms($st);
    T::ok($r['status'] === 200 && Db::value("SELECT status FROM messages WHERE provider_id = 'SMdeliver0001'") === 'delivered', 'devolucion de estado (MessageStatus=delivered): actualiza el mensaje');
    $sms($st);
    T::eq($count('webhook_events', "event_key LIKE 'st:SMdeliver0001%'"), 1, '  y repetirla no se procesa dos veces');

    // ------------------------------------------------------------ n8n.php
    $sec = 'n8n-secret-123';
    $n8n = static function (array $body, ?int $ts = null, ?string $sig = null, string $secret = 'n8n-secret-123') use ($panel): array {
        $raw = (string) json_encode($body);
        $t = (string) ($ts ?? time());
        return Infra::http('POST', $panel->base . '/api/webhooks/n8n.php', ['raw' => $raw, 'headers' => ['Content-Type: application/json', 'X-NS-Timestamp: ' . $t, 'X-NS-Signature: ' . ($sig ?? 'sha256=' . hash_hmac('sha256', $t . '.' . $raw, $secret))]]);
    };
    $N = Fx::lead(['phone_e164' => '+13055550210', 'email' => 'n8n.lead@example.com', 'first_name' => 'Nico']);
    $ev = fn(array $x) => $x + ['event_id' => 'evt-' . bin2hex(random_bytes(5)), 'lead_id' => $N];
    T::eq(Infra::http('POST', $panel->base . '/api/webhooks/n8n.php', ['raw' => '{}'])['status'], 401, 'n8n sin firma: 401');
    T::eq($n8n($ev(['event' => 'message_sent']), null, 'sha256=' . str_repeat('0', 64))['status'], 401, 'n8n con firma incorrecta: 401');
    T::eq($n8n($ev(['event' => 'message_sent']), null, null, 'otra-clave')['status'], 401, 'n8n firmado con otra clave: 401');
    T::eq($n8n($ev(['event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a1_welcome']), time() - 1000)['status'], 401, 'n8n con marca de tiempo vieja (replay a mas de 5 min): 401');
    T::eq($n8n($ev(['event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a1_welcome']), time() + 1000)['status'], 401, 'n8n con marca de tiempo del futuro: 401');
    T::eq($count('messages', "lead_id = $N"), 0, '  nada dejo rastro');
    $r = $n8n(['event' => 'ping']);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'test'), 'n8n ping firmado: 200 sin efectos');
    $e1 = $ev(['event' => 'message_sent', 'channel' => 'whatsapp', 'template' => 'a1_welcome', 'message_id' => 'wamid.HBgL123']);
    $r = $n8n($e1);
    T::ok($r['status'] === 200 && $count('messages', "lead_id = $N AND direction = 'out' AND template_key = 'a1_welcome' AND provider_id = 'wamid.HBgL123'") === 1 && $count('lead_events', "lead_id = $N AND type = 'message_sent'") === 1, 'message_sent: guarda el mensaje saliente y el evento');
    $r = $n8n($e1);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'duplicate') && $count('messages', "lead_id = $N AND direction = 'out'") === 1, '  el mismo event_id dos veces: no duplica (idempotente)');
    T::eq($n8n(['event' => 'message_sent', 'lead_id' => $N, 'channel' => 'whatsapp', 'template' => 'x'])['status'], 422, 'sin event_id: 422');
    T::eq($n8n($ev(['event' => 'hackear']))['status'], 422, 'evento desconocido: 422');
    T::eq($n8n($ev(['event' => 'message_sent', 'lead_id' => 99999999, 'channel' => 'whatsapp', 'template' => 'x']))['status'], 404, 'lead inexistente: 404');
    T::eq($n8n($ev(['event' => 'message_sent', 'channel' => 'telegram', 'template' => 'x']))['status'], 422, 'canal no permitido: 422');
    $r = $n8n($ev(['event' => 'reply_received', 'channel' => 'whatsapp', 'text' => 'Hola, soy yo', 'message_id' => 'wamid.IN1']));
    T::ok($r['status'] === 200 && $count('messages', "lead_id = $N AND direction = 'in' AND channel = 'whatsapp'") === 1 && $count('consents', "lead_id = $N AND channel = 'whatsapp_inbound'") === 1 && Db::value('SELECT wa_last_inbound_at FROM leads WHERE id = ?', [$N]) !== null, 'reply_received por WhatsApp: guarda el mensaje, abre la ventana de 24 h y registra whatsapp_inbound');
    T::ok(!Consent::isGranted($N, 'whatsapp'), '  SIN consentimiento de marketing (isGranted whatsapp = false)');
    $cc = Messaging::consentCheck($N, 'whatsapp');
    T::ok($cc['allowed'] && $cc['basis'] === 'inbound_window' && $cc['free_text_allowed'] && $cc['window_expires_at'] !== null, '  consent-check: permitido por la ventana de 24 h (texto libre)');
    Clock::travel(25 * 3600);
    $cc = Messaging::consentCheck($N, 'whatsapp');
    T::ok(!$cc['allowed'] && $cc['reason'] === 'window_closed' && !$cc['free_text_allowed'], '  pasadas 24 h: ventana cerrada, ya no se puede escribir');
    Clock::travel(0);
    $r = $n8n($ev(['event' => 'bot_finished', 'outcome' => 'needs_human', 'summary' => 'Pregunta por tarifas']));
    T::ok($r['status'] === 200 && $count('tasks', "lead_id = $N AND title LIKE 'Llamar a %' AND done_at IS NULL") === 1 && $count('jobs', "type = 'telegram_notice' AND payload LIKE '%bot_needs_human%'") === 1, 'bot_finished (needs_human): tarea «Llamar a…» y aviso a Carlos');
    $n8n($ev(['event' => 'bot_finished', 'outcome' => 'qualified']));
    T::eq($count('tasks', "lead_id = $N AND title LIKE 'Llamar a %'"), 1, '  no se acumulan tareas de llamada repetidas');
    $r = $n8n($ev(['event' => 'bot_finished', 'outcome' => 'inventado']));
    T::eq($r['status'], 422, 'bot_finished con resultado invalido: 422');
    $n8n($ev(['event' => 'bot_finished', 'outcome' => 'opted_out']));
    T::ok(!Messaging::consentCheck($N, 'whatsapp')['allowed'], 'bot_finished opted_out: WhatsApp queda bloqueado');
    $r = $n8n($ev(['event' => 'error', 'workflow' => 'A1', 'error' => 'Fallo con el token ' . $tok]));
    Settings::clear();
    $last = Captacion\Services\Integrations::last('n8n');
    T::ok($r['status'] === 200 && ($last['last'] ?? '') === 'err' && !str_contains(json_encode($last) . json_encode(Db::all('SELECT payload FROM lead_events WHERE lead_id = ?', [$N])), $tok), 'evento error: queda en el estado de la integracion y no filtra secretos del .env');

    // ------------------------------------------------------------ WhatsApp entrante por /api/lead.php
    T::reset();
    Settings::set('mail', []);
    Settings::clear();
    $api = Panel::start($ctx, 'wh26a', $env + ['API_RATE_LIMIT' => '500']);
    $post = static fn(array $b) => Infra::http('POST', $api->base . '/api/lead.php', ['json' => $b, 'headers' => ['Authorization: Bearer ' . $ctx['env']['INTERNAL_API_TOKEN']]]);
    $lead = ['profile' => 'owner_operator', 'name' => 'Wa Primero', 'phone' => '+13055550220', 'source' => 'n8n_whatsapp', 'inbound' => ['channel' => 'whatsapp', 'text' => 'Hi, I need a dispatcher', 'at' => gmdate('c', time() - 60), 'message_id' => 'wamid.FIRST1']];
    $r = $post($lead);
    $j = json_decode($r['body'], true);
    $id = (int) ($j['lead_id'] ?? 0);
    T::ok($r['status'] === 201 && ($j['inbound_recorded'] ?? false) === true, 'lead.php con «inbound» de WhatsApp: 201 y mensaje registrado');
    $c = Db::one("SELECT * FROM consents WHERE lead_id = ? AND channel = 'whatsapp_inbound'", [$id]);
    T::ok($c !== null && str_contains($c['consent_text'], 'Hi, I need a dispatcher') && $c['consent_version'] === 'wa-inbound-1' && abs(strtotime($c['granted_at'] . ' UTC') - (time() - 60)) < 3, '  consents canal whatsapp_inbound con el texto y la hora del primer mensaje');
    T::ok(!Consent::isGranted($id, 'whatsapp') && !Consent::isGranted($id, 'sms') && !Consent::isGranted($id, 'email') && (int) Db::value("SELECT COUNT(*) FROM consents WHERE lead_id = $id AND channel IN ('sms','email','call','whatsapp')") === 0, '  SIN consentimiento de marketing en ningun canal');
    T::ok(Messaging::consentCheck($id, 'whatsapp')['allowed'] && !Messaging::consentCheck($id, 'sms')['allowed'] && !Messaging::consentCheck($id, 'email')['allowed'], '  consent-check: solo WhatsApp (ventana abierta); SMS y correo no');
    $r = $post($lead);
    T::ok($r['status'] === 200 && (int) Db::value("SELECT COUNT(*) FROM messages WHERE lead_id = $id") === 1 && (int) Db::value("SELECT COUNT(*) FROM consents WHERE lead_id = $id") === 1, 'el mismo mensaje (message_id) reenviado: no duplica mensaje ni consentimiento');
    $lead2 = $lead;
    $lead2['inbound'] = ['channel' => 'whatsapp', 'text' => 'Segundo mensaje', 'message_id' => 'wamid.SECOND2'];
    $post($lead2);
    T::ok((int) Db::value("SELECT COUNT(*) FROM messages WHERE lead_id = $id AND direction = 'in'") === 2 && (int) Db::value("SELECT COUNT(*) FROM consents WHERE lead_id = $id") === 1 && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = $id AND type = 'lead_replied'") === 1, 'un segundo mensaje de un lead existente: lo guarda, SIN otro consentimiento, y avisa que respondio');
    $r = $post(['inbound' => ['channel' => 'telegram', 'text' => 'x']] + $lead + ['phone' => '+13055550221', 'name' => 'Canal Raro']);
    T::ok(($j2 = json_decode($r['body'], true)) && ($j2['inbound_recorded'] ?? null) === false, 'canal de «inbound» no permitido: el lead se crea pero el mensaje no se registra (inbound_recorded false)');

    Env::set('TELEGRAM_BOT_TOKEN', null);
    Env::set('TELEGRAM_CHAT_ID', null);
    Env::set('TELEGRAM_API_BASE', null);
    foreach (['tg26', 'wh26', 'wh26a'] as $n) {
        Infra::stop($n);
    }
    foreach (['twilio', 'n8n', 'mail'] as $k) {
        Settings::set($k, []);
    }
    Settings::clear();
    T::reset(true);
};
