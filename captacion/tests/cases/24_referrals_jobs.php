<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Handoff;
use Captacion\Services\JobPostings;
use Captacion\Services\JobsPublisher;
use Captacion\Services\LeadActions;
use Captacion\Services\Notifications;
use Captacion\Services\Referrals;
use Captacion\Services\Settings;
use Captacion\Services\Templates;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Referidos y vacantes (Q4a-B y C), de punta a punta con el SITIO real (php -S):
 *  - ?ref= viaja en el formulario, se ignora si no existe, y el motor asocia el lead, suma puntos, avanza el estado y deja el bono pendiente al firmar (con aviso por Telegram);
 *  - las vacantes se publican a storage/jobs.json y el sitio las muestra (pagina, JobPosting, hub, formulario, sitemap) o las oculta (pausa, cierre, vencimiento);
 *  - app/data/jobs.php del sitio resiste JSON vacio, corrupto, enorme y malicioso sin fallar nunca.
 */
return static function (array $ctx): void {
    T::reset(true);
    $work = $ctx['work'];
    $repo = dirname($ctx['root']);
    $count = static fn(string $t, string $where = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $where");
    $admin = Panel::user('admin');
    $uid = (int) $admin['id'];

    // ------------------------------------------------------------------ Sitio real con el CRM encendido (copia del codigo con su propio storage)
    $dir = "$work/site-refs";
    exec('rm -rf ' . escapeshellarg($dir));
    mkdir("$dir/storage", 0777, true);
    foreach (['app', 'templates'] as $d) {
        exec('cp -r ' . escapeshellarg("$repo/$d") . ' ' . escapeshellarg("$dir/$d"));
    }
    copy("$repo/index.php", "$dir/index.php");
    foreach (['assets', 'vendor', 'captacion'] as $d) {
        symlink("$repo/$d", "$dir/$d");
    }
    @unlink("$dir/app/config.local.php");
    file_put_contents("$dir/app/config.local.php", "<?php\ndefine('ENV', 'staging');\ndefine('PREVIEW_TOKEN', 'tok');\ndefine('CAPTACION_ENABLED', 1);\n");
    $vars = array_merge($ctx['env'], ['STORAGE_DIR' => "$work/crm-storage-refs", 'APP_URL' => 'https://captacion.example']);
    file_put_contents("$work/site-refs.env", implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($vars), $vars)) . "\n");
    $port = Infra::phpServer('site-refs', $dir, ['CAPTACION_ENV_FILE' => "$work/site-refs.env"], "$dir/index.php", 19200);
    $base = "http://127.0.0.1:$port";
    $siteGet = static fn(string $p) => Infra::http('GET', $base . $p);
    $r = $siteGet('/careers/apply/');
    preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $a);
    preg_match('/name="form_ts" value="(\d+)"/', $r['body'], $b);
    $csrf = $a[1] ?? '';
    $ts = $b[1] ?? '';
    T::ok(!str_contains($r['body'], 'value="company-driver"'), 'sitio: sin vacantes, el formulario no ofrece «company driver»');
    $r0 = $siteGet('/careers/');
    T::ok(str_contains($r0['body'], 'No openings listed right now'), 'sitio: sin vacantes, el hub de Careers lo dice con honestidad');
    T::eq([$siteGet('/careers/cdl-a-drivers/')['status'], $siteGet('/careers/cdl-a-drivers/regional-cdl-a-driver/')['status']], [404, 404], 'sitio: sin vacantes, el hub y las páginas de vacante dan 404');
    sleep(4);   // el sitio rechaza envios de menos de 3 s
    $form = static fn(array $over = [], ?array $attr = null) => $over + [
        'csrf' => $csrf, 'form_ts' => $ts, 'website' => '', 'profile' => 'owner-operator', 'trucks' => '2', 'equipment' => ['dry-van', 'amazon-relay'], 'years' => '4', 'authority' => 'active',
        'cdl_standard' => 'yes', 'english' => 'yes', 'name' => 'Maria Perez', 'phone' => '8635550201', 'email' => 'maria@example.com', 'state' => 'FL', 'language' => 'es', 'contact_pref' => 'whatsapp',
        'best_time' => 'morning', 'consent' => '1', 'attr' => json_encode($attr ?? ['utm_source' => 'google', 'landing' => '/careers/owner-operators/', 'first_seen' => '2026-11-01']),
    ];
    $post = static fn(array $f) => Infra::http('POST', $base . '/careers/apply/', ['form' => $f, 'headers' => ['Origin: ' . $base]]);
    $jsonl = static function () use ($dir): array {
        $out = [];
        foreach (glob("$dir/storage/leads/*.jsonl") ?: [] as $f) {
            foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                $out[] = json_decode($l, true);
            }
        }
        return $out;
    };

    // ------------------------------------------------------------------ Codigos de referido (servicio)
    $owner = Fx::lead(['first_name' => 'Luis', 'last_name' => 'Ortega', 'stage' => 'signed', 'handoff_status' => 'signed', 'signed_at' => Clock::now(), 'phone_e164' => '+13055550900']);
    T::ok(!Referrals::createCode('', null, '', '', '', $uid)['ok'] && !Referrals::createCode('Luis', null, 'a b', '', '', $uid)['ok'] && !Referrals::createCode('Luis', null, 'AB', '', '', $uid)['ok'], 'código: nombre vacío, caracteres no válidos o muy corto se rechazan');
    T::ok(!Referrals::createCode('Luis', 999999, '', '', '', $uid)['ok'] && !Referrals::createCode('Luis', null, '', 'abc', '', $uid)['ok'] && !Referrals::createCode('Luis', null, '', '-5', '', $uid)['ok'], 'código: lead asociado inexistente o monto inválido se rechazan');
    $c1 = Referrals::createCode('Luis Ortega', $owner, 'luis-7k2m', '250', 'Primer referidor', $uid);
    T::ok($c1['ok'] && $c1['code'] === 'LUIS-7K2M', 'código: se guarda en mayúsculas y con su lead asociado');
    T::ok(!Referrals::createCode('Otro', null, 'LUIS-7K2M', '', '', $uid)['ok'] && !Referrals::createCode('Otro', null, 'luis-7k2m', '', '', $uid)['ok'], 'código: un código repetido (sin importar mayúsculas) se rechaza');
    $g = Referrals::createCode('Mónica Pérez', null, '', '', '', $uid);
    T::ok($g['ok'] && preg_match('/^MONICA-[2-9A-HJKMNP-Z]{4}$/', $g['code']) === 1, 'código: generado con el nombre sin acentos y un sufijo sin caracteres ambiguos (' . ($g['code'] ?? '') . ')');
    T::eq(Referrals::link('LUIS-7K2M'), 'https://northstarfreightsolutions.us/careers/?ref=LUIS-7K2M', 'enlace compartible: SITE_URL/careers/?ref=CODIGO');
    $old = Referrals::createCode('Viejo', null, 'OLD-CODE', '100', '', $uid);
    Referrals::setActive((int) $old['id'], false, $uid);

    // ------------------------------------------------------------------ ?ref= desde el formulario del sitio hasta el CRM
    $r1 = $post($form(['name' => 'Ana Ref', 'phone' => '8635550201', 'email' => 'ana@example.com'], ['utm_source' => 'google', 'landing' => '/careers/', 'ref' => 'LUIS-7K2M']));
    $ana = Db::one("SELECT * FROM leads WHERE phone_e164 = '+18635550201'");
    T::ok($r1['status'] === 303 && $ana !== null && $ana['referral_code'] === 'LUIS-7K2M' && (int) $ana['score'] === 70, 'sitio + CRM: con un código válido el lead entra ligado a él y suma los 5 puntos de referido (70 en vez de 65)');
    $ref = Db::one('SELECT * FROM referrals WHERE lead_id = ?', [$ana['id']]);
    T::ok($ref !== null && $ref['status'] === 'referred' && $ref['bonus_status'] === 'none' && (int) $ref['referral_code_id'] === (int) $c1['id'], '  fila en referrals: «referido», sin bono todavía');
    T::eq($count('lead_events', "lead_id = {$ana['id']} AND type = 'referral_linked'"), 1, '  evento «referral_linked» en la línea de tiempo del lead');
    $lines = $jsonl();
    T::ok($lines[0]['attribution']['ref'] === 'LUIS-7K2M' && $lines[0]['crm_ingested'] === true, '  el JSONL del sitio guarda ref en la atribución');

    $r2 = $post($form(['name' => 'No Existe', 'phone' => '8635550202', 'email' => 'noex@example.com'], ['ref' => 'NOPE-0000']));
    $l2 = Db::one("SELECT * FROM leads WHERE phone_e164 = '+18635550202'");
    T::ok($r2['status'] === 303 && $l2 !== null && $l2['referral_code'] === null && (int) $l2['score'] === 65 && $count('referrals', 'lead_id = ' . (int) $l2['id']) === 0, 'código inexistente: se ignora sin error (mismo 303, sin puntos de referido, sin fila en referrals)');
    $r3 = $post($form(['name' => 'Script Kid', 'phone' => '8635550203', 'email' => 'kid@example.com'], ['ref' => '<script>alert(1)</script>']));
    $l3 = Db::one("SELECT * FROM leads WHERE phone_e164 = '+18635550203'");
    $lines = $jsonl();
    T::ok($r3['status'] === 303 && $l3['referral_code'] === null && end($lines)['attribution']['ref'] === '', 'código con caracteres raros: el sitio lo descarta (el JSONL guarda ref vacío)');
    $r4 = $post($form(['name' => 'Codigo Viejo', 'phone' => '8635550204', 'email' => 'viejo@example.com'], ['ref' => 'OLD-CODE']));
    $l4 = Db::one("SELECT * FROM leads WHERE phone_e164 = '+18635550204'");
    T::ok($r4['status'] === 303 && $l4['referral_code'] === null && $count('referrals', 'lead_id = ' . (int) $l4['id']) === 0, 'código desactivado: se ignora como si no existiera');
    $r5 = $post($form(['name' => 'No Existe', 'phone' => '8635550202', 'email' => 'noex@example.com'], ['ref' => 'luis-7k2m']));
    $l2 = Db::one("SELECT * FROM leads WHERE phone_e164 = '+18635550202'");
    T::ok($r5['status'] === 303 && $count('leads', "phone_e164 = '+18635550202'") === 1 && $l2['referral_code'] === 'LUIS-7K2M' && $count('referrals', 'lead_id = ' . (int) $l2['id']) === 1, 'reingreso: un lead existente que vuelve con un código (en minúsculas) queda ligado a él, sin duplicarse (' . $r5['status'] . ' ' . json_encode($l2['referral_code'] ?? null) . ' ' . $count('referrals') . ')');
    T::eq($count('referrals'), 2, 'en total hay 2 referidos (solo los válidos)');
    Infra::stop('site-refs');

    // Un lead no se refiere a si mismo
    T::ok(Referrals::attach($owner, 'LUIS-7K2M') === null && $count('referrals', 'lead_id = ' . $owner) === 0, 'un lead no puede referirse a sí mismo con su propio código');

    // ------------------------------------------------------------------ Avance, firma y bono
    LeadActions::setStage((int) $ana['id'], 'contacted', [], $uid);
    T::eq(Db::value('SELECT status FROM referrals WHERE lead_id = ?', [$ana['id']]), 'referred', 'avance: «Contactado» todavía es «Referido»');
    LeadActions::setStage((int) $ana['id'], 'qualified', [], $uid);
    T::eq(Db::value('SELECT status FROM referrals WHERE lead_id = ?', [$ana['id']]), 'qualified', 'avance: al pasar a «Calificado» el referido pasa a «Calificado»');
    $tgLog = "$work/tg24.log";
    @unlink($tgLog);
    $tgPort = Infra::phpServer('tg24', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $tgLog], $ctx['root'] . '/tests/lib/telegram_mock.php', 19300);
    $jobs0 = $count('jobs', "type = 'referral_notify'");
    LeadActions::setStage((int) $ana['id'], 'presented', [], $uid);
    $jobsNoTg = $count('jobs', "type = 'referral_notify'");
    Handoff::confirmFromPanel((int) $ana['id'], 'signed', $uid);
    $ref = Db::one('SELECT * FROM referrals WHERE lead_id = ?', [$ana['id']]);
    T::ok($ref['status'] === 'signed' && $ref['bonus_status'] === 'pending' && $ref['signed_at'] !== null && (float) $ref['bonus_amount'] === 250.0, 'firma: el referido pasa a «Firmado» y el bono a «pendiente de aprobar» con el monto del código');
    T::eq($count('jobs', "type = 'referral_notify'"), $jobsNoTg, '  sin Telegram configurado no se encola ningún aviso (la campana igual lo avisa)');
    T::ok($count('lead_events', "lead_id = {$ana['id']} AND type = 'referral_signed'") === 1 && $count('lead_events', "lead_id = $owner AND type = 'referral_signed'") === 1, '  evento «referral_signed» en el lead firmado y en el del referidor');
    Env::set('TELEGRAM_BOT_TOKEN', '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11');
    Env::set('TELEGRAM_CHAT_ID', '555000');
    Env::set('TELEGRAM_API_BASE', "http://127.0.0.1:$tgPort");
    // Otro referido que firma con Telegram listo
    $bo = Fx::lead(['first_name' => 'Bo', 'last_name' => 'Referido', 'stage' => 'qualified']);
    Referrals::attach($bo, 'MONICA-' . substr((string) $g['code'], 7));
    LeadActions::setStage($bo, 'presented', [], $uid);
    Handoff::confirmFromPanel($bo, 'signed', $uid);
    T::eq($count('jobs', "type = 'referral_notify'"), $jobs0 + 1, 'firma con Telegram: se encola el aviso «bono por aprobar»');
    Fx::runJobs(10);
    $tgs = Infra::readLog($tgLog);
    $sent = array_values(array_filter($tgs, static fn($m) => str_contains((string) json_encode($m['body']), 'bono por aprobar')));
    T::ok(count($sent) === 1 && str_contains((string) json_encode($sent[0]['body']), (string) $g['code']) && !str_contains((string) json_encode($sent[0]['body']), 'ABC-DEF'), '  el mensaje de Telegram dice el código y el monto pendiente, sin secretos');
    Referrals::onSigned(Db::one('SELECT * FROM leads WHERE id = ?', [$ana['id']]), $uid);
    T::eq($count('jobs', "type = 'referral_notify'"), $jobs0 + 1, 'firma repetida: no duplica el aviso ni cambia el bono (idempotente)');
    Infra::stop('tg24');

    $noti = array_map(static fn($n) => explode(':', $n['key'])[0], Notifications::all($uid));
    T::ok(in_array('bonus_pending', $noti, true), 'campana: aviso de bonos de referido por aprobar');
    $rid = (int) $ref['id'];
    T::ok(!Referrals::bonusAction($rid, 'pay', null, '', $uid)['ok'], 'bono: no se marca pagado antes de aprobarlo');
    Db::pdo()->exec('UPDATE referrals SET bonus_amount = NULL WHERE id = ' . $rid);
    T::ok(!Referrals::bonusAction($rid, 'approve', '', '', $uid)['ok'] && !Referrals::bonusAction($rid, 'approve', 'abc', '', $uid)['ok'] && !Referrals::bonusAction($rid, 'approve', '0', '', $uid)['ok'], 'bono: sin monto (o con monto inválido o 0) no se aprueba');
    $r = Referrals::bonusAction($rid, 'approve', '300.50', 'Confirmado por teléfono', $uid);
    $row = Db::one('SELECT * FROM referrals WHERE id = ?', [$rid]);
    T::ok($r['ok'] && $row['bonus_status'] === 'approved' && (float) $row['bonus_amount'] === 300.5 && $row['bonus_approved_at'] !== null && $row['bonus_note'] === 'Confirmado por teléfono', 'bono: aprobar con monto y nota');
    T::ok(!Referrals::bonusAction($rid, 'approve', '100', '', $uid)['ok'], 'bono: aprobar dos veces se rechaza');
    $r = Referrals::bonusAction($rid, 'pay', null, '', $uid);
    T::ok($r['ok'] && Db::value('SELECT bonus_status FROM referrals WHERE id = ?', [$rid]) === 'paid' && Db::value('SELECT bonus_paid_at FROM referrals WHERE id = ?', [$rid]) !== null, 'bono: marcar pagado');
    T::ok(!Referrals::bonusAction($rid, 'cancel', null, '', $uid)['ok'] && !Referrals::bonusAction($rid, 'nada', null, '', $uid)['ok'], 'bono: un bono pagado no se cancela y una acción desconocida se rechaza');
    $rid2 = (int) Db::value('SELECT id FROM referrals WHERE lead_id = ?', [$bo]);
    Referrals::bonusAction($rid2, 'cancel', null, '', $uid);
    T::eq(Db::value('SELECT bonus_status FROM referrals WHERE id = ?', [$rid2]), 'cancelled', 'bono: cancelar uno pendiente');
    T::ok(Referrals::bonusAction($rid2, 'reopen', null, '', $uid)['ok'] && Db::value('SELECT bonus_status FROM referrals WHERE id = ?', [$rid2]) === 'pending', 'bono: reabrir uno cancelado lo devuelve a pendiente');
    T::ok((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action LIKE 'referral_bonus_%'") === 4, 'bono: cada acción queda en activity_log');

    // A12: plantilla con el enlace del lead firmado
    $tpl = Templates::byKey('a12_referral', 'en');
    T::ok($tpl !== null && str_contains($tpl['body'], '{link_referido}') && !str_contains($tpl['body'], '$') && !str_contains($tpl['body'], 'bono'), 'A12: la plantilla de fábrica incluye {link_referido} y ninguna cifra de bono');
    $anaLead = Db::one('SELECT * FROM leads WHERE id = ?', [$ana['id']]);
    $out = Templates::render($tpl, $anaLead, 'en', 'https://captacion.example/u/token');
    $mine = Db::one('SELECT code FROM referral_codes WHERE owner_lead_id = ?', [$ana['id']]);
    T::ok($mine !== null && str_contains($out['text'], 'https://northstarfreightsolutions.us/careers/?ref=' . $mine['code']) && $out['unresolved'] === [], 'A12: al enviarla se crea el código del lead firmado y el correo lleva su enlace (sin variables sin resolver)');
    $out2 = Templates::render($tpl, $anaLead, 'en', 'https://captacion.example/u/token');
    T::eq([$out2['text'] === $out['text'], $count('referral_codes', 'owner_lead_id = ' . (int) $ana['id'])], [true, 1], 'A12: renderizar otra vez reutiliza el mismo código (no crea otro)');
    $prev = Templates::render($tpl, Templates::sampleLead('en'), 'en', 'https://captacion.example/u/token');
    T::ok(str_contains($prev['text'], '?ref=EJEMPLO'), 'A12: la vista previa usa un código de ejemplo y no crea nada');

    // ------------------------------------------------------------------ Pantalla /referidos
    $s = Panel::start($ctx, 'panel-ref');
    T::ok($s->login($admin['email']), 'login del administrador');
    $p = $s->get('/referidos');
    T::ok($p['status'] === 200 && str_contains($p['body'], 'LUIS-7K2M') && str_contains($p['body'], 'https://northstarfreightsolutions.us/careers/?ref=LUIS-7K2M') && str_contains($p['body'], 'Ana Ref'), 'pantalla: lista el código, su enlace compartible y el lead referido');
    T::ok(str_contains($p['body'], 'Monto del bono provisional: pendiente de confirmar con NorthStar'), '  avisa que el monto es provisional hasta confirmarlo con NorthStar');
    $tok = $s->csrfOf($p['body']);
    $r = $s->post('/referidos/new', ['owner_name' => 'Sin CSRF']);
    T::eq([$r['status'], $count('referral_codes', "owner_name = 'Sin CSRF'")], [303, 0], 'pantalla: crear un código sin token CSRF se rechaza');
    $r = $s->post('/referidos/new', ['_csrf' => $tok, 'owner_name' => 'Rosa Gómez', 'code' => 'ROSA-1', 'bonus_amount' => '150', 'note' => 'Del grupo de Facebook', 'lead_id' => '']);
    T::ok($r['status'] === 303 && $count('referral_codes', "code = 'ROSA-1'") === 1, 'pantalla: crear un código');
    $s->post('/referidos/new', ['_csrf' => $tok, 'owner_name' => 'Rosa Gómez', 'code' => 'ROSA-1']);
    T::ok(str_contains($s->get('/referidos')['body'], 'Ese código ya existe') || $count('referral_codes', "code = 'ROSA-1'") === 1, 'pantalla: repetir un código no lo duplica');
    $rid3 = (int) Db::value('SELECT id FROM referrals WHERE lead_id = ?', [$bo]);
    $s->post("/referidos/$rid3/bonus", ['_csrf' => $tok, 'op' => 'approve', 'bonus_amount' => '200']);
    T::eq(Db::value('SELECT bonus_status FROM referrals WHERE id = ?', [$rid3]), 'approved', 'pantalla: aprobar un bono desde la lista');
    $pp = $s->get('/referidos?f=approved');
    T::ok(str_contains($pp['body'], 'Marcar pagado') && str_contains($pp['body'], 'Bo Referido') && !str_contains($pp['body'], 'Código LUIS-7K2M · de Luis Ortega'), 'pantalla: el filtro «Aprobados» muestra solo esos y ofrece «Marcar pagado»');
    $s->post('/referidos/confirm', ['_csrf' => $tok, 'confirmed' => '1']);
    Settings::clear();
    T::ok(Referrals::bonusConfirmed() && str_contains($s->get('/referidos')['body'], 'Montos confirmados con NorthStar'), 'pantalla: «NorthStar ya confirmó» quita la marca de provisional');
    $s->post("/referidos/" . (int) $old['id'] . "/code", ['_csrf' => $tok, 'op' => 'enable']);
    T::eq((int) Db::value('SELECT active FROM referral_codes WHERE id = ?', [$old['id']]), 1, 'pantalla: reactivar un código');
    $s->post("/referidos/" . (int) $old['id'] . "/code", ['_csrf' => $tok, 'op' => 'amount', 'bonus_amount' => '1e3']);
    T::eq((float) Db::value('SELECT bonus_amount FROM referral_codes WHERE id = ?', [$old['id']]), 100.0, 'pantalla: un monto inválido no cambia el del código');
    Infra::stop('panel-ref');
    Env::set('TELEGRAM_BOT_TOKEN', null);
    Env::set('TELEGRAM_CHAT_ID', null);
    Env::set('TELEGRAM_API_BASE', null);

    // ======================================================================= VACANTES
    T::reset(true);
    $admin = Panel::user('admin');
    $uid = (int) $admin['id'];
    $siteStorage = "$dir/storage";
    Env::set('SITE_STORAGE_DIR', $siteStorage);
    Settings::clear();
    @unlink("$siteStorage/jobs.json");
    $today = JobPostings::today();
    $plus = static fn(int $d) => (new DateTimeImmutable($today))->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
    $good = ['title' => 'Regional CDL-A Company Driver', 'slug' => '', 'posted_on' => $today, 'valid_through' => $plus(45), 'employment_type' => 'FULL_TIME', 'city' => 'Lakeland', 'region' => 'FL', 'postal' => '33801',
        'summary' => 'Regional dry van routes out of Lakeland, home most weekends.', 'equipment' => 'Dry van', 'responsibilities' => "Run regional dry van loads\nKeep logs and paperwork current", 'requirements' => "Standard CDL-A\n1 year of driving experience",
        'benefits' => '', 'pay_on' => '', 'pay_min' => '', 'pay_max' => '', 'pay_unit' => 'WEEK'];

    // Validacion estricta
    $e = static fn(array $over, bool $pub = false) => JobPostings::validate($over + $good, $pub)['errors'];
    T::ok(isset($e(['title' => 'Hi'])['title']) && isset($e(['title' => '<b>Chofer</b> nuevo'])['title']), 'vacante: título corto o con HTML se rechaza');
    T::ok(isset($e(['summary' => 'Texto <script>alert(1)</script> largo y válido'])['summary']) && isset($e(['responsibilities' => "Bien\n<img src=x onerror=alert(1)>"])['responsibilities']) && isset($e(['summary' => 'Uno &lt;b&gt; escapado raro y suficiente'])['summary']), 'vacante: HTML en el resumen o en las listas (incluido &lt;) se rechaza');
    T::ok(isset($e(['posted_on' => '2026-02-30'])['posted_on']) && isset($e(['valid_through' => 'mañana'])['valid_through']) && isset($e(['valid_through' => $plus(-1), 'posted_on' => $today])['valid_through']) && isset($e(['valid_through' => $plus(500)])['valid_through']), 'vacante: fechas inexistentes, no ISO, anteriores a la publicación o de más de un año se rechazan');
    T::ok(isset($e(['valid_through' => $today], true)['valid_through']) && isset($e(['valid_through' => $plus(-3), 'posted_on' => $plus(-10)], true)['valid_through']), 'vacante: para publicar, valid_through debe ser futura');
    T::ok(isset($e(['slug' => 'Mal Slug!'])['slug']) && isset($e(['slug' => '../x'])['slug']) && isset($e(['slug' => 'a--b'])['slug']), 'vacante: slug con mayúsculas, símbolos o guiones dobles se rechaza');
    T::ok(isset($e(['region' => 'ZZ'])['region']) && isset($e(['postal' => '123'])['postal']), 'vacante: estado y código postal inválidos se rechazan');
    T::ok(isset($e(['pay_min' => '900'])['pay']), 'vacante: cifras de pago sin marcar «Publicar el pago» se rechazan (no hay pago sin confirmar)');
    T::ok(isset($e(['pay_on' => '1'])['pay']) && isset($e(['pay_on' => '1', 'pay_min' => '-5', 'pay_unit' => 'WEEK'])['pay']) && isset($e(['pay_on' => '1', 'pay_min' => '900', 'pay_max' => '800', 'pay_unit' => 'WEEK'])['pay']) && isset($e(['pay_on' => '1', 'pay_min' => '900', 'pay_unit' => 'DECADE'])['pay']), 'vacante: pago marcado sin cifras, negativo, con mínimo mayor que máximo o con unidad inválida se rechaza');
    T::ok($e(['pay_on' => '1', 'pay_min' => '1,050.50', 'pay_max' => '1200', 'pay_unit' => 'WEEK']) === [], 'vacante: pago válido con coma de miles');
    T::ok(isset($e(['summary' => 'corto', 'city' => '', 'responsibilities' => '', 'requirements' => ''], true)['summary']) && isset($e(['city' => ''], true)['city']) && isset($e(['responsibilities' => '', 'requirements' => ''], true)['requirements']), 'vacante: para publicar exige resumen, ciudad, estado y al menos una responsabilidad o requisito');
    $long = $e(['responsibilities' => implode("\n", array_fill(0, 13, 'Línea'))]);
    T::ok(isset($long['responsibilities']), 'vacante: más de 12 líneas se rechaza');

    // Alta como borrador: no toca el sitio
    $made = JobPostings::create($good, $uid);
    $jid = (int) ($made['id'] ?? 0);
    T::ok($made['ok'] && $jid > 0 && JobPostings::find($jid)['status'] === 'draft' && JobPostings::find($jid)['slug'] === 'regional-cdl-a-company-driver', 'vacante: se guarda como borrador con el slug armado del título');
    T::ok(!is_file("$siteStorage/jobs.json"), '  un borrador no escribe nada en el sitio');
    T::ok(!JobPostings::create($good, $uid)['ok'] && isset(JobPostings::create($good, $uid)['errors']['slug']), 'vacante: el mismo slug dos veces se rechaza');

    // Sitio real con las vacantes
    $port = Infra::phpServer('site-jobs', $dir, ['CAPTACION_ENV_FILE' => "$work/site-refs.env"], "$dir/index.php", 19400);
    $base = "http://127.0.0.1:$port";
    $siteGet = static fn(string $p) => Infra::http('GET', $base . $p);
    $slugUrl = '/careers/cdl-a-drivers/regional-cdl-a-company-driver/';
    T::eq($siteGet($slugUrl)['status'], 404, 'sitio: el borrador no existe en el sitio (404)');
    $pub = JobPostings::setStatus($jid, 'publish', $uid);
    T::ok($pub['ok'] && JobPostings::find($jid)['status'] === 'active' && JobPostings::find($jid)['published_at'] !== null, 'publicar: la vacante queda «Publicada»');
    $file = "$siteStorage/jobs.json";
    $json = json_decode((string) file_get_contents($file), true);
    T::ok(is_file($file) && ($json['schema'] ?? null) === 1 && isset($json['jobs']['regional-cdl-a-company-driver']) && $json['jobs']['regional-cdl-a-company-driver']['status'] === 'active', 'publicar: escribe storage/jobs.json del sitio con esquema versionado');
    T::eq(decoct(fileperms($file) & 0777), '640', '  con permisos 0640');
    T::eq(glob("$siteStorage/.jobs.json.*.tmp") ?: [], [], '  y sin archivos temporales (escritura atómica)');
    $jp = $json['jobs']['regional-cdl-a-company-driver'];
    T::ok(!isset($jp['pay']) && $jp['location'] === ['city' => 'Lakeland', 'region' => 'FL', 'postal' => '33801', 'country' => 'US'] && $jp['responsibilities'] === ['Run regional dry van loads', 'Keep logs and paperwork current'], '  sin pago no hay clave pay; ubicación y listas completas');
    $page = $siteGet($slugUrl);
    T::ok($page['status'] === 200 && str_contains($page['body'], 'Regional CDL-A Company Driver') && str_contains($page['body'], 'Run regional dry van loads') && str_contains($page['body'], 'Lakeland, FL'), 'sitio: la página de la vacante existe con su contenido');
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $page['body'], $lds);
    $types = [];
    $jobLd = null;
    foreach ($lds[1] as $l) {
        $d = json_decode($l, true);
        foreach (isset($d['@graph']) ? $d['@graph'] : [$d] as $n) {
            $types[] = $n['@type'] ?? '';
            if (($n['@type'] ?? '') === 'JobPosting') {
                $jobLd = $n;
            }
        }
    }
    T::ok($jobLd !== null && $jobLd['title'] === 'Regional CDL-A Company Driver' && $jobLd['datePosted'] === $today && str_starts_with($jobLd['validThrough'], $plus(45)) && $jobLd['jobLocation']['address']['addressLocality'] === 'Lakeland'
        && $jobLd['jobLocation']['address']['addressRegion'] === 'FL' && !isset($jobLd['baseSalary']) && in_array('BreadcrumbList', $types, true), 'sitio: JSON-LD JobPosting con fechas y lugar, sin baseSalary inventado, más migas');
    $hub = $siteGet('/careers/cdl-a-drivers/');
    T::ok($hub['status'] === 200 && str_contains($hub['body'], 'Regional CDL-A Company Driver') && str_contains($hub['body'], $slugUrl), 'sitio: el hub de vacantes pasa a existir y lista la vacante');
    T::ok(str_contains($siteGet('/careers/')['body'], 'See the open positions') && !str_contains($siteGet('/careers/')['body'], 'No openings listed right now'), 'sitio: el hub /careers/ ya no dice que no hay puestos y enlaza a las vacantes');
    T::ok(str_contains($siteGet('/careers/apply/')['body'], 'value="company-driver"'), 'sitio: el formulario ofrece «company driver»');
    $sm = $siteGet('/sitemap.xml')['body'];
    T::ok(str_contains($sm, $slugUrl) && str_contains($sm, '/careers/cdl-a-drivers/<'), 'sitio: el sitemap incluye el hub y la vacante');
    $llms = $siteGet('/llms.txt')['body'] . $siteGet('/llms-full.txt')['body'];
    T::ok(str_contains($llms, 'Regional CDL-A Company Driver') || str_contains($llms, $slugUrl), 'sitio: llms.txt / llms-full.txt la incluyen');
    T::ok(!str_contains($page['body'], 'Fatal error') && !str_contains($page['body'], 'Warning:'), 'sitio: sin errores de PHP en la página');

    // Edicion de una publicada: se envia al sitio; el slug no cambia
    $r = JobPostings::save($jid, ['title' => 'Regional CDL-A Driver (Dry Van)', 'slug' => 'otro-slug', 'pay_on' => '1', 'pay_min' => '1000', 'pay_max' => '1200', 'pay_unit' => 'WEEK'] + $good, $uid);
    $j = JobPostings::find($jid);
    T::ok($r['ok'] && $j['slug'] === 'regional-cdl-a-company-driver' && $j['title'] === 'Regional CDL-A Driver (Dry Van)', 'editar publicada: guarda y conserva el slug publicado');
    $page = $siteGet($slugUrl);
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $page['body'], $lds);
    $sal = null;
    foreach ($lds[1] as $l) {
        $d = json_decode($l, true);
        foreach (isset($d['@graph']) ? $d['@graph'] : [$d] as $n) {
            if (($n['@type'] ?? '') === 'JobPosting') {
                $sal = $n['baseSalary'] ?? null;
            }
        }
    }
    T::ok(str_contains($page['body'], 'Regional CDL-A Driver (Dry Van)') && $sal !== null && $sal['value']['minValue'] === 1000 && $sal['value']['maxValue'] === 1200 && $sal['value']['unitText'] === 'WEEK' && $sal['currency'] === 'USD', 'editar publicada: el sitio muestra el cambio y baseSalary solo porque se marcó «Publicar el pago»');
    $r = JobPostings::save($jid, ['valid_through' => $today, 'pay_on' => ''] + $good, $uid);
    T::ok(!$r['ok'] && isset($r['errors']['valid_through']) && JobPostings::find($jid)['valid_through'] === $plus(45), 'editar publicada: una fecha de vencimiento no futura se rechaza y no se guarda');

    // Pausa -> 404
    $r = JobPostings::setStatus($jid, 'pause', $uid);
    $json = json_decode((string) file_get_contents($file), true);
    T::ok($r['ok'] && JobPostings::find($jid)['status'] === 'paused' && ($json['jobs']['regional-cdl-a-company-driver']['status'] ?? '') === 'paused', 'pausar: la vacante queda «En pausa»');
    T::eq([$siteGet($slugUrl)['status'], $siteGet('/careers/cdl-a-drivers/')['status']], [404, 404], '  la página de la vacante y el hub dan 404');
    T::ok(!str_contains($siteGet('/sitemap.xml')['body'], 'cdl-a-drivers') && !str_contains($siteGet('/careers/apply/')['body'], 'value="company-driver"') && str_contains($siteGet('/careers/')['body'], 'No openings listed right now'), '  fuera del sitemap, sin la opción «company driver» y el hub de Careers vuelve a decir que no hay puestos');
    T::ok(!str_contains($siteGet('/llms.txt')['body'] . $siteGet('/llms-full.txt')['body'], 'Regional CDL-A'), '  y fuera de llms.txt');
    T::eq($siteGet($slugUrl . '?preview=tok')['status'], 200, '  con el token de vista previa del sitio se puede revisar');
    T::ok(JobPostings::setStatus($jid, 'publish', $uid)['ok'] && $siteGet($slugUrl)['status'] === 200, 'publicar otra vez desde la pausa: vuelve a existir');
    // Cierre -> 404
    $r = JobPostings::setStatus($jid, 'close', $uid);
    $json = json_decode((string) file_get_contents($file), true);
    T::ok($r['ok'] && JobPostings::find($jid)['status'] === 'closed' && !isset($json['jobs']['regional-cdl-a-company-driver']) && $siteGet($slugUrl)['status'] === 404, 'cerrar: sale de jobs.json y la página da 404');
    T::ok(!JobPostings::save($jid, $good, $uid)['ok'] && JobPostings::setStatus($jid, 'reopen', $uid)['ok'] && JobPostings::find($jid)['status'] === 'draft', 'una cerrada no se edita; reabrir la deja como borrador');
    T::ok(!JobPostings::setStatus($jid, 'pause', $uid)['ok'] && !JobPostings::setStatus($jid, 'explotar', $uid)['ok'] && !JobPostings::setStatus(999999, 'publish', $uid)['ok'], 'transiciones inválidas o vacante inexistente se rechazan');
    // Publicar un borrador incompleto
    $inc = JobPostings::create(['title' => 'Vacante incompleta aun', 'summary' => 'corto', 'city' => '', 'region' => '', 'responsibilities' => '', 'requirements' => ''] + $good, $uid);
    $pr = JobPostings::setStatus((int) $inc['id'], 'publish', $uid);
    T::ok(!$pr['ok'] && str_contains($pr['msg'], 'Para publicar') && JobPostings::find((int) $inc['id'])['status'] === 'draft', 'publicar un borrador incompleto: se rechaza con el motivo y sigue como borrador');
    T::ok(JobPostings::setStatus((int) $inc['id'], 'delete', $uid)['ok'] && JobPostings::find((int) $inc['id']) === null, 'borrar un borrador que nunca se publicó');
    T::ok(!JobPostings::setStatus($jid, 'delete', $uid)['ok'] || JobPostings::find($jid) === null, 'borrar: una vacante con historial no se borra');

    // Vencimiento: el sitio la oculta solo
    JobPostings::setStatus($jid, 'publish', $uid);
    Db::pdo()->exec('UPDATE job_postings SET valid_through = \'' . $plus(-1) . '\', posted_on = \'' . $plus(-20) . '\' WHERE id = ' . $jid);
    T::ok(JobPostings::expired(JobPostings::find($jid)), 'vencimiento: el panel marca «Vencida» a una publicada cuya fecha pasó');
    $stale = (string) file_get_contents($file);   // el archivo anterior (todavia con la vacante vigente de ayer) sigue mostrando la fecha vieja
    $data = json_decode($stale, true);
    $data['jobs']['regional-cdl-a-company-driver']['valid_through'] = $plus(-1);
    $data['jobs']['regional-cdl-a-company-driver']['posted'] = $plus(-20);
    file_put_contents($file, json_encode($data));
    T::eq($siteGet($slugUrl)['status'], 404, 'vencimiento: aunque el archivo siga diciendo «active», el sitio da 404 al pasar valid_through');
    T::ok(!str_contains($siteGet('/sitemap.xml')['body'], 'cdl-a-drivers'), '  y la saca del sitemap');
    JobsPublisher::publish();
    T::ok(!isset(json_decode((string) file_get_contents($file), true)['jobs']['regional-cdl-a-company-driver']), '  al volver a publicar, el panel ya no incluye las vencidas');

    // Si el archivo no se puede escribir, el cambio se revierte y se dice
    Db::pdo()->exec('UPDATE job_postings SET valid_through = \'' . $plus(40) . '\', posted_on = \'' . $today . '\', status = \'draft\', is_active = 0 WHERE id = ' . $jid);
    Env::set('SITE_STORAGE_DIR', "$work/no-puedo/escribir/aqui");
    exec('mkdir -p ' . escapeshellarg("$work/no-puedo") . ' && : > ' . escapeshellarg("$work/no-puedo/escribir"));   // una carpeta que en realidad es un archivo
    $r = JobPostings::setStatus($jid, 'publish', $uid);
    T::ok(!$r['ok'] && str_contains($r['msg'], 'jobs.json') && JobPostings::find($jid)['status'] === 'draft', 'sin permiso de escritura: el cambio de estado se revierte y el mensaje lo explica');
    Env::set('SITE_STORAGE_DIR', $siteStorage);

    // ------------------------------------------------------------------ Pantalla /vacantes (HTTP)
    $s = Panel::start($ctx, 'panel-vac', ['SITE_STORAGE_DIR' => $siteStorage]);
    T::ok($s->login($admin['email']), 'login del administrador');
    $v = $s->get('/vacantes');
    T::ok($v['status'] === 200 && str_contains($v['body'], 'Regional CDL-A Driver (Dry Van)') && str_contains($v['body'], 'Borrador') && str_contains($v['body'], 'hasta 5 minutos'), 'pantalla: lista las vacantes y avisa del retraso por la caché del sitio');
    $tok = $s->csrfOf($v['body']);
    $form1 = $s->get('/vacantes/new');
    T::ok($form1['status'] === 200 && str_contains($form1['body'], 'name="title"') && str_contains($form1['body'], 'name="valid_through"'), 'pantalla: formulario de nueva vacante');
    $r = $s->post('/vacantes/new', ['_csrf' => $tok, 'title' => 'Otra <b>vacante</b>', 'valid_through' => $plus(10), 'posted_on' => $today, 'summary' => 'Texto']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'no puede llevar HTML') && str_contains($r['body'], 'value="Otra &lt;b&gt;vacante&lt;/b&gt;"') && !str_contains($r['body'], 'Otra <b>'), 'pantalla: HTML en el título → 422 con el error junto al campo y el valor escapado');
    $r = $s->post('/vacantes/new', ['_csrf' => $tok, 'title' => 'Local CDL-A Driver Tampa', 'slug' => ''] + $good);
    $nid = (int) Db::value("SELECT id FROM job_postings WHERE slug = 'local-cdl-a-driver-tampa'");
    T::ok($r['status'] === 303 && $nid > 0 && str_contains(Infra::header($r, 'location'), '/vacantes/' . $nid), 'pantalla: crear una vacante la deja como borrador y abre su edición');
    $r = $s->post("/vacantes/$nid/status", ['_csrf' => $tok, 'op' => 'publish']);
    T::ok($r['status'] === 303 && JobPostings::find($nid)['status'] === 'active' && $siteGet('/careers/cdl-a-drivers/local-cdl-a-driver-tampa/')['status'] === 200, 'pantalla: publicar desde el panel y verla en el sitio');
    $edit = $s->get("/vacantes/$nid");
    T::ok(str_contains($edit['body'], 'readonly') && str_contains($edit['body'], 'Pausar') && str_contains($edit['body'], 'Cerrar'), 'pantalla: una publicada tiene el slug bloqueado y los botones Pausar y Cerrar');
    $r = $s->post("/vacantes/$nid/status", ['op' => 'close']);
    T::ok($r['status'] === 303 && JobPostings::find($nid)['status'] === 'active', 'pantalla: cerrar sin token CSRF se rechaza');
    $s->post("/vacantes/$nid/status", ['_csrf' => $tok, 'op' => 'close', 'from' => 'list']);
    T::ok(JobPostings::find($nid)['status'] === 'closed' && $siteGet('/careers/cdl-a-drivers/local-cdl-a-driver-tampa/')['status'] === 404, 'pantalla: cerrar desde la lista y comprobar el 404 en el sitio');
    T::eq($s->get('/vacantes/999999')['status'], 404, 'pantalla: una vacante inexistente da 404');
    Infra::stop('panel-vac');

    // ------------------------------------------------------------------ app/data/jobs.php del sitio con JSON vacio, corrupto, enorme y malicioso
    $root = "$work/jobsroot";
    @mkdir("$root/storage", 0777, true);
    if (!defined('NS_ROOT')) {
        define('NS_ROOT', $root);
    }
    $jobsFile = $repo . '/app/data/jobs.php';
    $read = static fn() => (static function () use ($jobsFile): array {
        $r = require $jobsFile;
        return is_array($r) ? $r : ['__no_array__' => true];
    })();
    $target = "$root/storage/jobs.json";
    $valid = ['schema' => 1, 'generated_at' => '2026-11-05T12:00:00Z', 'jobs' => [
        'driver-a' => ['status' => 'active', 'title' => 'Driver A', 'posted' => '2026-11-01', 'valid_through' => '2026-12-31', 'employment_type' => 'PART_TIME', 'location' => ['city' => 'Tampa', 'region' => 'fl', 'postal' => '33601', 'country' => 'US'],
            'summary' => 'Resumen', 'responsibilities' => ['Uno', 'Dos'], 'pay' => ['min' => 20, 'max' => 25.5, 'unit' => 'HOUR', 'currency' => 'USD']],
    ]];
    file_put_contents($target, json_encode($valid));
    $got = $read();
    T::ok(isset($got['driver-a']) && $got['driver-a']['title'] === 'Driver A' && $got['driver-a']['location']['region'] === 'FL' && $got['driver-a']['employment_type'] === 'PART_TIME' && $got['driver-a']['pay'] === ['min' => 20.0, 'max' => 25.5, 'unit' => 'HOUR', 'currency' => 'USD'], 'jobs.php: JSON válido → vacante saneada (región en mayúsculas, pago normalizado)');
    @unlink($target);
    T::eq($read(), [], 'jobs.php: sin archivo → arreglo vacío');
    foreach ([['vacío (0 bytes)', ''], ['solo espacios', "   \n  "], ['llaves vacías', '{}'], ['corrupto (truncado)', substr(json_encode($valid), 0, 60)], ['basura binaria', random_bytes(300)], ['esquema distinto', json_encode(['schema' => 2] + $valid)],
        ['jobs no es lista', json_encode(['schema' => 1, 'jobs' => 'x'])], ['JSON escalar', '12345678901234567890123'], ['demasiado profundo', str_repeat('[', 40) . str_repeat(']', 40)]] as [$label, $body]) {
        file_put_contents($target, $body);
        $got = $read();
        T::eq($got, [], "jobs.php: JSON {$label} → arreglo vacío y sin fallar");
    }
    file_put_contents($target, str_repeat(' ', 300000) . json_encode($valid));
    T::eq($read(), [], 'jobs.php: archivo de más de 256 KB → se ignora completo');
    $evil = ['schema' => 1, 'jobs' => [
        '../../etc/passwd' => ['status' => 'active', 'title' => 'Ruta', 'posted' => '2026-11-01', 'valid_through' => '2026-12-31'],
        'Con Mayusculas' => ['status' => 'active', 'title' => 'Mayusculas', 'posted' => '2026-11-01', 'valid_through' => '2026-12-31'],
        'sin-titulo' => ['status' => 'active', 'title' => '', 'posted' => '2026-11-01', 'valid_through' => '2026-12-31'],
        'titulo-array' => ['status' => 'active', 'title' => ['x'], 'posted' => '2026-11-01', 'valid_through' => '2026-12-31'],
        'fecha-mala' => ['status' => 'active', 'title' => 'Fecha mala', 'posted' => '2026-02-30', 'valid_through' => '2026-12-31'],
        'sin-vencimiento' => ['status' => 'active', 'title' => 'Sin vencimiento', 'posted' => '2026-11-01'],
        'estado-raro' => ['status' => 'hacked', 'title' => 'Estado raro', 'posted' => '2026-11-01', 'valid_through' => '2026-12-31'],
        'no-es-objeto' => 'texto',
        'xss-ok' => ['status' => 'active', 'title' => '<script>alert(1)</script>Chofer', 'posted' => '2026-11-01', 'valid_through' => '2026-12-31', 'employment_type' => 'DROP TABLE',
            'location' => ['city' => '<img src=x onerror=alert(1)>Miami', 'region' => 'ZZZ', 'postal' => 'abc'], 'summary' => str_repeat('a', 5000), 'responsibilities' => ['ok', ['anidado'], 7, str_repeat('b', 900), '<b>x</b>'],
            'pay' => ['min' => -5, 'max' => 'mucho', 'unit' => 'HOUR'], 'equipment' => "Dry\x00van\n"],
        'pago-mal' => ['status' => 'paused', 'title' => 'Pago invertido', 'posted' => '2026-11-01', 'valid_through' => '2026-12-31', 'pay' => ['min' => 30, 'max' => 10, 'unit' => 'WEEK']],
    ]];
    for ($i = 0; $i < 70; $i++) {
        $evil['jobs']['extra-' . $i] = ['status' => 'active', 'title' => 'Extra ' . $i, 'posted' => '2026-11-01', 'valid_through' => '2026-12-31'];
    }
    file_put_contents($target, json_encode($evil));
    $got = $read();
    T::ok(!isset($got['../../etc/passwd']) && !isset($got['Con Mayusculas']) && !isset($got['sin-titulo']) && !isset($got['titulo-array']) && !isset($got['fecha-mala']) && !isset($got['sin-vencimiento']) && !isset($got['estado-raro']) && !isset($got['no-es-objeto']), 'jobs.php malicioso: se ignoran slug con ruta o mayúsculas, sin título, título no texto, fechas inexistentes, sin vencimiento, estado raro y entradas que no son objeto');
    $x = $got['xss-ok'] ?? [];
    T::ok($x !== [] && !str_contains(json_encode($x), '<') && !str_contains(json_encode($x), '>') && $x['title'] === 'alert(1)Chofer' && $x['employment_type'] === 'FULL_TIME' && !isset($x['location']['postal']) && !isset($x['location']['region']) && !isset($x['pay'])
        && mb_strlen($x['summary']) === 500 && $x['responsibilities'] === ['ok', str_repeat('b', 240), 'x'], 'jobs.php malicioso: sin < ni >, tipo de empleo/región/código postal inválidos descartados, pago inválido descartado, textos recortados');
    T::ok(!isset($got['pago-mal']['pay']) && isset($got['pago-mal']), 'jobs.php malicioso: pago con mínimo mayor que máximo se descarta (la vacante sigue)');
    T::ok(count($got) <= 50, 'jobs.php malicioso: como mucho 50 vacantes (' . count($got) . ')');
    // El sitio completo con ese archivo en su storage: nunca falla
    file_put_contents("$siteStorage/jobs.json", json_encode($evil));
    $bad = 0;
    foreach (['/', '/careers/', '/careers/apply/', '/careers/cdl-a-drivers/', '/sitemap.xml', '/llms.txt', '/careers/cdl-a-drivers/xss-ok/', '/nope-404/'] as $p) {
        $rr = $siteGet($p);
        if (str_contains($rr['body'], 'Fatal error') || str_contains($rr['body'], 'Warning:') || str_contains($rr['body'], 'Stack trace') || in_array($rr['status'], [500, 502], true)) {
            $bad++;
        }
    }
    T::eq($bad, 0, 'sitio con un jobs.json malicioso: ninguna página falla ni imprime errores');
    $xp = $siteGet('/careers/cdl-a-drivers/xss-ok/');
    T::ok($xp['status'] === 200 && !str_contains($xp['body'], '<script>alert(1)') && !str_contains($xp['body'], 'onerror=alert'), '  y el contenido malicioso no llega al HTML');
    file_put_contents("$siteStorage/jobs.json", substr(json_encode($evil), 0, 200));
    T::eq([$siteGet('/')['status'], $siteGet('/careers/cdl-a-drivers/')['status']], [200, 404], 'sitio con un jobs.json corrupto: sigue funcionando y no hay vacantes');
    T::ok(!is_file("$siteStorage/error.log") || !str_contains((string) file_get_contents("$siteStorage/error.log"), 'jobs'), '  sin errores en el log del sitio');
    @unlink("$siteStorage/jobs.json");
    Infra::stop('site-jobs');
    Env::set('SITE_STORAGE_DIR', null);
    T::reset(true);
};
