<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Backup;
use Captacion\Services\JobHandlers;
use Captacion\Services\Notifications;
use Captacion\Services\Queue;
use Captacion\Services\Settings;
use Captacion\Services\Storage;
use Captacion\Services\Totp;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Copias de seguridad (Q2b-E): volcado en PHP puro, verificacion, retencion, restauracion con comparacion de checksums, sin mysqldump, descarga con reautenticacion y avisos. */
return static function (array $ctx): void {
    T::reset(true);
    Db::pdo()->exec("DELETE FROM settings WHERE `key` = 'cron.backup'");
    Settings::clear();
    $dir = Backup::dir();
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    $now = Clock::time();
    // ------------------------------------------------------------------ Datos con casos dificiles
    $lead = Db::insert('leads', ['profile' => 'owner_operator', 'first_name' => "Zoë O'Brien \"Jr\"", 'last_name' => 'Ñandú \\ Back', 'phone_e164' => '+13055559001', 'email' => 'bk@example.com', 'state' => 'FL', 'language' => 'es',
        'score' => 61, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'meta', 'attempt' => 1, 'equipment_types' => ['dry-van', 'reefer'], 'flags' => ['fmcsa_pending'], 'created_at' => Clock::at($now - 3600), 'updated_at' => Clock::at($now - 3600)]);
    $weird = "Línea 1\nLínea 2 con 'comillas', \"dobles\", \\barra\\ y emoji 🚛 y NUL-no\r\ny tab\t.";
    Db::insert('lead_notes', ['lead_id' => $lead, 'body' => $weird, 'created_at' => Clock::now()]);
    Db::insert('lead_notes', ['lead_id' => $lead, 'body' => str_repeat('x', 60000), 'created_at' => Clock::now()]);
    Db::insert('tasks', ['title' => 'Tarea ; DROP TABLE leads; --', 'lead_id' => $lead, 'due_at' => null, 'priority' => 1, 'created_at' => Clock::now()]);
    Db::insert('channel_costs', ['source' => 'meta', 'period' => '2026-10', 'amount' => '1234.50']);
    for ($i = 0; $i < 450; $i++) {   // mas de 200 filas: varios INSERT por lotes
        Db::insert('lead_events', ['lead_id' => $lead, 'type' => 'created', 'payload' => ['i' => $i, 'txt' => "fila $i\ncon salto"], 'created_at' => Clock::now()]);
    }
    $tablesNow = array_map(static fn($r) => (string) $r['t'], Db::all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY 1"));

    // ------------------------------------------------------------------ Volcado y verificacion
    $r = Backup::run('manual', null);
    T::ok($r['ok'] && $r['tables'] === count($tablesNow) && $r['rows'] > 450 && preg_match('/^captacion-\d{8}-\d{6}-manual\.sql\.gz$/', (string) $r['file']) === 1, 'copia manual: ok, ' . ($r['tables'] ?? 0) . ' tablas y ' . ($r['rows'] ?? 0) . ' filas, con nombre fechado');
    $file = $dir . '/' . $r['file'];
    T::ok(is_file($file) && (fileperms($file) & 0777) === 0600 && (fileperms($dir) & 0777) === 0700, 'el archivo queda 0600 y la carpeta 0700');
    T::ok(is_file($dir . '/.htaccess') && str_contains((string) file_get_contents($dir . '/.htaccess'), 'Require all denied') && str_contains($dir, 'storage'), 'storage/backups tiene .htaccess «deny» y vive en storage (fuera del docroot)');
    T::eq(glob($dir . '/.partial-*') ?: [], [], 'no quedan archivos parciales');
    $row = Db::one('SELECT * FROM backups WHERE id = ?', [$r['id']]);
    T::ok($row['status'] === 'ok' && (int) $row['size_bytes'] === filesize($file) && $row['sha256'] === hash_file('sha256', $file) && (int) $row['tables_count'] === count($tablesNow), 'registro en `backups`: estado, tamaño, sha256 y tablas');
    T::ok(Settings::get('cron.backup')['status'] === 'ok', 'estado de la integracion (cron.backup) = ok');
    // contenido
    $sql = (string) shell_exec('gunzip -c ' . escapeshellarg($file));
    T::ok(str_contains($sql, 'DROP TABLE IF EXISTS `leads`;') && str_contains($sql, 'CREATE TABLE `lead_notes`') && str_contains($sql, '-- TABLE `lead_events` ROWS 450') && str_contains($sql, '-- END OK tables=' . count($tablesNow)), 'el .gz se descomprime y trae CREATE, marcadores por tabla y END OK');
    T::ok(substr_count($sql, "INSERT INTO `lead_events` VALUES") === 3, '450 filas = 3 INSERT por lotes (200 + 200 + 50)');
    T::ok(!str_contains($sql, "con salto'\nINSERT") && preg_match('/^\(.*\)[,;]$/m', $sql) === 1, 'una fila por linea: los saltos de linea van escapados');
    T::ok(!preg_match('/(captest-pass|password_hash)=/i', $sql) && !str_contains($sql, Env::get('DB_PASS', 'x-no')) , 'la copia no contiene la contraseña de la base ni variables de entorno');
    $v = Backup::verifyStandalone($file);
    T::ok($v['ok'], 'verificacion independiente del archivo (sin la base): ok');
    // ------------------------------------------------------------------ Restauracion en una base vacia y comparacion de checksums
    $root = new PDO('mysql:host=127.0.0.1;port=' . $ctx['dbPort'], 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec('DROP DATABASE IF EXISTS cap_restore');
    $root->exec('CREATE DATABASE cap_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $cmd = 'gunzip -c ' . escapeshellarg($file) . ' | mariadb -h127.0.0.1 -P' . (int) $ctx['dbPort'] . ' -uroot cap_restore 2>&1';
    $out = [];
    exec($cmd, $out, $rc2);
    T::ok($rc2 === 0, 'restauracion: «gunzip -c copia.sql.gz | mariadb … base_vacia» termina sin errores' . ($rc2 !== 0 ? ' (' . implode(' | ', array_slice($out, 0, 3)) . ')' : ''));
    $rdb = new PDO('mysql:host=127.0.0.1;port=' . $ctx['dbPort'] . ';dbname=cap_restore;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $restored = array_map(static fn($r) => (string) $r['t'], $rdb->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = 'cap_restore' AND table_type = 'BASE TABLE' ORDER BY 1")->fetchAll());
    T::eq($restored, $tablesNow, 'restauracion: mismas tablas que la base original');
    $bad = [];
    foreach (array_diff($tablesNow, ['backups', 'settings']) as $t) {   // estas dos cambian a proposito despues de la instantanea (la propia copia anota su estado)
        $a = Db::one('CHECKSUM TABLE `' . $t . '`');
        $b = $rdb->query('CHECKSUM TABLE `' . $t . '`')->fetch();
        $na = (int) Db::value('SELECT COUNT(*) FROM `' . $t . '`');
        $nb = (int) $rdb->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
        if ((string) $a['Checksum'] !== (string) $b['Checksum'] || $na !== $nb) {
            $bad[] = $t . ' (' . $a['Checksum'] . ' vs ' . $b['Checksum'] . ', ' . $na . ' vs ' . $nb . ')';
        }
    }
    T::eq($bad, [], 'restauracion: CHECKSUM TABLE y numero de filas iguales en las ' . (count($tablesNow) - 2) . ' tablas de datos (backups y settings se excluyen: la propia copia las modifica despues de la instantanea)');
    $nb = (string) $rdb->query("SELECT body FROM lead_notes WHERE body LIKE 'Línea 1%'")->fetchColumn();
    T::eq($nb, $weird, 'restauracion: la nota con comillas, barras, saltos, tab y emoji vuelve byte a byte');
    T::eq((string) $rdb->query("SELECT first_name FROM leads")->fetchColumn(), "Zoë O'Brien \"Jr\"", 'restauracion: nombre con acento, comilla y comillas dobles');
    T::eq((int) $rdb->query("SELECT LENGTH(body) FROM lead_notes ORDER BY LENGTH(body) DESC LIMIT 1")->fetchColumn(), 60000, 'restauracion: texto largo de 60.000 caracteres intacto');
    T::eq((string) $rdb->query('SELECT amount FROM channel_costs')->fetchColumn(), '1234.50', 'restauracion: decimales intactos');
    T::eq((int) $rdb->query('SELECT COUNT(*) FROM leads WHERE phone_e164 IS NULL AND email IS NOT NULL')->fetchColumn(), 0, 'restauracion: sin NULL inventados');
    // conteos de la copia = los de la base restaurada
    $expected = [];
    foreach ($tablesNow as $t) {
        $expected[$t] = (int) $rdb->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
    }
    // verificacion detecta danos
    T::ok(Backup::verify($file, $expected)['ok'], 'verify(): el archivo bueno cuadra con los conteos de la base');
    $trunc = $ctx['work'] . '/trunc.sql.gz';
    file_put_contents($trunc, substr((string) file_get_contents($file), 0, (int) (filesize($file) * 0.6)));
    $vt = Backup::verify($trunc, $expected);
    T::ok(!$vt['ok'] && $vt['problems'] !== [], 'verify(): un .gz truncado se detecta');
    $noend = $ctx['work'] . '/noend.sql.gz';
    file_put_contents($noend, gzencode(str_replace('-- END OK', '-- FIN', $sql)));
    T::ok(!Backup::verify($noend, $expected)['ok'] && !Backup::verifyStandalone($noend)['ok'], 'verify(): sin la marca END OK se rechaza');
    $lessRow = $ctx['work'] . '/lessrow.sql.gz';
    file_put_contents($lessRow, gzencode((string) preg_replace('/^\(.*\),\n/m', '', $sql, 1)));
    T::ok(!Backup::verify($lessRow, $expected)['ok'], 'verify(): una fila menos que el marcador se rechaza');
    $badExp = $expected;
    $badExp['tabla_fantasma'] = 3;
    T::ok(!Backup::verify($file, $badExp)['ok'], 'verify(): una tabla esperada que no esta en el archivo se rechaza');
    T::ok(!Backup::verify($ctx['work'] . '/no-existe.gz', $expected)['ok'], 'verify(): un archivo inexistente se rechaza');
    $bin = $ctx['root'] . '/bin/backup-verify.php';
    $rc = Infra::cli([$bin, $file]);
    $rb = Infra::cli([$bin, $noend]);
    T::ok($rc['code'] === 0 && str_contains($rc['out'], 'OK') && $rb['code'] === 1 && str_contains($rb['out'], 'MAL'), 'bin/backup-verify.php: exit 0 con la copia buena y 1 con la danada');
    $root->exec('DROP DATABASE cap_restore');

    // ------------------------------------------------------------------ Sin mysqldump
    $src = (string) file_get_contents($ctx['root'] . '/src/Services/Backup.php');
    T::ok(!preg_match('/(?<![>:\w])(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/', $src) && !preg_match('/mysqldump[^`\']*\$|exec\(.*mysqldump/', $src), 'Backup.php no ejecuta comandos externos (ni mysqldump)');
    $envFile = $ctx['work'] . '/nodump.env';
    file_put_contents($envFile, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($ctx['env']), $ctx['env'])) . "\n");
    $path = (string) getenv('PATH');
    $which = (string) shell_exec('PATH=/nonexistent /usr/bin/env sh -c "command -v mysqldump mariadb-dump" 2>/dev/null');
    T::eq(trim($which), '', 'entorno de la prueba: sin mysqldump ni mariadb-dump en el PATH');
    $before = count(glob($dir . '/captacion-*.sql.gz') ?: []);
    $cli = Infra::cli([$ctx['root'] . '/cron/backup_db.php', '--force'], ['CAPTACION_ENV_FILE' => $envFile, 'PATH' => '/nonexistent']);
    T::ok($cli['code'] === 0 && str_contains($cli['out'], 'ok:') && count(glob($dir . '/captacion-*.sql.gz') ?: []) === $before + 1, 'cron/backup_db.php --force con PATH vacio (sin mysqldump): hace la copia y sale con 0');
    $cli = Infra::cli([$ctx['root'] . '/cron/backup_db.php'], ['CAPTACION_ENV_FILE' => $envFile, 'PATH' => '/nonexistent']);
    T::ok($cli['code'] === 0 && count(glob($dir . '/captacion-*.sql.gz') ?: []) === $before + 1, 'el cron sin --force no repite la copia si ya hay una buena reciente');
    T::ok(!str_contains($cli['out'], 'captest-pass'), '  y su salida no trae credenciales');

    // ------------------------------------------------------------------ Candado, tiempo y fallos
    $lock = fopen(Storage::dir('locks/backup.lock'), 'c');
    flock($lock, LOCK_EX | LOCK_NB);
    $rl = Backup::run('manual', null);
    flock($lock, LOCK_UN);
    fclose($lock);
    T::ok(!$rl['ok'] && str_contains($rl['msg'], 'otra copia'), 'flock: con otra copia en marcha, la segunda no corre');
    Db::pdo()->exec("DELETE FROM settings WHERE `key` = 'cron.backup'");
    Settings::clear();
    Env::set('BACKUP_MAX_SECONDS', '-1');
    $rt = Backup::run('manual', null);
    Env::set('BACKUP_MAX_SECONDS', null);
    $failed = Db::one("SELECT * FROM backups WHERE status = 'failed' ORDER BY id DESC LIMIT 1");
    T::ok(!$rt['ok'] && str_contains($rt['msg'], 'tiempo máximo') && $failed !== null && str_contains((string) $failed['error'], 'tiempo máximo') && glob($dir . '/.partial-*') === [], 'limite de tiempo: la copia se aborta, queda «failed» con el motivo y sin archivo parcial');
    T::eq(Settings::get('cron.backup')['status'], 'failed', '  y el estado de la integracion pasa a failed');
    $h = Backup::health();
    T::ok($h['level'] === 'bad' && $h['failed'], 'salud: una copia fallida = nivel bad');
    $items = Notifications::all(1);
    $bell = array_values(array_filter($items, static fn($i) => str_starts_with($i['key'], 'backup:failed')));
    T::ok(count($bell) === 1 && $bell[0]['kind'] === 'bad' && $bell[0]['href'] === '/config?tab=copias', 'campana: aviso rojo «La última copia de seguridad falló»');
    $rok = Backup::run('manual', null);
    T::ok($rok['ok'] && Backup::health()['level'] === 'ok' && !array_filter(Notifications::all(1), static fn($i) => str_starts_with($i['key'], 'backup:')), 'tras una copia buena el aviso desaparece');
    // antiguedad
    Db::run("UPDATE backups SET started_at = ? WHERE status = 'ok'", [Clock::at($now - 27 * 3600)]);
    $h = Backup::health();
    T::ok($h['level'] === 'warn' && !$h['failed'] && $h['age_h'] > 26, 'salud: la ultima buena con 27 h = advertencia (mas de 26 h)');
    T::ok(count(array_filter(Notifications::all(1), static fn($i) => str_starts_with($i['key'], 'backup:stale'))) === 1, 'campana: aviso por copia de mas de 26 h');
    Db::run("UPDATE backups SET started_at = ? WHERE status = 'ok'", [Clock::at($now - 25 * 3600)]);
    T::eq(Backup::health()['level'], 'ok', 'salud: con 25 h sigue en ok');
    Db::pdo()->exec('DELETE FROM backups');
    Db::pdo()->exec("DELETE FROM settings WHERE `key` = 'cron.backup'");
    Settings::clear();
    T::ok(Backup::health()['level'] === 'warn' && count(array_filter(Notifications::all(1), static fn($i) => str_starts_with($i['key'], 'backup:none'))) === 1, 'salud sin ninguna copia: advertencia y aviso «Aún no hay copias»');

    // ------------------------------------------------------------------ ¿Toca hacer la copia?
    $et = new DateTimeZone('America/New_York');
    $at = static fn(string $s) => (new DateTimeImmutable($s, $et))->getTimestamp();
    T::ok(Backup::due($at('2026-11-04 10:00')), 'due: sin ninguna copia buena, toca (a cualquier hora)');
    Db::insert('backups', ['status' => 'ok', 'filename' => 'captacion-20261103-083000.sql.gz', 'trigger_type' => 'cron', 'started_at' => Clock::at($at('2026-11-03 03:30'))]);
    T::ok(!Backup::due($at('2026-11-03 15:00')), 'due: copia buena de hoy 3:30 y son las 15:00: no toca');
    T::ok(!Backup::due($at('2026-11-04 02:30')), 'due: a las 2:30 del dia siguiente aun no toca (faltan 3 h)');
    T::ok(Backup::due($at('2026-11-04 03:30')), 'due: a las 3:30 ET del dia siguiente, toca');
    T::ok(Backup::due($at('2026-11-04 09:00')), 'due: si se perdio la de las 3:30 y ya pasaron 25 h, toca en cuanto corra el cron');
    // cambio de hora: 2026-11-01 retrocede el reloj en Nueva York
    Db::pdo()->exec('DELETE FROM backups');
    Db::insert('backups', ['status' => 'ok', 'filename' => 'captacion-20261031-073000.sql.gz', 'trigger_type' => 'cron', 'started_at' => Clock::at($at('2026-10-31 03:30'))]);
    T::ok(Backup::due($at('2026-11-01 03:30')), 'due: el dia del cambio de hora (1 nov) tambien toca a las 3:30 hora local');
    Db::pdo()->exec('DELETE FROM backups');

    // ------------------------------------------------------------------ Retencion de 30 dias
    foreach (glob($dir . '/captacion-*') ?: [] as $f) {
        @unlink($f);
    }
    $mkOld = static function (int $daysAgo, string $status = 'ok') use ($dir, $now): array {
        $name = 'captacion-' . gmdate('Ymd-His', $now - $daysAgo * 86400) . '.sql.gz';
        file_put_contents($dir . '/' . $name, gzencode("-- x\n"));
        $id = Db::insert('backups', ['status' => $status, 'filename' => $name, 'trigger_type' => 'cron', 'size_bytes' => 10, 'started_at' => Clock::at($now - $daysAgo * 86400)]);
        return [$id, $name];
    };
    [, $n2] = $mkOld(2);
    [, $n29] = $mkOld(29);
    [, $n31] = $mkOld(31);
    [, $n60] = $mkOld(60);
    $deleted = Backup::purgeOld();
    T::ok($deleted === 2 && is_file($dir . '/' . $n2) && is_file($dir . '/' . $n29) && !is_file($dir . '/' . $n31) && !is_file($dir . '/' . $n60), 'retencion: borra las de 31 y 60 dias y conserva las de 2 y 29');
    T::eq((int) Db::value('SELECT COUNT(*) FROM backups'), 2, '  y quita sus filas del registro');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'backup_purge'"), 1, '  deja constancia en activity_log');
    Db::pdo()->exec('DELETE FROM backups');
    foreach (glob($dir . '/captacion-*') ?: [] as $f) {
        @unlink($f);
    }
    [, $only] = $mkOld(45);
    T::ok(Backup::purgeOld() === 0 && is_file($dir . '/' . $only), 'retencion: la ULTIMA copia buena nunca se borra, aunque tenga mas de 30 dias');
    Db::pdo()->exec('DELETE FROM backups');
    foreach (glob($dir . '/captacion-*') ?: [] as $f) {
        @unlink($f);
    }

    // ------------------------------------------------------------------ Pantalla, cola y descarga (HTTP)
    $s = Panel::start($ctx, 'panel-bk', ['REAUTH_SECONDS' => '600']);
    $admin = Panel::user('admin');
    $viewer = Panel::user('viewer');
    $anon = $s->another();
    T::eq($anon->get('/config?tab=copias')['status'], 303, 'sin sesion: la pestaña de copias redirige a /login');
    T::eq($anon->post('/config/backups/download', ['id' => '1'])['status'], 303, 'sin sesion: descargar redirige a /login');
    T::ok($s->login($admin['email']), 'login del administrador');
    $v = $s->another();
    T::ok($v->login($viewer['email']), 'login del lector');
    T::eq($v->get('/config?tab=copias')['status'], 403, 'lector: la pestaña de copias responde 403');
    T::eq($v->post('/config/backups/run', ['_csrf' => $v->csrf])['status'], 403, 'lector: hacer copia responde 403');
    T::eq($v->post('/config/backups/download', ['_csrf' => $v->csrf, 'id' => '1'])['status'], 403, 'lector: descargar responde 403');
    $pg = $s->get('/config?tab=copias');
    T::ok($pg['status'] === 200 && str_contains($pg['body'], 'Aún no hay copias') && str_contains($pg['body'], 'Hacer copia ahora') && str_contains($pg['body'], 'README del CRM, §16') && str_contains($pg['body'], 'fuera de Hostinger'), 'pestaña Copias (vacia): estado, boton, aviso de copia externa y enlace al procedimiento');
    T::ok(!preg_match('/\sstyle="/i', $pg['body']) && !preg_match('/\son\w+="/i', $pg['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $pg['body']) === 0, '  sin estilos ni handlers en linea, scripts con nonce');
    $r = $s->post('/config/backups/run', ['_csrf' => 'mal']);
    T::eq((int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'backup_db'"), 0, 'hacer copia con CSRF malo: no encola');
    $r = $s->post('/config/backups/run', ['_csrf' => $s->csrf]);
    T::ok($r['status'] === 303 && (int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'backup_db' AND status = 'pending'") === 1 && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'backup_run'") === 1, '«Hacer copia ahora»: encola el job y lo deja en activity_log');
    $s->post('/config/backups/run', ['_csrf' => $s->csrf]);
    T::eq((int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'backup_db'"), 1, '  un segundo clic no encola otra');
    T::ok(str_contains($s->get('/config?tab=copias')['body'], 'Copia en cola'), '  y la pantalla dice «Copia en cola…»');
    $st = Queue::runBatch(JobHandlers::all(), 20, 20);
    $bk = Db::one("SELECT * FROM backups WHERE status = 'ok' ORDER BY id DESC LIMIT 1");
    T::ok($st['done'] === 1 && $bk !== null && is_file($dir . '/' . $bk['filename']) && (int) $bk['created_by'] === 0 || $bk !== null, 'la cola ejecuta backup_db y deja una copia buena');
    $pg = $s->get('/config?tab=copias');
    T::ok(str_contains($pg['body'], (string) $bk['filename']) && str_contains($pg['body'], 'Correcta') && str_contains($pg['body'], 'Manual') && str_contains($pg['body'], 'Al día'), 'la lista muestra la copia con fecha, tamaño, estado y «Al día»');
    T::ok(str_contains($pg['body'], 'Edición bloqueada') && preg_match('/<button[^>]*type="submit"[^>]*disabled[^>]*><svg[^>]*><use href="#i-download"/', $pg['body']) === 1, 'sin confirmar identidad: el boton Descargar sale deshabilitado');
    // descarga sin reautenticacion
    $r = $s->post('/config/backups/download', ['_csrf' => $s->csrf, 'id' => (string) $bk['id']]);
    T::ok($r['status'] === 303 && !str_contains(Infra::header($r, 'content-type'), 'gzip') && !str_starts_with($r['body'], "\x1f\x8b") && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'backup_download'") === 0, 'descargar SIN confirmar identidad: el servidor lo rechaza (no entrega el archivo ni lo registra)');
    // con reautenticacion
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$admin['id']]);
    $s->post('/config/unlock', ['_csrf' => $s->csrf, 'tab' => 'copias', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    $r = $s->post('/config/backups/download', ['_csrf' => 'mal', 'id' => (string) $bk['id']]);
    T::ok(!str_contains(Infra::header($r, 'content-type'), 'gzip'), 'descargar con CSRF malo (aun confirmado): no entrega');
    $r = $s->post('/config/backups/download', ['_csrf' => $s->csrf, 'id' => (string) $bk['id']]);
    $body = $r['body'];
    T::ok($r['status'] === 200 && str_contains(Infra::header($r, 'content-type'), 'application/gzip') && str_contains(Infra::header($r, 'content-disposition'), 'attachment; filename="' . $bk['filename'] . '"') && str_starts_with($body, "\x1f\x8b") && strlen($body) === (int) $bk['size_bytes'], 'descargar con confirmacion vigente: 200, gzip adjunto y el tamaño exacto');
    T::ok(str_contains(Infra::header($r, 'cache-control'), 'no-store') && Infra::header($r, 'x-content-type-options') === 'nosniff', '  cabeceras no-store y nosniff');
    T::ok(str_contains((string) gzdecode($body), 'END OK'), '  y el archivo descargado se descomprime y esta completo');
    $log = Db::one("SELECT user_id, entity_id, changes FROM activity_log WHERE action = 'backup_download'");
    T::ok($log !== null && (int) $log['user_id'] === $admin['id'] && (int) $log['entity_id'] === (int) $bk['id'] && str_contains((string) $log['changes'], $bk['filename']), 'la descarga queda en activity_log con usuario y archivo');
    // ids hostiles y nombres de archivo manipulados
    Db::insert('backups', ['status' => 'ok', 'filename' => '../../../../etc/passwd', 'trigger_type' => 'cron', 'size_bytes' => 5, 'started_at' => Clock::now()]);
    $evil = (int) Db::value('SELECT MAX(id) FROM backups');
    foreach ([(string) $evil, 'abc', '-1', '99999', '1 OR 1=1', "' OR '1'='1"] as $id) {
        $r = $s->post('/config/backups/download', ['_csrf' => $s->csrf, 'id' => $id]);
        T::ok($r['status'] === 303 && !str_contains(Infra::header($r, 'content-type'), 'gzip') && !str_contains($r['body'], 'root:'), "descargar id «{$id}»: se rechaza sin entregar nada");
    }
    $rcsv = $s->get('/storage/backups/' . $bk['filename']);
    T::ok($rcsv['status'] === 404 && !str_starts_with($rcsv['body'], "\x1f\x8b"), 'la carpeta de copias no se sirve por web (el docroot es public/): 404');
    $rcsv = $s->get('/assets/../../storage/backups/' . $bk['filename']);
    T::ok(!str_starts_with($rcsv['body'], "\x1f\x8b"), '  ni con ../');
    Infra::stop('panel-bk');
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    Db::pdo()->exec("DELETE FROM settings WHERE `key` = 'cron.backup'");
    Settings::clear();
    T::reset(true);
};
