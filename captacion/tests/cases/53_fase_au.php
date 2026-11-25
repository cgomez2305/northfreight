<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\JobHandlers;
use Captacion\Services\LeadIngest;
use Captacion\Services\ProspectAutoMail;
use Captacion\Services\ProspectConfig;
use Captacion\Services\ProspectMail;
use Captacion\Services\ProspectScript;
use Captacion\Services\ProspectSources;
use Captacion\Services\Prospects;
use Captacion\Services\Referrals;
use Captacion\Services\Sequences;
use Captacion\Services\Settings;
use Captacion\Services\SocrataClient;
use Captacion\Services\UsTimeZones;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\SocrataFixture;
use CaptacionTests\T;

/**
 * Fase AU (2026-11-25, Carlos: «desactiva el módulo de referidos, y enfoquémonos en optimizar al máximo el manejo de los prospectos»):
 * referidos apagados por defecto, prioridad de llamada, guion, calificación rápida, «Dejé mensaje» y «Número equivocado», rellamada a una hora local, meta diaria,
 * Modo llamada, «Interesado» convierte en lead, correo automático tras no contestar, añadir por USDOT e importar CSV.
 */
return static function (array $ctx): void {
    T::reset(true);
    Fx::noSmtp();
    Settings::set('mail', []);
    Settings::clear();
    $work = $ctx['work'];
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $now = Clock::time();
    $n = 0;
    $mk = static function (array $o = []) use (&$n): int {
        $n++;
        return (int) Db::insert('prospects_fmcsa', $o + ['usdot' => (string) (9600000 + $n), 'legal_name' => "AU CARRIER $n LLC", 'state' => 'TX', 'city' => 'DALLAS', 'phone' => '+1469555' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'authority_granted_at' => '2025-01-01', 'fleet_size' => 12, 'status' => 'to_call', 'attempts' => 0, 'listed_on' => '2026-11-20', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    };

    // ------------------------------------------------------------------ Referidos: apagados por defecto
    T::eq(Referrals::enabled(), false, 'referidos: el módulo viene apagado');
    $code = (int) Db::insert('referral_codes', ['owner_name' => 'Ana', 'code' => 'ANA-2345', 'active' => 1, 'created_at' => Clock::now()]);
    T::eq(Referrals::find('ANA-2345'), null, '  un código del sitio se ignora (sin enlace ni puntos de referido)');
    $r = LeadIngest::handle(['profile' => 'owner_operator', 'first_name' => 'Ref', 'phone' => '(469) 555-0901', 'email' => 'ref@example.com', 'state' => 'TX', 'language' => 'en', 'referral_code' => 'ANA-2345', 'consent' => ['given' => false]], 'web_careers', ['quiet' => true, 'skip_antispam' => true]);
    T::ok($r['ok'] && (int) Db::value('SELECT COUNT(*) FROM referrals') === 0 && Db::value('SELECT referral_code FROM leads WHERE id = ?', [(int) $r['lead_id']]) === null, '  el lead entra sin referido ni código');
    T::eq(Sequences::enrollReferral((int) $r['lead_id'], $now), null, '  no se inscribe a nadie en A12 (pedido de referidos)');
    Db::insert('referrals', ['referral_code_id' => $code, 'lead_id' => (int) $r['lead_id'], 'status' => 'signed', 'bonus_status' => 'pending', 'created_at' => Clock::now()]);
    T::eq(Referrals::pendingApproval(), 0, '  ni bonos por aprobar (campana, menú y dashboard)');
    Referrals::setEnabled(true, $aid);
    T::ok(Referrals::enabled() && Referrals::find('ANA-2345') !== null && Referrals::pendingApproval() === 1, '  encendido vuelve a funcionar con lo guardado');
    Referrals::setEnabled(false, $aid);

    // ------------------------------------------------------------------ Resultados nuevos
    $p1 = $mk();
    $res = Prospects::record($p1, 'voicemail', 'Buzón', '', $aid);
    $p = Prospects::find($p1);
    T::ok($res['ok'] && $p['status'] === 'no_answer' && (int) $p['attempts'] === 1 && $p['last_result'] === 'voicemail' && str_contains($res['msg'], 'Mensaje de voz'), '«Dejé mensaje»: cuenta como intento sin respuesta');
    $p2 = $mk();
    $res = Prospects::record($p2, 'wrong_number', '', '', $aid);
    $p = Prospects::find($p2);
    T::ok($res['ok'] && $p['status'] === 'no_answer_closed' && (int) $p['do_not_call'] === 0 && (int) Db::value('SELECT COUNT(*) FROM dnc_numbers') === 0, '«Número equivocado»: cierra sin meter el teléfono en «No llamar más»');
    T::ok(Prospects::reopen($p2, $aid)['ok'], '  y se puede reabrir');

    // ------------------------------------------------------------------ Rellamada a la hora local del prospecto
    $tomorrow = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('America/Chicago'))->modify('+1 day')->format('Y-m-d');
    $p3 = $mk(['state' => 'TX']);
    $res = Prospects::record($p3, 'talked', '', $tomorrow, $aid, '15:00');
    $p = Prospects::find($p3);
    $exp = gmdate('Y-m-d H:i:s', (new DateTimeImmutable($tomorrow . ' 15:00', new DateTimeZone('America/Chicago')))->getTimestamp());
    T::ok($res['ok'] && $p['next_call_at'] === $exp && (int) $p['callback_exact'] === 1, 'rellamada: «mañana a las 3:00 p. m.» en la hora de Texas (zona principal)');
    T::ok(str_contains($res['msg'], '3:00 p. m.') && str_contains($res['msg'], 'hora local'), '  el aviso dice la hora local');
    $p4 = $mk(['state' => 'TX']);
    T::ok(!Prospects::record($p4, 'talked', '', $tomorrow, $aid, '22:00')['ok'] && Prospects::find($p4)['status'] === 'to_call', '  fuera de 8 a. m. a 9 p. m. hora local se rechaza');
    T::ok(!Prospects::record($p4, 'talked', '', '2020-01-01', $aid, '10:00')['ok'], '  una fecha pasada se rechaza');
    T::ok(!Prospects::record($p4, 'talked', '', $tomorrow, $aid, '9am')['ok'], '  una hora mal escrita se rechaza');
    $p5 = $mk(['state' => null]);
    T::ok(!Prospects::record($p5, 'talked', '', $tomorrow, $aid, '10:00')['ok'], '  sin estado no se acepta hora (no se sabe la zona)');
    T::ok(Prospects::record($p4, 'talked', '', $tomorrow, $aid, '')['ok'] && (int) Prospects::find($p4)['callback_exact'] === 0, '  solo con fecha: 9:00 a. m. ET de siempre');

    // ------------------------------------------------------------------ Calificación rápida y conversión con lo anotado
    $q = Prospects::normalizeQual(['contact_name' => '  Mike  Ross ', 'email' => 'MIKE@Example.com', 'equipment' => ['dry-van', 'nave-espacial'], 'relay' => 'yes', 'dispatcher' => 'no', 'best_time' => 'morning', '_on' => '1']);
    T::eq($q['clean'], ['contact_name' => 'Mike Ross', 'email' => 'mike@example.com', 'equipment' => ['dry-van'], 'relay' => 'yes', 'dispatcher' => 'no', 'best_time' => 'morning'], 'calificación: se limpia con lista blanca');
    T::ok(Prospects::normalizeQual(['email' => 'no-es-correo'])['error'] !== null, '  un correo inválido se rechaza');
    Settings::set('prospects.config', ['auto_convert' => false] + ProspectConfig::DEFAULTS);
    Settings::clear();
    $p6 = $mk(['email' => 'fmcsa6@example.com', 'fleet_size' => 1]);
    $res = Prospects::record($p6, 'interested', 'Quiere empezar el lunes', '', $aid, '', ['contact_name' => 'Mike Ross', 'email' => 'mike@example.com', 'equipment' => ['dry-van'], 'relay' => 'yes']);
    $p = Prospects::find($p6);
    T::ok($res['ok'] && $p['converted_lead_id'] === null && $p['qual']['contact_name'] === 'Mike Ross', 'con «convertir al marcar Interesado» apagado: queda interesado y guarda lo anotado');
    Settings::set('prospects.config', ProspectConfig::DEFAULTS);
    Settings::clear();
    T::eq(ProspectConfig::get()['auto_convert'], true, '  por defecto «Interesado» convierte en lead');
    $p7 = $mk(['email' => 'fmcsa7@example.com', 'fleet_size' => 1]);
    $res = Prospects::record($p7, 'interested', 'Tiene Relay', '', $aid, '', ['contact_name' => 'Laura Díaz', 'email' => 'laura@example.com', 'equipment' => ['dry-van'], 'relay' => 'yes', 'best_time' => 'morning']);
    $p = Prospects::find($p7);
    $lead = $p['converted_lead_id'] !== null ? Db::one('SELECT * FROM leads WHERE id = ?', [(int) $p['converted_lead_id']]) : null;
    T::ok($res['ok'] && $lead !== null && str_starts_with($res['msg'], 'Interesado:'), 'Interesado: se convierte en lead en el mismo clic');
    T::ok($lead !== null && $lead['first_name'] === 'Laura Díaz' && $lead['email'] === 'laura@example.com' && $lead['stage'] === 'contacted' && $lead['best_time'] === 'morning', '  el lead lleva el contacto, el correo dado por teléfono y el mejor horario');
    T::ok($lead !== null && str_contains((string) $lead['equipment_types'], 'dry-van') && str_contains((string) $lead['equipment_types'], 'amazon-relay'), '  y el equipo (dry van + Amazon Relay porque tiene cuenta)');
    $note = (string) Db::value('SELECT body FROM lead_notes WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [(int) ($lead['id'] ?? 0)]);
    T::ok(str_contains($note, 'AU CARRIER') && str_contains($note, 'Cuenta de Amazon Relay: Sí') && str_contains($note, 'Tiene Relay'), '  con una nota: empresa, lo anotado y la última nota de la llamada');

    // ------------------------------------------------------------------ Prioridad
    Db::pdo()->exec('DELETE FROM prospect_calls');
    Db::pdo()->exec('DELETE FROM prospects_fmcsa');
    $plain = $mk(['fleet_size' => 50, 'authority_granted_at' => '2024-01-01']);
    $fresh = $mk(['fleet_size' => 2, 'authority_granted_at' => (new DateTimeImmutable('@' . $now))->modify('-10 days')->format('Y-m-d'), 'cargo' => json_encode(['genfreight'])]);
    $replied = $mk(['fleet_size' => 50, 'authority_granted_at' => '2024-01-01', 'email' => 'r@example.com', 'email_replied_at' => Clock::now()]);
    $f = Prospects::filters(['tab' => 'to_call']);
    $ids = array_map(static fn($x) => (int) $x['id'], Prospects::page($f, $now, 1, 25)['rows']);
    T::eq($ids, [$replied, $fresh, $plain], 'prioridad: primero quien respondió a un correo, luego flota pequeña con autoridad reciente y carga general');
    $row = Prospects::page($f, $now, 1, 25)['rows'][1];
    $info = Prospects::priorityInfo($row, $now);
    T::ok($info['level'] === 'mid' || $info['level'] === 'high', '  la reciente con flota de 2 no es «baja»');
    T::ok(in_array('Flota de 1 a 5', $info['why'], true) && in_array('Autoridad de 10 días', $info['why'], true) && in_array('Carga general (encaja con dry van)', $info['why'], true), '  y explica por qué');
    T::eq(Prospects::priorityInfo(Prospects::page($f, $now, 1, 25)['rows'][2], $now)['level'], 'low', '  la flota grande y vieja queda en baja');

    // ------------------------------------------------------------------ Meta diaria y Modo llamada
    Prospects::record($plain, 'no_answer', '', '', $aid);
    Prospects::record($fresh, 'talked', '', '', $aid);
    $st = Prospects::todayStats(Clock::time(), $aid);
    T::ok($st['calls'] === 2 && $st['spoke'] === 1 && $st['mine'] === 2 && $st['goal'] === 40, 'meta diaria: 2 llamadas, 1 conversación, meta de 40 por defecto');
    Db::pdo()->exec('DELETE FROM prospect_calls');
    Db::pdo()->exec('DELETE FROM prospects_fmcsa');
    $ok = Prospects::callableStates(Clock::time());
    $off = array_values(array_diff(array_keys(UsTimeZones::ZONES), $ok));
    if ($ok) {
        $a = $mk(['state' => $ok[0], 'fleet_size' => 2]);
        $b = $mk(['state' => $ok[0], 'fleet_size' => 50]);
        if ($off) {
            $mk(['state' => $off[0], 'fleet_size' => 1, 'email_replied_at' => Clock::now(), 'email' => 'x@example.com']);
        }
        $nx = Prospects::nextForCall(Clock::time(), []);
        T::ok($nx['row'] !== null && (int) $nx['row']['id'] === $a && $nx['left'] === 2, 'Modo llamada: el siguiente es el de mayor prioridad que se puede llamar YA (nunca uno fuera de horario)');
        T::eq((int) (Prospects::nextForCall(Clock::time(), [$a])['row']['id'] ?? 0), $b, '  «Saltar» pasa al siguiente');
        T::eq(Prospects::nextForCall(Clock::time(), [$a, $b])['row'], null, '  sin más, no hay nadie');
    } else {
        T::ok(true, 'Modo llamada: (ningún estado en horario a esta hora; se prueba solo el caso vacío)');
        T::eq(Prospects::nextForCall(Clock::time(), [])['row'], null, '  fuera de horario no ofrece a nadie');
    }

    // ------------------------------------------------------------------ Guion
    $sc = ProspectScript::get();
    T::ok($sc['en'] === ProspectScript::DEFAULTS['en'] && $sc['lang'] === 'en', 'guion: de fábrica en inglés');
    $html = ProspectScript::html($sc['en'], ProspectScript::vars(['legal_name' => 'ACME TRUCKING LLC', 'state' => 'TX', 'city' => 'DALLAS', 'fleet_size' => 1, 'mc_number' => 'MC123'], 'Jhonatan'));
    T::ok(str_contains($html, 'Acme Trucking LLC') && str_contains($html, 'Jhonatan') && str_contains($html, 'Texas') && !preg_match('/\{[a-z]+\}/', $html), '  con las variables del prospecto y del agente resueltas');
    T::ok(str_contains($sc['en'], "not calling from FMCSA") && str_contains($sc['en'], 'estimates') && str_contains($sc['es'], 'No llamar más'), '  dice que no se llama de FMCSA, que los precios son estimaciones y qué hacer si piden no llamar');
    T::ok(str_contains($html, '<h4>Opening</h4>') && str_contains($html, '<ul><li>'), '  secciones y listas');
    T::ok(str_contains(ProspectScript::html('<script>x</script>', []), '&lt;script&gt;'), '  el texto se escapa');
    T::ok(ProspectScript::save(['en' => '# Hola', 'es' => '', 'lang' => 'es'], $aid)['ok'] && ProspectScript::get()['en'] === '# Hola' && ProspectScript::get()['es'] === ProspectScript::DEFAULTS['es'] && ProspectScript::get()['lang'] === 'es', '  se guarda uno propio; vacío = el de fábrica');

    // ------------------------------------------------------------------ Correo automático tras no contestar
    Db::pdo()->exec('DELETE FROM prospect_calls');
    Db::pdo()->exec('DELETE FROM prospects_fmcsa');
    $m1 = $mk(['email' => 'auto1@example.com']);
    Prospects::record($m1, 'no_answer', '', '', $aid);
    T::eq((int) Db::value('SELECT COUNT(*) FROM prospect_auto_mails'), 0, 'correo automático: apagado por defecto, no encola nada');
    T::ok(!ProspectAutoMail::save(['enabled' => '1', 'subject' => 'Hi', 'body' => 'Hello'], $aid)['ok'], '  no se enciende sin dirección física ni SMTP');
    T::ok(!ProspectAutoMail::save(['enabled' => '0', 'subject' => 'Hi', 'body' => 'Hello {nombre_raro}'], $aid)['ok'], '  una variable desconocida se rechaza');
    $smtpLog = "$work/smtp53.log";
    @unlink($smtpLog);
    $port = Infra::smtpMock('smtp53', $smtpLog);
    Fx::smtp($port);
    Settings::set('mail', ['address' => '30 N Gould St, Ste N, Sheridan, WY 82801', 'from_name' => 'NorthStar Freight Solutions']);
    Settings::clear();
    T::ok(ProspectAutoMail::save(['enabled' => '1', 'subject' => ProspectAutoMail::DEFAULT_SUBJECT, 'body' => ProspectAutoMail::DEFAULT_BODY], $aid)['ok'], '  con dirección y SMTP se enciende');
    $m2 = $mk(['email' => 'auto2@example.com']);
    $m3 = $mk(['email' => null]);
    Prospects::record($m2, 'voicemail', '', '', $aid);
    Prospects::record($m3, 'no_answer', '', '', $aid);
    $row = Db::one('SELECT * FROM prospect_auto_mails WHERE prospect_id = ?', [$m2]);
    T::ok($row !== null && $row['status'] === 'queued' && (int) Db::value('SELECT COUNT(*) FROM prospect_auto_mails') === 1, '  «Dejé mensaje» con correo encola uno; sin correo, nada');
    $job = Db::one("SELECT * FROM jobs WHERE type = 'prospect_auto_mail' ORDER BY id DESC LIMIT 1");
    T::ok(isset(JobHandlers::all()['prospect_auto_mail']) && $job !== null && (int) Clock::parse((string) $job['run_at']) >= Clock::time() + ProspectAutoMail::DELAY - 5, '  y sale por la cola 10 minutos después');
    Prospects::record($m2, 'no_answer', '', '', $aid);
    T::eq((int) Db::value('SELECT COUNT(*) FROM prospect_auto_mails WHERE prospect_id = ?', [$m2]), 1, '  uno por prospecto como mucho');
    ProspectAutoMail::job(['id' => (int) $row['id']]);
    $mails = Infra::readLog($smtpLog);
    $raw = quoted_printable_decode((string) ($mails[0]['raw'] ?? ''));
    T::ok(count($mails) === 1 && in_array('auto2@example.com', (array) $mails[0]['to'], true) && str_contains($raw, 'Sorry I missed you'), '  envío: llega al prospecto con el asunto de fábrica');
    T::ok(str_contains($raw, 'List-Unsubscribe: <') && str_contains($raw, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click') && str_contains($raw, 'Sheridan, WY'), '  con baja de un clic y la dirección física');
    T::ok(Db::value('SELECT status FROM prospect_auto_mails WHERE id = ?', [(int) $row['id']]) === 'sent' && Db::value('SELECT last_emailed_at FROM prospects_fmcsa WHERE id = ?', [$m2]) !== null, '  queda enviado y cuenta para el periodo de espera');
    T::ok(ProspectMail::sentLastHour(Clock::time()) >= 1, '  y comparte el tope por hora de los correos en lote');
    $m4 = $mk(['email' => 'auto4@example.com']);
    Prospects::record($m4, 'no_answer', '', '', $aid);
    Prospects::record($m4, 'dnc', 'No quiere', '', $aid);
    ProspectAutoMail::job(['id' => (int) Db::value('SELECT id FROM prospect_auto_mails WHERE prospect_id = ?', [$m4])]);
    T::ok(Db::value('SELECT status FROM prospect_auto_mails WHERE prospect_id = ?', [$m4]) === 'skipped' && count(Infra::readLog($smtpLog)) === 1, '  si en esos 10 minutos se marca «No llamar más», no sale');
    $m5 = $mk(['email' => 'auto5@example.com', 'last_emailed_at' => Clock::now()]);
    Prospects::record($m5, 'no_answer', '', '', $aid);
    ProspectAutoMail::job(['id' => (int) Db::value('SELECT id FROM prospect_auto_mails WHERE prospect_id = ?', [$m5])]);
    T::eq(Db::value('SELECT skip_reason FROM prospect_auto_mails WHERE prospect_id = ?', [$m5]), 'periodo de espera', '  respeta el periodo de espera de los correos en lote');
    $t = ProspectAutoMail::sendTest('carlos@example.com', $aid);
    T::ok($t['ok'] && str_contains(quoted_printable_decode((string) (Infra::readLog($smtpLog)[1]['raw'] ?? '')), '[PRUEBA]'), '  prueba al correo del administrador');
    Fx::noSmtp();

    // ------------------------------------------------------------------ Añadir por USDOT
    $fx = new SocrataFixture();
    $client = new SocrataClient($fx->http(), static function (float $s): void {}, 'http://socrata.test');
    $a = ProspectSources::addByUsdot('USDOT 9100001', 'Grupo de Facebook', $aid, $client);
    $pa = $a['ok'] ? Prospects::find((int) $a['id']) : null;
    T::ok($a['ok'] && $pa !== null && $pa['source'] === 'manual' && $pa['legal_name'] === 'FIXTURE CARRIER 01 LLC' && $pa['phone'] === '+13055550100' && $pa['mc_number'] === 'MC8800001' && $pa['authority_granted_at'] === '2026-11-04', 'añadir por USDOT: se completa con el censo y la última concesión de FMCSA');
    T::eq(Db::value("SELECT note FROM prospect_calls WHERE prospect_id = ? AND result = 'added'", [(int) ($a['id'] ?? 0)]), 'Origen: Grupo de Facebook', '  y guarda dónde lo encontraron');
    T::ok(!ProspectSources::addByUsdot('9100001', '', $aid, $client)['ok'], '  un USDOT repetido no se duplica');
    T::ok(!ProspectSources::addByUsdot('9999999', '', $aid, $client)['ok'], '  uno que no está en el censo se rechaza');
    T::ok(!ProspectSources::addByUsdot('9100010', '', $aid, $client)['ok'], '  uno inactivo se rechaza');
    Db::run('INSERT INTO dnc_numbers (phone_e164, reason, created_at) VALUES (?, ?, ?)', ['+17135550101', 'prospect', Clock::now()]);
    T::ok(!ProspectSources::addByUsdot('9100002', '', $aid, $client)['ok'], '  uno con teléfono en «No llamar más» no entra');
    T::ok(!ProspectSources::addByUsdot('abc', '', $aid, $client)['ok'], '  sin dígitos se rechaza');

    // ------------------------------------------------------------------ Importar CSV
    $csv = "$work/prospectos53.csv";
    file_put_contents($csv, "\xEF\xBB\xBFDOT Number,Company Name,Phone,Email,State,City,Power Units\n9700001,Alpha Freight LLC,(469) 555-7001,alpha@example.com,tx,Dallas,3\n9700001,Repetido LLC,(469) 555-7002,,TX,,\n9100001,Ya existe LLC,,,TX,,\n,Sin DOT,,,TX,,\n9700003,Bloqueado LLC,(713) 555-0101,,TX,,\n9700004,Beta LLC,,,ZZ,,x\n");
    $im = ProspectSources::importCsv($csv, 'Lista de prueba', $aid);
    T::eq($im['counts'] ?? [], ['added' => 2, 'known' => 2, 'invalid' => 1, 'blocked' => 1, 'too_many' => 0], 'importar CSV: entran 2; repetidos, sin USDOT y teléfonos bloqueados se omiten');
    $alpha = Db::one("SELECT * FROM prospects_fmcsa WHERE usdot = '9700001'");
    T::ok($alpha['source'] === 'csv' && $alpha['phone'] === '+14695557001' && $alpha['state'] === 'TX' && (int) $alpha['fleet_size'] === 3 && $alpha['legal_name'] === 'Alpha Freight LLC', '  reconoce encabezados distintos (DOT Number, Company Name, Power Units)');
    T::eq(Db::value("SELECT state FROM prospects_fmcsa WHERE usdot = '9700004'"), null, '  un estado inválido queda vacío');
    file_put_contents($csv, "name,phone\nX,1\n");
    T::ok(!ProspectSources::importCsv($csv, '', $aid)['ok'], '  sin columna de USDOT se rechaza');

    // ------------------------------------------------------------------ Pantallas
    $s = Panel::start($ctx, 'panel-au53');
    T::ok($s->login($admin['email']), 'login del administrador');
    $page = $s->get('/prospectos?tab=to_call');
    $b = $page['body'];
    T::ok($page['status'] === 200 && str_contains($b, 'href="/prospectos/llamar"') && str_contains($b, 'llamadas hoy') && str_contains($b, 'Añadir por USDOT') && str_contains($b, 'Importar CSV'), 'lista: Modo llamada, meta diaria y «Añadir»');
    T::ok(str_contains($b, 'Guion de la llamada') && str_contains($b, 'Datos de la llamada') && str_contains($b, 'name="next_time"') && str_contains($b, 'value="voicemail"') && str_contains($b, 'value="wrong_number"'), '  cada prospecto: guion, datos de la llamada, hora de rellamada y los resultados nuevos');
    T::ok(str_contains($b, 'sin voces grabadas ni IA') && str_contains($b, 'FMCSA, del DOT o de Amazon'), '  reglas de las llamadas en frío actualizadas');
    T::ok(!str_contains($b, 'href="/referidos"'), '  Referidos fuera del menú');
    $call = $s->get('/prospectos/llamar');
    T::ok($call['status'] === 200 && str_contains($call['body'], 'Modo llamada'), 'Modo llamada: abre');
    $tok = $s->csrfOf($b);
    $r = $s->post('/prospectos/add', ['_csrf' => $tok, 'usdot' => '', 'origin' => '']);
    T::ok($r['status'] === 303 && str_contains($s->get('/prospectos')['body'], 'USDOT válido'), 'añadir por HTTP: valida el número');
    $ref = $s->get('/referidos');
    T::ok($ref['status'] === 200 && str_contains($ref['body'], 'El programa de referidos está apagado') && str_contains($ref['body'], 'Activar el módulo'), 'referidos: la pantalla explica que está apagado y permite activarlo');
    $r = $s->post('/referidos/module', ['_csrf' => $s->csrfOf($ref['body']), 'on' => '1']);
    Settings::clear();
    T::ok($r['status'] === 303 && Referrals::enabled() && str_contains($s->get('/')['body'], 'href="/referidos"'), '  activarlo lo devuelve al menú');
    $r = $s->post('/referidos/module', ['_csrf' => $tok, 'on' => '0']);
    Settings::clear();
    T::eq(Referrals::enabled(), false, '  y desactivarlo lo apaga otra vez');
    T::ok($s->unlock($aid, 'prospectos'), 'configuración: desbloqueo');
    $cfg = $s->get('/config?tab=prospectos')['body'];
    T::ok(str_contains($cfg, 'Guion de la llamada') && str_contains($cfg, 'Correo automático tras no contestar') && str_contains($cfg, 'Meta de llamadas por día'), '  la pestaña Prospectos tiene llamadas, guion y correo tras no contestar');
    $r = $s->post('/config/prospects/calls', ['_csrf' => $s->csrfOf($cfg), 'daily_goal' => '25']);
    Settings::clear();
    T::ok($r['status'] === 303 && ProspectConfig::get()['daily_goal'] === 25 && ProspectConfig::get()['auto_convert'] === false, '  guardar meta 25 y «Interesado convierte» apagado');
    $r = $s->post('/config/prospects/script', ['_csrf' => $s->csrfOf($cfg), 'restore' => '1', 'lang' => 'en']);
    Settings::clear();
    T::ok($r['status'] === 303 && ProspectScript::get()['en'] === ProspectScript::DEFAULTS['en'], '  restaurar el guion de fábrica');
};
