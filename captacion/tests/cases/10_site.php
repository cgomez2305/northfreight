<?php
declare(strict_types=1);

use Captacion\Db;
use CaptacionTests\Infra;
use CaptacionTests\T;

return static function (array $ctx): void {
    $work = $ctx['work'];
    $repo = dirname($ctx['root']);
    $count = static fn(string $t, string $where = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $where");
    T::reset();

    /** Copia del sitio (codigo real) con su propio storage/ y config.local.php; assets, vendor y captacion enlazados. */
    $mkSite = static function (string $name, string $captacionEnabled, string $dbPort) use ($work, $repo, $ctx): array {
        $dir = "$work/site-$name";
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
        file_put_contents("$dir/app/config.local.php", "<?php\ndefine('ENV', 'staging');\ndefine('PREVIEW_TOKEN', 'tok');\ndefine('CAPTACION_ENABLED', $captacionEnabled);\n");
        $vars = array_merge($ctx['env'], ['DB_PORT' => $dbPort, 'STORAGE_DIR' => "$work/crm-storage-$name", 'APP_URL' => 'https://captacion.example']);
        file_put_contents("$work/site-$name.env", implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($vars), $vars)) . "\n");
        $port = Infra::phpServer("site-$name", $dir, ['CAPTACION_ENV_FILE' => "$work/site-$name.env"], "$dir/index.php");
        return ['dir' => $dir, 'port' => $port, 'base' => "http://127.0.0.1:$port"];
    };
    $sites = [
        'on'   => $mkSite('on', '1', (string) $ctx['dbPort']),
        'down' => $mkSite('down', '1', '1'),     // CRM habilitado pero la base no responde
        'off'  => $mkSite('off', '0', (string) $ctx['dbPort']),
    ];

    $tokens = static function (array $site): array {
        $r = Infra::http('GET', $site['base'] . '/careers/apply/');
        preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $a);
        preg_match('/name="form_ts" value="(\d+)"/', $r['body'], $b);
        return [$a[1] ?? '', $b[1] ?? ''];
    };
    $tk = [];
    foreach ($sites as $k => $s) {
        $tk[$k] = $tokens($s);
    }
    sleep(4);   // el sitio rechaza envios de menos de 3 s ("That was quick")
    $form = static fn(string $k, array $over = []) => $over + [
        'csrf' => $tk[$k][0], 'form_ts' => $tk[$k][1], 'website' => '', 'profile' => 'owner-operator', 'trucks' => '2', 'equipment' => ['dry-van', 'amazon-relay'], 'years' => '4',
        'authority' => 'active', 'cdl_standard' => 'yes', 'english' => 'yes', 'name' => 'Maria Perez', 'phone' => '8635550101', 'email' => 'maria@example.com', 'state' => 'FL',
        'language' => 'es', 'contact_pref' => 'whatsapp', 'best_time' => 'morning', 'consent' => '1', 'attr' => json_encode(['utm_source' => 'google', 'utm_campaign' => 'q1', 'landing' => '/careers/owner-operators/', 'referrer' => 'https://google.com/', 'first_seen' => '2026-11-01']),
    ];
    $post = static fn(string $k, array $f, array $extra = []) => Infra::http('POST', $sites[$k]['base'] . '/careers/apply/', ['form' => $f, 'headers' => array_merge(['Origin: ' . $sites[$k]['base']], $extra)]);
    $jsonl = static function (string $k): array {
        $out = [];
        foreach (glob($GLOBALS['CTX']['work'] . "/site-$k/storage/leads/*.jsonl") ?: [] as $f) {
            foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                $out[] = json_decode($l, true);
            }
        }
        return $out;
    };
    $thanksOf = static function (string $k, array $r) use ($sites): string {
        $loc = Infra::header($r, 'location');
        return Infra::http('GET', $sites[$k]['base'] . $loc)['body'];
    };

    // ------------------------------------------------------------------ CRM habilitado
    $ua = 'Mozilla/5.0 (SiteTest)';
    $r = $post('on', $form('on'), ['User-Agent: ' . $ua]);
    T::ok($r['status'] === 303 && str_contains(Infra::header($r, 'location'), '/careers/thank-you/?t='), 'sitio + CRM: envio valido -> 303 a /careers/thank-you/?t=…');
    $lines = $jsonl('on');
    T::ok(count($lines) === 1 && $lines[0]['crm_ingested'] === true && !isset($lines[0]['crm_reason']) && $lines[0]['qualified'] === true, 'JSONL de respaldo escrito igualmente, con crm_ingested=true');
    $lead = Db::one('SELECT * FROM leads');
    T::ok($lead !== null && $lead['site_lead_id'] === $lines[0]['id'] && $lead['source'] === 'web_careers' && $lead['phone_e164'] === '+18635550101' && $lead['profile'] === 'owner_operator', 'fila en leads con el id del lead del sitio, fuente web_careers y telefono E.164');
    T::eq([(int) $lead['score'], $lead['temperature'], $lead['pipeline'], $lead['stage']], [65, 'warm', 'A', 'new'], 'puntuacion 65, tibio, pipeline A, etapa new');
    T::ok(json_decode((string) $lead['utm'], true)['utm_source'] === 'google' && $lead['landing_page'] === '/careers/owner-operators/' && $lead['referrer'] === 'https://google.com/', 'atribucion (utm, landing, referrer) llega al CRM');
    $cs = Db::all('SELECT * FROM consents WHERE lead_id = ? ORDER BY channel', [$lead['id']]);
    preg_match("/ns_default\('CONSENT_TEXT_V1', '((?:[^'\\\\]|\\\\.)*)'\);/", (string) file_get_contents($repo . '/app/config.php'), $mt);
    $siteText = stripslashes($mt[1] ?? '');
    T::eq(array_column($cs, 'channel'), ['call', 'email', 'sms'], 'consents: una fila por canal');
    T::ok($cs[0]['consent_text'] === $siteText && $siteText !== '' && $cs[0]['ip'] === '127.0.0.1' && $cs[0]['user_agent'] === $ua && $cs[0]['consent_version'] === 'v1', 'consents: CONSENT_TEXT_V1 EXACTO del sitio, IP, user agent y version');
    T::eq(Db::all('SELECT type FROM lead_events WHERE lead_id = ? ORDER BY id', [$lead['id']]) === [['type' => 'created'], ['type' => 'scored']], true, 'lead_events: created, scored');
    $jobs = Db::all('SELECT type, status FROM jobs');
    T::eq($jobs, [['type' => 'email_notify', 'status' => 'pending']], 'jobs: email_notify encolado (lead tibio)');
    $thanksOn = $thanksOf('on', $r);
    T::ok(str_contains($thanksOn, 'Application received') && str_contains($thanksOn, 'data-lead-type="owner-operator"'), 'pagina de gracias normal');

    // Mismo telefono en otro formato -> reingreso (sin lead nuevo)
    $r2 = $post('on', $form('on', ['phone' => '(863) 555-0101', 'name' => 'Maria Perez']));
    T::ok($r2['status'] === 303 && $count('leads') === 1 && $count('lead_events', "type = 'reentry'") === 1 && count($jsonl('on')) === 2, 'segundo envio del mismo telefono: 303 igual, reingreso en el CRM, 2 lineas en el JSONL');
    // Descartado por elegibilidad: el visitante ve lo mismo
    $r3 = $post('on', $form('on', ['name' => 'Dan Lee', 'phone' => '3052345678', 'email' => 'dan@example.com', 'cdl_standard' => 'no', 'english' => 'no', 'years' => '0']));
    $l3 = Db::one("SELECT * FROM leads WHERE phone_e164 = '+13052345678'");
    T::ok($r3['status'] === 303 && $l3['temperature'] === 'disqualified' && $l3['disqualified_reason'] === 'cdl_non_domiciled,english_no', 'CDL "No" + ingles "No": mismo 303 neutro, en el CRM queda disqualified con motivo');
    T::eq(Infra::header($r3, 'location') !== '' && str_contains($thanksOf('on', $r3), 'Application received'), true, '  y la pagina de gracias es la misma (sin mostrar el motivo)');
    $l3events = array_column(Db::all('SELECT type FROM lead_events WHERE lead_id = ?', [$l3['id']]), 'type');
    T::ok(in_array('disqualified', $l3events, true) && $count('jobs', 'type = "email_notify"') === 1 + 1 /* reingreso */, '  evento disqualified y sin acciones (solo el aviso previo y el del reingreso)');
    // "No estoy seguro": unsure
    $r4 = $post('on', $form('on', ['name' => 'Eva Mar', 'phone' => '3052345679', 'email' => 'eva@example.com', 'cdl_standard' => 'unsure']));
    $l4 = Db::one("SELECT * FROM leads WHERE phone_e164 = '+13052345679'");
    T::ok($r4['status'] === 303 && $l4['cdl_type'] === 'unsure' && in_array('cdl_unsure', json_decode((string) $l4['flags'], true), true) && $l4['temperature'] !== 'disqualified', 'CDL "No estoy seguro" -> unsure + bandera cdl_unsure, sin descartar');
    // Invalido -> 422 como siempre, sin tocar nada
    $before = [$count('leads'), count($jsonl('on'))];
    $r5 = $post('on', $form('on', ['name' => '', 'phone' => '123', 'email' => 'nope', 'years' => '']));
    T::ok($r5['status'] === 422 && str_contains($r5['body'], 'id="ap-err-name"') && str_contains($r5['body'], 'id="ap-err-phone"') && [$count('leads'), count($jsonl('on'))] === $before, 'envio invalido: 422 con errores por campo, ni JSONL ni CRM');
    // Honeypot -> 303 falso sin guardar
    $r6 = $post('on', $form('on', ['website' => 'http://spam.example', 'name' => 'Bot', 'phone' => '3052345680']));
    T::ok($r6['status'] === 303 && [$count('leads'), count($jsonl('on'))] === $before, 'honeypot: mismo 303 y no se guarda nada (ni JSONL ni CRM)');
    // CSRF
    T::eq($post('on', $form('on', ['csrf' => 'mal']))['status'], 403, 'CSRF invalido: 403 como antes');
    T::ok(!is_file($sites['on']['dir'] . '/storage/error.log') || trim((string) file_get_contents($sites['on']['dir'] . '/storage/error.log')) === '', 'storage/error.log del sitio vacio');

    // ------------------------------------------------------------------ CRM habilitado pero la base cae: el lead NO se pierde
    $n0 = $count('leads');
    $rd = $post('down', $form('down', ['name' => 'Fran Sol', 'phone' => '8635550144', 'email' => 'fran@example.com']));
    T::ok($rd['status'] === 303 && str_contains(Infra::header($rd, 'location'), '/careers/thank-you/?t='), 'base del CRM caida: el visitante recibe el mismo 303');
    $ld = $jsonl('down');
    T::ok(count($ld) === 1 && $ld[0]['name'] === 'Fran Sol' && $ld[0]['crm_ingested'] === false && ($ld[0]['crm_reason'] ?? '') === 'error', 'base caida: el lead se guarda en el JSONL con crm_ingested=false y crm_reason=error');
    T::eq($count('leads'), $n0, '  nada en la base (estaba caida)');
    $errlog = (string) @file_get_contents($sites['down']['dir'] . '/storage/error.log');
    T::ok(str_contains($errlog, 'lead-crm') && !str_contains($errlog, 'Fran') && !str_contains($errlog, 'fran@example.com') && !str_contains($errlog, '8635550144') && !str_contains($errlog, 'captest-pass'), '  el error se registra sin datos del lead ni credenciales');
    T::eq(Infra::http('GET', $sites['down']['base'] . Infra::header($rd, 'location'))['status'], 200, '  y la pagina de gracias funciona');
    $mailLog = (string) @file_get_contents($sites['down']['dir'] . '/storage/mail.log');
    T::ok(str_contains($mailLog, 'New driver lead: Fran Sol'), '  el aviso por correo del sitio sale igual');

    // Reimportar ese JSONL con la base viva: idempotente
    $envMain = "$work/import.env";
    file_put_contents($envMain, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($ctx['env']), $ctx['env'])) . "\n");
    $leadsDir = $sites['down']['dir'] . '/storage/leads';
    $jobsBefore = $count('jobs');
    $imp = Infra::cli([$ctx['root'] . '/bin/import-jsonl.php', "--dir=$leadsDir"], ['CAPTACION_ENV_FILE' => $envMain]);
    T::ok($imp['code'] === 0 && str_contains($imp['out'], '"created":1'), 'import-jsonl: importa el lead pendiente (' . trim($imp['out']) . ')');
    $fl = Db::one("SELECT * FROM leads WHERE phone_e164 = '+18635550144'");
    T::ok($fl !== null && $fl['site_lead_id'] === $ld[0]['id'] && $fl['created_at'] === gmdate('Y-m-d H:i:s', strtotime($ld[0]['created_at'])), '  con el id y la fecha originales del lead');
    T::eq($count('jobs'), $jobsBefore, '  la importacion es silenciosa: no encola alertas ni avisos (un lote viejo no dispara Telegram)');
    $imp2 = Infra::cli([$ctx['root'] . '/bin/import-jsonl.php', "--dir=$leadsDir"], ['CAPTACION_ENV_FILE' => $envMain]);
    T::ok($imp2['code'] === 0 && str_contains($imp2['out'], '"duplicate_id":1') && str_contains($imp2['out'], '"created":0') && $count('leads', "phone_e164 = '+18635550144'") === 1, 'import-jsonl repetido: idempotente (duplicate_id, sin duplicados)');
    $imp3 = Infra::cli([$ctx['root'] . '/bin/import-jsonl.php', "--dir=$leadsDir", '--dry-run'], ['CAPTACION_ENV_FILE' => $envMain]);
    T::ok($imp3['code'] === 0 && str_contains($imp3['out'], '[dry-run]'), 'import-jsonl --dry-run no escribe');
    // Importar tambien los de la instancia "on" que ya estaban: todo duplicate_id
    $imp4 = Infra::cli([$ctx['root'] . '/bin/import-jsonl.php', '--dir=' . $sites['on']['dir'] . '/storage/leads', '--only-pending'], ['CAPTACION_ENV_FILE' => $envMain]);
    T::ok($imp4['code'] === 0 && str_contains($imp4['out'], '"created":0') && str_contains($imp4['out'], '"skipped":'), 'import-jsonl --only-pending salta los que ya entraron (crm_ingested=true)');
    $bad = "$work/badleads";
    @mkdir($bad, 0777, true);
    file_put_contents("$bad/2026-11.jsonl", "{no json\n" . json_encode($ld[0]) . "\n\n");
    $imp5 = Infra::cli([$ctx['root'] . '/bin/import-jsonl.php', "--dir=$bad"], ['CAPTACION_ENV_FILE' => $envMain]);
    T::ok(str_contains($imp5['out'], 'ilegible') && str_contains($imp5['out'], '"duplicate_id":1'), 'import-jsonl: una linea ilegible se salta y avisa, el resto sigue');

    // ------------------------------------------------------------------ CRM apagado: comportamiento de la fase P
    $nOff = $count('leads');
    $ro = $post('off', $form('off', ['name' => 'Gus Rey', 'phone' => '8635550155', 'email' => 'gus@example.com']));
    $lo = $jsonl('off');
    T::ok($ro['status'] === 303 && count($lo) === 1 && $lo[0]['crm_ingested'] === false && !isset($lo[0]['crm_reason']) && $count('leads') === $nOff, 'CAPTACION_ENABLED=0: JSONL con crm_ingested=false (sin motivo) y la base no se toca');
    T::ok(!is_file($sites['off']['dir'] . '/storage/error.log') || trim((string) file_get_contents($sites['off']['dir'] . '/storage/error.log')) === '', '  sin errores en el log');

    // Lo que ve el visitante es identico con o sin CRM (paginas de gracias y errores)
    $strip = static fn(string $h) => preg_replace(['/\?v=\d+/', '/name="csrf" value="[^"]*"/', '/name="form_ts" value="\d+"/'], '', $h);
    T::eq($strip($thanksOf('on', $r)) === $strip($thanksOf('off', $ro)) && $strip($thanksOf('down', $rd)) === $strip($thanksOf('off', $ro)), true, 'pagina de gracias identica con CRM, con CRM caido y sin CRM');
    $e1 = $post('off', $form('off', ['name' => '', 'phone' => '123']));
    $e2 = $post('down', $form('down', ['name' => '', 'phone' => '123']));
    T::eq($e1['status'] === $e2['status'] && $strip($e1['body']) === $strip($e2['body']), true, 'pagina de errores de validacion identica con CRM caido y sin CRM');

    // El codigo del CRM no es accesible por el dominio principal (index.php del sitio)
    foreach (['/captacion/', '/captacion/config/.env', '/captacion/public/setup.php', '/captacion/src/Env.php', '/Captacion/cron/process_jobs.php', '/cron/process_jobs.php', '/captacion/storage/error.log', '/captacion/db/schema.sql'] as $p) {
        $rr = Infra::http('GET', $sites['on']['base'] . $p);
        T::ok($rr['status'] === 404 && !str_contains($rr['body'], '<?php') && !str_contains($rr['body'], 'captest'), "sitio principal: GET $p -> 404 (no expone el CRM)");
    }
    foreach (array_keys($sites) as $k) {
        Infra::stop("site-$k");
    }
};
