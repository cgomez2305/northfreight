<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Integrations;
use Captacion\Services\Prospects;
use Captacion\Services\Settings;
use Captacion\Services\Vonage;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase AV (2026-11-25): llamadas a prospectos desde el navegador con Vonage (Voice API + Client SDK). Apagada sin las 4 variables; con ellas: JWT RS256, usuario del
 * agente, ticket de un solo uso, webhook answer firmado que devuelve el NCCO «connect» y webhook de eventos que registra la llamada. Sin marcador, sin buzón grabado.
 */
return static function (array $ctx): void {
    T::reset(true);
    $work = $ctx['work'];
    $root = $ctx['root'];
    foreach (Vonage::VARS as $k) {
        Env::set($k, null);
    }
    Env::set('VONAGE_API_BASE', null);
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $user = ['id' => $aid, 'name' => 'Admin Prueba'];

    // ------------------------------------------------------------------ Apagada sin variables
    T::eq(Vonage::missing(), Vonage::VARS, 'sin variables: faltan las 4 y la integración está apagada');
    T::ok(!Vonage::start($user, 1)['ok'], '  /ajax/vonage/start no hace nada');
    T::eq(Vonage::test()['level'], 'warn', '  «Probar» avisa de lo que falta');

    // ------------------------------------------------------------------ Configuración de prueba (clave RSA generada aquí, API simulada)
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $pub = (string) openssl_pkey_get_details($key)['key'];
    file_put_contents("$work/vonage54.key", $pem);
    file_put_contents("$work/vonage54.pub", $pub);
    $mockLog = "$work/vonage54-calls.log";
    @unlink($mockLog);
    @unlink("$mockLog.users");
    $mockPort = Infra::phpServer('vonage54-api', $work, ['VONAGE_MOCK_LOG' => $mockLog, 'VONAGE_MOCK_PUB' => "$work/vonage54.pub"], $root . '/tests/lib/vonage_mock.php', 19300);
    $secret = 'sig-secret-' . bin2hex(random_bytes(12));
    $env = ['VONAGE_APPLICATION_ID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'VONAGE_PRIVATE_KEY' => "$work/vonage54.key", 'VONAGE_NUMBER' => '+1 (469) 555-0100',
        'VONAGE_SIGNATURE_SECRET' => $secret, 'VONAGE_API_BASE' => "http://127.0.0.1:$mockPort"];
    foreach ($env as $k => $v) {
        Env::set($k, $v);
    }
    T::ok(Vonage::configured() && Vonage::number() === '14695550100', 'con las 4 variables queda configurada; el número va en dígitos (formato del NCCO)');
    Env::set('VONAGE_NUMBER', '+52 55 1234 5678');
    T::eq(Vonage::missing(), ['VONAGE_NUMBER'], '  un número que no es de EE. UU. no vale');
    Env::set('VONAGE_NUMBER', $env['VONAGE_NUMBER']);

    // ------------------------------------------------------------------ JWT
    $parts = explode('.', Vonage::jwt('ns-agent-' . $aid, 3600));
    $dec = static fn(string $s) => json_decode((string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)), true);
    $sig = (string) base64_decode(strtr($parts[2], '-_', '+/') . str_repeat('=', (4 - strlen($parts[2]) % 4) % 4));
    $c = $dec($parts[1]);
    T::ok($dec($parts[0])['alg'] === 'RS256' && openssl_verify($parts[0] . '.' . $parts[1], $sig, $pub, OPENSSL_ALGO_SHA256) === 1, 'JWT RS256 firmado con la clave privada de la aplicación');
    T::ok($c['application_id'] === $env['VONAGE_APPLICATION_ID'] && $c['sub'] === 'ns-agent-' . $aid && isset($c['acl']['paths']['/*/sessions/**']) && $c['exp'] - $c['iat'] === 3600 && strlen((string) $c['jti']) === 32, '  el del agente lleva sub, ACL mínima, jti y caduca en 1 hora');
    T::ok(!isset($dec(explode('.', Vonage::jwt())[1])['sub']), '  el de la aplicación no lleva sub');

    // ------------------------------------------------------------------ Firma de los webhooks
    $mk = static function (array $claims, string $sec, string $alg = 'HS256'): string {
        $b = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $d = $b((string) json_encode(['alg' => $alg, 'typ' => 'JWT'])) . '.' . $b((string) json_encode($claims));
        return 'Bearer ' . $d . '.' . $b(hash_hmac('sha256', $d, $sec, true));
    };
    $body = '{"status":"ringing"}';
    $now = Clock::time();
    T::eq(Vonage::verifyWebhook($mk(['iat' => $now, 'jti' => 'x', 'iss' => 'Vonage', 'payload_hash' => hash('sha256', $body)], $secret), $body), null, 'firma: JWT HS256 válido con payload_hash del cuerpo');
    T::ok(Vonage::verifyWebhook($mk(['iat' => $now], 'otro-secreto-cualquiera'), $body) !== null, '  otro secreto se rechaza');
    T::ok(Vonage::verifyWebhook($mk(['iat' => $now - 3600], $secret), $body) !== null, '  una firma vieja se rechaza');
    T::ok(Vonage::verifyWebhook($mk(['iat' => $now, 'payload_hash' => hash('sha256', 'otro')], $secret), $body) !== null, '  un cuerpo cambiado se rechaza');
    T::ok(Vonage::verifyWebhook($mk(['iat' => $now], $secret, 'none'), $body) !== null && Vonage::verifyWebhook(null, $body) !== null, '  sin firma o con alg «none» se rechaza');

    // ------------------------------------------------------------------ Antes de llamar (en un martes a las 10:00 a. m. de Nueva York)
    $target = (new DateTimeImmutable('2026-12-01 10:00', new DateTimeZone('America/New_York')))->getTimestamp();
    Clock::travel($target - time());
    $n = 0;
    $mkP = static function (array $o = []) use (&$n): int {
        $n++;
        return (int) Db::insert('prospects_fmcsa', $o + ['usdot' => (string) (9800000 + $n), 'legal_name' => "VONAGE $n LLC", 'state' => 'TX', 'phone' => '+1469555' . str_pad((string) (200 + $n), 4, '0', STR_PAD_LEFT),
            'status' => 'to_call', 'attempts' => 0, 'listed_on' => '2026-11-30', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    };
    $ok = $mkP();
    $dnc = $mkP(['do_not_call' => 1, 'status' => 'dnc']);
    $ca = $mkP(['state' => 'CA']);   // 7:00 a. m. en California: fuera de horario
    $mx = $mkP(['phone' => '+525512345678']);
    T::ok(str_contains((string) Vonage::start($user, $dnc)['msg'], 'No llamar más'), 'antes de llamar: «No llamar más» se bloquea en el servidor');
    T::ok(str_contains((string) Vonage::start($user, $ca)['msg'], 'Fuera del horario'), '  fuera de 8 a. m. a 9 p. m. hora local, también');
    T::ok(str_contains((string) Vonage::start($user, $mx)['msg'], 'EE. UU.'), '  y un teléfono que no es de EE. UU.');
    $s1 = Vonage::start($user, $ok);
    $row = Db::one('SELECT * FROM prospect_voice_calls WHERE id = ?', [(int) ($s1['call_id'] ?? 0)]);
    T::ok($s1['ok'] && $row !== null && $row['status'] === 'requested' && preg_match('/^[a-f0-9]{32}$/', (string) $s1['ticket']) === 1 && count(explode('.', (string) $s1['jwt'])) === 3, 'start: crea la llamada «requested» con su ticket y devuelve el JWT del agente');
    $api = array_map(static fn($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents($mockLog))));
    T::ok(count($api) === 1 && $api[0]['path'] === '/v1/users' && $api[0]['jwt_ok'] === true && str_contains((string) $api[0]['body'], 'ns-agent-' . $aid), '  la primera vez crea el usuario del agente en Vonage (con JWT válido)');
    Vonage::start($user, $ok);
    T::eq(count(array_filter(explode("\n", (string) @file_get_contents($mockLog)))), 1, '  y no lo vuelve a crear');
    Settings::set('vonage.user.' . $aid, null);
    Settings::clear();
    T::ok(Vonage::start($user, $ok)['ok'], '  si Vonage dice que ya existe (409), sigue igual');

    // ------------------------------------------------------------------ Webhook answer (NCCO)
    $t = (string) $s1['ticket'];
    Db::run("UPDATE prospect_voice_calls SET status = 'requested' WHERE ticket = ?", [$t]);
    $ncco = Vonage::answer(['from_user' => 'ns-agent-' . $aid, 'uuid' => 'APP-1', 'conversation_uuid' => 'CON-1', 'custom_data' => ['ticket' => $t]]);
    T::ok(($ncco[0]['action'] ?? '') === 'connect' && $ncco[0]['from'] === '14695550100' && $ncco[0]['endpoint'][0] === ['type' => 'phone', 'number' => '14695550201'], 'answer: conecta al teléfono del prospecto (sale del ticket, no del navegador) desde el número de NorthStar');
    T::ok($ncco[0]['eventUrl'][0] === Vonage::url('event') && $ncco[0]['timeout'] === 45 && $ncco[0]['limit'] === 1800 && !isset($ncco[0]['machineDetection']) && !isset($ncco[0]['advancedMachineDetection']), '  con eventos, 45 s de timbre, tope de 30 min y SIN detección de contestador');
    $c1 = Db::one('SELECT * FROM prospect_voice_calls WHERE ticket = ?', [$t]);
    T::ok($c1['status'] === 'dialing' && $c1['app_uuid'] === 'APP-1' && $c1['conversation_uuid'] === 'CON-1', '  la llamada pasa a «dialing» con sus identificadores');
    // ------------------------------------------------------------------ Webhook de eventos
    Vonage::event(['conversation_uuid' => 'CON-1', 'uuid' => 'PSTN-1', 'status' => 'ringing']);
    T::eq(Db::value('SELECT status FROM prospect_voice_calls WHERE ticket = ?', [$t]), 'ringing', 'eventos: sonando');
    Vonage::event(['conversation_uuid' => 'CON-1', 'uuid' => 'PSTN-1', 'status' => 'answered']);
    Vonage::event(['conversation_uuid' => 'CON-1', 'uuid' => 'PSTN-1', 'status' => 'completed', 'duration' => '133', 'price' => '0.0300', 'rate' => '0.0135', 'disconnected_by' => 'user']);
    $c1 = Db::one('SELECT * FROM prospect_voice_calls WHERE ticket = ?', [$t]);
    T::ok($c1['status'] === 'completed' && (int) $c1['duration'] === 133 && (float) $c1['price'] === 0.03 && $c1['pstn_uuid'] === 'PSTN-1' && $c1['answered_at'] !== null, '  contestó y colgó: duración, precio y el uuid del tramo al teléfono');
    Vonage::event(['conversation_uuid' => 'CON-1', 'uuid' => 'PSTN-1', 'status' => 'ringing']);
    T::eq(Db::value('SELECT status FROM prospect_voice_calls WHERE ticket = ?', [$t]), 'completed', '  un evento tardío no hace retroceder un estado final');
    T::eq(Vonage::answer(['custom_data' => ['ticket' => $t]])[0]['action'], 'talk', '  el ticket es de un solo uso');
    T::eq(Vonage::answer(['custom_data' => ['ticket' => 'zz']])[0]['action'], 'talk', '  sin ticket válido no se llama a nadie');
    $s2 = Vonage::start($user, $ok);
    Db::run('UPDATE prospects_fmcsa SET do_not_call = 1, status = ? WHERE id = ?', ['dnc', $ok]);
    $nb = Vonage::answer(['from_user' => 'ns-agent-' . $aid, 'custom_data' => ['ticket' => $s2['ticket']]]);
    T::ok($nb[0]['action'] === 'talk' && Db::value('SELECT status FROM prospect_voice_calls WHERE id = ?', [$s2['call_id']]) === 'blocked', '  si entre el clic y la llamada pasó a «No llamar más», se bloquea');
    Db::run('UPDATE prospects_fmcsa SET do_not_call = 0, status = ? WHERE id = ?', ['to_call', $ok]);
    $s3 = Vonage::start($user, $ok);
    T::eq(Vonage::answer(['from_user' => 'ns-agent-999', 'custom_data' => ['ticket' => $s3['ticket']]])[0]['action'], 'talk', '  un ticket de otro agente no sirve');
    $s4 = Vonage::start($user, $ok);
    Clock::travel($target - time() + Vonage::TICKET_TTL + 5);
    T::eq(Vonage::answer(['custom_data' => ['ticket' => $s4['ticket']]])[0]['action'], 'talk', '  un ticket de más de 5 minutos caduca');
    Clock::travel($target - time());

    $st = Vonage::status((int) $c1['id'], $aid);
    T::ok($st['answered'] && $st['duration'] === 133 && $st['suggest'] === 'talked', '  estado para el navegador: contestó, 2 min 13 s, sugiere «Conversó»');
    T::eq(Vonage::status((int) $c1['id'], $aid + 1), null, '  otro agente no ve la llamada');
    $s5 = Vonage::start($user, $ok);
    Vonage::answer(['from_user' => 'ns-agent-' . $aid, 'uuid' => 'APP-5', 'conversation_uuid' => 'CON-5', 'custom_data' => ['ticket' => $s5['ticket']]]);
    Vonage::event(['conversation_uuid' => 'CON-5', 'uuid' => 'PSTN-5', 'status' => 'ringing']);
    Vonage::event(['conversation_uuid' => 'CON-5', 'uuid' => 'PSTN-5', 'status' => 'timeout']);
    T::eq(Vonage::status((int) $s5['call_id'], $aid)['suggest'], 'no_answer', '  no contestó: sugiere «Sin respuesta»');
    $s6 = Vonage::start($user, $ok);
    Vonage::answer(['from_user' => 'ns-agent-' . $aid, 'uuid' => 'APP-6', 'conversation_uuid' => 'CON-6', 'custom_data' => ['ticket' => $s6['ticket']]]);
    Vonage::event(['conversation_uuid' => 'CON-6', 'uuid' => 'APP-6', 'status' => 'completed']);
    T::eq(Db::value('SELECT status FROM prospect_voice_calls WHERE id = ?', [$s6['call_id']]), 'cancelled', '  el agente colgó antes de que contestaran: cancelada');
    Vonage::event(['conversation_uuid' => 'CON-NADA', 'uuid' => 'X', 'status' => 'answered']);
    T::ok(true, '  un evento de una conversación desconocida se ignora sin error');
    $vs = Vonage::todayStats(Clock::time());
    T::ok($vs['answered'] === 1 && $vs['minutes'] === 2, 'métricas de hoy: 1 contestada, 2 minutos hablados');
    T::ok(Vonage::test()['ok'], '«Probar» de Integraciones: la API (simulada) acepta el JWT de la aplicación');
    $cards = array_column(Integrations::status(), null, 'key');
    T::ok(isset($cards['vonage']) && $cards['vonage']['level'] === 'ok' && str_contains((string) $cards['vonage']['url'], '/api/webhooks/vonage-answer.php'), '  y su tarjeta en Integraciones muestra la URL del webhook answer');
    Clock::travel(0);

    // ------------------------------------------------------------------ HTTP: webhooks y pantallas
    $s = Panel::start($ctx, 'panel-av54', $env);
    T::ok($s->login($admin['email']), 'login del administrador');
    $now = time();
    $callable = Prospects::callableStates($now);
    $pid = $mkP(['state' => $callable[0] ?? 'TX']);
    $page = $s->get('/prospectos?tab=to_call&q=' . (9800000 + $n));
    $csp = Infra::header($page, 'content-security-policy');
    if ($callable) {
        T::ok(str_contains($page['body'], 'data-vcall="' . $pid . '"') && str_contains($page['body'], 'Llamar con Vonage') && str_contains($page['body'], Vonage::SDK_SRI) && str_contains($page['body'], 'Desde mi teléfono'), 'pantalla: «Llamar con Vonage» con el SDK fijado y su SRI; «Desde mi teléfono» como alternativa');
    } else {
        T::ok(!str_contains($page['body'], 'data-vcall="' . $pid . '"'), 'pantalla: (ningún estado en horario ahora) sin botón de llamada');
    }
    T::ok(str_contains($csp, 'https://cdn.jsdelivr.net') && str_contains($csp, 'wss://*.nexmo.com') && str_contains(Infra::header($page, 'permissions-policy'), 'microphone=(self)'), '  la CSP de Prospectos admite el SDK y Vonage, y el micrófono propio');
    $dash = $s->get('/');
    T::ok(!str_contains(Infra::header($dash, 'content-security-policy'), 'jsdelivr') && str_contains(Infra::header($dash, 'permissions-policy'), 'microphone=()'), '  el resto del panel sigue sin CDN ni micrófono');
    $tok = $s->csrfOf($page['body']);
    $r = Infra::http('POST', $s->base . '/ajax/vonage/start', ['headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $tok], 'raw' => json_encode(['prospect_id' => $pid]), 'jar' => $s->jar]);
    $j = json_decode((string) $r['body'], true);
    T::ok($callable ? ($r['status'] === 200 && !empty($j['ticket'])) : ($r['status'] === 422), '/ajax/vonage/start por HTTP (con CSRF)' . ($callable ? ': devuelve ticket y JWT' : ': fuera de horario, 422'));
    $r = Infra::http('POST', $s->base . '/ajax/vonage/start', ['headers' => ['Content-Type: application/json'], 'raw' => json_encode(['prospect_id' => $pid]), 'jar' => $s->jar]);
    T::eq($r['status'], 403, '  sin CSRF: 403');
    $ab = (string) json_encode(['from_user' => 'ns-agent-' . $aid, 'uuid' => 'APP-H', 'conversation_uuid' => 'CON-H', 'custom_data' => ['ticket' => (string) ($j['ticket'] ?? str_repeat('a', 32))]]);
    $r = Infra::http('POST', $s->base . '/api/webhooks/vonage-answer.php', ['headers' => ['Content-Type: application/json', 'Authorization: ' . $mk(['iat' => time(), 'payload_hash' => hash('sha256', $ab)], $secret)], 'raw' => $ab]);
    $nc = json_decode((string) $r['body'], true);
    T::ok($r['status'] === 200 && ($nc[0]['action'] ?? '') === ($callable ? 'connect' : 'talk'), 'webhook answer por HTTP con firma válida: devuelve el NCCO');
    $r = Infra::http('POST', $s->base . '/api/webhooks/vonage-answer.php', ['headers' => ['Content-Type: application/json', 'Authorization: ' . $mk(['iat' => time()], 'secreto-equivocado-123456')], 'raw' => $ab]);
    T::eq($r['status'], 401, '  con firma inválida: 401 y nada más');
    $eb = (string) json_encode(['conversation_uuid' => 'CON-H', 'uuid' => 'PSTN-H', 'status' => 'ringing']);
    $r = Infra::http('POST', $s->base . '/api/webhooks/vonage-event.php', ['headers' => ['Content-Type: application/json', 'Authorization: ' . $mk(['iat' => time(), 'payload_hash' => hash('sha256', $eb)], $secret)], 'raw' => $eb]);
    T::ok($r['status'] === 200 && (!$callable || Db::value("SELECT status FROM prospect_voice_calls WHERE conversation_uuid = 'CON-H'") === 'ringing'), 'webhook de eventos por HTTP: actualiza la llamada');
    $off = Panel::start($ctx, 'panel-av54-off');
    $r = Infra::http('POST', $off->base . '/api/webhooks/vonage-event.php', ['headers' => ['Content-Type: application/json'], 'raw' => $eb]);
    T::eq($r['status'], 503, 'sin variables los webhooks responden 503');
    $js = (string) file_get_contents($root . '/public/assets/prospectos.js');
    T::ok(str_contains($js, "NS.post('/ajax/vonage/start'") && str_contains($js, 'serverCall({ ticket: start.ticket })') && str_contains($js, 'sc.integrity') && !str_contains($js, 'machineDetection'), 'JS: pide el ticket al servidor, llama con serverCall y carga el SDK con integridad (SRI)');
    foreach (['panel-av54', 'panel-av54-off', 'vonage54-api'] as $name) {
        Infra::stop($name);
    }
    foreach (array_keys($env) as $k) {
        Env::set($k, null);
    }
};
