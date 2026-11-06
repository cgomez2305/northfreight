<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\Appointments;
use Captacion\Services\Consent;
use Captacion\Services\MetaLeads;
use Captacion\Services\Settings;
use Captacion\Services\Webhook;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Q4b-A: webhooks de Meta Lead Ads y Cal.com (firma, idempotencia, limites, e2e, eventos desordenados). Todo con servidores simulados y fixtures: ningun servicio real. */
return static function (array $ctx): void {
    T::reset(true);
    Settings::set('meta', []);
    Settings::set('calcom', []);
    Settings::clear();
    $work = $ctx['work'];
    $secrets = ['META_APP_SECRET' => 'meta-app-secret-123', 'META_VERIFY_TOKEN' => 'verify-token-xyz', 'META_PAGE_TOKEN' => 'EAAB-page-token-SECRETO-999', 'CALCOM_WEBHOOK_SECRET' => 'cal-secret-123'];
    $graphDir = "$work/graph25";
    @mkdir($graphDir, 0777, true);
    $gLog = "$work/graph25.log";
    $gMode = "$work/graph25.mode";
    @unlink($gLog);
    file_put_contents($gMode, 'ok');
    $gPort = Infra::phpServer('graph25', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $gLog, 'MOCK_MODE_FILE' => $gMode, 'GRAPH_DIR' => $graphDir], $ctx['root'] . '/tests/lib/graph_mock.php');
    $tgLog = "$work/tg25.log";
    @unlink($tgLog);
    $tgPort = Infra::phpServer('tg25', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $tgLog, 'MOCK_MODE_FILE' => "$work/tg25.mode"], $ctx['root'] . '/tests/lib/telegram_mock.php');
    $smtpLog = "$work/smtp25.log";
    @unlink($smtpLog);
    $smtpPort = Infra::smtpMock('smtp25', $smtpLog);
    $env = $secrets + ['META_GRAPH_BASE' => "http://127.0.0.1:$gPort"];
    foreach ($env + ['TELEGRAM_BOT_TOKEN' => '123456789:FAKE_TEST_TOKEN_not_real_0123456789', 'TELEGRAM_CHAT_ID' => '42', 'TELEGRAM_API_BASE' => "http://127.0.0.1:$tgPort"] as $k => $v) {
        Env::set($k, $v);
    }
    Fx::smtp($smtpPort);
    $panel = Panel::start($ctx, 'wh25', $env);
    $sign = static fn(string $body, string $secret = 'meta-app-secret-123'): string => 'sha256=' . hash_hmac('sha256', $body, $secret);
    $count = static fn(string $t, string $w = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $w");
    $metaPost = static fn(string $body, array $h = []) => Infra::http('POST', $panel->base . '/api/webhooks/meta.php', ['raw' => $body, 'headers' => array_merge(['Content-Type: application/json'], $h)]);
    $event = static fn(string $id, string $form = '555') => (string) json_encode(['object' => 'page', 'entry' => [['id' => '999', 'time' => time(), 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => $id, 'form_id' => $form, 'ad_id' => '777', 'page_id' => '999', 'created_time' => time()]]]]]]);
    $leadFixture = static fn(string $id, array $fields, array $extra = []) => file_put_contents("$graphDir/$id.json", json_encode($extra + ['id' => $id, 'created_time' => gmdate('Y-m-d\TH:i:s+0000', time() - 120), 'ad_id' => '777', 'ad_name' => 'Anuncio Reefer Texas', 'adset_name' => 'Conjunto A', 'campaign_name' => 'Campaña Otoño', 'form_id' => '555', 'platform' => 'facebook', 'field_data' => $fields]));
    $fd = static fn(string $n, string ...$v) => ['name' => $n, 'values' => $v];

    // ============================================================ META: verificacion (GET)
    $g = static fn(string $qs) => Infra::http('GET', $panel->base . '/api/webhooks/meta.php?' . $qs);
    $r = $g('hub.mode=subscribe&hub.verify_token=verify-token-xyz&hub.challenge=1158201444');
    T::ok($r['status'] === 200 && $r['body'] === '1158201444' && str_contains(Infra::header($r, 'content-type'), 'text/plain'), 'Meta GET: token correcto responde el challenge en texto plano');
    T::eq($g('hub.mode=subscribe&hub.verify_token=otro&hub.challenge=123')['status'], 401, 'Meta GET: token de verificacion incorrecto: 401 (no se devuelve el challenge)');
    T::eq($g('hub.mode=unsubscribe&hub.verify_token=verify-token-xyz&hub.challenge=123')['status'], 401, 'Meta GET: modo distinto de subscribe: 401');
    T::eq($g('hub.verify_token=verify-token-xyz&hub.challenge=123')['status'], 401, 'Meta GET: sin hub.mode: 401');
    T::eq($g('hub.mode=subscribe&hub.verify_token=verify-token-xyz&hub.challenge=%3Cscript%3E')['status'], 400, 'Meta GET: un challenge con caracteres raros se rechaza (400), no se refleja');
    T::eq(Infra::http('PUT', $panel->base . '/api/webhooks/meta.php')['status'], 405, 'Meta: PUT no existe (405)');

    // ============================================================ META: firma y cuerpo (POST)
    $body = $event('9000000001');
    $r = $metaPost($body);
    T::ok($r['status'] === 401 && $count('jobs', "type = 'meta_fetch_lead'") === 0 && $count('webhook_events') === 0, 'Meta POST sin firma: 401 y no se encola ni registra nada');
    T::eq($metaPost($body, ['X-Hub-Signature-256: sha256=' . str_repeat('0', 64)])['status'], 401, 'Meta POST con firma incorrecta: 401');
    T::eq($metaPost($body, ['X-Hub-Signature-256: ' . substr($sign($body), 7)])['status'], 200, 'Meta POST: la firma sin el prefijo «sha256=» tambien se acepta (hex valido)');
    Db::run('TRUNCATE TABLE jobs');
    Db::run('TRUNCATE TABLE webhook_events');
    T::eq($metaPost($body, ['X-Hub-Signature-256: ' . $sign($body, 'otra-clave')])['status'], 401, 'Meta POST firmado con OTRA clave: 401');
    T::eq($metaPost($body . ' ', ['X-Hub-Signature-256: ' . $sign($body)])['status'], 401, 'Meta POST con el cuerpo manipulado tras firmar (un espacio de mas): 401');
    T::eq($metaPost($body, ['X-Hub-Signature-256: sha256=zz'])['status'], 401, 'Meta POST con firma mal formada: 401');
    T::eq($count('jobs') + $count('webhook_events'), 0, '  ninguna de esas peticiones dejo rastro');
    $r = $metaPost($body, ['X-Hub-Signature-256: ' . $sign($body)]);
    $j = json_decode($r['body'], true);
    T::ok($r['status'] === 200 && ($j['queued'] ?? 0) === 1 && $count('jobs', "type = 'meta_fetch_lead' AND status = 'pending'") === 1, 'Meta POST firmado: 200 y un job meta_fetch_lead en cola (' . $r['body'] . ')');
    $r = $metaPost($body, ['X-Hub-Signature-256: ' . $sign($body)]);
    $j = json_decode($r['body'], true);
    T::ok($r['status'] === 200 && ($j['duplicates'] ?? 0) === 1 && ($j['queued'] ?? 1) === 0 && $count('jobs', "type = 'meta_fetch_lead'") === 1, 'el MISMO evento dos veces no duplica el job (idempotente por leadgen_id)');
    $bad = '{no es json';
    T::eq($metaPost($bad, ['X-Hub-Signature-256: ' . $sign($bad)])['status'], 400, 'cuerpo firmado pero JSON malformado: 400');
    $other = (string) json_encode(['object' => 'instagram', 'entry' => []]);
    $r = $metaPost($other, ['X-Hub-Signature-256: ' . $sign($other)]);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'ignored'), 'objeto distinto de «page»: 200 ignorado');
    $weird = (string) json_encode(['object' => 'page', 'entry' => [['changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => '../../etc/passwd']], ['field' => 'feed', 'value' => []], 'x', ['field' => 'leadgen']]], 'basura', null]]);
    $r = $metaPost($weird, ['X-Hub-Signature-256: ' . $sign($weird)]);
    T::ok($r['status'] === 200 && (json_decode($r['body'], true)['received'] ?? 1) === 0, 'estructuras raras o leadgen_id con caracteres peligrosos: se ignoran sin error');
    $huge = (string) json_encode(['object' => 'page', 'entry' => [], 'pad' => str_repeat('x', 140000)]);
    T::eq($metaPost($huge, ['X-Hub-Signature-256: ' . $sign($huge)])['status'], 413, 'cuerpo de mas de 128 KB: 413');
    $test = $event('nstest_abc123');
    $r = $metaPost($test, ['X-Hub-Signature-256: ' . $sign($test)]);
    T::ok($r['status'] === 200 && $count('jobs', "type = 'meta_fetch_lead'") === 1 && $count('webhook_events', "event_key = 'nstest_abc123' AND status = 'ignored'") === 1, 'evento de prueba (nstest_…): se registra y NO encola nada');

    // ============================================================ META: job que consulta Graph y crea el lead
    $leadFixture('9000000001', [$fd('full_name', 'Luis Ortega'), $fd('email', 'luis.meta@example.com'), $fd('phone_number', 'p:+13055550142'), $fd('state', 'Florida'), $fd('what_type_of_equipment_do_you_run?', 'Reefer; Dry Van'),
        $fd('how_many_trucks_do_you_have?', '3 trucks'), $fd('usdot_number', '1234567'), $fd('preferred_language', 'Español')]);
    Settings::set('meta', ['default_profile' => 'owner_operator', 'map' => [['from' => 'what_type_of_equipment_do_you_run?', 'to' => 'equipment'], ['from' => 'how_many_trucks_do_you_have?', 'to' => 'trucks'], ['from' => 'usdot_number', 'to' => 'usdot'], ['from' => 'preferred_language', 'to' => 'language']]]);
    Settings::clear();
    $fm = new Captacion\Services\Fmcsa(static function (string $u) use ($ctx): array {
        $d = $ctx['root'] . '/tests/fixtures/fmcsa/';
        return str_contains($u, '/authority') ? ['status' => 200, 'body' => str_replace('__GRANT__', gmdate('Y-m-d', strtotime('-12 months -2 days')), (string) file_get_contents($d . 'authority_active.json')), 'error' => ''] : ['status' => 200, 'body' => (string) file_get_contents($d . 'carrier_active.json'), 'error' => ''];
    }, 'k');
    Captacion\Services\JobHandlers::$fmcsa = $fm;
    $st = Fx::runJobs();
    $lead = Db::one("SELECT * FROM leads WHERE email = 'luis.meta@example.com'");
    $lead = $lead ? Lead::hydrate($lead) : null;
    T::ok($lead !== null && $lead['source'] === 'meta_ads' && $lead['site_lead_id'] === 'meta_9000000001' && $lead['phone_e164'] === '+13055550142' && $lead['state'] === 'FL' && $lead['language'] === 'es', 'job Meta: lead creado con fuente meta_ads, telefono E.164 (sin «p:»), estado por nombre e idioma');
    T::ok($lead !== null && $lead['equipment_types'] === ['reefer', 'dry-van'] && $lead['truck_count'] === 3 && $lead['usdot'] === '1234567' && $lead['profile'] === 'owner_operator', '  equipo normalizado, camiones desde «3 trucks», USDOT y perfil por defecto');
    T::ok($lead !== null && ($lead['utm']['utm_source'] ?? '') === 'meta' && ($lead['utm']['utm_medium'] ?? '') === 'facebook' && ($lead['utm']['utm_campaign'] ?? '') === 'Campaña Otoño' && ($lead['utm']['utm_content'] ?? '') === 'Anuncio Reefer Texas' && ($lead['utm']['utm_term'] ?? '') === 'form:555' && $lead['landing_page'] === 'meta_lead_ad:form_555', '  UTM con campana, anuncio y formulario; pagina de llegada del anuncio');
    T::ok($lead !== null && $count('consents', 'lead_id = ' . (int) $lead['id']) === 0, '  SIN consentimiento de mensajeria: el formulario no lo trae');
    T::ok($lead !== null && $lead['score'] > 0 && $lead['temperature'] !== '' && $count('lead_events', "type IN ('created','scored','fmcsa') AND lead_id = " . (int) $lead['id']) >= 3, '  mismos pasos del motor: puntuado, FMCSA consultada (fixture) y eventos ' . json_encode(array_column(Db::all('SELECT type FROM lead_events WHERE lead_id = ?', [(int) ($lead['id'] ?? 0)]), 'type')));
    T::ok($lead !== null && ($lead['temperature'] === 'hot' ? $count('jobs', "type = 'telegram_alert' AND payload LIKE '%\"lead_id\":" . (int) $lead['id'] . "%'") === 1 : $count('jobs', "type = 'email_notify'") >= 1), '  alertas segun la temperatura (' . ($lead['temperature'] ?? '?') . ')');
    $gl = Infra::readLog($gLog);
    T::ok(count($gl) === 1 && ($gl[0]['query']['access_token'] ?? '') === $secrets['META_PAGE_TOKEN'] && str_contains((string) ($gl[0]['query']['fields'] ?? ''), 'field_data'), '  la consulta a Graph lleva el token de pagina y los campos pedidos');
    T::ok($count('webhook_events', "source = 'meta' AND event_key = '9000000001' AND status = 'processed' AND lead_id = " . (int) ($lead['id'] ?? 0)) === 1, '  el evento queda «processed» con su lead');
    // Reproceso del mismo lead: sin duplicados
    Captacion\Services\Queue::push('meta_fetch_lead', ['leadgen_id' => '9000000001'], Clock::now(), 'otro-job-manual');
    Fx::runJobs();
    T::eq($count('leads', "email = 'luis.meta@example.com'"), 1, 'reprocesar el mismo leadgen_id no crea un segundo lead (site_lead_id unico)');

    // Consentimiento SOLO con texto + canales + pregunta mapeada
    $leadFixture('9000000002', [$fd('full_name', 'Rosa Diaz'), $fd('email', 'rosa.meta@example.com'), $fd('phone_number', '+13055550143'), $fd('accept_contact', 'Yes')]);
    Settings::set('meta', ['map' => [['from' => 'accept_contact', 'to' => 'consent']], 'consent_text' => '', 'consent_channels' => ['call', 'sms']]);
    Settings::clear();
    $b2 = $event('9000000002');
    $metaPost($b2, ['X-Hub-Signature-256: ' . $sign($b2)]);
    Fx::runJobs();
    $rosa = (int) Db::value("SELECT id FROM leads WHERE email = 'rosa.meta@example.com'");
    T::eq($count('consents', "lead_id = $rosa"), 0, 'pregunta de consentimiento marcada pero SIN texto del aviso configurado: no se registra consentimiento');
    $leadFixture('9000000003', [$fd('full_name', 'Pedro Gil'), $fd('email', 'pedro.meta@example.com'), $fd('phone_number', '+13055550144'), $fd('accept_contact', 'Yes')]);
    $leadFixture('9000000004', [$fd('full_name', 'Ana Paz'), $fd('email', 'ana.meta@example.com'), $fd('phone_number', '+13055550145'), $fd('accept_contact', 'No')]);
    Settings::set('meta', ['map' => [['from' => 'accept_contact', 'to' => 'consent']], 'consent_text' => 'Al enviar acepto que NorthStar me llame o me escriba por SMS.', 'consent_channels' => ['call', 'sms']]);
    Settings::clear();
    foreach (['9000000003', '9000000004'] as $i) {
        $bb = $event($i);
        $metaPost($bb, ['X-Hub-Signature-256: ' . $sign($bb)]);
    }
    Fx::runJobs();
    $pedro = (int) Db::value("SELECT id FROM leads WHERE email = 'pedro.meta@example.com'");
    $ana = (int) Db::value("SELECT id FROM leads WHERE email = 'ana.meta@example.com'");
    $ch = array_column(Db::all('SELECT channel, consent_text, consent_version FROM consents WHERE lead_id = ? ORDER BY channel', [$pedro]), null, 'channel');
    T::ok(array_keys($ch) === ['call', 'sms'] && str_contains($ch['sms']['consent_text'], 'acepto que NorthStar') && $ch['sms']['consent_version'] === 'meta-form', 'con texto + canales + respuesta afirmativa: consentimiento solo de los canales configurados (call y sms; correo y WhatsApp NO)');
    T::ok(Consent::isGranted($pedro, 'sms') && !Consent::isGranted($pedro, 'email') && !Consent::isGranted($pedro, 'whatsapp'), '  isGranted: sms si; correo y whatsapp no');
    T::eq($count('consents', "lead_id = $ana"), 0, 'respuesta «No» a la pregunta de consentimiento: sin consentimiento');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type = 'meta_fields_unmapped' AND lead_id = $pedro") === 0, 'sin campos desconocidos no se anota «sin mapear»');
    $leadFixture('9000000005', [$fd('full_name', 'Zoe Ruiz'), $fd('email', 'zoe.meta@example.com'), $fd('pregunta_rara_1', 'algo'), $fd('otra_pregunta', 'otra cosa')]);
    $b5 = $event('9000000005');
    $metaPost($b5, ['X-Hub-Signature-256: ' . $sign($b5)]);
    Fx::runJobs();
    $zoe = (int) Db::value("SELECT id FROM leads WHERE email = 'zoe.meta@example.com'");
    $ev = Db::value("SELECT payload FROM lead_events WHERE type = 'meta_fields_unmapped' AND lead_id = $zoe");
    T::ok($zoe > 0 && is_string($ev) && str_contains($ev, 'pregunta_rara_1') && str_contains($ev, 'otra_pregunta'), 'preguntas sin mapear: el lead entra igual y la linea de tiempo anota cuales fueron');
    // Datos inutilizables: se ignoran sin reintentar
    $leadFixture('9000000006', [$fd('full_name', ''), $fd('email', 'x@mailinator.com')]);
    $b6 = $event('9000000006');
    $metaPost($b6, ['X-Hub-Signature-256: ' . $sign($b6)]);
    $st = Fx::runJobs();
    T::ok($count('leads', "email = 'x@mailinator.com'") === 0 && $count('webhook_events', "event_key = '9000000006' AND status = 'ignored'") === 1 && $count('jobs', "status IN ('failed') ") === 0, 'lead de Meta inutilizable (sin nombre ni contacto valido): queda «ignored», sin reintentos ni fallos');

    // Errores de Graph
    $leadFixture('9000000007', [$fd('full_name', 'Tomas Vera'), $fd('email', 'tomas.meta@example.com')]);
    $b7 = $event('9000000007');
    Settings::set('meta', []);
    Settings::clear();
    file_put_contents($gMode, 'expired');
    $metaPost($b7, ['X-Hub-Signature-256: ' . $sign($b7)]);
    Fx::runJobs();
    $job = Db::one("SELECT status, attempts, last_error FROM jobs WHERE dedupe_key = 'meta_lead:9000000007'");
    T::ok($job['status'] === 'pending' && (int) $job['attempts'] === 1 && str_contains((string) $job['last_error'], '190') && !str_contains((string) $job['last_error'], $secrets['META_PAGE_TOKEN']), 'Graph con token vencido (400/190): el job se reintenta y el mensaje NO trae el token');
    $bell = Captacion\Services\Integrations::last('meta');
    T::ok(($bell['last'] ?? '') === 'err' && (int) ($bell['streak'] ?? 0) >= 1 && !str_contains(json_encode($bell), $secrets['META_PAGE_TOKEN']), '  el error queda en el estado de la integracion (sin secretos)');
    file_put_contents($gMode, 'error500');
    Db::run("UPDATE jobs SET run_at = ? WHERE dedupe_key = 'meta_lead:9000000007'", [Clock::shift(-5)]);
    Fx::runJobs();
    T::eq((int) Db::value("SELECT attempts FROM jobs WHERE dedupe_key = 'meta_lead:9000000007'"), 2, 'Graph 500: se reintenta (2.º intento)');
    file_put_contents($gMode, 'ok');
    Db::run("UPDATE jobs SET run_at = ? WHERE dedupe_key = 'meta_lead:9000000007'", [Clock::shift(-5)]);
    Fx::runJobs();
    T::ok($count('leads', "email = 'tomas.meta@example.com'") === 1 && Db::value("SELECT status FROM jobs WHERE dedupe_key = 'meta_lead:9000000007'") === 'done', 'cuando Graph vuelve, el 3.er intento crea el lead (nada se perdio)');
    file_put_contents($gMode, 'gone');
    $b8 = $event('9000000008');
    $metaPost($b8, ['X-Hub-Signature-256: ' . $sign($b8)]);
    Fx::runJobs();
    T::ok(Db::value("SELECT status FROM jobs WHERE dedupe_key = 'meta_lead:9000000008'") === 'failed', 'lead inexistente en Graph (404): fallo definitivo sin reintentos');
    file_put_contents($gMode, 'ok');
    $errLog = (string) @file_get_contents("$work/storage/error.log") . (string) @file_get_contents("$work/wh25.log") . json_encode(Db::all('SELECT last_error FROM jobs')) . json_encode(Db::all('SELECT payload FROM jobs'));
    T::ok(!str_contains($errLog, $secrets['META_PAGE_TOKEN']) && !str_contains($errLog, $secrets['META_APP_SECRET']), 'el token de pagina y el secreto de la app no aparecen en logs ni en la cola');

    // ============================================================ META sin configurar y limites
    $noMeta = Panel::start($ctx, 'wh25n', ['META_APP_SECRET' => '', 'META_VERIFY_TOKEN' => '', 'META_PAGE_TOKEN' => '']);
    $r = Infra::http('GET', $noMeta->base . '/api/webhooks/meta.php?hub.mode=subscribe&hub.verify_token=&hub.challenge=1');
    T::ok($r['status'] === 503 && str_contains($r['body'], 'not_configured') && str_contains($r['body'], 'META_VERIFY_TOKEN'), 'sin variables de Meta: 503 not_configured con mensaje claro (sin crash)');
    foreach (['calcom', 'sms', 'n8n'] as $f) {
        $r = Infra::http('POST', $noMeta->base . "/api/webhooks/$f.php", ['raw' => '{}', 'headers' => ['X-Cal-Signature-256: x']]);
        T::eq($r['status'], 503, "sin variables, /api/webhooks/$f.php: 503");
    }
    $noTok = Panel::start($ctx, 'wh25t', ['META_PAGE_TOKEN' => '']);
    $bt = $event('9000000010');
    $r = Infra::http('POST', $noTok->base . '/api/webhooks/meta.php', ['raw' => $bt, 'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . $sign($bt)]]);
    T::ok($r['status'] === 503 && $count('webhook_events', "event_key = '9000000010'") === 0, 'sin META_PAGE_TOKEN el POST responde 503 y no consume el evento (Meta reintentara mas tarde)');
    $lim = Panel::start($ctx, 'wh25r', $env + ['WEBHOOK_RATE_LIMIT' => '10', 'WEBHOOK_SIG_FAILS' => '3']);
    foreach (glob("$work/storage/ratelimit/*.json") ?: [] as $f) {
        @unlink($f);
    }
    $codes = [];
    for ($i = 0; $i < 4; $i++) {
        $codes[] = Infra::http('POST', $lim->base . '/api/webhooks/meta.php', ['raw' => $bt, 'headers' => ['X-Hub-Signature-256: sha256=' . str_repeat('a', 64)]])['status'];
    }
    T::eq($codes, [401, 401, 401, 429], 'firmas malas repetidas: tras 3 la IP queda bloqueada (429)');
    T::eq(Infra::http('POST', $lim->base . '/api/webhooks/meta.php', ['raw' => $bt, 'headers' => ['X-Hub-Signature-256: ' . $sign($bt)]])['status'], 429, '  y bloqueada no entra ni con la firma buena hasta que pase la ventana');
    T::ok(Captacion\Services\Integrations::sigFails('meta') >= 3, '  las firmas rechazadas quedan contadas para el aviso de la campana');
    foreach (glob("$work/storage/ratelimit/*.json") ?: [] as $f) {
        @unlink($f);
    }
    $lim2 = Panel::start($ctx, 'wh25r2', $env + ['WEBHOOK_RATE_LIMIT' => '10']);
    $codes = [];
    for ($i = 0; $i < 12; $i++) {
        $codes[] = Infra::http('POST', $lim2->base . '/api/webhooks/calcom.php', ['raw' => '{}', 'headers' => ['X-Cal-Signature-256: ' . hash_hmac('sha256', '{}', 'cal-secret-123')]])['status'];
    }
    T::ok(count(array_filter($codes, static fn($c) => $c === 429)) === 2 && $codes[0] === 200 || $codes[0] === 422, 'limite de tasa por IP: pasadas 10 peticiones por hora, 429 (' . implode(',', array_unique($codes)) . ')');
    foreach (glob("$work/storage/ratelimit/*.json") ?: [] as $f) {
        @unlink($f);
    }

    // ============================================================ CAL.COM
    T::reset();
    Settings::set('meta', []);
    Settings::set('mail', ['enabled' => true, 'address' => 'NorthStar Freight Solutions LLC, 100 Main St, Lakeland, FL 33801', 'cap_hour' => 50, 'cap_lead' => 3]);
    Settings::clear();
    $cal = static fn(string $body, ?string $sig = null) => Infra::http('POST', $panel->base . '/api/webhooks/calcom.php', ['raw' => $body, 'headers' => ['Content-Type: application/json', 'X-Cal-Signature-256: ' . ($sig ?? hash_hmac('sha256', $body, 'cal-secret-123'))]]);
    $start = time() + 3 * 86400;
    $ph = static fn(string $email): string => '+1305555' . str_pad((string) (crc32(strtolower($email)) % 10000), 4, '0', STR_PAD_LEFT);
    $mk = static fn(string $trigger, string $uid, int $startTs, int $createdTs, array $att = [], array $extra = []) => (string) json_encode(['triggerEvent' => $trigger, 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z', $createdTs), 'payload' => $extra + ['uid' => $uid, 'title' => '30 min entre NorthStar y Mike', 'startTime' => gmdate('Y-m-d\TH:i:s\Z', $startTs), 'endTime' => gmdate('Y-m-d\TH:i:s\Z', $startTs + 1800),
        'attendees' => [$att + ['name' => 'Mike Soto', 'email' => 'mike.cal@example.com', 'timeZone' => 'America/Chicago', 'language' => ['locale' => 'en']]], 'metadata' => ['videoCallUrl' => 'https://meet.example.com/abc'], 'responses' => ['attendeePhoneNumber' => ['value' => $ph((string) ($att['email'] ?? 'mike.cal@example.com'))]]]]);
    $b = $mk('BOOKING_CREATED', 'bkg-AAAA11', $start, time() - 10);
    T::eq($cal($b, 'sha256=' . str_repeat('1', 64))['status'], 401, 'Cal.com con firma incorrecta: 401');
    T::eq(Infra::http('POST', $panel->base . '/api/webhooks/calcom.php', ['raw' => $b])['status'], 401, 'Cal.com sin cabecera de firma: 401');
    T::eq($cal($b . ' ', hash_hmac('sha256', $b, 'cal-secret-123'))['status'], 401, 'Cal.com con el cuerpo manipulado: 401');
    T::eq($count('appointments') + $count('leads'), 0, '  nada de eso creo citas ni leads');
    $r = $cal('{}');
    T::eq($r['status'], 200, 'evento con firma buena pero sin triggerEvent conocido: 200 ignorado (no se reintenta)');
    $r = $cal((string) json_encode(['triggerEvent' => 'PING', 'createdAt' => gmdate('c'), 'payload' => []]));
    T::ok($r['status'] === 200 && str_contains($r['body'], 'test'), 'PING firmado: 200 sin efectos');
    $r = $cal((string) json_encode(['triggerEvent' => 'BOOKING_CREATED', 'payload' => ['uid' => '../x']]));
    T::eq($r['status'], 422, 'reserva con uid invalido: 422');
    $bad = $mk('BOOKING_CREATED', 'bkg-BAD', time() + 5 * 86400 * 1000, time());
    T::eq($cal($bad)['status'], 422, 'reserva con fecha absurda: 422');
    $r = $cal($b);
    $j = json_decode($r['body'], true);
    $lead = Db::one("SELECT * FROM leads WHERE email = 'mike.cal@example.com'");
    $appt = Db::one("SELECT * FROM appointments WHERE calcom_id = 'bkg-AAAA11'");
    T::ok($r['status'] === 200 && ($j['status'] ?? '') === 'processed' && $lead !== null && $lead['source'] === 'calcom' && $lead['phone_e164'] === $ph('mike.cal@example.com') && $appt !== null && (int) $appt['lead_id'] === (int) $lead['id'], 'reserva creada: lead nuevo con fuente calcom (telefono desde las respuestas) y cita enlazada');
    T::ok($appt['status'] === 'booked' && $appt['tz'] === 'America/Chicago' && $appt['meeting_url'] === 'https://meet.example.com/abc' && $appt['starts_at'] === gmdate('Y-m-d H:i:s', $start), '  cita con hora UTC, zona del asistente y enlace de la videollamada');
    T::eq(Db::value('SELECT stage FROM leads WHERE id = ?', [$lead['id']]), 'call_booked', '  etapa: Llamada agendada (reglas de la ficha)');
    $task = Db::one('SELECT * FROM tasks WHERE appointment_id = ? AND deleted_at IS NULL', [$appt['id']]);
    T::ok($task !== null && $task['due_at'] === $appt['starts_at'] && (int) $task['priority'] === 1 && str_contains($task['title'], 'Mike Soto'), '  tarea «Llamada con Mike Soto» con la hora de la cita');
    $rem = Db::all("SELECT run_at, status FROM jobs WHERE type = 'appointment_reminder' ORDER BY run_at");
    T::ok(count($rem) === 2 && $rem[0]['run_at'] === gmdate('Y-m-d H:i:s', $start - 86400) && $rem[1]['run_at'] === gmdate('Y-m-d H:i:s', $start - 3600), '  dos recordatorios programados: 24 h y 1 h antes');
    T::ok($count('lead_events', "type = 'appointment_booked' AND lead_id = " . (int) $lead['id']) === 1, '  evento de la linea de tiempo');
    $r = $cal($b);
    T::ok(json_decode($r['body'], true)['status'] === 'duplicate' && $count('appointments') === 1 && $count('tasks') === 1 && $count('jobs', "type = 'appointment_reminder'") === 2 && $count('leads') === 1, 'el mismo evento dos veces no duplica cita, tarea, recordatorios ni lead');
    // La vista de /tareas ya preparada muestra la cita
    $adm = Panel::user('admin');
    $ap = $panel->another();
    $ap->login($adm['email']);
    $pg = $ap->get('/tareas?tab=upcoming');
    T::ok($pg['status'] === 200 && str_contains($pg['body'], 'Mike Soto') && str_contains($pg['body'], 'Agendada'), '/tareas muestra la cita de Cal.com (Agendada) y su tarea');

    // Reprogramar (uid nuevo + rescheduleUid): una sola fila
    $start2 = $start + 86400;
    $b = $mk('BOOKING_RESCHEDULED', 'bkg-BBBB22', $start2, time() - 5, [], ['rescheduleUid' => 'bkg-AAAA11']);
    $r = $cal($b);
    $rows = Db::all('SELECT * FROM appointments');
    T::ok($r['status'] === 200 && count($rows) === 1 && $rows[0]['calcom_id'] === 'bkg-BBBB22' && $rows[0]['prev_calcom_id'] === 'bkg-AAAA11' && $rows[0]['starts_at'] === gmdate('Y-m-d H:i:s', $start2) && (int) $rows[0]['reschedules'] === 1, 'reprogramada: la MISMA cita cambia de hora y de uid (prev_calcom_id guarda el anterior)');
    $tasks = Db::all('SELECT due_at FROM tasks WHERE deleted_at IS NULL');
    T::ok(count($tasks) === 1 && $tasks[0]['due_at'] === gmdate('Y-m-d H:i:s', $start2), '  la tarea se mueve a la nueva hora (no se crea otra)');
    T::ok($count('jobs', "type = 'appointment_reminder' AND status = 'cancelled'") === 2 && $count('jobs', "type = 'appointment_reminder' AND status = 'pending'") === 2, '  los recordatorios viejos se cancelan y se programan los nuevos');
    $late = $mk('BOOKING_CREATED', 'bkg-AAAA11', $start, time() - 60);
    $r = $cal($late);
    T::ok(json_decode($r['body'], true)['status'] === 'stale' && $count('appointments') === 1, 'un «creada» tardio de la reserva ya reemplazada se ignora como obsoleto (no crea una cita fantasma)');

    // Cancelar
    $b = $mk('BOOKING_CANCELLED', 'bkg-BBBB22', $start2, time());
    $r = $cal($b);
    $a2 = Db::one('SELECT * FROM appointments');
    T::ok($r['status'] === 200 && $a2['status'] === 'cancelled' && $a2['cancelled_at'] !== null, 'cancelada: la cita queda «cancelled»');
    T::eq(Db::value('SELECT stage FROM leads WHERE id = ?', [$lead['id']]), 'qualified', '  la etapa vuelve a Calificado');
    T::ok($count('tasks', 'deleted_at IS NULL') === 0 && $count('jobs', "type = 'appointment_reminder' AND status = 'pending'") === 0, '  la tarea se borra y no quedan recordatorios pendientes');
    $pg = $ap->get('/tareas?tab=upcoming');
    T::ok(!str_contains($pg['body'], 'Agendada'), '  /tareas ya no la lista');
    $r = $cal($mk('BOOKING_CANCELLED', 'bkg-BBBB22', $start2, time() + 0));
    T::ok($count('appointments') === 1 && $count('lead_events', "type = 'appointment_cancelled'") === 1, 'cancelar de nuevo no repite efectos');

    // Eventos desordenados
    $before = $count('leads');
    $c1 = time() - 100;
    $cal($mk('BOOKING_CANCELLED', 'bkg-CCCC33', $start, $c1 + 50, ['email' => 'ooo.cal@example.com', 'name' => 'Orden Inverso']));
    $r = $cal($mk('BOOKING_CREATED', 'bkg-CCCC33', $start, $c1, ['email' => 'ooo.cal@example.com', 'name' => 'Orden Inverso']));
    $o = Db::one("SELECT * FROM appointments WHERE calcom_id = 'bkg-CCCC33'");
    T::ok($o['status'] === 'cancelled' && json_decode($r['body'], true)['status'] === 'stale' && $count('tasks', 'deleted_at IS NULL') === 0 && $count('jobs', "type = 'appointment_reminder' AND status = 'pending'") === 0, 'cancelar ANTES de crear (llegan al reves): el estado final es cancelada, sin tarea ni recordatorios');
    $cal($mk('BOOKING_RESCHEDULED', 'bkg-EEEE55', $start + 7200, $c1 + 50, ['email' => 'ooo2.cal@example.com', 'name' => 'Reprog Primero'], ['rescheduleUid' => 'bkg-DDDD44']));
    $r = $cal($mk('BOOKING_CREATED', 'bkg-DDDD44', $start, $c1, ['email' => 'ooo2.cal@example.com', 'name' => 'Reprog Primero']));
    T::ok($count('appointments', "lead_id = " . (int) Db::value("SELECT id FROM leads WHERE email = 'ooo2.cal@example.com'")) === 1 && json_decode($r['body'], true)['status'] === 'stale', 'reprogramar ANTES de crear: una sola cita y el «creada» llega obsoleto');

    // Lead existente y casos de etapa
    $ex = Fx::lead(['email' => 'existente.cal@example.com', 'phone_e164' => '+13055550160', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    $cal($mk('BOOKING_CREATED', 'bkg-FFFF66', $start + 1000, time(), ['email' => 'EXISTENTE.cal@example.com', 'name' => 'Otro Nombre']));
    T::ok($count('leads', "email = 'existente.cal@example.com'") === 1 && (int) Db::value("SELECT lead_id FROM appointments WHERE calcom_id = 'bkg-FFFF66'") === $ex && Db::value('SELECT stage FROM leads WHERE id = ?', [$ex]) === 'call_booked', 'reserva de un lead existente (por correo, sin importar mayusculas): se enlaza a ese lead y pasa a Llamada agendada');
    $pres = Fx::lead(['email' => 'presentado.cal@example.com', 'stage' => 'presented', 'first_contact_at' => Clock::now()]);
    $lost = Fx::lead(['email' => 'perdido.cal@example.com', 'stage' => 'lost', 'lost_reason' => 'price', 'lost_at' => Clock::now(), 'first_contact_at' => Clock::now()]);
    $drv = Fx::lead(['email' => 'driver.cal@example.com', 'profile' => 'company_driver', 'pipeline' => 'B', 'stage' => 'prequalified', 'first_contact_at' => Clock::now()]);
    foreach (['presentado' => 'bkg-GGGG01', 'perdido' => 'bkg-GGGG02', 'driver' => 'bkg-GGGG03'] as $n => $uid) {
        $cal($mk('BOOKING_CREATED', $uid, $start + 2000, time(), ['email' => $n . '.cal@example.com']));
    }
    T::ok(Db::value('SELECT stage FROM leads WHERE id = ?', [$pres]) === 'presented' && Db::value('SELECT stage FROM leads WHERE id = ?', [$lost]) === 'lost' && Db::value('SELECT stage FROM leads WHERE id = ?', [$drv]) === 'prequalified', 'la etapa NO cambia si el lead ya esta presentado, perdido o es company driver (pipeline B)');
    T::ok($count('appointments', "lead_id IN ($pres, $lost, $drv)") === 3 && $count('tasks', "appointment_id IS NOT NULL AND lead_id IN ($pres, $lost, $drv)") === 3, '  pero la cita y su tarea si se registran');
    // Un lead con otra cita futura no baja a Calificado al cancelar una
    $two = Fx::lead(['email' => 'dos.cal@example.com', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    $cal($mk('BOOKING_CREATED', 'bkg-HHHH01', $start, time(), ['email' => 'dos.cal@example.com']));
    $cal($mk('BOOKING_CREATED', 'bkg-HHHH02', $start + 9000, time(), ['email' => 'dos.cal@example.com']));
    $cal($mk('BOOKING_CANCELLED', 'bkg-HHHH01', $start, time() + 1, ['email' => 'dos.cal@example.com']));
    T::eq(Db::value('SELECT stage FROM leads WHERE id = ?', [$two]), 'call_booked', 'cancelar una cita cuando queda otra futura: sigue en Llamada agendada');

    // ============================================================ Recordatorios por correo (reloj simulado)
    T::reset();
    Settings::set('mail', ['enabled' => true, 'address' => 'NorthStar Freight Solutions LLC, 100 Main St, Lakeland, FL 33801', 'cap_hour' => 50, 'cap_lead' => 3]);
    Settings::clear();
    $mails = static fn(): array => Infra::readLog($smtpLog);
    $nMails = count($mails());
    $cons = Fx::lead(['email' => 'recordar@example.com', 'first_name' => 'Rita', 'language' => 'es', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    Fx::consent($cons);
    $nocons = Fx::lead(['email' => 'norecordar@example.com', 'first_name' => 'Nora', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    $s3 = time() + 4 * 86400;
    $cal($mk('BOOKING_CREATED', 'bkg-REM001', $s3, time(), ['email' => 'recordar@example.com', 'name' => 'Rita', 'language' => ['locale' => 'es'], 'timeZone' => 'America/New_York']));
    $cal($mk('BOOKING_CREATED', 'bkg-REM002', $s3, time(), ['email' => 'norecordar@example.com', 'name' => 'Nora']));
    Clock::travel($s3 - 86400 - time() + 120);   // 2 min despues de «24 h antes»
    Fx::runJobs(10);
    $m = array_slice($mails(), $nMails);
    $raw = static fn(array $x): string => quoted_printable_decode((string) $x['raw']);
    T::ok(count($m) === 1 && $m[0]['to'] === ['recordar@example.com'] && str_contains($raw($m[0]), 'Recordatorio') && str_contains($raw($m[0]), 'https://meet.example.com/abc') && !str_contains($raw($m[0]), '{cita_') && str_contains($raw($m[0]), 'NorthStar Freight Solutions LLC'), 'recordatorio 24 h: un correo en español al lead con consentimiento, con fecha, enlace y pie con direccion');
    T::ok(str_contains($raw($m[0]), 'America/New_York') && preg_match('/de (enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre) a las/', $raw($m[0])) === 1, '  la hora va en la zona del asistente y la fecha en espanol');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type = 'reminder_skipped' AND lead_id = $nocons") === 1 && (int) Db::value("SELECT COUNT(*) FROM messages WHERE lead_id = $nocons AND channel = 'email'") === 0, 'sin consentimiento de correo: no se envia y la linea de tiempo anota «omitido»');
    T::ok((int) Db::value("SELECT COUNT(*) FROM messages WHERE lead_id = $cons AND channel = 'email' AND sequence_key IS NULL AND template_key = 'appt_reminder_24h'") === 1, '  el recordatorio no consume los topes de las secuencias (sequence_key NULL)');
    Clock::travel($s3 - 3600 + 60 - time());
    Fx::runJobs(10);
    T::eq(count(array_slice($mails(), $nMails)), 2, 'recordatorio 1 h: segundo correo');
    // cancelar antes de que llegue el recordatorio
    $s4 = time() + 5 * 86400;
    Clock::travel(0);
    $c4 = Fx::lead(['email' => 'cancela@example.com', 'first_name' => 'Cris', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    Fx::consent($c4);
    $cal($mk('BOOKING_CREATED', 'bkg-REM004', $s4, time(), ['email' => 'cancela@example.com']));
    $cal($mk('BOOKING_CANCELLED', 'bkg-REM004', $s4, time() + 1, ['email' => 'cancela@example.com']));
    Clock::travel($s4 - 86400 - time() + 120);
    $n0 = count($mails());
    Fx::runJobs(10);
    T::eq(count($mails()), $n0, 'cita cancelada: no sale ningun recordatorio');
    // un recordatorio atrasado (el cron estuvo caido) no se envia tarde
    Clock::travel(0);
    $c5 = Fx::lead(['email' => 'tarde@example.com', 'first_name' => 'Tito', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    Fx::consent($c5);
    $s5 = time() + 5 * 86400;
    $cal($mk('BOOKING_CREATED', 'bkg-REM005', $s5, time(), ['email' => 'tarde@example.com']));
    Clock::travel($s5 - 3600 + 1800 - time());   // 30 min antes de la cita: ya paso la hora del de 24 h y casi la del de 1 h
    $n0 = count($mails());
    Fx::runJobs(10);
    T::ok(count($mails()) === $n0 + 1 && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type = 'reminder_skipped' AND lead_id = $c5 AND payload LIKE '%late%'") === 1, 'recordatorio muy atrasado: el de 24 h se omite («late») y el de 1 h todavia sale (faltan 30 min)');
    Clock::travel(0);
    Settings::set('mail', []);
    Settings::clear();
    $s6 = time() + 5 * 86400;
    $c6 = Fx::lead(['email' => 'apagado@example.com', 'stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    Fx::consent($c6);
    $cal($mk('BOOKING_CREATED', 'bkg-REM006', $s6, time(), ['email' => 'apagado@example.com']));
    Clock::travel($s6 - 86400 - time() + 60);
    $n0 = count($mails());
    Fx::runJobs(10);
    T::ok(count($mails()) === $n0 && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type = 'reminder_skipped' AND lead_id = $c6 AND payload LIKE '%mail_disabled%'") === 1, 'con el correo del CRM apagado (o sin direccion fisica) los recordatorios no salen y quedan anotados');
    Clock::travel(0);

    foreach (array_keys($secrets) as $k) {
        Env::set($k, null);
    }
    foreach (['META_GRAPH_BASE', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'TELEGRAM_API_BASE'] as $k) {
        Env::set($k, null);
    }
    Captacion\Services\JobHandlers::$fmcsa = null;
    Fx::noSmtp();
    Settings::set('meta', []);
    Settings::set('mail', []);
    Settings::clear();
    foreach (['graph25', 'tg25', 'smtp25', 'wh25', 'wh25n', 'wh25t', 'wh25r', 'wh25r2'] as $n) {
        Infra::stop($n);
    }
    T::reset(true);
};
