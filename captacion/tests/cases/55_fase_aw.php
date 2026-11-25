<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\ProspectAutoMail;
use Captacion\Services\ProspectConfig;
use Captacion\Services\ProspectImport;
use Captacion\Services\ProspectMail;
use Captacion\Services\ProspectReport;
use Captacion\Services\Prospects;
use Captacion\Services\ProspectScript;
use Captacion\Services\Settings;
use Captacion\Services\SocrataClient;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\SocrataFixture;
use CaptacionTests\T;

/**
 * Fase AW (2026-11-25, Carlos: «realiza todo lo que consideres que nos ayudará a captar más leads con el CRM»): búsqueda diaria por rango de antigüedad,
 * idioma preferido del prospecto, intentos y espaciado configurables, cadencia de correos automáticos (presentación, tras no contestar y cierre) en inglés y español,
 * campañas en español con segmento por idioma, y Reportes por idioma, origen y hora a la que contestan.
 */
return static function (array $ctx): void {
    T::reset(true);
    Fx::noSmtp();
    Settings::set('mail', []);
    Settings::clear();
    Clock::travel(0);
    $work = $ctx['work'];
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $et = static fn(string $s): int => (new DateTimeImmutable($s, new DateTimeZone('America/New_York')))->getTimestamp();
    $n = 0;
    $mk = static function (array $o = []) use (&$n): int {
        $n++;
        return (int) Db::insert('prospects_fmcsa', $o + ['usdot' => (string) (9800000 + $n), 'legal_name' => "AW CARRIER $n LLC", 'state' => 'TX', 'city' => 'DALLAS', 'phone' => '+1469556' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'authority_granted_at' => '2026-06-01', 'fleet_size' => 1, 'status' => 'to_call', 'attempts' => 0, 'listed_on' => '2026-11-20', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    };

    // ------------------------------------------------------------------ Búsqueda por rango de antigüedad
    T::eq(ProspectConfig::DEFAULTS['min_age_months'], 0, 'búsqueda: sin antigüedad mínima por defecto (igual que antes)');
    T::ok(isset(ProspectConfig::normalize(['min_age_months' => '12', 'max_age_months' => '12'])['errors']['min_age_months']), '  la mínima debe ser menor que la máxima');
    T::ok(isset(ProspectConfig::normalize(['min_age_months' => '24'])['errors']['min_age_months']), '  la mínima va de 0 a 23');
    $v = ProspectConfig::normalize(['min_age_months' => '3', 'max_age_months' => '24']);
    T::ok(!$v['errors'] && $v['clean']['min_age_months'] === 3 && $v['clean']['max_age_months'] === 24, '  3 a 24 meses es válido');
    T::ok(str_contains(ProspectImport::grantsParams('20241105', null, 10, '20260805')['$where'], "status_change_date<='20260805'"), '  la consulta a FMCSA lleva el tope de fecha');
    T::ok(!str_contains(ProspectImport::grantsParams('20241105', null, 10)['$where'], 'status_change_date<='), '  sin mínima no lo lleva');

    $dir = "$work/socrata55";
    @mkdir($dir, 0777, true);
    $grants = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/socrata/authhist_granted.json'), true);
    foreach ($grants as $i => &$g) {
        if ($i % 2 === 0) {
            $g['status_change_date'] = '20260601';   // la mitad, con 5 meses de autoridad
        }
    }
    unset($g);
    file_put_contents("$dir/authhist_granted.json", json_encode($grants));
    copy(dirname(__DIR__) . '/fixtures/socrata/census.json', "$dir/census.json");
    $fx = new SocrataFixture($dir);
    $client = new SocrataClient($fx->http(), static function (float $s): void {}, 'https://example.test');
    Clock::travel($et('2026-11-05 10:00') - time());
    Settings::set('prospects.config', ['min_age_months' => 3, 'max_age_months' => 24] + ProspectConfig::DEFAULTS);
    Settings::clear();
    $r = ProspectImport::tick(Clock::time(), ['client' => $client, 'force' => true]);
    $newCount = (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa');
    T::ok($r['status'] === 'ran' && ($r['run']['until'] ?? null) === '20260805' && $r['run']['since'] === '20241105', '  corrida 3 a 24 meses: ventana del 5 nov 2024 al 5 ago 2026');
    T::ok($newCount >= 1 && (int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa WHERE authority_granted_at > '2026-08-05'") === 0, '  solo entran autoridades con 3 meses o más (' . $newCount . ' nuevas)');
    T::eq(ProspectImport::completeUntil(), '2026-08-05', '  la marca de ventana completa queda en la fecha del tope, no en hoy');
    Clock::travel(0);
    Settings::set('prospects.config', ProspectConfig::DEFAULTS);
    Settings::clear();

    // ------------------------------------------------------------------ Idioma preferido
    foreach (['prospect_auto_mails', 'prospect_calls', 'prospects_fmcsa'] as $tb) {
        Db::pdo()->exec("DELETE FROM $tb");
    }
    $p1 = $mk();
    Prospects::record($p1, 'talked', '', '', $aid, '', ['_on' => '1', 'lang' => 'es', 'contact_name' => 'José']);
    $row = Prospects::find($p1);
    T::ok($row['lang'] === 'es' && ($row['qual']['contact_name'] ?? '') === 'José' && !isset($row['qual']['lang']), 'idioma: se anota en la llamada (columna propia, no dentro de la calificación)');
    Prospects::record($p1, 'talked', '', '', $aid);
    T::eq(Prospects::find($p1)['lang'], 'es', '  un registro sin el formulario de datos no lo borra');
    Prospects::record($p1, 'talked', '', '', $aid, '', ['_on' => '1', 'lang' => 'xx']);
    T::eq(Prospects::find($p1)['lang'], null, '  «Sin saber» (o un valor raro) lo deja vacío');
    Prospects::record($p1, 'talked', '', '', $aid, '', ['_on' => '1', 'lang' => 'es']);
    $c = Prospects::convert($p1, $aid);
    T::ok($c['ok'] && Db::value('SELECT language FROM leads WHERE id = ?', [(int) $c['lead_id']]) === 'es', '  al convertirlo, el lead queda en español');
    T::ok(str_contains(ProspectScript::DEFAULTS['en'], 'English or Spanish') && str_contains(ProspectScript::DEFAULTS['es'], 'inglés o en español'), '  el guion de fábrica pregunta el idioma');

    // ------------------------------------------------------------------ Intentos y espaciado
    T::eq(Prospects::retryAt($et('2026-11-25 12:00'), 1), Clock::at($et('2026-11-26 09:00')), 'espaciado por defecto: siguiente día hábil, como antes');
    Settings::set('prospects.config', ['retry_spacing' => 'spread', 'max_attempts' => 4] + ProspectConfig::DEFAULTS);
    Settings::clear();
    T::eq(Prospects::retryAt($et('2026-11-25 12:00'), 1), Clock::at($et('2026-11-27 09:00')), '  «2, 3 y 5 días»: tras el intento 1 vuelve a los 2 días hábiles');
    T::eq(Prospects::retryAt($et('2026-11-27 12:00'), 1), Clock::at($et('2026-12-01 09:00')), '  el fin de semana no cuenta');
    T::eq(Prospects::retryAt($et('2026-11-25 12:00'), 2), Clock::at($et('2026-11-30 09:00')), '  tras el intento 2, a los 3 días hábiles');
    T::eq(Prospects::retryAt($et('2026-11-25 12:00'), 9), Prospects::retryAt($et('2026-11-25 12:00'), 3), '  después se repite el último');
    T::ok(isset(ProspectConfig::normalize(['max_attempts' => '7'])['errors']['max_attempts']) && isset(ProspectConfig::normalize(['retry_spacing' => 'nunca'])['errors']['retry_spacing']), '  intentos de 3 a 6 y espaciado de la lista');
    $p2 = $mk();
    foreach ([1, 2, 3] as $i) {
        Prospects::record($p2, 'no_answer', '', '', $aid);
    }
    T::ok(Prospects::find($p2)['status'] === 'no_answer' && Prospects::maxAttempts() === 4, '  con 4 intentos, el tercero sin respuesta no cierra');
    $m = Prospects::record($p2, 'no_answer', '', '', $aid);
    T::ok(Prospects::find($p2)['status'] === 'no_answer_closed' && str_contains($m['msg'], 'Intento 4 de 4'), '  el cuarto sí');
    Settings::set('prospects.config', ProspectConfig::DEFAULTS);
    Settings::clear();

    // ------------------------------------------------------------------ Cadencia de correos automáticos
    T::eq(ProspectAutoMail::sendWindow($et('2026-11-28 10:00')), $et('2026-11-30 09:00'), 'cadencia: un sábado espera al lunes 9:00 ET');
    T::eq(ProspectAutoMail::sendWindow($et('2026-11-25 07:30')), $et('2026-11-25 09:00'), '  antes de las 9 espera a las 9');
    T::eq(ProspectAutoMail::sendWindow($et('2026-11-25 17:30')), $et('2026-11-26 09:00'), '  después de las 5 pasa al día siguiente');
    T::eq(ProspectAutoMail::sendWindow($et('2026-11-25 12:00')), $et('2026-11-25 12:00'), '  en horario sale ya');
    foreach (array_keys(ProspectAutoMail::KINDS) as $k) {
        T::eq(ProspectAutoMail::settings($k)['enabled'], false, "  «{$k}» viene apagado");
    }
    $smtpLog = "$work/smtp55.log";
    @unlink($smtpLog);
    Fx::smtp(Infra::smtpMock('smtp55', $smtpLog));
    Settings::set('mail', ['address' => '30 N Gould St, Ste N, Sheridan, WY 82801', 'from_name' => 'NorthStar Freight Solutions']);
    Settings::clear();
    $d = ProspectAutoMail::DEFAULTS;
    T::ok(!ProspectAutoMail::save(['enabled' => '1', 'subject' => 'Hi', 'body' => 'Hello there', 'subject_es' => '', 'body_es' => 'Hola'], $aid, 'intro')['ok'], '  sin la versión en español no se guarda');
    foreach (['intro', 'missed', 'breakup'] as $k) {
        T::ok(ProspectAutoMail::save(['enabled' => '1', 'subject' => $d[$k]['en'][0], 'body' => $d[$k]['en'][1], 'subject_es' => $d[$k]['es'][0], 'body_es' => $d[$k]['es'][1]], $aid, $k)['ok'], "  «{$k}» se enciende con dirección y SMTP");
    }
    Clock::travel($et('2026-11-25 11:00') - time());
    $es = $mk(['email' => 'es@example.com', 'lang' => 'es']);
    ProspectAutoMail::queue($es, 0, 'intro');
    $intro = Db::one("SELECT * FROM prospect_auto_mails WHERE prospect_id = ? AND kind = 'intro'", [$es]);
    T::ok($intro !== null && $intro['status'] === 'queued', '  presentación en cola al entrar en la lista');
    ProspectAutoMail::job(['id' => (int) $intro['id']]);
    $mails = Infra::readLog($smtpLog);
    $raw = quoted_printable_decode((string) ($mails[0]['raw'] ?? ''));
    T::ok(count($mails) === 1 && str_contains($raw, 'Subject: Dispatch para Aw Carrier') && str_contains($raw, 'registro público de FMCSA'), '  sale en español (asunto y pie) para quien prefiere español');
    Prospects::record($es, 'no_answer', '', '', $aid);
    $missed = Db::one("SELECT * FROM prospect_auto_mails WHERE prospect_id = ? AND kind = 'missed'", [$es]);
    Clock::travel($et('2026-11-25 11:15') - time());
    ProspectAutoMail::job(['id' => (int) $missed['id']]);
    $gapJob = Db::one("SELECT run_at FROM jobs WHERE type = 'prospect_auto_mail' AND dedupe_key = ?", ['pam:' . $missed['id'] . ':gap']);
    T::ok(Db::value('SELECT status FROM prospect_auto_mails WHERE id = ?', [(int) $missed['id']]) === 'queued' && $gapJob !== null && (int) Clock::parse((string) $gapJob['run_at']) >= $et('2026-11-27 11:00'), '  el correo tras no contestar espera 2 días desde la presentación');
    Clock::travel($et('2026-11-27 11:05') - time());
    ProspectAutoMail::job(['id' => (int) $missed['id']]);
    T::ok(Db::value('SELECT status FROM prospect_auto_mails WHERE id = ?', [(int) $missed['id']]) === 'sent' && str_contains(quoted_printable_decode((string) (Infra::readLog($smtpLog)[1]['raw'] ?? '')), 'Intenté llamarte'), '  y luego sale (en español)');
    Prospects::record($es, 'no_answer', '', '', $aid);
    Prospects::record($es, 'voicemail', '', '', $aid);
    $bk = Db::one("SELECT * FROM prospect_auto_mails WHERE prospect_id = ? AND kind = 'breakup'", [$es]);
    $bkJob = Db::one("SELECT run_at FROM jobs WHERE type = 'prospect_auto_mail' AND dedupe_key = ?", ['pam:' . ($bk['id'] ?? 0)]);
    T::ok($bk !== null && Prospects::find($es)['status'] === 'no_answer_closed' && $bkJob !== null && (int) Clock::parse((string) $bkJob['run_at']) >= $et('2026-11-29 11:00'), '  al agotar los intentos, el último correo queda programado 2 días después');
    Clock::travel($et('2026-11-30 10:00') - time());
    ProspectAutoMail::job(['id' => (int) $bk['id']]);
    T::ok(Db::value('SELECT status FROM prospect_auto_mails WHERE id = ?', [(int) $bk['id']]) === 'sent' && str_contains(quoted_printable_decode((string) (Infra::readLog($smtpLog)[2]['raw'] ?? '')), 'Cierro tu expediente'), '  y sale');
    T::eq((int) Db::value('SELECT COUNT(*) FROM prospect_auto_mails WHERE prospect_id = ?', [$es]), 3, '  uno de cada tipo, como mucho');
    $en = $mk(['email' => 'en@example.com']);
    ProspectAutoMail::queue($en, 0, 'intro');
    Prospects::record($en, 'talked', '', '', $aid);
    ProspectAutoMail::job(['id' => (int) Db::value("SELECT id FROM prospect_auto_mails WHERE prospect_id = ? AND kind = 'intro'", [$en])]);
    T::eq(Db::value("SELECT skip_reason FROM prospect_auto_mails WHERE prospect_id = ? AND kind = 'intro'", [$en]), 'ya se habló con el prospecto', '  la presentación no sale si ya se habló con él');
    $en2 = $mk(['email' => 'en2@example.com']);
    foreach ([1, 2, 3] as $i) {
        Prospects::record($en2, 'no_answer', '', '', $aid);
    }
    Prospects::reopen($en2, $aid);
    ProspectAutoMail::job(['id' => (int) Db::value("SELECT id FROM prospect_auto_mails WHERE prospect_id = ? AND kind = 'breakup'", [$en2])]);
    T::eq(Db::value("SELECT skip_reason FROM prospect_auto_mails WHERE prospect_id = ? AND kind = 'breakup'", [$en2]), 'el prospecto ya no está cerrado sin respuesta', '  el último correo no sale si se reabrió');
    $wn = $mk(['email' => 'wn@example.com']);
    Prospects::record($wn, 'wrong_number', '', '', $aid);
    T::eq((int) Db::value("SELECT COUNT(*) FROM prospect_auto_mails WHERE prospect_id = ? AND kind = 'breakup'", [$wn]), 0, '  «Número equivocado» no manda el último correo');
    $before = count(Infra::readLog($smtpLog));
    $t = ProspectAutoMail::sendTest('carlos@example.com', $aid, 'breakup');
    $logT = Infra::readLog($smtpLog);
    T::ok($t['ok'] && count($logT) === $before + 2 && str_contains(quoted_printable_decode((string) $logT[$before]['raw']), '[PRUEBA]') && str_contains(quoted_printable_decode((string) $logT[$before + 1]['raw']), 'Cierro tu expediente'), '  la prueba manda las dos versiones');
    Clock::travel(0);

    // ------------------------------------------------------------------ Campañas en español
    foreach (['prospect_auto_mails', 'prospect_calls', 'prospect_campaign_recipients', 'prospects_fmcsa'] as $tb) {
        Db::pdo()->exec("DELETE FROM $tb");
    }
    $mk(['email' => 'c1@example.com', 'lang' => 'es']);
    $mk(['email' => 'c2@example.com', 'lang' => 'en']);
    $mk(['email' => 'c3@example.com']);
    $now = Clock::time();
    $cnt = static fn(string $lg) => ProspectMail::audience(ProspectMail::segment(['tab' => '', 'lg' => $lg]), $now)['n'];
    T::ok($cnt('') === 3 && $cnt('es') === 1 && $cnt('en') === 2, 'campañas: segmento por idioma (español 1, el resto 2, todos 3)');
    T::ok(isset(ProspectMail::STARTERS['owner_es'], ProspectMail::STARTERS['fleet_es'], ProspectMail::STARTERS['new_mc']) && ProspectMail::STARTERS['owner_es']['lang'] === 'es' && ProspectMail::STARTERS['new_mc']['segment']['ag'] === 3, '  textos de partida en español y para MC nuevos');
    $v = ProspectMail::validate(['name' => 'ES', 'subject' => 'Hola {empresa}', 'body' => 'Hola, somos NorthStar y hacemos dispatch.', 'lang' => 'es', 'lg' => 'es']);
    T::ok($v['ok'] && $v['values']['lang'] === 'es' && $v['values']['segment']['lg'] === 'es', '  la campaña guarda su idioma y el segmento');
    $cid = ProspectMail::create($v['values'], $aid);
    $camp = ProspectMail::find($cid);
    $pv = ProspectMail::render($camp, ['legal_name' => 'UNO LLC', 'state' => 'TX', 'fleet_size' => 1, 'id' => 1], 'https://x.test/u/t');
    T::ok($camp['lang'] === 'es' && str_contains($pv['text'], 'registro público de FMCSA') && ProspectMail::vars(['fleet_size' => 1], 'es')['{flota}'] === '1 camión', '  pie y variables en español');
    T::ok(str_contains(ProspectMail::segmentLabel($camp['segment']), 'Prefieren español'), '  la etiqueta del segmento lo dice');

    // ------------------------------------------------------------------ Reportes
    Db::pdo()->exec('DELETE FROM prospect_calls');
    $tx = $mk(['lang' => 'es', 'source' => 'manual']);
    $ins = static function (int $pid, string $result, int $ts): void {
        Db::insert('prospect_calls', ['prospect_id' => $pid, 'user_id' => null, 'result' => $result, 'attempt_no' => 0, 'created_at' => Clock::at($ts)]);
    };
    $base = strtotime('-2 days', Clock::time());
    $day = gmdate('Y-m-d', $base);
    for ($i = 0; $i < 10; $i++) {   // 9:00 a 9:59 hora de Texas (Central): 6 de 10 contestan
        $ins($tx, $i < 6 ? 'talked' : 'no_answer', (new DateTimeImmutable("$day 09:" . str_pad((string) ($i * 5), 2, '0', STR_PAD_LEFT), new DateTimeZone('America/Chicago')))->getTimestamp());
        $ins($tx, $i < 2 ? 'talked' : 'no_answer', (new DateTimeImmutable("$day 15:" . str_pad((string) ($i * 5), 2, '0', STR_PAD_LEFT), new DateTimeZone('America/Chicago')))->getTimestamp());
    }
    $h = ProspectReport::hours(Clock::time() - 7 * 86400, Clock::time() + 60);
    $byKey = array_column($h['rows'], null, 'key');
    T::ok($byKey['8-10']['calls'] === 10 && $byKey['8-10']['reached'] === 6 && $byKey['14-16']['calls'] === 10 && $byKey['14-16']['reached'] === 2 && $h['best'] === '8-10', 'reportes: llamadas por hora LOCAL del prospecto y la mejor franja');
    $lang = ProspectReport::breakdown('lang', Clock::time() - 7 * 86400, Clock::time() + 60);
    $src = ProspectReport::breakdown('source', Clock::time() - 7 * 86400, Clock::time() + 60);
    T::ok(($lang[0]['label'] ?? '') === 'Español' && ($src[0]['label'] ?? '') === 'Añadido a mano', '  y por idioma y origen');

    // ------------------------------------------------------------------ Pantallas
    $s = Panel::start($ctx, 'panel-aw55');
    T::ok($s->login($admin['email']), 'login del administrador');
    Db::run("UPDATE prospects_fmcsa SET status = 'to_call', attempts = 0, next_call_at = NULL WHERE id = ?", [$tx]);
    $b = $s->get('/prospectos?tab=to_call')['body'];
    T::ok(str_contains($b, 'name="q[lang]"') && str_contains($b, 'title="Prefiere hablar en español">ES</abbr>') && str_contains($b, 'data-sc="es" lang="es"'), 'lista: idioma en los datos de la llamada, marca «ES» y guion en su idioma');
    T::ok(preg_match('/pc__sc is-on" data-sc="es"/', $b) === 1, '  el guion de quien prefiere español se abre en español');
    $f = $s->get('/prospectos/correos/nueva?inicio=owner_es')['body'];
    T::ok(str_contains($f, '<option value="es" selected>Español</option>') && str_contains($f, '<option value="es" selected>Prefieren español</option>') && str_contains($f, 'Seguimiento: dispatch para'), 'campaña: «En español» llega con idioma, segmento y segundo correo en español');
    $rep = $s->get('/reportes?tab=prospectos');
    T::ok($rep['status'] === 200 && str_contains($rep['body'], 'A qué hora contestan') && str_contains($rep['body'], 'Por idioma que prefieren') && str_contains($rep['body'], 'Por origen'), 'reportes: hora a la que contestan, idioma y origen');
    T::ok($s->unlock($aid, 'prospectos'), 'configuración: desbloqueo');
    $cfg = $s->get('/config?tab=prospectos')['body'];
    T::ok(str_contains($cfg, 'Antigüedad mínima de la autoridad') && str_contains($cfg, 'Intentos sin respuesta antes de cerrar') && str_contains($cfg, 'Correo de presentación') && str_contains($cfg, 'Último correo al agotar los intentos'), '  la pestaña tiene rango de antigüedad, intentos y los tres correos');
    $r = $s->post('/config/prospects/calls', ['_csrf' => $s->csrfOf($cfg), 'daily_goal' => '40', 'auto_convert' => '1', 'max_attempts' => '5', 'retry_spacing' => 'wide']);
    Settings::clear();
    T::ok($r['status'] === 303 && ProspectConfig::get()['max_attempts'] === 5 && ProspectConfig::get()['retry_spacing'] === 'wide', '  guardar 5 intentos y espaciado amplio');
    $r = $s->post('/config/prospects/automail', ['_csrf' => $s->csrfOf($cfg), 'kind' => 'intro', 'subject' => 'Hello {empresa}', 'body' => 'Hi, this is NorthStar.', 'subject_es' => 'Hola {empresa}', 'body_es' => 'Hola, somos NorthStar.']);
    Settings::clear();
    $si = ProspectAutoMail::settings('intro');
    T::ok($r['status'] === 303 && !$si['enabled'] && $si['subject_es'] === 'Hola {empresa}' && ProspectAutoMail::settings('missed')['enabled'], '  guardar la presentación (apagada) no toca los otros correos');
    Fx::noSmtp();
};
