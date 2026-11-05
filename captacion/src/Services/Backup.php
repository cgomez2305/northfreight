<?php
/**
 * Copias de seguridad de la base del CRM (doc §17), hechas en PHP puro: SIN mysqldump (el hosting compartido puede no tenerlo y asi el codigo no depende del PATH).
 *
 * Volcado: una conexion propia en transaccion con instantanea consistente (REPEATABLE READ + CONSISTENT SNAPSHOT: todas las tablas al mismo instante aunque el
 * panel siga escribiendo), SHOW CREATE TABLE + INSERT por lotes de 200 filas (una fila por linea: los saltos de linea van escapados), escrito en streaming a un
 * .sql.gz (memoria constante), con flock (una copia a la vez), limite de tiempo (BACKUP_MAX_SECONDS, 240 por defecto) y `SET FOREIGN_KEY_CHECKS=0` para restaurar en
 * cualquier orden. Cada tabla cierra con el marcador `-- TABLE `t` ROWS n` y el archivo con `-- END OK tables=N rows=M`.
 *
 * Verificacion (antes de dar la copia por buena): se descomprime todo el .gz; las tablas del archivo son EXACTAMENTE las de la base; las filas escritas de cada
 * tabla (lineas de INSERT) coinciden con el marcador y con COUNT(*) leido en la misma instantanea; el archivo termina con `-- END OK`. Solo entonces pasa a
 * .sql.gz definitivo (0600, en storage/backups con .htaccess deny, fuera del docroot). Si algo falla, el archivo parcial se borra y queda el error en `backups`.
 * Retencion: 30 dias (siempre se conserva la ultima copia buena). Restauracion paso a paso: README §16.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use PDO;

final class Backup
{
    public const RETENTION_DAYS = 30;
    public const ROWS_PER_INSERT = 200;
    public const STALE_HOURS = 26;
    public const CRON_HOUR = 3;            // 3:30 a. m. hora de Nueva York (el cron corre cada hora a los :30 y solo actua en esa hora)
    public const CRON_TZ = 'America/New_York';
    private const FILE_RE = '/^captacion-\d{8}-\d{6}(-[a-z]+)*\.sql\.gz$/';

    public static function dir(): string
    {
        return Storage::dir('backups');
    }

    /** Crea storage/backups (0700) con su .htaccess deny. */
    public static function ensureDir(): string
    {
        $d = self::dir();
        Storage::ensure($d, 0700);
        @chmod($d, 0700);
        $ht = $d . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# Copias de la base: nunca se sirven por web\nRequire all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
        }
        return $d;
    }

    private static function pdo(): PDO
    {
        $pdo = new PDO(Db::dsn(), (string) Env::get('DB_USER'), (string) Env::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            PDO::ATTR_TIMEOUT => Env::int('DB_TIMEOUT', 3),
        ]);
        $pdo->exec("SET NAMES utf8mb4, time_zone = '+00:00', sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    /** Encola una copia manual (la hace el cron de la cola en el proximo minuto). @return array{ok:bool,msg:string} */
    public static function request(int $userId): array
    {
        $running = (int) Db::value("SELECT COUNT(*) FROM backups WHERE status = 'running' AND started_at > ?", [Clock::shift(-1800)]);
        $queued = (int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'backup_db' AND status IN ('pending','running')");
        if ($running > 0 || $queued > 0) {
            return ['ok' => false, 'msg' => 'Ya hay una copia en cola o en marcha.'];
        }
        Queue::push('backup_db', ['by' => $userId], Clock::now(), 'backup_db:' . gmdate('YmdHi', Clock::time()) . ':' . $userId);
        ActivityLog::record('backup_run', $userId, 'backup', null, ['trigger' => 'manual']);
        return ['ok' => true, 'msg' => 'Copia en cola: se hace en el próximo minuto (el cron de la cola). Actualiza esta página en un momento.'];
    }

    /** Handler del job backup_db. */
    public static function handler(array $payload, array $job = []): void
    {
        $r = self::run('manual', isset($payload['by']) ? (int) $payload['by'] : null);
        if (!$r['ok']) {
            throw new PermanentJobFailure($r['msg']);
        }
    }

    /**
     * Hace una copia completa. @return array{ok:bool,msg:string,id:?int,file:?string,size:?int,tables:?int,rows:?int}
     */
    public static function run(string $trigger = 'cron', ?int $userId = null): array
    {
        $fail = static fn(string $msg, ?int $id = null): array => ['ok' => false, 'msg' => $msg, 'id' => $id, 'file' => null, 'size' => null, 'tables' => null, 'rows' => null];
        @set_time_limit(Env::int('BACKUP_MAX_SECONDS', 240) + 30);
        $dir = self::ensureDir();
        Storage::ensure(Storage::dir('locks'));
        $lock = fopen(Storage::dir('locks/backup.lock'), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $fail('Ya hay otra copia en marcha.');
        }
        $id = null;
        $tmp = null;
        try {
            $started = Clock::now();
            $id = Db::insert('backups', ['status' => 'running', 'trigger_type' => substr($trigger, 0, 10), 'created_by' => $userId, 'started_at' => $started]);
            $stem = 'captacion-' . gmdate('Ymd-His', Clock::time()) . ($trigger === 'cron' ? '' : '-' . preg_replace('/[^a-z]/', '', strtolower($trigger)));
            $name = $stem . '.sql.gz';
            for ($k = 'b'; is_file($dir . '/' . $name) && $k <= 'z'; $k++) {   // dos copias en el mismo segundo no se pisan
                $name = $stem . '-' . $k . '.sql.gz';
            }
            $tmp = $dir . '/.partial-' . $name;
            $final = $dir . '/' . $name;
            $stats = self::dump($tmp, Env::int('BACKUP_MAX_SECONDS', 240));
            $v = self::verify($tmp, $stats['counts']);
            if (!$v['ok']) {
                throw new \RuntimeException('La verificación de la copia falló: ' . implode('; ', array_slice($v['problems'], 0, 3)));
            }
            if (!@rename($tmp, $final)) {
                throw new \RuntimeException('No se pudo guardar el archivo de la copia.');
            }
            @chmod($final, 0600);
            clearstatcache(true, $final);
            $size = (int) filesize($final);
            Db::update('backups', $id, ['status' => 'ok', 'filename' => $name, 'size_bytes' => $size, 'sha256' => hash_file('sha256', $final), 'tables_count' => $v['tables'], 'rows_count' => $v['rows'], 'finished_at' => Clock::now()]);
            Settings::set('cron.backup', ['at' => Clock::now(), 'status' => 'ok', 'id' => $id, 'file' => $name, 'size' => $size, 'tables' => $v['tables'], 'rows' => $v['rows'], 'error' => null]);
            self::purgeOld();
            return ['ok' => true, 'msg' => 'Copia hecha y verificada: ' . $v['tables'] . ' tablas, ' . $v['rows'] . ' filas.', 'id' => $id, 'file' => $name, 'size' => $size, 'tables' => $v['tables'], 'rows' => $v['rows']];
        } catch (\Throwable $t) {
            $msg = mb_substr(Storage::redact($t->getMessage()), 0, 480);
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
            try {
                if ($id !== null) {
                    Db::update('backups', $id, ['status' => 'failed', 'error' => $msg, 'finished_at' => Clock::now()]);
                }
                Settings::set('cron.backup', ['at' => Clock::now(), 'status' => 'failed', 'id' => $id, 'file' => null, 'size' => null, 'tables' => null, 'rows' => null, 'error' => $msg]);
            } catch (\Throwable) {
            }
            Storage::error('backup failed', $msg);
            return $fail($msg, $id);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Escribe el volcado en $file. @return array{counts:array<string,int>} filas por tabla, contadas con COUNT(*) en la misma instantanea
     */
    private static function dump(string $file, int $maxSeconds): array
    {
        $t0 = microtime(true);
        $pdo = self::pdo();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $tables = array_map(static fn($r) => (string) $r[0], $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll());
        sort($tables);
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('No se pudo crear el archivo de la copia.');
        }
        @chmod($file, 0600);
        $w = static function (string $s) use ($gz): void {
            if (gzwrite($gz, $s) === false) {
                throw new \RuntimeException('Error al escribir la copia (¿disco lleno?).');
            }
        };
        $w("-- NorthStar Captacion: copia de la base (PHP puro, sin mysqldump)\n-- Hecha: " . gmdate('c') . " UTC · tablas: " . count($tables) . "\n");
        $w("SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION';\n\n");
        $counts = [];
        $totalRows = 0;
        foreach ($tables as $t) {
            $q = '`' . str_replace('`', '``', $t) . '`';
            $one = static function (string $sql) use ($pdo): array {
                $st = $pdo->query($sql);
                $r = $st->fetch();
                $st->closeCursor();
                return (array) $r;
            };
            $create = $one('SHOW CREATE TABLE ' . $q);
            $w("-- ----------------------------------------\nDROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n");
            $stmt = $pdo->query('SELECT * FROM ' . $q);
            $n = 0;
            $batch = [];
            $flush = static function () use (&$batch, $q, $w): void {
                if ($batch) {
                    $w('INSERT INTO ' . $q . " VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            };
            while (($row = $stmt->fetch()) !== false) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : (is_int($v) ? (string) $v : $pdo->quote((string) $v));
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                $n++;
                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $flush();
                    if (microtime(true) - $t0 > $maxSeconds) {
                        throw new \RuntimeException('La copia superó el tiempo máximo (' . $maxSeconds . ' s).');
                    }
                }
            }
            $stmt->closeCursor();
            $flush();
            $w('-- TABLE ' . $q . ' ROWS ' . $n . "\n\n");
            $counts[$t] = $n;
            $totalRows += $n;
            // el conteo de la base, en la misma instantanea, debe coincidir con lo escrito
            $real = (int) ($one('SELECT COUNT(*) FROM ' . $q)[0] ?? -1);
            if ($real !== $n) {
                throw new \RuntimeException('La tabla ' . $t . ' escribió ' . $n . ' filas pero la base tiene ' . $real . '.');
            }
        }
        $w("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- END OK tables=" . count($tables) . ' rows=' . $totalRows . "\n");
        gzclose($gz);
        $pdo->exec('COMMIT');
        return ['counts' => $counts];
    }

    /**
     * Verifica un .sql.gz: se descomprime entero, las tablas son las esperadas, las filas escritas coinciden con el marcador y con $expected y termina con END OK.
     * @param array<string,int> $expected tabla => filas segun la base
     * @return array{ok:bool,tables:int,rows:int,problems:list<string>}
     */
    public static function verify(string $file, array $expected): array
    {
        $problems = [];
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            return ['ok' => false, 'tables' => 0, 'rows' => 0, 'problems' => ['No se pudo abrir el archivo comprimido.']];
        }
        $created = [];
        $written = [];
        $marks = [];
        $cur = null;
        $end = null;
        $lines = 0;
        while (($line = gzgets($gz, 4194304)) !== false) {
            $lines++;
            if (str_starts_with($line, '(')) {
                if ($cur !== null) {
                    $written[$cur] = ($written[$cur] ?? 0) + 1;
                }
            } elseif (preg_match('/^CREATE TABLE `((?:[^`]|``)+)`/', $line, $m) === 1) {
                $cur = str_replace('``', '`', $m[1]);
                $created[$cur] = true;
            } elseif (preg_match('/^-- TABLE `((?:[^`]|``)+)` ROWS (\d+)$/', rtrim($line, "\r\n"), $m) === 1) {
                $marks[str_replace('``', '`', $m[1])] = (int) $m[2];
            } elseif (preg_match('/^-- END OK tables=(\d+) rows=(\d+)$/', rtrim($line, "\r\n"), $m) === 1) {
                $end = [(int) $m[1], (int) $m[2]];
            }
        }
        $eof = gzeof($gz);
        gzclose($gz);
        if (!$eof) {
            $problems[] = 'El archivo comprimido está truncado o dañado.';
        }
        if ($end === null) {
            $problems[] = 'Falta la marca final «END OK» (copia incompleta).';
        }
        $names = array_keys($expected);
        sort($names);
        $got = array_keys($created);
        sort($got);
        if ($names !== $got) {
            $problems[] = 'Las tablas del archivo no son las de la base (faltan: ' . implode(', ', array_diff($names, $got)) . '; sobran: ' . implode(', ', array_diff($got, $names)) . ').';
        }
        $rows = 0;
        foreach ($expected as $t => $n) {
            $w = $written[$t] ?? 0;
            $rows += $w;
            if ($w !== $n || ($marks[$t] ?? -1) !== $n) {
                $problems[] = 'Tabla ' . $t . ': base ' . $n . ' filas, archivo ' . $w . ', marcador ' . ($marks[$t] ?? 'ninguno') . '.';
            }
        }
        if ($end !== null && ($end[0] !== count($expected) || $end[1] !== $rows)) {
            $problems[] = 'El resumen final no coincide con el contenido.';
        }
        return ['ok' => !$problems, 'tables' => count($created), 'rows' => $rows, 'problems' => $problems];
    }

    /** Verifica un archivo ya guardado contra las tablas actuales (solo estructura y marcadores, sin comparar con la base viva): para «Verificar» a mano. */
    public static function verifyStandalone(string $file): array
    {
        $gz = @gzopen($file, 'rb');
        if ($gz === false) {
            return ['ok' => false, 'problems' => ['No se pudo abrir el archivo.']];
        }
        $expected = [];
        $cur = null;
        $written = [];
        $marks = [];
        $end = false;
        while (($line = gzgets($gz, 4194304)) !== false) {
            if (str_starts_with($line, '(')) {
                $written[$cur] = ($written[$cur] ?? 0) + 1;
            } elseif (preg_match('/^CREATE TABLE `((?:[^`]|``)+)`/', $line, $m) === 1) {
                $cur = str_replace('``', '`', $m[1]);
            } elseif (preg_match('/^-- TABLE `((?:[^`]|``)+)` ROWS (\d+)$/', rtrim($line), $m) === 1) {
                $marks[str_replace('``', '`', $m[1])] = (int) $m[2];
            } elseif (str_starts_with($line, '-- END OK')) {
                $end = true;
            }
        }
        $ok = gzeof($gz) && $end;
        gzclose($gz);
        foreach ($marks as $t => $n) {
            $expected[$t] = $n;
            $ok = $ok && ($written[$t] ?? 0) === $n;
        }
        return ['ok' => $ok, 'problems' => $ok ? [] : ['El archivo no pasa la verificación.']];
    }

    /** Borra las copias de mas de RETENTION_DAYS dias (siempre queda la ultima buena). @return int archivos borrados */
    public static function purgeOld(): int
    {
        $cut = Clock::shift(-self::RETENTION_DAYS * 86400);
        $keep = (int) Db::value("SELECT id FROM backups WHERE status = 'ok' ORDER BY started_at DESC, id DESC LIMIT 1");
        $old = Db::all('SELECT id, filename FROM backups WHERE started_at < ? AND id <> ?', [$cut, $keep]);
        $n = 0;
        foreach ($old as $b) {
            if ($b['filename'] !== null && preg_match(self::FILE_RE, (string) $b['filename']) === 1 && is_file(self::dir() . '/' . $b['filename'])) {
                @unlink(self::dir() . '/' . $b['filename']);
                $n++;
            }
            Db::run('DELETE FROM backups WHERE id = ?', [(int) $b['id']]);
        }
        if ($n > 0) {
            ActivityLog::record('backup_purge', null, 'backup', null, ['deleted' => $n, 'older_than' => $cut]);
        }
        return $n;
    }

    /** ¿Toca hacer la copia diaria ahora? Hora de Nueva York == CRON_HOUR sin copia buena de ese dia, o la ultima buena tiene mas de 25 h (recuperarse de un dia perdido). */
    public static function due(?int $now = null): bool
    {
        $now ??= Clock::time();
        $tz = new \DateTimeZone(self::CRON_TZ);
        $d = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $last = Db::value("SELECT MAX(started_at) FROM backups WHERE status = 'ok'");
        $lastTs = $last !== null ? (int) Clock::parse((string) $last) : 0;
        if ($lastTs === 0 || $now - $lastTs > 25 * 3600) {
            return true;
        }
        $lastDay = (new \DateTimeImmutable('@' . $lastTs))->setTimezone($tz)->format('Y-m-d');
        return (int) $d->format('G') === self::CRON_HOUR && $lastDay !== $d->format('Y-m-d');
    }

    // --- Pantalla y avisos -----------------------------------------------------------------------------------------------

    /** Estado de la ultima copia para la campana y la pantalla: ['level'=>ok|warn|bad,'msg'=>...,'last_ok'=>?string,'age_h'=>?float,'failed'=>bool]. */
    public static function health(?int $now = null): array
    {
        $now ??= Clock::time();
        $lastOk = Db::value("SELECT MAX(started_at) FROM backups WHERE status = 'ok'");
        $state = Settings::get('cron.backup');
        $failed = is_array($state) && ($state['status'] ?? '') === 'failed';
        $age = $lastOk !== null ? ($now - (int) Clock::parse((string) $lastOk)) / 3600 : null;
        if ($failed) {
            return ['level' => 'bad', 'msg' => 'La última copia falló: ' . mb_substr((string) ($state['error'] ?? ''), 0, 120), 'last_ok' => $lastOk, 'age_h' => $age, 'failed' => true];
        }
        if ($lastOk === null) {
            return ['level' => 'warn', 'msg' => 'Todavía no hay ninguna copia de seguridad.', 'last_ok' => null, 'age_h' => null, 'failed' => false];
        }
        if ($age > self::STALE_HOURS) {
            return ['level' => 'warn', 'msg' => 'La última copia buena tiene ' . (int) floor($age) . ' h (más de ' . self::STALE_HOURS . ' h).', 'last_ok' => $lastOk, 'age_h' => $age, 'failed' => false];
        }
        return ['level' => 'ok', 'msg' => 'Última copia buena ' . Fmt::ago((string) $lastOk) . '.', 'last_ok' => $lastOk, 'age_h' => $age, 'failed' => false];
    }

    public static function pageVars(): array
    {
        $rows = Db::all('SELECT id, filename, status, trigger_type, size_bytes, tables_count, rows_count, error, started_at, finished_at FROM backups ORDER BY started_at DESC, id DESC LIMIT 40');
        foreach ($rows as &$r) {
            $r['exists'] = $r['filename'] !== null && preg_match(self::FILE_RE, (string) $r['filename']) === 1 && is_file(self::dir() . '/' . $r['filename']);
        }
        unset($r);
        return ['backups' => $rows, 'health' => self::health(), 'queued' => (int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'backup_db' AND status IN ('pending','running')") > 0,
            'retentionDays' => self::RETENTION_DAYS, 'dirName' => 'captacion/storage/backups/'];
    }

    /** Descarga de una copia (solo admin y con confirmacion reciente: lo comprueba el controlador). Cabeceras no-store; queda en activity_log. */
    public static function download(int $id, int $userId, string $back): never
    {
        $row = Db::one("SELECT id, filename, size_bytes FROM backups WHERE id = ? AND status = 'ok'", [$id]);
        $path = $row !== null && preg_match(self::FILE_RE, (string) $row['filename']) === 1 ? self::dir() . '/' . $row['filename'] : null;
        if ($path === null || !is_file($path)) {
            $_SESSION['flash'] = ['ok' => false, 'msg' => 'Esa copia ya no existe en el servidor.'];
            \Captacion\Middleware\SecurityHeaders::send();
            header('Location: ' . $back, true, 303);
            exit;
        }
        ActivityLog::record('backup_download', $userId, 'backup', $id, ['file' => $row['filename'], 'bytes' => (int) filesize($path)]);
        http_response_code(200);
        \Captacion\Middleware\SecurityHeaders::send(false);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $row['filename'] . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
