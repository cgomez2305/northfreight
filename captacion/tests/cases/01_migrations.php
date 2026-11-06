<?php
declare(strict_types=1);

use Captacion\Db;
use Captacion\Migrator;
use Captacion\Services\Scoring;
use CaptacionTests\Infra;
use CaptacionTests\T;

return static function (array $ctx): void {
    $root = $ctx['root'];
    $dir = $root . '/db/migrations';
    $rootPdo = new PDO('mysql:host=127.0.0.1;port=' . $ctx['dbPort'], 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rootPdo->exec('DROP DATABASE IF EXISTS cap_mig');
    $rootPdo->exec('CREATE DATABASE cap_mig CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO('mysql:host=127.0.0.1;port=' . $ctx['dbPort'] . ';dbname=cap_mig;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    $files = array_map(static fn($f) => basename($f, '.sql'), glob($dir . '/[0-9][0-9][0-9]_*.sql') ?: []);
    sort($files);
    $applied = Migrator::run($pdo, $dir);
    T::eq($applied, $files, 'migraciones desde cero: se aplican todas, en orden');
    T::eq(Migrator::run($pdo, $dir), [], 'migraciones repetidas: no aplica nada (idempotente)');
    T::eq((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(), count($files), 'schema_migrations tiene una fila por migracion');
    T::eq($ctx['migrationsApplied'], $files, 'la base de trabajo tambien se migro desde cero');

    // Q3: la migracion se aplica SOBRE la base de Q2b (001 a 005) con datos reales, sin perderlos
    $rootPdo->exec('DROP DATABASE IF EXISTS cap_mig_q2b');
    $rootPdo->exec('CREATE DATABASE cap_mig_q2b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $q2b = new PDO('mysql:host=127.0.0.1;port=' . $ctx['dbPort'] . ';dbname=cap_mig_q2b;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $tmp = $ctx['work'] . '/mig-q2b';
    @mkdir($tmp, 0777, true);
    foreach (glob($tmp . '/*.sql') ?: [] as $f) {
        unlink($f);
    }
    foreach (array_filter($files, static fn($f) => (int) substr($f, 0, 3) <= 5) as $f) {
        copy("$dir/$f.sql", "$tmp/$f.sql");
    }
    $first = Migrator::run($q2b, $tmp);
    T::eq($first, ['001_core', '002_auth', '003_settings_seed', '004_panel', '005_pipeline_config'], 'base de Q2b: se aplican 001 a 005');
    $q2b->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Carlos', 'c@example.com', 'x', 'admin')");
    $q2b->exec("INSERT INTO leads (profile, first_name, last_name, source, stage, temperature) VALUES ('owner_operator', 'Real', 'Lead', 'web_careers', 'presented', 'hot')");
    $q2b->exec("INSERT INTO sequence_enrollments (lead_id, sequence_key, step, status) VALUES (1, 'a6_warm', 0, 'active')");
    $q2b->exec("INSERT INTO messages (lead_id, channel, direction, body) VALUES (1, 'email', 'out', 'hola')");
    $q2b->exec("INSERT INTO settings (`key`, value) VALUES ('alerts', '{\"email\":true}')");
    // Q4a: filas previas de prospectos, referidos y vacantes (existian vacias en el esquema original) sobreviven a la 009
    $q2b->exec("INSERT INTO prospects_fmcsa (usdot, legal_name, state, status, attempts) VALUES ('1234567', 'Viejo Prospecto', 'TX', 'to_call', 1)");
    $q2b->exec("INSERT INTO referral_codes (owner_name, code) VALUES ('Ana', 'ANA-1')");
    $q2b->exec("INSERT INTO referrals (referral_code_id, lead_id) VALUES (1, 1)");
    $q2b->exec("INSERT INTO job_postings (title, slug, valid_through, is_active) VALUES ('Driver viejo', 'driver-viejo', '2027-01-01', 1)");
    $newOnes = Migrator::run($q2b, $dir);
    T::eq($newOnes, ['006_handoff', '007_viewer_reports', '008_comms', '009_prospects_referrals_jobs', '010_channels', '011_ux_prefs_views', '012_rules', '013_lead_modes'], 'sobre la base de Q2b se aplican solo 006 a 013');
    T::eq([$q2b->query('SELECT status FROM referrals WHERE id = 1')->fetchColumn(), $q2b->query("SELECT status FROM job_postings WHERE slug = 'driver-viejo'")->fetchColumn(), (int) $q2b->query('SELECT attempts FROM prospects_fmcsa')->fetchColumn(), $q2b->query('SELECT source FROM prospects_fmcsa')->fetchColumn()],
        ['referred', 'active', 1, 'socrata'], '  009 conserva las filas previas (el referido pasa de «new» a «referred», la vacante activa queda «active»)');
    T::eq([(int) $q2b->query('SELECT COUNT(*) FROM leads')->fetchColumn(), (int) $q2b->query('SELECT COUNT(*) FROM sequence_enrollments')->fetchColumn(), (int) $q2b->query('SELECT COUNT(*) FROM messages')->fetchColumn(), (int) $q2b->query('SELECT COUNT(*) FROM users')->fetchColumn()], [1, 1, 1, 1], '  y los datos existentes siguen ahi');
    T::eq(Migrator::run($q2b, $dir), [], '  repetirlas no hace nada');
    $qc = static fn(string $t) => $q2b->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'cap_mig_q2b' AND TABLE_NAME = '$t'")->fetchAll(PDO::FETCH_COLUMN);
    T::ok(in_array('invited_by', $qc('users'), true) && in_array('deactivated_at', $qc('users'), true) && in_array('subject', $qc('messages'), true) && in_array('sequence_key', $qc('messages'), true) && in_array('blocks', $qc('sequence_enrollments'), true) && in_array('blocked_reason', $qc('sequence_enrollments'), true), '  columnas nuevas en users, messages y sequence_enrollments');
    $q2bTables = $q2b->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    T::ok(!array_diff(['handoffs', 'handoff_deliveries', 'user_invites', 'reports', 'email_templates'], $q2bTables), '  tablas nuevas: handoffs, handoff_deliveries, user_invites, reports, email_templates');
    T::eq($q2b->query("SELECT status FROM sequence_enrollments WHERE id = 1")->fetchColumn(), 'active', '  la inscripcion previa conserva su estado');
    $q2b->exec("INSERT INTO sequence_enrollments (lead_id, sequence_key, step, status) VALUES (1, 'a12_referral', 0, 'active')");
    T::throws(static fn() => $q2b->exec("INSERT INTO sequence_enrollments (lead_id, sequence_key, step, status) VALUES (1, 'a6_warm', 0, 'active')"), 'una inscripcion por lead y secuencia (clave unica)', 'Duplicate');
    // Q4b: la 010 tambien se aplica sobre una cita, un lead y una inscripcion previos sin perderlos
    T::eq([(int) $q2b->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn(), $q2b->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'cap_mig_q2b' AND TABLE_NAME = 'leads' AND COLUMN_NAME IN ('last_inbound_at','wa_last_inbound_at') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN)],
        [0, ['last_inbound_at', 'wa_last_inbound_at']], '  010: tabla webhook_events vacia y columnas de la ventana de WhatsApp en leads');
    T::eq((int) $q2b->query("SELECT wa_step FROM sequence_enrollments WHERE id = 1")->fetchColumn(), 0, '  010: la inscripcion previa queda con wa_step 0');
    $q2b->exec("INSERT INTO appointments (lead_id, starts_at, calcom_id, status) VALUES (1, '2030-01-01 10:00:00', 'uid-unico-1', 'booked')");
    T::throws(static fn() => $q2b->exec("INSERT INTO appointments (lead_id, starts_at, calcom_id, status) VALUES (1, '2030-01-02 10:00:00', 'uid-unico-1', 'booked')"), '  010: el uid de Cal.com es unico (una reserva = una fila)', 'Duplicate');
    $q2b->exec("INSERT INTO webhook_events (source, event_key) VALUES ('meta', 'k1')");
    T::throws(static fn() => $q2b->exec("INSERT INTO webhook_events (source, event_key) VALUES ('meta', 'k1')"), '  010: el mismo evento dos veces no entra (UNIQUE source + event_key)', 'Duplicate');
    $rootPdo->exec('DROP DATABASE cap_mig_q2b');

    // Q5b: la 012 se aplica SOBRE la base de Q5a (001 a 011) con datos reales, sin tocarlos, y es idempotente
    $rootPdo->exec('DROP DATABASE IF EXISTS cap_mig_q5a');
    $rootPdo->exec('CREATE DATABASE cap_mig_q5a CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $q5a = new PDO('mysql:host=127.0.0.1;port=' . $ctx['dbPort'] . ';dbname=cap_mig_q5a;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $tmp5 = $ctx['work'] . '/mig-q5a';
    @mkdir($tmp5, 0777, true);
    foreach (glob($tmp5 . '/*.sql') ?: [] as $f) {
        unlink($f);
    }
    foreach (array_filter($files, static fn($f) => (int) substr($f, 0, 3) <= 11) as $f) {
        copy("$dir/$f.sql", "$tmp5/$f.sql");
    }
    T::eq(count(Migrator::run($q5a, $tmp5)), 11, 'base de Q5a: se aplican 001 a 011');
    $q5a->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Carlos', 'c@example.com', 'x', 'admin')");
    $q5a->exec("INSERT INTO leads (profile, first_name, last_name, source, stage, temperature, score) VALUES ('owner_operator', 'Real', 'Lead', 'web_careers', 'new', 'warm', 55)");
    $q5a->exec("INSERT INTO saved_views (user_id, name, filters) VALUES (1, 'Mi vista', '{\"t\":\"hot\"}')");
    $q5a->exec("INSERT INTO user_prefs (user_id, pkey, pvalue) VALUES (1, 'theme', 'dark')");
    T::eq(Migrator::run($q5a, $dir), ['012_rules', '013_lead_modes'], '  sobre ella se aplican solo 012_rules y 013_lead_modes');
    T::eq([(int) $q5a->query('SELECT COUNT(*) FROM leads')->fetchColumn(), (int) $q5a->query('SELECT COUNT(*) FROM saved_views')->fetchColumn(), (int) $q5a->query('SELECT COUNT(*) FROM user_prefs')->fetchColumn(), $q5a->query('SELECT score FROM leads')->fetchColumn()], [1, 1, 1, 55], '  los datos de Q5a siguen ahi, sin cambios');
    T::eq(Migrator::run($q5a, $dir), [], '  repetirla no hace nada');
    $q5aTables = $q5a->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    T::ok(!array_diff(['rule_sets', 'rule_versions', 'rule_applications', 'rule_simulations', 'rule_simulation_rows'], $q5aTables), '  tablas nuevas: rule_sets, rule_versions, rule_applications, rule_simulations y rule_simulation_rows');
    // Idempotencia a nivel de sentencias: ejecutar el archivo dos veces no falla ni duplica
    $sql012 = (string) file_get_contents("$dir/012_rules.sql");
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', (string) preg_replace('/^\s*--.*$/m', '', $sql012)) ?: [])) as $stmt) {
        $q5a->exec($stmt);
    }
    T::eq(count(array_filter($q5a->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), static fn($t) => str_starts_with($t, 'rule_'))), 5, '  012 ejecutada de nuevo a mano: sigue habiendo 5 tablas (CREATE TABLE IF NOT EXISTS)');
    // Invariantes del esquema de reglas
    $q5a->exec("INSERT INTO rule_sets (kind, name) VALUES ('scoring', 'A')");
    $q5a->exec("INSERT INTO rule_versions (set_id, version, status, rules, pub_slot) VALUES (1, 1, 'published', '[]', 1)");
    T::throws(static fn() => $q5a->exec("INSERT INTO rule_versions (set_id, version, status, rules, pub_slot) VALUES (1, 2, 'published', '[]', 1)"), '  esquema: una sola versión publicada por conjunto (UNIQUE set_id + pub_slot)', 'Duplicate');
    $q5a->exec("INSERT INTO rule_versions (set_id, version, status, rules, draft_slot) VALUES (1, 2, 'draft', '[]', 1)");
    T::throws(static fn() => $q5a->exec("INSERT INTO rule_versions (set_id, version, status, rules, draft_slot) VALUES (1, 3, 'draft', '[]', 1)"), '  esquema: un solo borrador por conjunto', 'Duplicate');
    $q5a->exec("INSERT INTO rule_versions (set_id, version, status, rules) VALUES (1, 3, 'archived', '[]'), (1, 4, 'archived', '[]')");
    T::eq((int) $q5a->query('SELECT COUNT(*) FROM rule_versions WHERE set_id = 1')->fetchColumn(), 4, '  esquema: puede haber muchas archivadas (slot NULL no choca)');
    T::throws(static fn() => $q5a->exec("INSERT INTO rule_versions (set_id, version, status, rules) VALUES (1, 4, 'archived', '[]')"), '  esquema: el número de versión no se repite', 'Duplicate');
    T::throws(static fn() => $q5a->exec("INSERT INTO rule_versions (set_id, version, status, rules) VALUES (1, 9, 'archived', 'no es json')"), '  esquema: las reglas deben ser JSON válido');
    T::throws(static fn() => $q5a->exec("INSERT INTO rule_versions (set_id, version, status, rules) VALUES (999, 9, 'archived', '[]')"), '  esquema: la versión exige un conjunto existente', 'foreign key');
    $q5a->exec("INSERT INTO rule_applications (lead_id, set_id, version_id, rule_id, rule_name, kind, action, dedupe_key) VALUES (1, 1, 1, 'r', 'R', 'alerts', 'telegram_alert', 'k1')");
    T::throws(static fn() => $q5a->exec("INSERT INTO rule_applications (lead_id, set_id, version_id, rule_id, rule_name, kind, action, dedupe_key) VALUES (1, 1, 1, 'r', 'R', 'alerts', 'telegram_alert', 'k1')"), '  esquema: un efecto con la misma clave no se repite (dedupe_key único)', 'Duplicate');
    $q5a->exec("INSERT INTO rule_simulations (set_id, params, rules) VALUES (1, '{}', '[]')");
    $q5a->exec("INSERT INTO rule_simulation_rows (sim_id, lead_id, before_score, before_temp, after_score, after_temp) VALUES (1, 1, 50, 'warm', 60, 'warm')");
    $q5a->exec('DELETE FROM rule_simulations WHERE id = 1');
    T::eq((int) $q5a->query('SELECT COUNT(*) FROM rule_simulation_rows')->fetchColumn(), 0, '  esquema: borrar una simulación borra sus filas (ON DELETE CASCADE)');
    $rootPdo->exec('DROP DATABASE cap_mig_q5a');

    // Q5c: la 013 se aplica SOBRE la base de Q5b (001 a 012) con datos reales, sin tocarlos, y es idempotente
    $rootPdo->exec('DROP DATABASE IF EXISTS cap_mig_q5b');
    $rootPdo->exec('CREATE DATABASE cap_mig_q5b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $q5b = new PDO('mysql:host=127.0.0.1;port=' . $ctx['dbPort'] . ';dbname=cap_mig_q5b;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $tmp5b = $ctx['work'] . '/mig-q5b';
    @mkdir($tmp5b, 0777, true);
    foreach (glob($tmp5b . '/*.sql') ?: [] as $f) {
        unlink($f);
    }
    foreach (array_filter($files, static fn($f) => (int) substr($f, 0, 3) <= 12) as $f) {
        copy("$dir/$f.sql", "$tmp5b/$f.sql");
    }
    T::eq(count(Migrator::run($q5b, $tmp5b)), 12, 'base de Q5b: se aplican 001 a 012');
    $q5b->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Carlos', 'c@example.com', 'x', 'admin')");
    $q5b->exec("INSERT INTO leads (profile, first_name, last_name, source, stage, temperature, score) VALUES ('owner_operator', 'Real', 'Lead', 'web_careers', 'new', 'warm', 55)");
    $q5b->exec("INSERT INTO saved_views (user_id, name, filters, sort, dir) VALUES (1, 'Mi vista', '{\"t\":\"hot\"}', 'score', 'asc')");
    $q5b->exec("INSERT INTO tasks (title, lead_id, due_at) VALUES ('Llamar', 1, '2026-11-10 14:00:00')");
    $q5b->exec("INSERT INTO lead_events (lead_id, type, payload) VALUES (1, 'created', '{}')");
    $q5b->exec("INSERT INTO jobs (type, payload, run_at) VALUES ('handoff_deliver', '{}', '2026-11-10 14:00:00')");
    T::eq(Migrator::run($q5b, $dir), ['013_lead_modes'], 'sobre la base de Q5b se aplica solo 013_lead_modes');
    T::eq([(int) $q5b->query('SELECT COUNT(*) FROM leads')->fetchColumn(), (int) $q5b->query('SELECT COUNT(*) FROM tasks')->fetchColumn(), (int) $q5b->query('SELECT COUNT(*) FROM lead_events')->fetchColumn(), (int) $q5b->query('SELECT COUNT(*) FROM jobs')->fetchColumn(), $q5b->query('SELECT sort FROM saved_views')->fetchColumn(), $q5b->query('SELECT mode FROM saved_views')->fetchColumn()],
        [1, 1, 1, 1, 'score', null], '  los datos previos siguen y la vista guardada antigua queda «sin modo» (se abre en la pantalla actual)');
    T::eq(Migrator::run($q5b, $dir), [], '  repetirla no hace nada');
    $q5bIdx = static fn(string $t): array => $q5b->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'cap_mig_q5b' AND TABLE_NAME = '$t'")->fetchAll(PDO::FETCH_COLUMN);
    T::ok(in_array('idx_events_created', $q5bIdx('lead_events'), true) && in_array('idx_tasks_due_range', $q5bIdx('tasks'), true) && in_array('idx_jobs_type_run', $q5bIdx('jobs'), true), '  índices nuevos: cronología (created_at, id), tareas por vencimiento y jobs por tipo');
    $sql013 = (string) file_get_contents("$dir/013_lead_modes.sql");
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', (string) preg_replace('/^\s*--.*$/m', '', $sql013)) ?: [])) as $stmt) {
        $q5b->exec($stmt);
    }
    T::eq([count(array_keys($q5bIdx('lead_events'), 'idx_events_created')), (int) $q5b->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'cap_mig_q5b' AND TABLE_NAME = 'saved_views' AND COLUMN_NAME = 'mode'")->fetchColumn()], [1, 1], '  013 ejecutada de nuevo a mano: ni índices ni columna duplicados (IF NOT EXISTS)');
    $rootPdo->exec('DROP DATABASE cap_mig_q5b');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['prospect_calls', 'dnc_numbers', 'users', 'leads', 'lead_events', 'consents', 'prospects_fmcsa', 'tasks', 'appointments', 'messages', 'sequence_enrollments', 'referral_codes', 'referrals', 'job_postings', 'channel_costs', 'settings', 'jobs', 'activity_log', 'recovery_codes', 'login_attempts', 'schema_migrations', 'handoffs', 'handoff_deliveries', 'user_invites', 'reports', 'email_templates', 'backups', 'lead_notes'] as $t) {
        T::ok(in_array($t, $tables, true), "tabla $t existe");
    }
    $cols = static fn(string $t) => $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'cap_mig' AND TABLE_NAME = '$t'")->fetchAll(PDO::FETCH_COLUMN);
    $uc = $cols('users');
    T::ok(in_array('totp_secret', $uc, true) && in_array('totp_confirmed_at', $uc, true) && in_array('totp_last_step', $uc, true), 'users: totp_secret, totp_confirmed_at, totp_last_step');
    T::ok(in_array('external_crm_id', $cols('leads'), true) && in_array('score_breakdown', $cols('leads'), true) && in_array('fmcsa_data', $cols('leads'), true), 'leads: external_crm_id, score_breakdown, fmcsa_data');

    // Indices del documento (§13)
    $idx = static function (string $t) use ($pdo): array {
        $out = [];
        foreach ($pdo->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'cap_mig' AND TABLE_NAME = '$t' GROUP BY INDEX_NAME")->fetchAll() as $r) {
            $out[] = $r['c'];
        }
        return $out;
    };
    $li = $idx('leads');
    T::ok(in_array('phone_e164', $li, true) && in_array('email', $li, true) && in_array('stage,temperature', $li, true) && in_array('source,created_at', $li, true), 'indices de leads: phone_e164, email, (stage,temperature), (source,created_at)');
    T::ok(in_array('status,last_call_at', $idx('prospects_fmcsa'), true), 'indice prospects_fmcsa(status,last_call_at)');
    T::ok(in_array('status,run_at', $idx('jobs'), true), 'indice jobs(status,run_at)');

    // JSON: el tipo JSON (nativo en MySQL 8, LONGTEXT+JSON_VALID en MariaDB) rechaza texto que no sea JSON
    $pdo->exec("INSERT INTO leads (profile, first_name, source) VALUES ('owner_operator', 'J', 'test')");
    T::throws(static fn() => $pdo->exec("UPDATE leads SET flags = 'esto no es json'"), 'columna JSON rechaza texto invalido');
    $pdo->exec('UPDATE leads SET flags = \'["a"]\'');
    T::eq($pdo->query('SELECT flags FROM leads')->fetchColumn(), '["a"]', 'columna JSON acepta JSON valido');
    T::throws(static fn() => $pdo->exec("INSERT INTO lead_events (lead_id, type) VALUES (9999, 'x')"), 'clave foranea: lead_events exige un lead existente');

    // Ajustes sembrados == DEFAULTS del codigo
    $seed = [];
    foreach ($pdo->query('SELECT `key`, value FROM settings')->fetchAll() as $r) {
        $seed[$r['key']] = json_decode($r['value'], true);
    }
    foreach (Scoring::DEFAULTS as $k => $v) {
        T::eq($seed[$k] ?? null, $v, "ajuste sembrado $k coincide con Scoring::DEFAULTS");
    }
    T::ok(isset($seed['provisional']), 'ajuste provisional sembrado (lista de ajustes pendientes de confirmar)');

    // Una migracion que falla queda sin registrar y con mensaje claro
    $bad = $ctx['work'] . '/badmig';
    @mkdir($bad, 0777, true);
    file_put_contents($bad . '/001_ok.sql', "CREATE TABLE t_ok (id INT);\n");
    file_put_contents($bad . '/002_bad.sql', "CREATE TABLE t_ok2 (id INT);\nINSERT INTO no_existe VALUES (1);\n");
    $rootPdo->exec('DROP DATABASE IF EXISTS cap_mig2');
    $rootPdo->exec('CREATE DATABASE cap_mig2');
    $p2 = new PDO('mysql:host=127.0.0.1;port=' . $ctx['dbPort'] . ';dbname=cap_mig2', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    T::throws(static fn() => Migrator::run($p2, $bad), 'migracion que falla: error claro con version y sentencia', '002_bad');
    T::eq($p2->query('SELECT GROUP_CONCAT(version) FROM schema_migrations')->fetchColumn(), '001_ok', 'migracion fallida NO se registra; la anterior si');

    // Divisor de sentencias: respeta comillas, ; dentro de cadenas y comentarios --
    T::eq(Migrator::split("-- c; x\nSELECT 'a;b'; SELECT \"c;d\";\n-- fin\n"), ["SELECT 'a;b'", 'SELECT "c;d"'], 'divisor SQL: comillas y comentarios');

    // CLI
    $envFile = $ctx['work'] . '/mig.env';
    file_put_contents($envFile, "DB_HOST=127.0.0.1\nDB_PORT={$ctx['dbPort']}\nDB_NAME=cap_test\nDB_USER=captest\nDB_PASS=captest-pass\n");
    $r = Infra::cli([$root . '/bin/migrate.php'], ['CAPTACION_ENV_FILE' => $envFile]);
    T::ok($r['code'] === 0 && str_contains($r['out'], 'al dia'), 'bin/migrate.php repetido: "la base esta al dia"');
    $r = Infra::cli([$root . '/bin/migrate.php', '--status'], ['CAPTACION_ENV_FILE' => $envFile]);
    T::ok($r['code'] === 0 && substr_count($r['out'], '[aplicada]') === count($files), 'bin/migrate.php --status lista las aplicadas');
    $schema = (string) file_get_contents($root . '/db/schema.sql');
    $allIn = true;
    foreach ($files as $f) {
        $allIn = $allIn && str_contains($schema, "===== $f.sql =====");
    }
    T::ok($allIn, 'db/schema.sql incluye todas las migraciones (regenerar con migrate.php --schema)');
    $rootPdo->exec('DROP DATABASE IF EXISTS cap_mig');
    $rootPdo->exec('DROP DATABASE IF EXISTS cap_mig2');
};
