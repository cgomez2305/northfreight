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
    $newOnes = Migrator::run($q2b, $dir);
    T::eq($newOnes, ['006_handoff', '007_viewer_reports', '008_comms'], 'sobre la base de Q2b se aplican solo 006, 007 y 008');
    T::eq([(int) $q2b->query('SELECT COUNT(*) FROM leads')->fetchColumn(), (int) $q2b->query('SELECT COUNT(*) FROM sequence_enrollments')->fetchColumn(), (int) $q2b->query('SELECT COUNT(*) FROM messages')->fetchColumn(), (int) $q2b->query('SELECT COUNT(*) FROM users')->fetchColumn()], [1, 1, 1, 1], '  y los datos existentes siguen ahi');
    T::eq(Migrator::run($q2b, $dir), [], '  repetirlas no hace nada');
    $qc = static fn(string $t) => $q2b->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'cap_mig_q2b' AND TABLE_NAME = '$t'")->fetchAll(PDO::FETCH_COLUMN);
    T::ok(in_array('invited_by', $qc('users'), true) && in_array('deactivated_at', $qc('users'), true) && in_array('subject', $qc('messages'), true) && in_array('sequence_key', $qc('messages'), true) && in_array('blocks', $qc('sequence_enrollments'), true) && in_array('blocked_reason', $qc('sequence_enrollments'), true), '  columnas nuevas en users, messages y sequence_enrollments');
    $q2bTables = $q2b->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    T::ok(!array_diff(['handoffs', 'handoff_deliveries', 'user_invites', 'reports', 'email_templates'], $q2bTables), '  tablas nuevas: handoffs, handoff_deliveries, user_invites, reports, email_templates');
    T::eq($q2b->query("SELECT status FROM sequence_enrollments WHERE id = 1")->fetchColumn(), 'active', '  la inscripcion previa conserva su estado');
    $q2b->exec("INSERT INTO sequence_enrollments (lead_id, sequence_key, step, status) VALUES (1, 'a12_referral', 0, 'active')");
    T::throws(static fn() => $q2b->exec("INSERT INTO sequence_enrollments (lead_id, sequence_key, step, status) VALUES (1, 'a6_warm', 0, 'active')"), 'una inscripcion por lead y secuencia (clave unica)', 'Duplicate');
    $rootPdo->exec('DROP DATABASE cap_mig_q2b');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['users', 'leads', 'lead_events', 'consents', 'prospects_fmcsa', 'tasks', 'appointments', 'messages', 'sequence_enrollments', 'referral_codes', 'referrals', 'job_postings', 'channel_costs', 'settings', 'jobs', 'activity_log', 'recovery_codes', 'login_attempts', 'schema_migrations', 'handoffs', 'handoff_deliveries', 'user_invites', 'reports', 'email_templates', 'backups', 'lead_notes'] as $t) {
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
