<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\JobHandlers;
use Captacion\Services\LeadIngest;
use Captacion\Services\PermanentJobFailure;
use Captacion\Services\Queue;
use Captacion\Services\Settings;
use Captacion\Services\Storage;
use Captacion\Services\Telegram;
use CaptacionTests\Infra;
use CaptacionTests\T;

return static function (array $ctx): void {
    $work = $ctx['work'];
    $job = static fn(int $id) => Db::one('SELECT * FROM jobs WHERE id = ?', [$id]);
    $count = static fn(string $t, string $where = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $where");

    // ---------------------------------------------------------------- cola
    T::reset();
    $id1 = Queue::push('t_ok', ['a' => 1], null, 'k1');
    T::ok($id1 !== null && Queue::push('t_ok', ['a' => 2], null, 'k1') === null && $count('jobs') === 1, 'dedupe_key: el mismo job no se encola dos veces');
    $ran = [];
    $h = ['t_ok' => static function (array $p, array $j) use (&$ran): void { $ran[] = $p; }];
    $st = Queue::runBatch($h, 5);
    T::ok($st['processed'] === 1 && $st['done'] === 1 && $job($id1)['status'] === 'done' && (int) $job($id1)['attempts'] === 1 && $ran === [['a' => 1]], 'exito: el handler recibe el payload y el job queda done (1 intento)');
    $hb = Settings::get('cron.process_jobs');
    T::ok(is_array($hb) && $hb['processed'] === 1 && $hb['at'] !== '', 'cada pasada deja su marca de "ultima ejecucion del cron"');
    T::eq(Queue::runBatch($h, 5)['processed'], 0, 'sin jobs vencidos no procesa nada (y deja marca igualmente)');

    // Reintentos a 1, 5 y 15 minutos y luego failed
    T::reset();
    $id = Queue::push('t_fail', []);
    $calls = 0;
    $h = ['t_fail' => static function () use (&$calls): void { $calls++; throw new RuntimeException('boom ' . $calls); }];
    $t0 = Clock::time();
    Queue::runBatch($h, 5);
    $j = $job($id);
    T::ok($j['status'] === 'pending' && (int) $j['attempts'] === 1 && abs((int) Clock::parse($j['run_at']) - ($t0 + 60)) <= 3 && $j['last_error'] === 'boom 1', 'fallo 1: reintento en 1 minuto, last_error guardado');
    T::eq(Queue::runBatch($h, 5)['processed'], 0, 'antes de la hora del reintento no se vuelve a ejecutar');
    Clock::travel(61);
    Queue::runBatch($h, 5);
    $j = $job($id);
    T::ok($j['status'] === 'pending' && (int) $j['attempts'] === 2 && abs((int) Clock::parse($j['run_at']) - ($t0 + 61 + 300)) <= 3, 'fallo 2: reintento en 5 minutos');
    Clock::travel(61 + 301);
    Queue::runBatch($h, 5);
    $j = $job($id);
    T::ok($j['status'] === 'pending' && (int) $j['attempts'] === 3 && abs((int) Clock::parse($j['run_at']) - ($t0 + 362 + 900)) <= 3, 'fallo 3: reintento en 15 minutos');
    Clock::travel(61 + 301 + 901);
    Queue::runBatch($h, 5);
    $j = $job($id);
    T::ok($j['status'] === 'failed' && (int) $j['attempts'] === 4 && $j['finished_at'] !== null && $j['last_error'] === 'boom 4' && $calls === 4, 'fallo 4: estado failed para revision (4 ejecuciones en total)');
    Clock::travel(100000);
    T::eq(Queue::runBatch($h, 5)['processed'], 0, 'un job failed no se vuelve a ejecutar solo');
    T::eq(Queue::stats()['counts']['failed'], 1, 'stats(): 1 fallido');
    T::eq(count(Queue::stats()['recent_fail']), 1, 'stats(): lista de fallidos recientes');

    // Tipos desconocidos, fallo permanente, errores largos y con secretos
    T::reset();
    $a = Queue::push('no_existe', []);
    $b = Queue::push('t_perm', []);
    Env::set('TELEGRAM_BOT_TOKEN', '123456789:FAKE_TEST_TOKEN_not_real_0123456789');
    $c = Queue::push('t_long', []);
    $h = ['t_perm' => static function (): void { throw new PermanentJobFailure('lead inexistente'); },
          't_long' => static function (): void { throw new RuntimeException(str_repeat('x', 2000) . ' https://api.telegram.org/bot123456789:FAKE_TEST_TOKEN_not_real_0123456789/sendMessage'); }];
    Queue::runBatch($h, 5);
    T::ok($job($a)['status'] === 'failed' && str_contains((string) $job($a)['last_error'], 'desconocido'), 'tipo de job desconocido: failed de inmediato');
    T::ok($job($b)['status'] === 'failed' && (int) $job($b)['attempts'] === 1, 'PermanentJobFailure: failed sin reintentos');
    $le = (string) $job($c)['last_error'];
    T::ok(mb_strlen($le) <= 480 && !str_contains($le, 'FAKE_TEST_TOKEN_not_real_0123456789') && $job($c)['status'] === 'pending', 'last_error truncado a 480 caracteres y sin el token');
    Env::set('TELEGRAM_BOT_TOKEN', null);

    // Futuro, parked, stale, limite de tiempo, claim atomico
    T::reset();
    Queue::push('t_ok', [], Clock::shift(3600));
    $pk = Queue::push('t_ok', [], null, 'park', 'parked', 'nota');
    $h = ['t_ok' => static function (): void {}];
    T::eq(Queue::runBatch($h, 5)['processed'], 0, 'jobs futuros y jobs parked no se ejecutan');
    T::eq($job($pk)['status'], 'parked', 'parked sigue parked');
    T::reset();
    $sid = Queue::push('t_ok', []);
    Db::run("UPDATE jobs SET status = 'running', started_at = ?, attempts = 1 WHERE id = ?", [Clock::shift(-1200), $sid]);
    Queue::runBatch($h, 5);
    T::ok($job($sid)['status'] === 'done', 'job "running" de hace 20 min (proceso muerto): se recupera y se procesa');
    T::reset();
    for ($i = 0; $i < 6; $i++) {
        Queue::push('t_slow', ['i' => $i]);
    }
    $st = Queue::runBatch(['t_slow' => static function (): void { usleep(400000); }], 1);
    T::ok($st['processed'] >= 2 && $st['processed'] <= 4 && $count('jobs', "status = 'pending'") === 6 - $st['processed'], 'tiempo maximo por pasada: se detiene y deja el resto pendiente (procesados ' . $st['processed'] . ')');
    T::reset();
    $cid = Queue::push('t_ok', []);
    $claim = new ReflectionMethod(Queue::class, 'claim');
    $claim->setAccessible(true);
    T::ok($claim->invoke(null, $cid) !== null && $claim->invoke(null, $cid) === null, 'claim atomico: el segundo intento de tomar el mismo job devuelve null');

    // ---------------------------------------------------------------- cron (CLI) + flock
    T::reset();
    $envFile = "$work/cron.env";
    $envVars = ['CAPTACION_ENV_FILE' => $envFile, 'STORAGE_DIR' => "$work/storage"];
    file_put_contents($envFile, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($ctx['env']), $ctx['env'])) . "\n");
    $lead = LeadIngest::handle(['profile' => 'owner-operator', 'name' => 'Cron Test', 'phone' => '3052345601', 'email' => 'cron@example.com', 'cdl_standard' => 'yes', 'english' => 'yes', 'years' => '3'], 'web_careers', ['quiet' => true]);
    Queue::push('email_notify', ['lead_id' => $lead['lead_id'], 'kind' => 'new_lead']);
    Storage::ensure(Storage::dir('locks'));
    @unlink(Storage::dir('mail.log'));
    $fh = fopen(Storage::dir('locks/process_jobs.lock'), 'c');
    flock($fh, LOCK_EX);
    $r = Infra::cli([$ctx['root'] . '/cron/process_jobs.php'], $envVars);
    T::ok($r['code'] === 0 && str_contains($r['out'], 'locked'), 'cron con el flock ocupado: sale sin hacer nada ("locked")');
    T::eq($count('jobs', "status = 'pending'"), 1, '  el job sigue pendiente (no hubo solape)');
    flock($fh, LOCK_UN);
    fclose($fh);
    $r = Infra::cli([$ctx['root'] . '/cron/process_jobs.php'], $envVars);
    T::ok($r['code'] === 0 && str_contains($r['out'], '"done":1'), 'cron con el flock libre: procesa el job (' . trim($r['out']) . ')');
    T::eq($count('jobs', "status = 'done'"), 1, '  email_notify hecho');
    $mail = is_file(Storage::dir('mail.log')) ? (string) file_get_contents(Storage::dir('mail.log')) : '';
    T::ok(str_contains($mail, '[Captacion] Nuevo lead: Cron Test') && str_contains($mail, 'Telefono: +13052345601') && str_contains($mail, '[logged: SMTP sin configurar]'), '  sin SMTP el aviso queda registrado en storage/mail.log (con el asunto y los datos del lead)');
    // Base caida: otro .env con un puerto que no responde
    $badEnv = "$work/cron-bad.env";
    file_put_contents($badEnv, str_replace('DB_PORT=' . $ctx['dbPort'], 'DB_PORT=1', (string) file_get_contents($envFile)));
    $r = Infra::cli([$ctx['root'] . '/cron/process_jobs.php'], ['CAPTACION_ENV_FILE' => $badEnv, 'STORAGE_DIR' => "$work/storage"]);
    T::ok($r['code'] === 1 && !str_contains($r['out'], 'captest-pass'), 'cron con la base caida: exit 1 y sin credenciales en la salida');

    // ---------------------------------------------------------------- Telegram (servidor simulado)
    $log = "$work/tg.log";
    $modeFile = "$work/tg.mode";
    @unlink($log);
    file_put_contents($modeFile, 'ok');
    $port = Infra::phpServer('tgmock', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $log, 'MOCK_MODE_FILE' => $modeFile], $ctx['root'] . '/tests/lib/telegram_mock.php');
    $token = '123456789:FAKE_TEST_TOKEN_not_real_0123456789';
    $reqs = static fn() => is_file($log) ? array_values(array_filter(array_map(static fn($l) => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES) ?: []))) : [];

    // Lead caliente de prueba: 10 camiones + referido + FMCSA activa (fixtures) = 100 puntos
    $fmOk = static function () use ($ctx): Captacion\Services\Fmcsa {
        return new Captacion\Services\Fmcsa(static function (string $u) use ($ctx): array {
            $d = $ctx['root'] . '/tests/fixtures/fmcsa/';
            return str_contains($u, '/authority')
                ? ['status' => 200, 'body' => str_replace('__GRANT__', gmdate('Y-m-d', strtotime('-12 months -2 days')), (string) file_get_contents($d . 'authority_active.json'))]
                : ['status' => 200, 'body' => (string) file_get_contents($d . 'carrier_active.json')];
        }, 'K');
    };
    $baseHot = ['profile' => 'owner-operator', 'name' => 'Ana <b>Perez</b> & Co', 'phone' => '(863) 555-0199', 'email' => 'ana@example.com', 'state' => 'FL', 'language' => 'en', 'cdl_standard' => 'yes',
        'english' => 'yes', 'years' => '4', 'authority' => 'active', 'contact_pref' => 'whatsapp', 'usdot' => '1234567', 'referral_code' => 'AMIGO', 'trucks' => '10', 'equipment' => ['dry-van', 'reefer'],
        'attribution' => ['landing' => '/careers/owner-operators/', 'utm_source' => 'google'], 'consent' => ['given' => true, 'text' => 'T', 'version' => 'v1']];
    $mkHot = static fn(array $over = []) => LeadIngest::handle($over + $baseHot, 'web_careers', ['fmcsa' => $fmOk()]);

    // Sin configurar: falla de forma controlada, con reintentos y mensaje claro
    T::reset();
    Env::set('TELEGRAM_BOT_TOKEN', null);
    Env::set('TELEGRAM_CHAT_ID', null);
    Env::set('TELEGRAM_API_BASE', "http://127.0.0.1:$port");
    T::eq(Telegram::configured(), false, 'Telegram::configured() = false sin variables');
    $res = $mkHot();
    $leadId = (int) $res['lead_id'];
    T::eq($res['temperature'], 'hot', 'lead caliente para las alertas');
    Queue::runBatch(JobHandlers::all(), 5);
    $alert = Db::one("SELECT * FROM jobs WHERE type = 'telegram_alert'");
    T::ok($alert['status'] === 'pending' && (int) $alert['attempts'] === 1 && str_contains((string) $alert['last_error'], 'Telegram sin configurar') && $reqs() === [], 'sin TELEGRAM_*: el job falla con mensaje claro, queda para reintento y no hace ninguna peticion');

    // Configurado: alerta A2
    Env::set('TELEGRAM_BOT_TOKEN', $token);
    Env::set('TELEGRAM_CHAT_ID', '555000');
    Clock::travel(61);
    Queue::runBatch(JobHandlers::all(), 5);
    $rq = $reqs();
    T::eq(count($rq), 1, 'A2: una peticion a la API de Telegram tras configurar y reintentar');
    $body = $rq[0]['body'] ?? [];
    T::ok(($rq[0]['path'] ?? '') === "/bot$token/sendMessage" && ($body['chat_id'] ?? '') === '555000' && ($body['parse_mode'] ?? '') === 'HTML', 'A2: POST /bot<token>/sendMessage con chat_id y parse_mode HTML');
    $text = (string) ($body['text'] ?? '');
    foreach (['Lead CALIENTE' => 'Lead CALIENTE', 'nombre (escapado)' => 'Ana &lt;b&gt;Perez&lt;/b&gt; &amp; Co', 'perfil' => 'Owner-operator', 'estado' => 'Estado: FL', 'equipo' => 'dry-van, reefer', 'puntuacion' => 'Puntuacion: 100/100',
        'fuente' => 'Fuente: web_careers', 'pagina de llegada' => '/careers/owner-operators/', 'enlace tel:' => 'href="tel:+18635550199"', 'enlace al panel' => 'href="' . $ctx['env']['APP_URL'] . '/leads/' . $leadId . '"'] as $what => $needle) {
        T::ok(str_contains($text, $needle), "  la alerta incluye $what");
    }
    T::ok(!str_contains($text, '<b>Perez'), '  el nombre no puede inyectar HTML en el mensaje');
    T::eq(Db::one("SELECT status FROM jobs WHERE type = 'telegram_alert'")['status'], 'done', 'A2: job hecho');
    T::ok($count('lead_events', "lead_id = $leadId AND type = 'alert_sent'") === 1, 'A2: evento alert_sent en el lead');

    // A3: a los 15 minutos sin primer contacto -> segunda alerta + SMS de respaldo registrado (parked, no ejecutado)
    $fu = Db::one("SELECT * FROM jobs WHERE type = 'telegram_alert_followup'");
    T::ok($fu['status'] === 'pending' && (int) Clock::parse($fu['run_at']) > Clock::time(), 'A3: sigue pendiente (programado a 15 min)');
    Clock::travel(13 * 60);
    Queue::runBatch(JobHandlers::all(), 5);
    T::eq(count($reqs()), 1, 'A3: antes de los 15 min no sale nada');
    Clock::travel(16 * 60);
    Queue::runBatch(JobHandlers::all(), 5);
    $rq = $reqs();
    T::ok(count($rq) === 2 && str_contains((string) ($rq[1]['body']['text'] ?? ''), 'SIN CONTACTO tras 15 min'), 'A3: a los 15 min sin primer contacto sale la segunda alerta');
    $sms = Db::one("SELECT * FROM jobs WHERE type = 'sms_backup_alert'");
    T::ok($sms !== null && $sms['status'] === 'parked' && str_contains((string) $sms['last_error'], 'TODO Q4'), 'A3: sms_backup_alert registrado como parked con TODO Q4 (no se ejecuta)');
    Clock::travel(200000);
    Queue::runBatch(JobHandlers::all(), 5);
    T::eq(Db::one("SELECT status FROM jobs WHERE type = 'sms_backup_alert'")['status'], 'parked', 'sms_backup_alert sigue parked despues de otra pasada');

    // A3 con primer contacto registrado: no alerta
    T::reset();
    @unlink($log);
    $res = $mkHot(['phone' => '(863) 555-0188', 'email' => 'bea@example.com', 'name' => 'Bea Cruz']);
    Queue::runBatch(JobHandlers::all(), 5);
    $n1 = count($reqs());
    Db::update('leads', (int) $res['lead_id'], ['first_contact_at' => Clock::now()]);
    Clock::travel(16 * 60);
    Queue::runBatch(JobHandlers::all(), 5);
    T::ok($n1 === 1 && count($reqs()) === 1 && $count('jobs', "type = 'sms_backup_alert'") === 0, 'A3: si ya hubo primer contacto no se envia la segunda alerta ni el SMS de respaldo');
    Clock::travel(0);

    // Fallos de Telegram: 401, 500, sin servidor; el token nunca queda registrado
    foreach (['fail' => 'rechazo el mensaje (HTTP 401): Unauthorized', 'error500' => 'rechazo el mensaje (HTTP 500)'] as $mode => $expect) {
        T::reset();
        file_put_contents($modeFile, $mode);
        $mkHot(['phone' => '(863) 555-0177', 'email' => 'cy@example.com', 'name' => 'Cy Dee']);
        Queue::runBatch(JobHandlers::all(), 5);
        $j = Db::one("SELECT * FROM jobs WHERE type = 'telegram_alert'");
        T::ok($j['status'] === 'pending' && str_contains((string) $j['last_error'], $expect) && !str_contains((string) $j['last_error'], 'FAKE_TEST_TOKEN_not_real_0123456789'), "Telegram $mode: el job se reintenta con el motivo, sin el token");
    }
    file_put_contents($modeFile, 'ok');
    T::reset();
    Env::set('TELEGRAM_API_BASE', 'http://127.0.0.1:1');
    $mkHot(['phone' => '(863) 555-0166', 'email' => 'di@example.com', 'name' => 'Di Eff']);
    Queue::runBatch(JobHandlers::all(), 5);
    $j = Db::one("SELECT * FROM jobs WHERE type = 'telegram_alert'");
    T::ok($j['status'] === 'pending' && str_contains((string) $j['last_error'], 'Telegram no responde') && !str_contains((string) $j['last_error'], 'FAKE_TEST_TOKEN_not_real_0123456789'), 'Telegram caido (sin servidor): se reintenta con "Telegram no responde"');
    Env::set('TELEGRAM_API_BASE', "http://127.0.0.1:$port");

    // El lead desaparece antes de la alerta
    T::reset();
    $x = Queue::push('telegram_alert', ['lead_id' => 424242, 'kind' => 'hot']);
    Queue::runBatch(JobHandlers::all(), 5);
    T::eq($job($x)['status'], 'failed', 'alerta de un lead inexistente: failed sin reintentos');

    // Una pasada lenta del cron real no se solapa con la siguiente (flock)
    T::reset();
    file_put_contents($modeFile, 'slow');
    $mkHot(['phone' => '(863) 555-0155', 'email' => 'ed@example.com', 'name' => 'Ed Fox']);
    $tgEnv = "$work/cron-tg.env";
    file_put_contents($tgEnv, (string) file_get_contents($envFile) . "TELEGRAM_BOT_TOKEN=$token\nTELEGRAM_CHAT_ID=555000\nTELEGRAM_API_BASE=http://127.0.0.1:$port\n");
    $pipes = [];
    $slow = proc_open([PHP_BINARY, $ctx['root'] . '/cron/process_jobs.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['CAPTACION_ENV_FILE' => $tgEnv, 'STORAGE_DIR' => "$work/storage"]));
    usleep(1200000);   // la primera pasada ya esta dentro de la peticion lenta (3 s)
    $r2 = Infra::cli([$ctx['root'] . '/cron/process_jobs.php'], ['CAPTACION_ENV_FILE' => $tgEnv, 'STORAGE_DIR' => "$work/storage"]);
    $out1 = stream_get_contents($pipes[1]);
    proc_close($slow);
    T::ok(str_contains($r2['out'], 'locked') && str_contains($out1, '"done":1'), 'solape real: la segunda pasada del cron sale con "locked" mientras la primera sigue enviando; la primera termina (' . trim($out1) . ')');
    $alerts = array_filter($reqs(), static fn($q) => str_contains((string) ($q['body']['text'] ?? ''), 'Ed Fox'));
    T::eq(count($alerts), 1, '  la alerta salio una sola vez (sin duplicados)');
    file_put_contents($modeFile, 'ok');

    // El token no aparece en ningun log del workdir
    $leak = false;
    foreach (glob("$work/storage/*.log") ?: [] as $f) {
        if (str_contains((string) file_get_contents($f), 'FAKE_TEST_TOKEN_not_real_0123456789')) {
            $leak = true;
        }
    }
    T::ok(!$leak, 'el token de Telegram no aparece en storage/*.log');
    Env::set('TELEGRAM_BOT_TOKEN', null);
    Env::set('TELEGRAM_CHAT_ID', null);
    Env::set('TELEGRAM_API_BASE', null);
    Infra::stop('tgmock');
};
