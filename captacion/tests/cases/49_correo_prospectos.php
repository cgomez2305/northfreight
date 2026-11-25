<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\JobHandlers;
use Captacion\Services\ProspectMail;
use Captacion\Services\Settings;
use Captacion\Services\Suppression;
use Captacion\Services\Unsub;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase AQ: correos en lote a prospectos FMCSA por segmentos. Exclusiones (no llamar, no interesado, convertidos, bajas, lista de supresión, periodo de espera,
 * inválidos y repetidos), bloqueos para lanzar (dirección física, SMTP, marcadores, prueba posterior a la última edición), envío por la cola con tope por hora,
 * pausa tras 3 rechazos, baja de un clic (bandera + supresión + cola) y píxel de apertura; pantallas y páginas públicas.
 */
return static function (array $ctx): void {
    T::reset(true);
    Fx::noSmtp();
    Settings::set('mail', []);
    Settings::clear();
    $work = $ctx['work'];
    $smtpLog = "$work/smtp49.log";
    $smtpMode = "$work/smtp49.mode";
    @unlink($smtpLog);
    @unlink($smtpMode);
    $port = Infra::smtpMock('smtp49', $smtpLog, $smtpMode);
    $mails = static fn(): array => Infra::readLog($smtpLog);
    $raw = static fn(array $m): string => quoted_printable_decode((string) $m['raw']);
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $now = Clock::time();
    $n = 0;
    $mk = static function (array $o = []) use (&$n): int {
        $n++;
        return (int) Db::insert('prospects_fmcsa', $o + ['usdot' => (string) (9500000 + $n), 'legal_name' => "CARRIER $n LLC", 'state' => 'TX', 'city' => 'DALLAS', 'phone' => '+1305555' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => "carrier$n@example.com", 'fleet_size' => 8, 'authority_granted_at' => '2026-11-10', 'status' => 'to_call', 'listed_on' => '2026-11-20', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    };
    $ok1 = $mk();
    $ok2 = $mk(['state' => 'GA']);
    $small = $mk(['fleet_size' => 1]);
    $dnc = $mk(['status' => 'dnc', 'do_not_call' => 1]);
    $notInt = $mk(['status' => 'not_interested']);
    $conv = $mk(['converted_lead_id' => Fx::lead()]);
    $optOut = $mk(['email_opt_out_at' => Clock::now()]);
    $supp = $mk(['email' => 'Listed@Example.com']);
    Suppression::add('email', 'listed@example.com', Suppression::ERASURE);
    $recent = $mk(['last_emailed_at' => Clock::at($now - 5 * 86400)]);
    $old = $mk(['last_emailed_at' => Clock::at($now - 40 * 86400)]);
    $bad = $mk(['email' => 'no-es-correo']);
    $dup = $mk(['email' => 'CARRIER1@example.com', 'authority_granted_at' => '2026-01-05']);   // misma dirección que el 1, autoridad más vieja: se queda fuera por repetido
    $none = $mk(['email' => null]);

    // ------------------------------------------------------------------ Segmento y destinatarios
    $seg = ProspectMail::segment(['fl' => '6+', 'us' => 'zz', 'ag' => '99', 'tab' => 'dnc']);
    T::eq($seg, ['tab' => '', 'us' => '', 'fl' => '6+', 'ag' => 0, 'lg' => ''], 'segmento: lista blanca (estado inválido, antigüedad inválida y «No llamar más» se descartan)');
    $a = ProspectMail::audience($seg, $now);
    $ids = array_map(static fn($r) => (int) $r['id'], $a['rows']);
    sort($ids);
    T::eq($ids, [$ok1, $ok2, $old], 'destinatarios de flotas de 6 o más: solo los que se pueden escribir (y el de hace 40 días, fuera del periodo de espera)');
    T::eq($a['excluded'], ['blocked' => 3, 'unsubscribed' => 2, 'recent' => 1, 'invalid' => 1, 'duplicate' => 1], '  exclusiones contadas: no llamar/no interesado/convertido, bajas y supresión, periodo de espera, inválido y repetido');
    T::eq([$a['in_segment'], $a['with_email']], [12, 11], '  12 en el segmento, 11 con correo');
    T::eq(ProspectMail::audience(ProspectMail::segment(['fl' => '1']), $now)['n'], 1, '  owner-operators (1 unidad): solo el de un camión');
    T::eq(ProspectMail::audience(ProspectMail::segment(['fl' => '6+', 'us' => 'GA']), $now)['n'], 1, '  por estado: GA = 1');
    Settings::set('prospect_mail', ['cap_hour' => 30, 'cooldown_days' => 0]);
    Settings::clear();
    T::eq(ProspectMail::audience($seg, $now)['n'], 4, '  sin periodo de espera entra también el de hace 5 días');
    Settings::set('prospect_mail', ['cap_hour' => 30, 'cooldown_days' => 30]);
    Settings::clear();

    // ------------------------------------------------------------------ Validación y render
    $v = ProspectMail::validate(['name' => 'Flotas', 'subject' => 'Hi {empresa}', 'body' => "Hello {empresa} in {estado} with {flota}. {secreto}", 'fl' => '6+']);
    T::ok(!$v['ok'] && str_contains($v['errors']['body'] ?? '', '{secreto}'), 'validación: una variable desconocida no se acepta');
    $st = ProspectMail::STARTERS['fleet'];
    $v = ProspectMail::validate(['name' => 'Flotas grandes', 'subject' => $st['subject'], 'body' => $st['body'], 'fl' => '6+']);
    T::ok($v['ok'], 'validación: el texto de partida de flotas es válido');
    $cid = ProspectMail::create($v['values'], $aid);
    $c = ProspectMail::find($cid);
    $p1 = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ?', [$ok1]);
    $r = ProspectMail::render($c, $p1, Unsub::prospectUrl($ok1), Unsub::openUrl(77));
    T::ok($r['subject'] === 'A dispatch desk for Carrier 1 LLC' && str_contains($r['text'], 'With 8 trucks on the road') && $r['unresolved'] === [], 'render: variables del prospecto (razón social legible, flota)');
    T::ok(str_contains($r['text'], 'public FMCSA registry') && str_contains($r['text'], Unsub::prospectUrl($ok1)) && str_contains($r['html'], Unsub::openUrl(77)) && str_contains($r['html'], 'width="1" height="1"'), '  pie con el motivo real (registro público de FMCSA), baja de un clic y píxel de apertura');

    // ------------------------------------------------------------------ Bloqueos para lanzar
    $b = ProspectMail::launchBlockers($c);
    T::ok(count(array_filter($b, static fn($x) => str_contains($x, 'dirección física'))) === 1 && count(array_filter($b, static fn($x) => str_contains($x, 'SMTP'))) === 1 && count(array_filter($b, static fn($x) => str_contains($x, 'prueba'))) === 1, 'lanzar exige dirección física, SMTP y una prueba');
    T::ok(!ProspectMail::launch($cid, $aid)['ok'] && ProspectMail::find($cid)['status'] === 'draft', '  sin eso no se lanza');
    Settings::set('mail', ['address' => '30 N Gould St, Ste N, Sheridan, WY 82801', 'from_name' => 'NorthStar Freight Solutions']);
    Settings::clear();
    Fx::smtp($port);
    $t = ProspectMail::sendTest($cid, 'carlos@example.com', $aid);
    $m = $mails();
    T::ok($t['ok'] && count($m) === 1 && str_contains($raw($m[0]), '[PRUEBA]') && in_array('carlos@example.com', (array) $m[0]['to'], true), 'prueba: sale al correo del administrador con «[PRUEBA]»');
    T::eq(ProspectMail::launchBlockers(ProspectMail::find($cid)), [], '  con dirección, SMTP y prueba ya no hay bloqueos');
    Clock::travel(5);
    $v2 = ProspectMail::validate(['name' => 'Flotas grandes', 'subject' => $st['subject'], 'body' => $st['body'] . "\n\n[[CONFIRMAR: añade un caso]]", 'fl' => '6+']);
    ProspectMail::update($cid, $v2['values'], $aid);
    $b = ProspectMail::launchBlockers(ProspectMail::find($cid));
    T::ok(count(array_filter($b, static fn($x) => str_contains($x, 'sin resolver'))) === 1 && count(array_filter($b, static fn($x) => str_contains($x, 'prueba'))) === 1, '  editar después de la prueba pide otra, y un marcador [[CONFIRMAR]] bloquea');
    ProspectMail::update($cid, $v['values'], $aid);
    Clock::travel(10);
    ProspectMail::sendTest($cid, 'carlos@example.com', $aid);

    // ------------------------------------------------------------------ Lanzar y enviar por la cola, con tope por hora
    Settings::set('prospect_mail', ['cap_hour' => 1, 'cooldown_days' => 30]);
    Settings::clear();
    $l = ProspectMail::launch($cid, $aid);
    $c = ProspectMail::find($cid);
    T::ok($l['ok'] && $c['status'] === 'sending' && (int) $c['total'] === 3 && (int) Db::value("SELECT COUNT(*) FROM prospect_campaign_recipients WHERE campaign_id = ? AND status = 'queued'", [$cid]) === 3, 'lanzar: 3 destinatarios en cola y campaña «Enviando»');
    T::ok(isset(JobHandlers::all()['prospect_mail_tick']) && (int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'prospect_mail_tick' AND status = 'pending'") === 1, '  y un trabajo en la cola');
    Db::run('UPDATE prospects_fmcsa SET status = ? WHERE id = ?', ['not_interested', $old]);   // dice que no después de lanzar (es el primero de la cola)
    $before = count($mails());
    ProspectMail::tick(['campaign_id' => $cid]);
    $st2 = ProspectMail::stats($cid);
    T::eq([$st2['sent'], $st2['skipped'], $st2['queued']], [1, 1, 1], 'envío: con tope de 1 por hora sale 1; el «no interesado» de después de lanzar se omite sin gastar el tope; el resto espera');
    T::eq(count($mails()) - $before, 1, '  1 correo en el servidor de correo');
    T::eq(Db::value('SELECT skip_reason FROM prospect_campaign_recipients WHERE prospect_id = ?', [$old]), 'no llamar más o no interesado', '  con su motivo');
    $last = $raw($mails()[count($mails()) - 1]);
    T::ok(str_contains($last, 'List-Unsubscribe: <') && str_contains($last, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click') && str_contains($last, '/o/'), '  cada correo lleva List-Unsubscribe de un clic y el píxel');
    ProspectMail::tick(['campaign_id' => $cid]);
    T::eq(ProspectMail::stats($cid)['sent'], 1, '  el tope de la hora se respeta en el siguiente lote');
    Clock::travel(3700);
    ProspectMail::tick(['campaign_id' => $cid]);
    $st2 = ProspectMail::stats($cid);
    T::ok($st2['sent'] === 2 && $st2['queued'] === 0 && ProspectMail::find($cid)['status'] === 'done', '  pasada la hora sale el que faltaba y la campaña termina');
    T::ok(Db::value('SELECT last_emailed_at FROM prospects_fmcsa WHERE id = ?', [$ok1]) !== null && Db::value('SELECT last_emailed_at FROM prospects_fmcsa WHERE id = ?', [$ok2]) !== null, '  y marca a los prospectos como escritos (periodo de espera)');

    // ------------------------------------------------------------------ Aperturas y baja
    $rid = (int) Db::value("SELECT id FROM prospect_campaign_recipients WHERE prospect_id = ? AND status = 'sent'", [$ok1]);
    T::eq(Unsub::verifyOpen(substr((string) strrchr(Unsub::openUrl($rid), '/'), 1)), $rid, 'píxel: token firmado del destinatario');
    ProspectMail::recordOpen($rid);
    ProspectMail::recordOpen($rid);
    $o = Db::one('SELECT opened_at, open_count FROM prospect_campaign_recipients WHERE id = ?', [$rid]);
    T::ok($o['opened_at'] !== null && (int) $o['open_count'] === 2 && ProspectMail::stats($cid)['opened'] === 1, '  primera apertura y conteo');
    $tokP = substr((string) strrchr(Unsub::prospectUrl($ok2), '/'), 1);
    T::ok(Unsub::verifyProspect($tokP) === $ok2 && Unsub::verify($tokP) === null && Unsub::verifyProspect(substr((string) strrchr(Unsub::url(5), '/'), 1)) === null, 'baja: el token de un prospecto no sirve como token de lead ni al revés');
    $u = ProspectMail::unsubscribe($ok2, 'unsubscribe_link');
    T::ok($u['ok'] && !$u['already'] && Db::value('SELECT email_opt_out_at FROM prospects_fmcsa WHERE id = ?', [$ok2]) !== null && Suppression::reason('email', 'carrier2@example.com') === Suppression::EMAIL_UNSUB, '  marca al prospecto y lo pone en la lista de supresión');
    T::ok(ProspectMail::unsubscribe($ok2, 'unsubscribe_link')['already'], '  idempotente');
    T::eq(Suppression::check(null, 'carrier2@example.com')['drop'], [], '  la baja de prospectos no quita consentimientos a un lead que después se apunte en la web');

    // ------------------------------------------------------------------ Pausa por rechazos del servidor de correo
    $mk2 = [];
    for ($i = 0; $i < 4; $i++) {
        $mk2[] = $mk(['email' => "flota$i@example.com", 'fleet_size' => 12]);
    }
    Settings::set('prospect_mail', ['cap_hour' => 50, 'cooldown_days' => 30]);
    Settings::clear();
    $c2 = ProspectMail::create(ProspectMail::validate(['name' => 'Rechazos', 'subject' => 'Hi {empresa}', 'body' => 'Hello {empresa}, a dispatch desk for your trucks.', 'fl' => '6+'])['values'], $aid);
    ProspectMail::sendTest($c2, 'carlos@example.com', $aid);
    $dbg = ProspectMail::launch($c2, $aid);
    T::ok($dbg['ok'] && (int) ProspectMail::find($c2)['total'] === 4, 'segunda campaña: 4 destinatarios (los de la primera están en espera o dados de baja, y el que comparte correo con uno ya escrito tampoco repite)');
    file_put_contents($smtpMode, 'reject');
    ProspectMail::tick(['campaign_id' => $c2]);
    $c2r = ProspectMail::find($c2);
    T::ok($c2r['status'] === 'paused' && str_contains((string) $c2r['paused_reason'], '3 envíos') && ProspectMail::stats($c2)['failed'] === 3 && ProspectMail::stats($c2)['queued'] === 1, '3 rechazos seguidos: la campaña se pausa sola y no sigue intentando');
    file_put_contents($smtpMode, 'ok');
    T::ok(ProspectMail::resume($c2, $aid)['ok'], '  se reanuda a mano');
    ProspectMail::tick(['campaign_id' => $c2]);
    T::ok(ProspectMail::stats($c2)['sent'] === 1 && ProspectMail::find($c2)['status'] === 'done', '  y termina con lo que faltaba');
    T::ok(!ProspectMail::cancel($c2, $aid)['ok'], 'una campaña terminada no se cancela');

    // ------------------------------------------------------------------ Pantallas y páginas públicas
    $s = Panel::start($ctx, 'panel-pm49', ['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string) $port, 'SMTP_USER' => 'crm@example.com', 'SMTP_PASS' => 'pw-smtp-prueba', 'SMTP_SECURE' => 'none']);
    T::ok($s->login($admin['email']), 'login del administrador');
    $p = $s->get('/prospectos/correos')['body'];
    T::ok(str_contains($p, 'Correos a prospectos') && str_contains($p, 'Flotas grandes') && str_contains($p, 'Rechazos') && str_contains($p, 'name="cap_hour"'), 'lista de campañas con ajustes de envío');
    $f = $s->get('/prospectos/correos/nueva?inicio=owner')['body'];
    T::ok(str_contains($f, 'Dispatch for {empresa}') && str_contains($f, '<option value="1" selected') && str_contains($f, 'saldr') && str_contains($f, 'data-var="{flota}"'), 'nueva campaña desde el texto de owner-operators: segmento, conteo y variables');
    $csrf = $s->csrfOf($f);
    $r = $s->post('/prospectos/correos/nueva', ['_csrf' => $csrf, 'do' => 'save', 'name' => 'Owners TX', 'subject' => 'Dispatch for {empresa}', 'body' => 'Hello from NorthStar, a dispatch desk for {empresa}.', 'fl' => '1', 'us' => 'TX']);
    $loc = Infra::header($r, 'location');
    T::ok($r['status'] === 303 && preg_match('#^/prospectos/correos/(\d+)$#', $loc, $mm) === 1, 'guardar borrador: lleva a su página');
    $d = $s->get($loc)['body'];
    T::ok(str_contains($d, 'Envíate una prueba después del último cambio') && str_contains($d, 'disabled><span class="btn__t">Lanzar'), '  el borrador avisa de lo que falta y el botón Lanzar está desactivado');
    $show = $s->get('/prospectos/correos/' . $cid)['body'];
    T::ok(str_contains($show, 'Terminada') && str_contains($show, 'Abiertos') && str_contains($show, 'carrier1@example.com'), 'campaña terminada: resultados y destinatarios');
    $list = $s->get('/prospectos?tab=to_call')['body'];
    T::ok(str_contains($list, 'href="/prospectos/correos"') && str_contains($list, 'Último correo en lote'), 'Prospectos: acceso a los correos en lote y rastro del último correo en la ficha');
    $pub = $s->another();
    $g = $pub->get('/u/' . $tokP)['body'];
    T::ok(str_contains($g, 'Confirm unsubscribe'), 'página pública de baja de un prospecto: pide confirmación');
    $tok3 = substr((string) strrchr(Unsub::prospectUrl($ok1), '/'), 1);
    $pr = $pub->post('/u/' . $tok3, []);
    T::ok($pr['status'] === 200 && str_contains($pr['body'], 'You are unsubscribed') && Db::value('SELECT email_opt_out_at FROM prospects_fmcsa WHERE id = ?', [$ok1]) !== null, '  el POST aplica la baja');
    $px = $pub->get('/o/' . substr((string) strrchr(Unsub::openUrl($rid), '/'), 1));
    T::ok($px['status'] === 200 && str_contains(Infra::header($px, 'content-type'), 'image/gif'), 'píxel público: imagen GIF');
    T::ok($pub->get('/prospectos/correos')['status'] !== 200, 'sin sesión no se ve la pantalla de campañas');
};
