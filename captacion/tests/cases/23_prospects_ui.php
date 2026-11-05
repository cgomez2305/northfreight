<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Notifications;
use Captacion\Services\ProspectConfig;
use Captacion\Services\ProspectImport;
use Captacion\Services\Prospects;
use Captacion\Services\Settings;
use Captacion\Services\UsTimeZones;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\SocrataFixture;
use CaptacionTests\T;

/**
 * Prospectos FMCSA por HTTP y cron (Q4a-A): pantalla /prospectos, resultado con un clic, convertir, reabrir, exportar CSV, configuracion, autorizacion por rol en cada ruta nueva,
 * campana y el cron fmcsa_daily.php por CLI contra un Socrata simulado (con las fixtures reales): decide por la hora ET, flock, token, errores y reanudacion.
 */
return static function (array $ctx): void {
    T::reset(true);
    $work = $ctx['work'];
    $utc = static fn(string $s): int => (new DateTimeImmutable($s, new DateTimeZone('UTC')))->getTimestamp();
    $admin = Panel::user('admin');
    $viewer = Panel::user('viewer');
    $uid = $admin['id'];

    // ------------------------------------------------------------------ Datos: corrida con fixtures reales
    Settings::clear();
    $fx = new SocrataFixture();
    $client = new Captacion\Services\SocrataClient($fx->http(), static function (float $s): void {}, 'https://example.test', '');
    Clock::travel($utc('2026-11-05 15:00') - time());
    ProspectImport::tick(Clock::time(), ['client' => $client, 'force' => true]);
    Clock::travel(0);   // el servidor php -S usa la hora real: las pruebas de pantalla se calculan contra ella
    T::eq((int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa'), 10, 'datos: 10 prospectos de las fixtures reales');
    Db::pdo()->exec("UPDATE prospects_fmcsa SET legal_name = '=HYPERLINK(\"http://x\")' WHERE usdot = '9100001'");

    $s = Panel::start($ctx, 'panel-pro');
    T::ok($s->login($admin['email']), 'login del administrador');
    $r = $s->get('/prospectos');
    T::ok($r['status'] === 200 && str_contains($r['body'], 'Prospectos FMCSA') && str_contains($r['body'], 'FIXTURE CARRIER 04 LLC') && str_contains($r['body'], 'Reglas de las llamadas en frío'), 'pantalla: lista de hoy con los prospectos y el recordatorio de reglas');
    T::ok(str_contains($r['body'], '10</span></a>') || str_contains($r['body'], 'ptab__n">10<'), '  contador «Lista de hoy» = 10');
    T::ok(!str_contains($r['body'], 'Warning') && !str_contains($r['body'], 'Fatal error') && !str_contains($r['body'], 'Stack trace'), '  sin errores de PHP');
    T::ok(str_contains($r['body'], 'validar') || str_contains($r['body'], 'abogado'), '  recuerda validar con el abogado (TCPA y listas estatales)');

    // Boton Llamar segun el horario del estado, calculado con la hora REAL de la prueba
    $now = time();
    $checks = ['TX' => UsTimeZones::check('TX', $now), 'CA' => UsTimeZones::check('CA', $now), 'MI' => UsTimeZones::check('MI', $now), 'WY' => UsTimeZones::check('WY', $now)];
    foreach (['9100004' => 'TX', '9100002' => 'CA', '9100007' => 'MI', '9100005' => 'WY'] as $dot => $st) {
        $id = (int) Db::value('SELECT id FROM prospects_fmcsa WHERE usdot = ?', [$dot]);
        $phone = (string) Db::value('SELECT phone FROM prospects_fmcsa WHERE id = ?', [$id]);
        preg_match('#<li class="pc[^"]*" id="p-' . $id . '">(.*?)</li>\s*(?=<li class="pc|</ol>)#s', $r['body'], $m);
        $card = $m[1] ?? '';
        $hasTel = str_contains($card, 'href="tel:' . $phone . '"');
        T::eq($hasTel, $checks[$st]['allowed'], "botón Llamar de {$st}: " . ($checks[$st]['allowed'] ? 'activo con tel:' : 'deshabilitado, sin enlace tel:') . ' (hora real)');
        if (!$checks[$st]['allowed']) {
            T::ok(str_contains($card, 'Fuera de horario') && str_contains($card, 'Ver teléfono') && str_contains($card, 'aria-disabled="true"'), '  fuera de horario: muestra la hora local, «Ver teléfono» y el botón con aria-disabled');
        } else {
            T::ok(str_contains($card, 'Allá son las '), '  dentro de horario: muestra la hora local del prospecto');
        }
    }
    $sinTel = Db::insert('prospects_fmcsa', ['usdot' => '9100555', 'legal_name' => 'Sin Telefono LLC', 'state' => 'TX', 'authority_granted_at' => '2026-11-04', 'status' => 'to_call', 'listed_on' => '2026-11-05', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    T::ok(str_contains($s->get('/prospectos?q=Sin+Telefono')['body'], 'Sin teléfono en FMCSA'), 'pantalla: un prospecto sin teléfono lo dice y no ofrece llamar');
    Db::pdo()->exec('DELETE FROM prospects_fmcsa WHERE id = ' . $sinTel);

    // Busqueda y pestañas
    T::ok(str_contains($s->get('/prospectos?q=9100007')['body'], 'FIXTURE CARRIER 07 LLC') && !str_contains($s->get('/prospectos?q=9100007')['body'], 'FIXTURE CARRIER 04 LLC'), 'pantalla: buscar por USDOT filtra la lista');
    T::ok(str_contains($s->get('/prospectos?q=zzzz-nada')['body'], 'Ningún prospecto coincide'), '  y sin coincidencias muestra el estado vacío con salida');
    T::ok(str_contains($s->get('/prospectos?tab=dnc')['body'], 'Aquí no hay nadie todavía'), 'pantalla: la pestaña «No llamar más» vacía lo explica');

    // Resultado con un clic (HTTP + CSRF)
    $pid = (int) Db::value("SELECT id FROM prospects_fmcsa WHERE usdot = '9100004'");
    $page = $s->get('/prospectos');
    $tok = $s->csrfOf($page['body']);
    $before = (int) Db::value('SELECT COUNT(*) FROM prospect_calls');
    $r = $s->post("/prospectos/$pid/result", ['result' => 'no_answer', 'note' => 'Buzón de voz', 'next' => '', 'back' => '/prospectos']);
    T::eq([$r['status'], (int) Db::value('SELECT COUNT(*) FROM prospect_calls')], [303, $before], 'resultado por HTTP sin token CSRF: se rechaza y no cambia nada');
    $r = $s->post("/prospectos/$pid/result", ['_csrf' => $tok, 'result' => 'no_answer', 'note' => 'Buzón de voz', 'next' => '', 'back' => '/prospectos']);
    $p = Prospects::find($pid);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/prospectos' && $p['status'] === 'no_answer' && (int) $p['attempts'] === 1, 'resultado por HTTP con CSRF: registra el intento y vuelve a la lista');
    T::ok(str_contains($s->get('/prospectos')['body'], 'Sin respuesta registrada'), '  y avisa con un mensaje');
    T::ok((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'prospect_call' AND entity_id = ?", [(string) $pid]) === 1, '  queda en activity_log (sin la nota ni el teléfono)');
    $log = (string) Db::value("SELECT changes FROM activity_log WHERE action = 'prospect_call' ORDER BY id DESC LIMIT 1");
    T::ok(!str_contains($log, 'Buzón') && !str_contains($log, '+1713'), '  el registro no guarda la nota ni datos personales');
    $r = $s->post("/prospectos/$pid/result", ['_csrf' => $tok, 'result' => 'abracadabra', 'back' => 'https://evil.example/']);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/prospectos' && Prospects::find($pid)['status'] === 'no_answer', 'resultado inválido: se ignora y el «back» externo se descarta (redirige a /prospectos)');
    $s->post("/prospectos/$pid/result", ['_csrf' => $tok, 'result' => 'no_answer', 'back' => '/prospectos']);
    $s->post("/prospectos/$pid/result", ['_csrf' => $tok, 'result' => 'no_answer', 'back' => '/prospectos']);
    T::eq(Prospects::find($pid)['status'], 'no_answer_closed', 'resultado: tres «sin respuesta» por HTTP cierran al prospecto');
    $page = $s->get('/prospectos?tab=no_answer');
    T::ok(str_contains($page['body'], 'Sin respuesta (cerrada)') && str_contains($page['body'], 'Reabrir') && str_contains($page['body'], 'Historial (3)'), 'pestaña Sin respuesta: muestra la cerrada, el botón Reabrir y su historial de 3 intentos');
    $r = $s->post("/prospectos/$pid/reopen", ['_csrf' => $tok, 'back' => '/prospectos?tab=no_answer']);
    T::ok($r['status'] === 303 && Prospects::find($pid)['status'] === 'to_call', 'reabrir por HTTP: vuelve a «Por llamar»');

    // Convertir en lead por HTTP
    $leadsBefore = (int) Db::value('SELECT COUNT(*) FROM leads');
    $jobsBefore = (int) Db::value('SELECT COUNT(*) FROM jobs');
    $r = $s->post("/prospectos/$pid/convert", ['_csrf' => $tok, 'back' => '/prospectos']);
    T::ok($r['status'] === 303 && (int) Db::value('SELECT COUNT(*) FROM leads') === $leadsBefore + 1 && (int) Db::value('SELECT COUNT(*) FROM jobs') === $jobsBefore, 'convertir por HTTP: crea el lead sin encolar nada');
    $lead = Db::one('SELECT * FROM leads ORDER BY id DESC LIMIT 1');
    T::ok($lead['source'] === 'fmcsa_outbound' && $lead['stage'] === 'contacted' && $lead['first_contact_at'] !== null, '  fuente fmcsa_outbound, etapa Contactado');
    $page = $s->get('/prospectos?tab=interested');
    T::ok(str_contains($page['body'], 'Lead #' . $lead['id']) && str_contains($page['body'], 'href="/leads/' . $lead['id'] . '"'), '  la tarjeta enlaza al lead');
    T::ok(str_contains($s->get('/leads/' . $lead['id'])['body'], 'Convertido desde un prospecto FMCSA'), '  la ficha del lead muestra el evento en su línea de tiempo');

    // No llamar mas por HTTP
    $pid2 = (int) Db::value("SELECT id FROM prospects_fmcsa WHERE usdot = '9100005'");
    $r = $s->post("/prospectos/$pid2/result", ['_csrf' => $tok, 'result' => 'dnc', 'note' => 'No quiere llamadas', 'back' => '/prospectos']);
    $page = $s->get('/prospectos?tab=dnc');
    T::ok(Prospects::find($pid2)['status'] === 'dnc' && str_contains($page['body'], 'No llamar más') && str_contains($page['body'], 'permanente') && !str_contains($page['body'], 'name="result"'), 'no llamar más por HTTP: la tarjeta queda sin botones de acción');
    T::ok(!str_contains($s->get('/prospectos')['body'], 'id="p-' . $pid2 . '"'), '  y desaparece de la lista de hoy');

    // Exportar CSV
    $r = $s->post('/prospectos/export', ['_csrf' => $tok, 'qs' => 'us=TX']);
    T::ok($r['status'] === 200 && str_starts_with($r['body'], "\xEF\xBB\xBFUSDOT,MC,") && str_contains((string) Infra::header($r, 'content-type'), 'text/csv'), 'exportar CSV: UTF-8 con BOM y cabecera');
    T::ok(str_contains($r['body'], '9100012') && str_contains($r['body'], '9100013') && str_contains($r['body'], '9100001') && !str_contains($r['body'], '9100002') && !str_contains($r['body'], '9100007'), '  respeta el filtro de estado (solo TX, sin los ya convertidos)');
    T::ok(str_contains($r['body'], "'=HYPERLINK") && !preg_match('/(^|,)=HYPERLINK/m', $r['body']), '  protección contra fórmulas: «=HYPERLINK» sale prefijado con comilla');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'prospects_export'"), 1, '  la exportación queda en activity_log');
    T::eq($s->post('/prospectos/export', ['qs' => 'us=TX'])['status'], 303, 'exportar sin token CSRF: se rechaza');

    // Campana y barra lateral
    Db::pdo()->exec("UPDATE prospects_fmcsa SET status = 'talked', next_call_at = '" . Clock::shift(-3600) . "' WHERE usdot = '9100001'");
    $notes = Notifications::all((int) $uid);
    $keys = array_map(static fn($n) => explode(':', $n['key'])[0], $notes);
    T::ok(in_array('prospects_due', $keys, true), 'campana: aviso de prospectos con seguimiento para hoy');
    T::ok(in_array('fmcsa_cron', $keys, true), 'campana: avisa que el cron de FMCSA nunca se ha ejecutado');
    Settings::set('cron.prospects', ['at' => Clock::now(), 'status' => 'done']);
    T::ok(!in_array('fmcsa_cron', array_map(static fn($n) => explode(':', $n['key'])[0], Notifications::all((int) $uid)), true), '  y deja de avisar cuando el cron corrió hace poco');
    Settings::set('cron.prospects', ['at' => Clock::shift(-4 * 3600), 'status' => 'done']);
    T::ok(in_array('fmcsa_cron', array_map(static fn($n) => explode(':', $n['key'])[0], Notifications::all((int) $uid)), true), '  y vuelve a avisar si lleva más de 3 horas sin correr');
    Settings::set('prospects.config', ['enabled' => false] + ProspectConfig::DEFAULTS);
    T::ok(!in_array('fmcsa_cron', array_map(static fn($n) => explode(':', $n['key'])[0], Notifications::all((int) $uid)), true), '  con la búsqueda apagada no avisa del cron');
    Settings::set('prospects.config', ProspectConfig::DEFAULTS);
    $home = $s->get('/');
    T::ok(str_contains($home['body'], 'href="/prospectos"') && str_contains($home['body'], 'href="/referidos"') && str_contains($home['body'], 'href="/vacantes"'), 'barra lateral: Prospectos, Referidos y Vacantes (administrador)');

    // ------------------------------------------------------------------ Configuracion > Prospectos
    $c = $s->get('/config?tab=prospectos');
    T::ok($c['status'] === 200 && str_contains($c['body'], 'Antigüedad máxima de la autoridad') && str_contains($c['body'], 'Edición bloqueada'), 'config Prospectos: formulario con la edición bloqueada hasta confirmar identidad');
    $r = $s->post('/config/prospects/save', ['_csrf' => $tok, 'max_age_months' => '6', 'max_fleet' => '3', 'daily_cap' => '10', 'enabled' => '1', 'require_phone' => '1']);
    T::ok($r['status'] === 303 && ProspectConfig::get()['max_age_months'] === 12, 'config Prospectos: sin confirmar identidad, el POST no guarda nada');
    T::ok($s->unlock((int) $uid, 'prospectos'), 'config: confirmar identidad (contraseña + 2FA)');
    $tok2 = $s->csrfOf($s->get('/config?tab=prospectos')['body']);
    Settings::set(ProspectImport::COMPLETE_KEY, '2026-11-05');   // marca de ventana completa previa
    $r = $s->post('/config/prospects/save', ['_csrf' => $tok2, 'max_age_months' => '99', 'max_fleet' => '3', 'daily_cap' => '10', 'enabled' => '1', 'require_phone' => '1']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'entre 1 y 24') && ProspectConfig::get()['max_age_months'] === 12, 'config Prospectos: meses fuera de rango → 422 con el error junto al campo y sin guardar');
    $r = $s->post('/config/prospects/save', ['_csrf' => $tok2, 'max_age_months' => '6', 'max_fleet' => '3', 'daily_cap' => '10', 'enabled' => '1', 'require_phone' => '1', 'states' => ['tx', 'FL'], 'cargo' => ['cold']]);
    Settings::clear();
    $cfg = ProspectConfig::get();
    T::ok($r['status'] === 303 && $cfg['max_age_months'] === 6 && $cfg['max_fleet'] === 3 && $cfg['daily_cap'] === 10 && $cfg['states'] === ['TX', 'FL'] && $cfg['cargo'] === ['cold'], 'config Prospectos: guarda los filtros válidos (HTTP ' . $r['status'] . ' ' . json_encode($cfg) . ')');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'settings_change' AND entity_id = 'prospects'"), 1, '  y deja el cambio en activity_log con antes y después');
    T::eq(ProspectImport::completeUntil(), null, '  cambiar un filtro reinicia la ventana (la próxima corrida revisa todo)');
    Settings::set(ProspectImport::COMPLETE_KEY, '2026-11-05');
    $s->post('/config/prospects/window', ['_csrf' => $tok2]);
    Settings::clear();
    T::eq(ProspectImport::completeUntil(), null, 'config Prospectos: «Revisar toda la ventana otra vez» reinicia la marca');
    Settings::set('prospects.config', ProspectConfig::DEFAULTS);
    T::ok(str_contains($s->get('/config?tab=integraciones')['body'], 'Datos públicos de FMCSA (Socrata)') && str_contains($s->get('/config?tab=integraciones')['body'], 'Prospectos FMCSA (cron)'), 'integraciones: tarjetas de Socrata y del cron de prospectos');

    // ------------------------------------------------------------------ Autorizacion por rol en cada ruta nueva
    $v = $s->another();
    T::ok($v->login($viewer['email']), 'login del lector (viewer)');
    $leak = ['FIXTURE CARRIER', '9100004', '+1713555', '(713) 555', 'fixture0', 'Reglas de las llamadas', 'Nuevo código'];
    $routes = ['/prospectos', '/prospectos?tab=dnc', '/prospectos?q=9100004', '/referidos', '/referidos?f=pending', '/vacantes', '/vacantes/new', '/vacantes/1', '/config?tab=prospectos'];
    foreach ($routes as $route) {
        $r = $v->get($route);
        $clean = true;
        foreach ($leak as $needle) {
            $clean = $clean && !str_contains($r['body'], $needle);
        }
        T::ok($r['status'] === 403 && $clean, "lector: GET {$route} → 403 sin datos");
    }
    $posts = ["/prospectos/$pid/result" => ['result' => 'dnc'], "/prospectos/$pid/convert" => [], "/prospectos/$pid/reopen" => [], '/prospectos/export' => ['qs' => ''], '/referidos/new' => ['owner_name' => 'X Y'],
        '/referidos/confirm' => ['confirmed' => '1'], '/referidos/1/code' => ['op' => 'disable'], '/referidos/1/bonus' => ['op' => 'approve'], '/vacantes/new' => ['title' => 'Intruso total'], '/vacantes/1/save' => [], '/vacantes/1/status' => ['op' => 'publish'],
        '/config/prospects/save' => ['max_age_months' => '1'], '/config/prospects/window' => []];
    $snapshot = static fn(): string => md5(json_encode([Db::all('SELECT id, status, attempts, do_not_call FROM prospects_fmcsa ORDER BY id'), Db::value('SELECT COUNT(*) FROM referral_codes'), Db::value('SELECT COUNT(*) FROM job_postings'), Db::value('SELECT COUNT(*) FROM leads'), Settings::get('prospects.config'), Settings::get('referrals.bonus_confirmed')]));
    $snap = $snapshot();
    foreach ($posts as $route => $fields) {
        $r = $v->post($route, $fields + ['_csrf' => $v->csrf]);
        T::ok($r['status'] === 403 && !str_contains($r['body'], 'FIXTURE'), "lector: POST {$route} → 403");
    }
    T::ok($snapshot() === $snap, 'lector: ninguna de esas peticiones cambió nada en la base');
    $anon = Panel::start($ctx, 'panel-pro-anon');
    foreach (array_merge($routes, array_keys($posts)) as $route) {
        $r = in_array($route, array_keys($posts), true) ? $anon->post($route, []) : $anon->get($route);
        T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/login', "sin sesión: {$route} → /login");
    }
    Infra::stop('panel-pro-anon');
    $r = $s->get('/prospectos/abc');
    T::eq($r['status'], 404, 'ruta inexistente bajo /prospectos → 404');
    T::eq($s->get('/prospectos/999999/result')['status'], 303, 'GET a una acción POST de prospectos no hace nada (redirige)');

    // ------------------------------------------------------------------ Cron fmcsa_daily.php por CLI contra Socrata simulado
    Infra::stop('panel-pro');
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['prospect_calls', 'prospects_fmcsa', 'dnc_numbers', 'lead_events', 'leads', 'jobs', 'consents'] as $t) {
        Db::pdo()->exec("TRUNCATE TABLE $t");
    }
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    Db::pdo()->exec("DELETE FROM settings WHERE `key` LIKE 'prospects.%' OR `key` LIKE 'cron.%'");
    Settings::clear();
    $mockLog = "$work/socrata-mock.log";
    @unlink($mockLog);
    $port = Infra::phpServer('socrata-mock', $ctx['root'] . '/tests/lib', ['SOCRATA_MOCK_LOG' => $mockLog], $ctx['root'] . '/tests/lib/socrata_mock.php');
    $envFile = "$work/cron-prospects.env";
    $vars = $ctx['env'] + ['SOCRATA_API_BASE' => "http://127.0.0.1:$port", 'SOCRATA_APP_TOKEN' => 'app-token-xyz'];
    file_put_contents($envFile, implode("\n", array_map(static fn($k, $x) => "$k=$x", array_keys($vars), $vars)) . "\n");
    $cron = static fn(array $args = [], array $env = []) => Infra::cli(array_merge([$ctx['root'] . '/cron/fmcsa_daily.php'], $args), ['CAPTACION_ENV_FILE' => $envFile, 'NO_PROXY' => '127.0.0.1'] + $env);
    $r = $cron(['--now=2026-11-05T05:30']);
    $j = json_decode(trim($r['out']), true);
    T::ok($r['code'] === 0 && ($j['status'] ?? '') === 'waiting' && (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa') === 0, 'cron: a las 5:30 a. m. ET espera y no consulta nada');
    T::eq(Infra::readLog($mockLog), [], '  (el servidor simulado no recibió peticiones)');
    $r = $cron(['--now=2026-11-05T06:10']);
    $j = json_decode(trim($r['out']), true);
    T::ok($r['code'] === 0 && ($j['status'] ?? '') === 'ran' && ($j['finished'] ?? false) === true && ($j['counters']['new'] ?? 0) === 10, 'cron: a las 6:10 a. m. ET corre y deja 10 prospectos (salida ' . trim($r['out']) . ')');
    $reqs = Infra::readLog($mockLog);
    T::ok(count($reqs) === 2 && $reqs[0]['token'] === 'app-token-xyz' && str_contains($reqs[0]['uri'], '/resource/yu5v-wbh6.json') && str_contains($reqs[1]['uri'], '/resource/az4n-8mr2.json'), '  dos peticiones, con la cabecera X-App-Token y los datasets correctos');
    T::ok(!str_contains(implode("\n", array_column($reqs, 'uri')), 'app-token-xyz'), '  el token no viaja en la URL');
    $cm = Settings::get('cron.prospects');
    T::ok(is_array($cm) && $cm['status'] === 'ran' && $cm['finished'] === true, '  deja la marca cron.prospects para la tarjeta de estado y la campana');
    $r = $cron(['--now=2026-11-05T07:10']);
    T::ok(($j2 = json_decode(trim($r['out']), true)) && $j2['status'] === 'done' && count(Infra::readLog($mockLog)) === 2, 'cron: a las 7:10 del mismo día no vuelve a consultar (idempotente)');
    $r = $cron(['--now=2026-11-06T09:00']);
    $j3 = json_decode(trim($r['out']), true);
    T::ok(($j3['status'] ?? '') === 'ran' && ($j3['counters']['new'] ?? -1) === 0 && ($j3['counters']['known'] ?? 0) === 10 && (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa') === 10, 'cron: al día siguiente (recuperando tarde, a las 9:00) reconoce los 10 y no duplica nada');
    // flock: otro proceso con el candado tomado
    $lockFile = $work . '/storage/locks/fmcsa_daily.lock';
    @mkdir(dirname($lockFile), 0777, true);
    $h = fopen($lockFile, 'c');
    flock($h, LOCK_EX);
    $r = $cron(['--now=2026-11-07T09:00']);
    T::ok(str_contains($r['out'], 'locked') && $r['code'] === 0, 'cron: con el candado tomado sale con «locked» sin hacer nada');
    flock($h, LOCK_UN);
    fclose($h);
    // Socrata caido: exit 1, error visible y reanuda despues
    Db::pdo()->exec("DELETE FROM settings WHERE `key` LIKE 'prospects.%'");
    Settings::clear();
    $t0 = microtime(true);
    // El caso de error usa un puerto cerrado (conexion rechazada)
    Db::pdo()->exec("DELETE FROM settings WHERE `key` LIKE 'prospects.%'");
    Settings::clear();
    $deadEnv = "$work/cron-prospects-dead.env";
    file_put_contents($deadEnv, implode("\n", array_map(static fn($k, $x) => "$k=$x", array_keys($vars), array_merge($vars, ['SOCRATA_API_BASE' => 'http://127.0.0.1:1']))) . "\n");
    $r = Infra::cli([$ctx['root'] . '/cron/fmcsa_daily.php', '--now=2026-11-11T09:00'], ['CAPTACION_ENV_FILE' => $deadEnv, 'NO_PROXY' => '127.0.0.1']);
    $je = json_decode(trim($r['out']), true);
    T::ok($r['code'] === 1 && ($je['status'] ?? '') === 'error' && str_contains((string) ($je['error'] ?? ''), 'intentos') && ($je['finished'] ?? true) === false, 'cron: con Socrata caído sale con código 1, dice el error y deja la corrida a medias (' . round(microtime(true) - $t0, 1) . ' s)');
    T::eq(Settings::get('integration.socrata')['last'] ?? '', 'err', '  y lo registra en la tarjeta de integraciones');
    $r = $cron(['--now=2026-11-11T10:00']);
    $jr = json_decode(trim($r['out']), true);
    T::ok($r['code'] === 0 && ($jr['status'] ?? '') === 'ran' && ($jr['finished'] ?? false) === true && array_key_exists('error', $jr) && $jr['error'] === null, 'cron: a la hora siguiente reanuda y termina');
    // Fuera de CLI el script no se ejecuta
    $http = Infra::phpServer('cron-web', $ctx['root'] . '/cron', [], null, 19100);
    T::eq(Infra::http('GET', "http://127.0.0.1:$http/fmcsa_daily.php")['status'], 404, 'cron: por la web responde 404');
    Infra::stop('cron-web');
    Infra::stop('socrata-mock');
    T::reset(true);
};
