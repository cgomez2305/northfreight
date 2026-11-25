<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\DailyDigest;
use Captacion\Services\JobHandlers;
use Captacion\Services\Notifications;
use Captacion\Services\ProspectMail;
use Captacion\Services\ProspectReplies;
use Captacion\Services\ProspectReport;
use Captacion\Services\Prospects;
use Captacion\Services\Settings;
use Captacion\Services\Unsub;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase AT (2026-11-25): barra lateral minimalista; captación saliente (segundo correo, clics, respuestas leídas del buzón por IMAP de solo lectura,
 * «No interesado» de vuelta a la lista, métricas en Reportes, resumen diario y Resultados) y calendario móvil (agenda del día).
 */
return static function (array $ctx): void {
    T::reset(true);
    Fx::noSmtp();
    Settings::set('mail', ['address' => '30 N Gould St, Ste N, Sheridan, WY 82801', 'from_name' => 'NorthStar Freight Solutions']);
    Settings::set('prospect_mail', []);
    Settings::set('prospect_mail.inbox', []);
    Settings::clear();
    $work = $ctx['work'];
    $root = $ctx['root'];
    $panelCss = (string) file_get_contents($root . '/public/assets/panel.css');

    // ------------------------------------------------------------------ Barra lateral
    T::ok(str_contains($panelCss, '.side__nav{') && preg_match('/\.side__nav\{[^}]*scrollbar-width:none/', $panelCss) === 1 && str_contains($panelCss, '.side__nav::-webkit-scrollbar{display:none}'), 'barra lateral sin barra de desplazamiento a la vista (un filete arriba o abajo avisa de que hay más)');
    T::ok(str_contains($panelCss, '@container side (max-width:100px)') && substr_count($panelCss, 'html[data-side=collapsed] .brand') === 0, '  el modo riel es UNA regla (container query) en lugar de dos copias');
    T::ok(!str_contains($panelCss, '.side__group') && !str_contains($panelCss, '.sys{'), '  sin títulos de grupo ni tarjeta de estado del sistema');
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $s = Panel::start($ctx, 'panel-at52');
    T::ok($s->login($admin['email']), 'login del administrador');
    $d = $s->get('/')['body'];
    $side = (string) preg_replace('#^.*?<aside class="side"(.*?)</aside>.*$#s', '$1', $d);
    T::ok(substr_count($side, '<hr class="side__sep">') === 3 && !str_contains($side, 'side__group'), '  secciones separadas por filetes, sin títulos');
    T::ok(str_contains($side, 'class="icon-btn side__fold"') && str_contains($side, 'aria-label="Contraer barra lateral"') && preg_match('#<div class="side__head">.*?side__fold#s', $side) === 1, '  «Contraer» es un botón de icono junto al logo');
    T::ok(str_contains($side, 'aria-label="Buscar o ejecutar"') && preg_match('#<button class="user"[^>]*aria-label="Cuenta de #', $side) === 1, '  buscador y cuenta con nombre accesible también en el riel');
    T::ok(str_contains($side, 'class="side__vh"') && str_contains($side, 'aria-controls="side-vl"') && str_contains($side, 'aria-label="Vistas guardadas"'), '  «Vistas» se pliega con un botón accesible');
    T::ok(str_contains($d, 'href="/#cola"') && str_contains($d, 'Estado del sistema'), '  el estado del sistema vive en el menú de la cuenta (con un punto en el avatar si hay avisos)');

    // ------------------------------------------------------------------ Campaña con segundo correo
    $smtpLog = "$work/smtp52.log";
    @unlink($smtpLog);
    $port = Infra::smtpMock('smtp52', $smtpLog);
    Fx::smtp($port);
    $mails = static fn(): array => Infra::readLog($smtpLog);
    $raw = static fn(array $m): string => quoted_printable_decode((string) $m['raw']);
    $n = 0;
    $mk = static function (array $o = []) use (&$n): int {
        $n++;
        return (int) Db::insert('prospects_fmcsa', $o + ['usdot' => (string) (9700000 + $n), 'legal_name' => "OUTBOUND $n LLC", 'state' => 'TX', 'city' => 'DALLAS', 'phone' => '+1305557' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => "out$n@example.com", 'fleet_size' => 8, 'authority_granted_at' => '2026-11-01', 'status' => 'to_call', 'listed_on' => '2026-11-20', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    };
    $p1 = $mk();
    $p2 = $mk();
    $p3 = $mk();
    $bad = ProspectMail::validate(['name' => 'Seguimiento', 'subject' => 'Hi {empresa}', 'body' => str_repeat('Texto de prueba. ', 3), 'fl' => '6+', 'followup_days' => '3', 'followup_subject' => '', 'followup_body' => 'corto']);
    T::ok(!$bad['ok'] && isset($bad['errors']['followup_subject'], $bad['errors']['followup_body']), 'segundo correo: si está elegido, exige asunto y mensaje');
    $v = ProspectMail::validate(['name' => 'Seguimiento', 'subject' => 'Dispatch for {empresa}', 'body' => "Hi,\n\nTry our calculator: {link_calculadora}\n\nNorthStar", 'fl' => '6+',
        'followup_days' => '3', 'followup_subject' => 'Following up, {empresa}', 'followup_body' => "Hi again,\n\nStill here if you need a dispatch desk: {link_web}"]);
    T::ok($v['ok'] && $v['values']['followup_days'] === 3, '  con texto válido se acepta (a los 3 días)');
    $cid = ProspectMail::create($v['values'], $aid);
    $t = ProspectMail::sendTest($cid, 'carlos@example.com', $aid);
    $m = $mails();
    T::ok($t['ok'] && count($m) === 2 && str_contains($raw($m[1]), '2.º_correo') && str_contains($raw($m[0]), 'northstarfreightsolutions.us/resources/rate-per-mile-calculator/'), '  la prueba manda los dos correos (la prueba lleva los enlaces directos)');
    T::ok(ProspectMail::launch($cid, $aid)['ok'], 'lanzar la campaña');
    ProspectMail::tick(['campaign_id' => $cid]);
    $st = ProspectMail::stats($cid);
    T::ok($st['sent'] === 3 && $st['fu_wait'] === 3 && ProspectMail::find($cid)['status'] === 'sending', '  salen los 3 primeros correos y la campaña espera la fecha del segundo');
    $sent = $raw($mails()[4]);
    T::ok(preg_match('#/r/([A-Za-z0-9_-]{20,100})#', $sent, $rm) === 1 && !str_contains($sent, 'rate-per-mile-calculator'), '  en el envío real los enlaces cuentan clics (/r/…)');
    T::ok((int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'prospect_mail_tick' AND status = 'pending' AND run_at > ?", [Clock::at(Clock::time() + 2 * 86400)]) === 1, '  y deja programada la vuelta para dentro de 3 días');

    // ------------------------------------------------------------------ Clics
    $rid1 = (int) Db::value('SELECT id FROM prospect_campaign_recipients WHERE prospect_id = ?', [$p1]);
    T::eq(Unsub::verifyClick(substr(Unsub::clickUrl($rid1, 1), strrpos(Unsub::clickUrl($rid1, 1), '/') + 1)), [$rid1, 1], 'token de clic: destinatario y destino firmados');
    $tok = substr(Unsub::clickUrl($rid1, 1), strrpos(Unsub::clickUrl($rid1, 1), '/') + 1);
    $r = Infra::http('GET', $s->base . '/r/' . $tok);
    T::ok($r['status'] === 302 && Infra::header($r, 'location') === 'https://northstarfreightsolutions.us/resources/rate-per-mile-calculator/', '/r/{token}: redirige a la calculadora del sitio');
    $row = Db::one('SELECT clicked_at, click_count, opened_at FROM prospect_campaign_recipients WHERE id = ?', [$rid1]);
    T::ok($row['clicked_at'] !== null && (int) $row['click_count'] === 1 && $row['opened_at'] !== null, '  y cuenta el clic (y la apertura)');
    $r = Infra::http('GET', $s->base . '/r/' . substr($tok, 0, -3) . 'AAA');
    T::ok($r['status'] === 302 && Infra::header($r, 'location') === 'https://northstarfreightsolutions.us/' && (int) Db::value('SELECT click_count FROM prospect_campaign_recipients WHERE id = ?', [$rid1]) === 1, '  un token falso no cuenta nada y lleva a la página principal (nunca a una URL de la petición)');

    // ------------------------------------------------------------------ Respuestas por IMAP (solo lectura)
    $msgFile = "$work/imap52.json";
    $imapLog = "$work/imap52.log";
    @unlink($imapLog);
    $date = gmdate('D, d M Y H:i:s +0000', Clock::time() + 60);
    file_put_contents($msgFile, json_encode([
        ['uid' => 10, 'headers' => "From: Someone Else <nobody@example.org>\nDate: $date\nSubject: hola"],
        ['uid' => 11, 'headers' => "From: Out 2 <OUT2@example.com>\nDate: $date\nSubject: Re: Dispatch for Outbound 2 LLC"],
        ['uid' => 12, 'headers' => "From: out3@example.com\nDate: $date\nSubject: Automatic reply: out of office\nAuto-Submitted: auto-replied"],
    ]));
    $iport = Infra::imapMock('imap52', $msgFile, $imapLog);
    foreach (['IMAP_HOST' => '127.0.0.1', 'IMAP_PORT' => (string) $iport, 'IMAP_USER' => 'contact@example.com', 'IMAP_PASS' => 'pw-imap', 'IMAP_SECURE' => 'none'] as $k => $val) {
        Env::set($k, $val);
    }
    Clock::travel(120);
    $sc = ProspectReplies::scan();
    T::ok($sc['ok'] && $sc['read'] === 3 && $sc['found'] === 1, 'respuestas: lee 3 correos nuevos y reconoce 1 respuesta (ignora a un desconocido y la respuesta automática)');
    $rid2 = (int) Db::value('SELECT id FROM prospect_campaign_recipients WHERE prospect_id = ?', [$p2]);
    T::ok(Db::value('SELECT replied_at FROM prospect_campaign_recipients WHERE id = ?', [$rid2]) !== null && Db::value('SELECT email_replied_at FROM prospects_fmcsa WHERE id = ?', [$p2]) !== null, '  marca la respuesta en el destinatario y en el prospecto');
    $log = (string) file_get_contents($imapLog);
    T::ok(str_contains($log, 'EXAMINE "INBOX"') && str_contains($log, 'BODY.PEEK[HEADER.FIELDS') && !preg_match('/\bSELECT\b|\bSTORE\b|\bEXPUNGE\b|\bCOPY\b|\bMOVE\b/i', $log), '  en solo lectura: EXAMINE y BODY.PEEK, nunca SELECT, STORE, EXPUNGE ni MOVE');
    $today = array_map('intval', array_column(Prospects::page(Prospects::filters([]), Clock::time(), 1, 50)['rows'], 'id'));
    T::eq($today[0] ?? 0, $p2, '  quien respondió sale el primero en la Lista de hoy');
    $notes = Notifications::all($aid);
    T::ok(count(array_filter($notes, static fn($x) => str_starts_with((string) $x['key'], 'prospect_replies:1:'))) === 1, '  y la campana avisa');
    $sc2 = ProspectReplies::scan();
    T::ok($sc2['ok'] && $sc2['found'] === 0 && ProspectReplies::state()['last_uid'] === 12, '  la siguiente lectura sigue desde el último correo y no duplica');
    T::ok(isset(JobHandlers::all()['prospect_reply_scan']), '  hay trabajo de cola para leer cada 10 minutos');
    Settings::set('prospect_mail', ['cap_hour' => 30, 'cooldown_days' => 30, 'read_replies' => true]);
    Settings::clear();
    ProspectReplies::job();
    T::eq((int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'prospect_reply_scan' AND status = 'pending'"), 1, '  encendido, se vuelve a programar solo');

    // ------------------------------------------------------------------ Segundo correo: solo a quien no abrió, no hizo clic ni respondió
    Clock::travel(3 * 86400 + 60);
    ProspectMail::tick(['campaign_id' => $cid]);
    $fu = Db::all('SELECT prospect_id, fu_status, fu_skip_reason FROM prospect_campaign_recipients WHERE campaign_id = ? ORDER BY prospect_id', [$cid]);
    T::eq(array_map(static fn($x) => [(int) $x['prospect_id'], $x['fu_status'], $x['fu_skip_reason']], $fu), [[$p1, 'skipped', 'hizo clic'], [$p2, 'skipped', 'respondió'], [$p3, 'sent', null]], 'a los 3 días el segundo correo sale solo al que no abrió, no hizo clic ni respondió');
    T::ok(str_contains($raw($mails()[count($mails()) - 1]), 'Following up, Outbound 3 LLC') && ProspectMail::find($cid)['status'] === 'done', '  con su propio asunto, y la campaña termina');
    $c = ProspectMail::stats($cid);
    T::ok($c['clicked'] === 1 && $c['replied'] === 1 && $c['fu_sent'] === 1, '  los resultados cuentan clics, respuestas y segundos correos');
    $sh = $s->get('/prospectos/correos/' . $cid)['body'];
    T::ok(str_contains($sh, 'Respondieron') && str_contains($sh, 'Segundo correo · a los 3 días') && str_contains($sh, '?ver=replied'), '  y la pantalla de la campaña los muestra');

    // ------------------------------------------------------------------ «No interesado» vuelve a la lista
    $ni7 = $mk(['status' => 'not_interested', 'closed_at' => Clock::at(Clock::time() - 220 * 86400)]);
    $ni5 = $mk(['status' => 'not_interested', 'closed_at' => Clock::at(Clock::time() - 150 * 86400)]);
    $dnc = $mk(['status' => 'dnc', 'do_not_call' => 1, 'closed_at' => Clock::at(Clock::time() - 400 * 86400)]);
    T::eq(Prospects::relist(Clock::time()), 0, '«No interesado» de vuelta: apagado por defecto');
    Settings::set('prospects.config', ['relist_months' => 6] + \Captacion\Services\ProspectConfig::DEFAULTS);
    Settings::clear();
    T::eq(Prospects::relist(Clock::time()), 1, '  con 6 meses vuelve solo el de hace más de 6 meses');
    T::eq([Db::value('SELECT status FROM prospects_fmcsa WHERE id = ?', [$ni7]), Db::value('SELECT status FROM prospects_fmcsa WHERE id = ?', [$ni5]), Db::value('SELECT status FROM prospects_fmcsa WHERE id = ?', [$dnc])], ['to_call', 'not_interested', 'dnc'], '  «No llamar más» nunca vuelve');

    // ------------------------------------------------------------------ Métricas
    Db::insert('prospect_calls', ['prospect_id' => $p1, 'user_id' => $aid, 'result' => 'no_answer', 'attempt_no' => 1, 'created_at' => gmdate('Y-m-d H:i:s')]);   // hora real: el servidor de la prueba no viaja en el tiempo
    Db::insert('prospect_calls', ['prospect_id' => $p2, 'user_id' => $aid, 'result' => 'interested', 'attempt_no' => 0, 'created_at' => gmdate('Y-m-d H:i:s')]);
    $sum = ProspectReport::summary(Clock::time() - 30 * 86400, Clock::time() + 60);
    T::ok($sum['calls'] === 2 && $sum['reached'] === 1 && $sum['interested'] === 1 && $sum['mail']['sent'] === 3 && $sum['mail']['followups'] === 1 && $sum['mail']['replied'] === 1, 'reportes: llamadas, contestadas, interesados, correos, segundos correos y respuestas del rango');
    $fl = ProspectReport::breakdown('fleet', time() - 86400, Clock::time() + 60);
    T::ok(count($fl) === 1 && $fl[0]['label'] === '6 o más' && $fl[0]['called'] === 2 && $fl[0]['interested'] === 1, '  y desglose por flota');
    $rp = $s->get('/reportes')['body'];
    T::ok(str_contains($rp, 'Captación saliente · prospectos FMCSA') && str_contains($rp, 'Por antigüedad de la autoridad') && str_contains($rp, 'Campañas de correo con envíos en el rango'), '  /reportes tiene la sección de captación saliente');
    $dg = DailyDigest::compute(time() + 86400);
    T::ok(str_contains($dg['text'], 'Prospectos ayer: 2 llamadas (1 contestaron, 1 interesados)') && str_contains($dg['text'], 'Lista de llamadas de hoy:'), 'resumen diario: llamadas y correos de ayer y la lista de hoy');
    $viewer = Panel::user('viewer');
    $vs = $s->another();
    T::ok($vs->login($viewer['email']), 'login del lector');
    $res = $vs->get('/resultados?r=90d')['body'];   // el lector abre en «Mes pasado»: el rango de 90 días incluye las llamadas de hoy
    T::ok(str_contains($res, 'Captación saliente') && str_contains($res, 'Respuestas por correo') && stripos($res, 'Outbound 2') === false && !str_contains($res, 'href="/prospectos"'), 'Resultados de NorthStar: el trabajo saliente en totales, sin nombres ni enlaces internos');

    // ------------------------------------------------------------------ Calendario móvil
    $cal = $s->get('/calendario')['body'];
    T::ok(str_contains($cal, 'data-cal-ag') && str_contains($cal, 'id="agenda"') && str_contains($cal, 'data-ag-l="'), 'calendario: agenda del día bajo el mes (móvil), con cada día listo para cambiar sin recargar');
    $cj = (string) file_get_contents($root . '/public/assets/calendario.js');
    T::ok(str_contains($cj, "e.pointerType !== 'touch'") && str_contains($cj, "'touchmove'") && str_contains($cj, 'passive: false'), '  y mover una tarea con el dedo (mantener pulsado y arrastrar)');

    foreach (['IMAP_HOST', 'IMAP_PORT', 'IMAP_USER', 'IMAP_PASS', 'IMAP_SECURE'] as $k) {
        Env::set($k, null);
    }
    Fx::noSmtp();
};
