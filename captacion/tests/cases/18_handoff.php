<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Handoff;
use Captacion\Services\Handoff\WebhookTarget;
use Captacion\Services\LeadActions;
use Captacion\Services\Settings;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Q3-A: entrega a NorthStar (correo, webhook firmado, reintentos), idempotencia y confirmacion sin login (/c/{token}) o desde la ficha. */
return static function (array $ctx): void {
    T::reset(true);
    Settings::set('handoff', []);
    Settings::clear();
    $work = $ctx['work'];
    $smtpLog = "$work/smtp18.log";
    $smtpMode = "$work/smtp18.mode";
    $hookLog = "$work/hook18.log";
    $hookMode = "$work/hook18.mode";
    $tgLog = "$work/tg18.log";
    foreach ([$smtpLog, $smtpMode, $hookLog, $hookMode, $tgLog] as $f) {
        @unlink($f);
    }
    $smtpPort = Infra::smtpMock('smtp18', $smtpLog, $smtpMode);
    $hookPort = Infra::phpServer('hook18', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $hookLog, 'MOCK_MODE_FILE' => $hookMode], $ctx['root'] . '/tests/lib/webhook_mock.php');
    $tgPort = Infra::phpServer('tg18', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $tgLog], $ctx['root'] . '/tests/lib/telegram_mock.php');
    Env::set('TELEGRAM_BOT_TOKEN', '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11');
    Env::set('TELEGRAM_CHAT_ID', '555000');
    Env::set('TELEGRAM_API_BASE', "http://127.0.0.1:$tgPort");
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $mails = static fn(): array => Infra::readLog($smtpLog);
    $hooks = static fn(): array => Infra::readLog($hookLog);
    $tgs = static fn(): array => Infra::readLog($tgLog);
    $decoded = static fn(array $m): string => quoted_printable_decode((string) $m['raw']);
    $present = static function (int $lead) use ($aid): array {
        Db::update('leads', $lead, ['stage' => 'qualified']);
        return LeadActions::setStage($lead, 'presented', [], $aid);
    };
    $handoffOf = static fn(int $lead): ?array => Db::one('SELECT * FROM handoffs WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$lead]);
    $tokenOf = static fn(string $url): string => substr($url, strrpos($url, '/c/') + 3);

    // ------------------------------------------------------------------ Sin destino configurado
    $l1 = Fx::lead(['temperature' => 'hot', 'score' => 82, 'first_name' => 'Sin', 'last_name' => 'Destino']);
    Fx::consent($l1);
    $r = $present($l1);
    $h1 = $handoffOf($l1);
    T::ok($r['ok'] && $h1 !== null && $h1['status'] === 'no_target', 'presentar sin destino: la entrega queda «no_target»');
    T::eq(Db::value('SELECT handoff_status FROM leads WHERE id = ?', [$l1]), 'pending', '  y el lead conserva handoff_status = pending');
    T::eq([(int) Db::value('SELECT COUNT(*) FROM handoff_deliveries'), (int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'handoff_deliver'")], [0, 0], '  sin entregas ni jobs: no se envia a nadie');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'handoff_no_target'", [$l1]) === 1, '  evento handoff_no_target en la linea de tiempo');
    T::ok(!Handoff::configured() && !$mails() && !$hooks(), 'sin destino: Handoff::configured() es falso y no salio nada');
    $panel = Panel::start($ctx, 'panel18');
    T::ok($panel->login($admin['email']), 'login del administrador');
    $ficha = $panel->get('/leads/' . $l1);
    T::ok($ficha['status'] === 200 && str_contains($ficha['body'], 'Destino de entrega sin configurar'), 'la ficha avisa «Destino de entrega sin configurar»');
    T::ok($panel->unlock($aid, 'entrega'), 'confirmar identidad para editar la entrega');
    $cfg = $panel->get('/config?tab=entrega');
    T::ok($cfg['status'] === 200 && str_contains($cfg['body'], 'Destino de entrega sin configurar'), 'Configuracion > Entrega muestra el aviso y está habilitada');

    // ------------------------------------------------------------------ Validacion de la configuracion
    $v = Handoff::validate(['email' => 'no-es-correo', 'webhook_url' => 'ftp://x', 'webhook_on' => '1']);
    T::ok(!$v['ok'] && isset($v['errors']['email'], $v['errors']['webhook_url']), 'configuracion: correo y URL invalidos se rechazan');
    Env::set('APP_ENV', 'production');
    T::ok(Handoff::urlProblem('http://example.com/hook') !== null && Handoff::urlProblem('https://user:pw@example.com/h') !== null && Handoff::urlProblem('https://localhost/h') !== null && Handoff::urlProblem('https://192.168.1.5/h') !== null && Handoff::urlProblem('https://127.0.0.1/h') !== null, 'produccion: http, credenciales en la URL, localhost y redes privadas se rechazan');
    Env::set('APP_ENV', 'local');
    T::ok(Handoff::urlProblem("http://127.0.0.1:$hookPort/hook") === null, 'en local (pruebas) se permite http://127.0.0.1');
    T::ok(!Handoff::saveConfig(['email' => '', 'webhook_url' => "http://127.0.0.1:$hookPort/hook", 'webhook_on' => true], null, $aid)['ok'], 'activar el webhook sin secreto: se rechaza');
    T::ok(!Handoff::saveConfig(['email' => '', 'webhook_url' => '', 'webhook_on' => false], 'corto', $aid)['ok'], 'un secreto de menos de 16 caracteres se rechaza');

    // ------------------------------------------------------------------ Correo a NorthStar
    $r = Handoff::saveConfig(['email' => 'equipo@northstar.test', 'webhook_url' => '', 'webhook_on' => false], null, $aid);
    T::ok($r['ok'] && Handoff::configured() && Handoff::config()['email'] === 'equipo@northstar.test', 'guardar el correo de entrega');
    $rp = Handoff::retryPending($aid);
    T::ok($rp['ok'] && (int) Db::value('SELECT COUNT(*) FROM handoff_deliveries WHERE handoff_id = ?', [$h1['id']]) === 1, 'ahora que hay destino, la entrega que esperaba se pone en cola (misma versión)');
    T::eq(Db::value('SELECT status FROM handoffs WHERE id = ?', [$h1['id']]), 'pending', '  y deja de estar «sin destino»');
    Fx::smtp($smtpPort);
    $st = Fx::runJobs();
    $m = $mails();
    T::ok($st['done'] >= 1 && count($m) === 1 && $m[0]['to'] === ['equipo@northstar.test'], 'la cola envia 1 correo a NorthStar');
    $body = $decoded($m[0]);
    T::ok(str_contains($body, 'Sin Destino') && str_contains($body, '82/100') && str_contains($body, 'CONSENTIMIENTOS') && str_contains($body, 'ATRIBUCIÓN') && str_contains($body, 'FMCSA'), 'la ficha lleva nombre, puntuación, consentimientos, atribución y FMCSA');
    T::ok(preg_match_all('#/c/([A-Za-z0-9_-]{40,})#', $body, $mm) >= 2 && count(array_unique($mm[1])) === 2, 'trae dos enlaces distintos de confirmacion (Firmó / Lo rechazamos)');
    T::ok(str_contains($body, 'text/html') && str_contains($body, 'Lo rechazamos') && !preg_match('/(licen[cs]e scan|escaneo de la licencia|\.pdf)/i', $body), 'correo HTML con los dos botones y sin documentos de conductores');
    T::eq([Db::value('SELECT status FROM handoff_deliveries WHERE handoff_id = ?', [$h1['id']]), Db::value('SELECT status FROM handoffs WHERE id = ?', [$h1['id']])], ['sent', 'sent'], 'entrega y estado: sent');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'handoff_sent'", [$l1]) === 1 && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'handoff_sent'") === 1, 'eventos handoff_sent y activity_log');
    // Idempotencia
    $again = Handoff::onPresented($l1, $aid);
    T::ok($again === (int) $h1['id'] && (int) Db::value('SELECT COUNT(*) FROM handoffs WHERE lead_id = ?', [$l1]) === 1, 'idempotente: volver a presentar no crea otra entrega');
    Handoff::deliver(['handoff_id' => (int) $h1['id'], 'target' => 'email']);
    \Captacion\Services\Queue::push('handoff_deliver', ['handoff_id' => (int) $h1['id'], 'target' => 'email'], null, 'otra-clave-1');
    Fx::runJobs();
    T::eq(count($mails()), 1, 'idempotente: reejecutar el job de una entrega ya enviada no manda otro correo');
    // SMTP que rechaza: reintento y fallo final
    $l2 = Fx::lead(['first_name' => 'Smtp', 'last_name' => 'Rechaza']);
    Fx::consent($l2);
    file_put_contents($smtpMode, 'reject');
    $present($l2);
    Fx::runJobs();
    $d2 = Db::one("SELECT * FROM handoff_deliveries WHERE handoff_id = ? AND target = 'email'", [$handoffOf($l2)['id']]);
    T::ok($d2['status'] === 'retrying' && (int) $d2['attempts'] === 1 && $d2['error'] !== null && !str_contains((string) $d2['error'], 'pw-smtp-prueba'), 'SMTP rechaza: la entrega queda «reintentando» (sin la contraseña en el error)');
    foreach ([70, 400, 1400] as $t) {
        Clock::travel($t);
        Fx::runJobs();
    }
    $d2 = Db::one('SELECT * FROM handoff_deliveries WHERE id = ?', [$d2['id']]);
    T::ok($d2['status'] === 'failed' && (int) $d2['attempts'] === 4 && Db::value('SELECT status FROM handoffs WHERE id = ?', [$d2['handoff_id']]) === 'failed', 'reintentos a 1, 5 y 15 min y después «failed» (4 intentos)');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'handoff_failed'", [$l2]) === 1, '  con el evento handoff_failed');
    Clock::travel(0);
    file_put_contents($smtpMode, 'ok');

    // ------------------------------------------------------------------ Webhook firmado
    $secret = 'secreto-webhook-de-prueba-0123456789abcdef';
    $r = Handoff::saveConfig(['email' => 'equipo@northstar.test', 'webhook_url' => "http://127.0.0.1:$hookPort/hook", 'webhook_on' => true], $secret, $aid);
    T::ok($r['ok'] && Handoff::config()['webhook_secret'] === $secret && Handoff::config()['secret_set'], 'guardar el webhook con su secreto');
    T::ok(!str_contains((string) Db::value("SELECT value FROM settings WHERE `key` = 'handoff'"), $secret), 'el secreto se guarda cifrado (no está en claro en settings)');
    T::ok(!str_contains((string) Db::value("SELECT GROUP_CONCAT(changes) FROM activity_log WHERE entity_id = 'handoff'"), $secret) && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'handoff_secret_changed'") === 1, '  ni en activity_log (solo «secreto cambiado»)');
    $l3 = Fx::lead(['first_name' => 'Web', 'last_name' => 'Hook', 'temperature' => 'hot', 'score' => 77]);
    Fx::consent($l3);
    $present($l3);
    $h3 = $handoffOf($l3);
    T::eq((int) Db::value('SELECT COUNT(*) FROM handoff_deliveries WHERE handoff_id = ?', [$h3['id']]), 2, 'con correo y webhook activos: dos entregas por lead');
    Fx::runJobs();
    $hk = $hooks();
    T::eq(count($hk), 1, 'el webhook recibe 1 peticion');
    $req = $hk[0];
    $ts = $req['headers']['x-ns-timestamp'] ?? '';
    T::ok(($req['headers']['x-ns-signature'] ?? '') === 'sha256=' . hash_hmac('sha256', $ts . '.' . $req['body'], $secret) && ctype_digit($ts) && abs((int) $ts - time()) < 30, 'firma HMAC-SHA256 de «timestamp.cuerpo» con el secreto, y marca de tiempo vigente');
    T::ok(WebhookTarget::sign($secret, $ts, $req['body']) === $req['headers']['x-ns-signature'] && ($req['headers']['idempotency-key'] ?? '') === 'handoff-' . $h3['id'] . '-v1' && ($req['headers']['x-ns-delivery'] ?? '') === (string) $h3['id'], 'cabeceras Idempotency-Key y X-NS-Delivery');
    $j = json_decode($req['body'], true);
    T::ok(($j['schema'] ?? '') === 'northstar.handoff.v1' && $j['event'] === 'lead.presented' && $j['lead']['name'] === 'Web Hook' && $j['lead']['score'] === 77 && isset($j['lead']['consents'], $j['lead']['attribution'], $j['lead']['score_breakdown']), 'cuerpo JSON versionado con la ficha');
    T::ok(str_contains((string) $j['confirm_urls']['signed'], '/c/') && str_contains((string) $j['confirm_urls']['rejected'], '/c/') && $j['confirm_urls']['signed'] !== $j['confirm_urls']['rejected'], '  y los dos enlaces de confirmacion');
    T::ok(!str_contains($req['body'], $secret) && !str_contains((string) @file_get_contents(\Captacion\Services\Storage::dir('error.log')), $secret), 'el secreto no viaja en el cuerpo ni aparece en los logs');
    T::eq(Db::value('SELECT status FROM handoffs WHERE id = ?', [$h3['id']]), 'sent', 'las dos entregas hechas: estado sent');
    // Webhook caido: parcial, reintentos y fallo final
    $l4 = Fx::lead(['first_name' => 'Hook', 'last_name' => 'Caido']);
    Fx::consent($l4);
    file_put_contents($hookMode, 'fail');
    $present($l4);
    $h4 = $handoffOf($l4);
    Fx::runJobs();
    $dw = static fn() => Db::one("SELECT * FROM handoff_deliveries WHERE handoff_id = ? AND target = 'webhook'", [$h4['id']]);
    T::ok($dw()['status'] === 'retrying' && Db::value('SELECT status FROM handoffs WHERE id = ?', [$h4['id']]) === 'partial', 'webhook responde 500: reintentando y entrega parcial (el correo salio)');
    $pj = Db::one("SELECT run_at FROM jobs WHERE type = 'handoff_deliver' AND payload LIKE ? AND status = 'pending'", ['%"target":"webhook"%' . '']);
    T::ok($pj !== null, '  el job del webhook queda reprogramado');
    foreach ([70, 400, 1400] as $t) {
        Clock::travel($t);
        Fx::runJobs();
    }
    Clock::travel(0);
    T::ok($dw()['status'] === 'failed' && (int) $dw()['attempts'] === 4 && Db::value('SELECT status FROM handoffs WHERE id = ?', [$h4['id']]) === 'partial', '4 intentos y «failed»; la entrega queda parcial (correo sí, webhook no)');
    // Reenviar: v2, la anterior queda sin efecto
    file_put_contents($hookMode, 'ok');
    $oldUrls = Handoff::urls($h4);
    $rs = Handoff::resend($l4, $aid);
    $h4b = $handoffOf($l4);
    T::ok($rs['ok'] && (int) $h4b['version'] === 2 && Db::value('SELECT confirm_status FROM handoffs WHERE id = ?', [$h4['id']]) === 'superseded', 'reenviar: nace la versión 2 y la 1 queda «superseded»');
    T::eq(Handoff::verifyToken($tokenOf($oldUrls['signed']))['status'], 'used', '  los enlaces de la versión 1 dejan de valer');
    $before = count($hooks());
    Fx::runJobs();
    T::ok(count($hooks()) === $before + 1 && Db::value('SELECT status FROM handoffs WHERE id = ?', [$h4b['id']]) === 'sent', '  la versión 2 se entrega completa');
    $lx = Fx::lead(['stage' => 'new']);
    T::ok(!Handoff::resend($lx, $aid)['ok'], '  (lead en «Nuevo»: no se reenvía nada)');

    // ------------------------------------------------------------------ Prueba de entrega
    $hc = (int) Db::value('SELECT COUNT(*) FROM handoffs');
    $mc = count($mails());
    $hk0 = count($hooks());
    $tr = Handoff::sendTest($aid);
    T::ok($tr['results']['email']['ok'] && $tr['results']['webhook']['ok'], 'enviar prueba: correo y webhook responden');
    T::ok((int) Db::value('SELECT COUNT(*) FROM handoffs') === $hc && count($mails()) === $mc + 1 && count($hooks()) === $hk0 + 1, '  la prueba no crea entregas ni toca leads reales');
    $tm = $mails();
    $tb = $decoded($tm[count($tm) - 1]);
    T::ok(str_contains($tb, '[PRUEBA]') && str_contains($tb, 'Prueba Entrega') && !preg_match('#/c/[A-Za-z0-9_-]{40,}#', $tb), '  el correo va marcado PRUEBA, con un lead ficticio y sin enlaces de confirmación');
    $th = json_decode($hooks()[count($hooks()) - 1]['body'], true);
    T::ok($th['event'] === 'handoff.test' && $th['lead']['name'] === 'Prueba Entrega', '  y el webhook lleva event = handoff.test');

    // ------------------------------------------------------------------ Confirmacion por enlace: Firmó
    $mkOpen = static function (string $name) use ($present, $handoffOf): array {
        $l = Fx::lead(['first_name' => $name, 'last_name' => 'Confirma', 'phone_e164' => '+1305559' . random_int(1000, 9999), 'email' => strtolower($name) . '@example.com', 'source' => 'meta']);
        Fx::consent($l);
        $present($l);
        $h = $handoffOf($l);
        return [$l, $h, Handoff::urls($h)];
    };
    [$l5, $h5, $u5] = $mkOpen('Firma');
    $tkS = $tokenOf($u5['signed']);
    $tkR = $tokenOf($u5['rejected']);
    $g = Infra::http('GET', $panel->base . '/c/' . $tkS);
    $phone5 = (string) Db::value('SELECT phone_e164 FROM leads WHERE id = ?', [$l5]);
    T::ok($g['status'] === 200 && str_contains($g['body'], 'Firma Confirma') && !str_contains($g['body'], $phone5) && !str_contains($g['body'], 'firma@example.com') && !str_contains($g['body'], '+1305'), 'GET /c/{token}: muestra solo el nombre del lead (sin teléfono ni correo)');
    T::ok(str_contains(Infra::header($g, 'x-robots-tag'), 'noindex') && str_contains(Infra::header($g, 'content-security-policy'), "default-src 'none'") && Infra::header($g, 'set-cookie') === '' && !preg_match('/\sstyle="/i', $g['body']), '  noindex, CSP estricta, sin cookies ni estilos en línea');
    T::eq(Db::value('SELECT stage FROM leads WHERE id = ?', [$l5]), 'presented', '  un GET nunca cambia nada (los escáneres de correo abren los enlaces)');
    T::ok(!str_contains($u5['signed'], 'Firma') && !str_contains($u5['signed'], (string) $phone5) && !str_contains($u5['signed'], 'example.com'), 'la URL del token no lleva datos personales');
    $p = Infra::http('POST', $panel->base . '/c/' . $tkS, ['form' => []]);
    $lead5 = Db::one('SELECT * FROM leads WHERE id = ?', [$l5]);
    T::ok($p['status'] === 200 && str_contains($p['body'], 'Firma registrada') && $lead5['stage'] === 'signed' && $lead5['signed_at'] !== null && $lead5['handoff_status'] === 'signed', 'POST «Firmó»: etapa Firmado, signed_at y handoff_status = signed');
    $hh5 = Db::one('SELECT * FROM handoffs WHERE id = ?', [$h5['id']]);
    T::ok($hh5['confirm_status'] === 'signed' && $hh5['confirmed_via'] === 'link' && $hh5['confirmed_at'] !== null, '  la entrega queda confirmada por enlace');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'conversion'", [$l5]) === 1 && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'handoff_confirmed'", [$l5]) === 1, '  registra la conversión (evento conversion)');
    $a12 = Db::one("SELECT * FROM sequence_enrollments WHERE lead_id = ? AND sequence_key = 'a12_referral'", [$l5]);
    T::ok($a12 !== null && abs((int) Clock::parse((string) $a12['next_run_at']) - ((int) Clock::parse((string) $lead5['signed_at']) + 30 * 86400)) <= 2, '  programa A12: pedido de referidos 30 días después de firmar');
    $tgBefore = count($tgs());
    Fx::runJobs();
    $tgNew = array_slice($tgs(), $tgBefore);
    T::ok(count($tgNew) === 1 && str_contains((string) json_encode($tgNew[0]['body']), 'FIRMADO') && str_contains((string) json_encode($tgNew[0]['body']), 'Firma Confirma'), '  avisa por Telegram a Carlos');
    T::eq(Infra::http('POST', $panel->base . '/c/' . $tkS, ['form' => []])['status'], 410, 'token reutilizado (POST): 410');
    T::eq(Infra::http('GET', $panel->base . '/c/' . $tkS)['status'], 410, 'token reutilizado (GET): 410');
    $rj = Infra::http('POST', $panel->base . '/c/' . $tkR, ['form' => ['reason' => 'price']]);
    T::ok($rj['status'] === 410 && Db::value('SELECT stage FROM leads WHERE id = ?', [$l5]) === 'signed', 'el otro botón de la misma entrega ya no sirve (un solo uso en conjunto)');

    // Manipulado y caducado
    [$l6, $h6, $u6] = $mkOpen('Manip');
    $tk6 = $tokenOf($u6['signed']);
    $pl = substr($tk6, 0, -43);
    $sig = substr($tk6, -43);
    $badSig = $pl . substr($sig, 0, -1) . ($sig[strlen($sig) - 1] === 'A' ? 'B' : 'A');
    $badPay = (str_starts_with($pl, 'M') ? 'N' : 'M') . substr($pl, 1) . $sig;
    foreach (['firma alterada' => $badSig, 'payload alterado' => $badPay, 'basura' => str_repeat('a', 50), 'otro lead' => $tkS] as $why => $tk) {
        $c = Infra::http('GET', $panel->base . '/c/' . $tk)['status'];
        T::ok(in_array($c, [404, 410], true) && Db::value('SELECT stage FROM leads WHERE id = ?', [$l6]) === 'presented', "token manipulado ($why): rechazado ($c) y sin cambios");
    }
    T::eq(Handoff::verifyToken($badSig)['status'], 'invalid', '  verifyToken: firma alterada = invalid');
    T::eq(Infra::http('GET', $panel->base . '/c/' . $badSig)['status'], 404, '  y la página responde 404');
    T::eq(Handoff::verifyToken($tk6)['status'], 'ok', 'token vigente: ok');
    Clock::travel(31 * 86400);
    T::eq(Handoff::verifyToken($tk6)['status'], 'expired', 'token a los 31 días: caducado');
    Clock::travel(0);
    Db::run("UPDATE handoffs SET expires_at = ? WHERE id = ?", [Clock::shift(-60), $h6['id']]);
    T::eq(Infra::http('GET', $panel->base . '/c/' . $tk6)['status'], 410, '  y la página responde 410 (caducado en la base)');
    T::eq(Infra::http('POST', $panel->base . '/c/' . $tk6, ['form' => []])['status'], 410, '  el POST de un token caducado tampoco cambia nada');
    T::eq(Db::value('SELECT stage FROM leads WHERE id = ?', [$l6]), 'presented', '  el lead sigue presentado');

    // Rechazo por enlace
    [$l7, $h7, $u7] = $mkOpen('Rechazo');
    $tk7 = $tokenOf($u7['rejected']);
    $g = Infra::http('GET', $panel->base . '/c/' . $tk7);
    T::ok($g['status'] === 200 && str_contains($g['body'], 'name="reason"') && str_contains($g['body'], 'Eligió otro dispatcher') && str_contains($g['body'], 'Rechazado por NorthStar'), 'GET del enlace «Lo rechazamos»: pide motivo de la lista del doc §7 y nota');
    $p = Infra::http('POST', $panel->base . '/c/' . $tk7, ['form' => ['reason' => 'other', 'note' => '']]);
    T::ok($p['status'] === 422 && Db::value('SELECT stage FROM leads WHERE id = ?', [$l7]) === 'presented', 'rechazo con «Otro» sin nota: 422 y no cambia nada (el token sigue vivo)');
    $p = Infra::http('POST', $panel->base . '/c/' . $tk7, ['form' => ['reason' => 'inventado']]);
    T::ok($p['status'] === 422 && Db::value('SELECT stage FROM leads WHERE id = ?', [$l7]) === 'presented', 'motivo fuera de la lista: 422');
    $p = Infra::http('POST', $panel->base . '/c/' . $tk7, ['form' => ['reason' => 'price', 'note' => 'Les parece caro']]);
    $lead7 = Db::one('SELECT * FROM leads WHERE id = ?', [$l7]);
    T::ok($p['status'] === 200 && str_contains($p['body'], 'Rechazo registrado') && $lead7['stage'] === 'lost' && $lead7['lost_reason'] === 'price' && $lead7['handoff_status'] === 'rejected', 'rechazo válido: el lead vuelve a la bandeja como Perdido con el motivo y handoff_status = rejected');
    $hh7 = Db::one('SELECT * FROM handoffs WHERE id = ?', [$h7['id']]);
    T::ok($hh7['confirm_status'] === 'rejected' && $hh7['reject_reason'] === 'price' && $hh7['reject_note'] === 'Les parece caro' && $hh7['confirmed_via'] === 'link', '  la entrega guarda motivo y nota');
    T::ok((int) Db::value("SELECT COUNT(*) FROM tasks WHERE lead_id = ? AND title LIKE 'Revisar el rechazo de NorthStar%' AND done_at IS NULL", [$l7]) === 1, '  y crea una tarea para Carlos');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'handoff_rejected'", [$l7]) === 1 && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'handoff_rejected' AND entity_id = ?", [(string) $l7]) === 1, '  evento y activity_log');
    $tgBefore = count($tgs());
    Fx::runJobs();
    $tgNew = array_slice($tgs(), $tgBefore);
    T::ok(count($tgNew) === 1 && str_contains((string) json_encode($tgNew[0]['body']), 'RECHAZADO') && str_contains((string) json_encode($tgNew[0]['body']), 'Precio'), '  avisa por Telegram con el motivo');
    // Rechazo sin motivo = el motivo por defecto del doc
    [$l8, $h8, $u8] = $mkOpen('Defecto');
    Infra::http('POST', $panel->base . '/c/' . $tokenOf($u8['rejected']), ['form' => []]);
    T::eq(Db::value('SELECT lost_reason FROM leads WHERE id = ?', [$l8]), 'rejected', 'rechazo sin elegir motivo: «Rechazado por NorthStar»');
    // El lead ya no esta presentado
    [$l9, $h9, $u9] = $mkOpen('Movido');
    LeadActions::setStage($l9, 'lost', ['reason' => 'no_answer'], $aid);
    $p = Infra::http('POST', $panel->base . '/c/' . $tokenOf($u9['signed']), ['form' => []]);
    T::ok($p['status'] === 410 && Db::value('SELECT stage FROM leads WHERE id = ?', [$l9]) === 'lost', 'confirmar un lead que ya no está presentado: 410 y no cambia nada');

    // ------------------------------------------------------------------ Marcado manual desde la ficha
    [$l10, $h10] = $mkOpen('Manual');
    $page = $panel->get('/leads/' . $l10);
    T::ok(str_contains($page['body'], 'Marcar firmó') && str_contains($page['body'], 'Marcar rechazado') && str_contains($page['body'], 'Reenviar entrega'), 'la ficha de un lead presentado trae «Marcar firmó», «Marcar rechazado» y «Reenviar entrega»');
    $csrf = $panel->csrfOf($page['body']);
    $r = $panel->post('/leads/' . $l10 . '/handoff-signed', ['_csrf' => $csrf]);
    T::ok($r['status'] === 303 && Db::value('SELECT stage FROM leads WHERE id = ?', [$l10]) === 'signed' && Db::value('SELECT confirmed_via FROM handoffs WHERE id = ?', [$h10['id']]) === 'panel', 'botón «Marcar firmó»: firma por el panel');
    T::ok((int) Db::value("SELECT COUNT(*) FROM sequence_enrollments WHERE lead_id = ? AND sequence_key = 'a12_referral'", [$l10]) === 1, '  y programa A12');
    [$l11, $h11] = $mkOpen('Manual');
    $csrf = $panel->csrfOf($panel->get('/leads/' . $l11)['body']);
    $r = $panel->post('/leads/' . $l11 . '/handoff-rejected', ['_csrf' => $csrf, 'reason' => 'chose_other', 'note' => '']);
    T::ok($r['status'] === 303 && Db::value('SELECT lost_reason FROM leads WHERE id = ?', [$l11]) === 'chose_other' && Db::value('SELECT handoff_status FROM leads WHERE id = ?', [$l11]) === 'rejected', 'botón «Marcar rechazado»: pierde el lead con el motivo elegido');
    [$l12, $h12] = $mkOpen('Manual');
    $csrf = $panel->csrfOf($panel->get('/leads/' . $l12)['body']);
    $r = $panel->post('/leads/' . $l12 . '/handoff-resend', ['_csrf' => $csrf]);
    T::ok($r['status'] === 303 && (int) Db::value('SELECT MAX(version) FROM handoffs WHERE lead_id = ?', [$l12]) === 2, 'botón «Reenviar entrega»: versión 2');
    $r = $panel->post('/leads/' . $l12 . '/handoff-signed', ['_csrf' => 'falso']);
    T::ok(Db::value('SELECT stage FROM leads WHERE id = ?', [$l12]) === 'presented', 'sin CSRF válido el botón no hace nada');
    // La etapa «Firmado» puesta con el selector de etapa de la ficha tambien cierra la entrega y programa A12
    [$l13, $h13] = $mkOpen('Selector');
    LeadActions::setStage($l13, 'signed', [], $aid);
    T::ok(Db::value('SELECT confirm_status FROM handoffs WHERE id = ?', [$h13['id']]) === 'signed' && (int) Db::value("SELECT COUNT(*) FROM sequence_enrollments WHERE lead_id = ? AND sequence_key = 'a12_referral'", [$l13]) === 1, 'cambiar la etapa a Firmado desde el selector tambien cierra la entrega y programa A12');
    // Pipeline B
    $lb = Fx::lead(['profile' => 'company_driver', 'pipeline' => 'B', 'stage' => 'prequalified', 'first_name' => 'Driver', 'last_name' => 'Contrata']);
    Fx::consent($lb);
    LeadActions::setStage($lb, 'presented', [], $aid);
    $hb = $handoffOf($lb);
    T::ok($hb !== null && Handoff::confirm((int) $hb['id'], 'signed', 'panel', $aid)['ok'] && Db::value('SELECT stage FROM leads WHERE id = ?', [$lb]) === 'hired', 'pipeline B: «Presentado» entrega y la firma lleva a «Contratado»');
    // Lector: sin acceso a las acciones
    $vwr = Panel::user('viewer');
    $vp = $panel->another();
    T::ok($vp->login($vwr['email']), 'login del lector');
    $r = $vp->post('/leads/' . $l12 . '/handoff-signed', ['_csrf' => $vp->csrf]);
    T::ok($r['status'] === 403 && Db::value('SELECT stage FROM leads WHERE id = ?', [$l12]) === 'presented', 'un lector no puede marcar firmas (403)');

    // ------------------------------------------------------------------ Pantalla de configuracion
    $cfg = $panel->get('/config?tab=entrega');
    T::ok($cfg['status'] === 200 && !str_contains($cfg['body'], $secret) && str_contains($cfg['body'], 'configurado') && str_contains($cfg['body'], 'Enviar prueba') && str_contains($cfg['body'], 'ÚLTIMAS ENTREGAS'), 'la pantalla de entrega muestra estado y prueba, nunca el secreto');
    $r = $panel->post('/config/handoff/save', ['_csrf' => $panel->csrf, 'email' => 'otro@northstar.test', 'webhook_url' => "http://127.0.0.1:$hookPort/hook", 'webhook_on' => '1', 'secret_mode' => 'generate']);
    Settings::clear();
    $after = $panel->get('/config?tab=entrega');
    preg_match('#<code>([a-f0-9]{64})</code>#', $after['body'], $gm);
    T::ok($r['status'] === 303 && isset($gm[1]) && Handoff::config()['webhook_secret'] === $gm[1] && Handoff::config()['email'] === 'otro@northstar.test', '«Generar uno nuevo»: se muestra una vez y es el que queda guardado');
    T::ok(!str_contains($panel->get('/config?tab=entrega')['body'], $gm[1]), '  y no vuelve a mostrarse');
    $r = $panel->post('/config/handoff/save', ['_csrf' => $panel->csrf, 'email' => 'mal', 'secret_mode' => 'keep']);
    Settings::clear();
    T::ok($r['status'] === 422 && str_contains($r['body'], 'no es válido') && Handoff::config()['email'] === 'otro@northstar.test', 'datos inválidos: 422 y no guarda');
    $r = $panel->post('/config/handoff/test', ['_csrf' => $panel->csrf]);
    T::ok($r['status'] === 303 && Settings::get('handoff.last_test') !== null, 'POST de la prueba por HTTP: ejecuta y guarda el resultado');
    $r = $vp->post('/config/handoff/save', ['_csrf' => $vp->csrf, 'email' => 'x@x.com']);
    T::eq($r['status'], 403, 'un lector no puede tocar la entrega (403)');
    $anon = $panel->another();
    T::eq($anon->post('/config/handoff/save', ['email' => 'x@x.com'])['status'], 303, 'sin sesión: redirige a /login');

    // ------------------------------------------------------------------ Limite de peticiones de la pagina publica
    $last = 0;
    for ($i = 0; $i < 70; $i++) {
        $last = Infra::http('GET', $panel->base . '/c/' . str_repeat('x', 50) . str_repeat('y', 43))['status'];
        if ($last === 429) {
            break;
        }
    }
    T::eq($last, 429, 'la página pública limita las peticiones por IP (429 a partir de 60 por hora)');
    foreach (['panel18', 'hook18', 'tg18', 'smtp18'] as $n) {
        Infra::stop($n);
    }
    Env::set('TELEGRAM_API_BASE', null);
    Env::set('TELEGRAM_BOT_TOKEN', null);
    Env::set('TELEGRAM_CHAT_ID', null);
    Fx::noSmtp();
    Settings::set('handoff', []);
    Clock::travel(0);
    T::reset(true);
};
