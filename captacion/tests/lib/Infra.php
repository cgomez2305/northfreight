<?php
/** Infraestructura de pruebas: MariaDB local temporal, servidores php -S y cliente HTTP. Todo se arranca con proc_open y se apaga por PID (nunca pkill). */

declare(strict_types=1);

namespace CaptacionTests;

final class Infra
{
    public static string $work = '';
    public static int $dbPort = 0;
    /** @var array<string,array{proc:resource,pid:int}> */
    private static array $procs = [];
    public static bool $keep = false;

    public static function freePort(int $from): int
    {
        for ($p = $from; $p < $from + 200; $p++) {
            $s = @stream_socket_server("tcp://127.0.0.1:$p", $errno, $err);
            if ($s !== false) {
                fclose($s);
                return $p;
            }
        }
        throw new \RuntimeException('Sin puertos libres desde ' . $from);
    }

    public static function startMariaDb(string $work): void
    {
        self::$work = $work;
        $data = "$work/db/data";
        if (!is_dir($data)) {
            mkdir($data, 0777, true);
            $out = [];
            exec('mariadb-install-db --user=root --datadir=' . escapeshellarg($data) . ' --auth-root-authentication-method=normal --skip-test-db 2>&1', $out, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException("mariadb-install-db fallo:\n" . implode("\n", array_slice($out, -10)));
            }
        }
        self::$dbPort = self::freePort(33306);
        $cmd = ['mariadbd', '--user=root', "--datadir=$data", "--socket=$work/db/m.sock", '--port=' . self::$dbPort, '--bind-address=127.0.0.1', '--skip-networking=0',
            "--pid-file=$work/db/m.pid", '--max-connections=100', '--innodb-flush-log-at-trx-commit=0', '--skip-name-resolve'];
        self::spawn('mariadb', $cmd, [], "$work/db/mariadb.log");
        $deadline = microtime(true) + 40;
        while (microtime(true) < $deadline) {
            try {
                $pdo = new \PDO('mysql:host=127.0.0.1;port=' . self::$dbPort, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('DROP DATABASE IF EXISTS cap_test');
                $pdo->exec('CREATE DATABASE cap_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $pdo->exec("CREATE USER IF NOT EXISTS 'captest'@'127.0.0.1' IDENTIFIED BY 'captest-pass'");
                $pdo->exec("GRANT ALL ON cap_test.* TO 'captest'@'127.0.0.1'");
                return;
            } catch (\Throwable) {
                usleep(300000);
            }
        }
        throw new \RuntimeException('MariaDB no arranco a tiempo (ver ' . "$work/db/mariadb.log)");
    }

    /** Arranca un proceso y guarda su PID para apagarlo luego. */
    public static function spawn(string $name, array $cmd, array $env, string $log, ?string $cwd = null): int
    {
        $envFull = array_merge(getenv(), $env);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, $envFull);
        if (!is_resource($proc)) {
            throw new \RuntimeException("No se pudo arrancar $name");
        }
        $pid = (int) proc_get_status($proc)['pid'];
        self::$procs[$name] = ['proc' => $proc, 'pid' => $pid];
        return $pid;
    }

    /** php -S en 127.0.0.1:puerto. $router = script router opcional. Devuelve el puerto. */
    public static function phpServer(string $name, string $docroot, array $env, ?string $router = null, int $from = 18900): int
    {
        $port = self::freePort($from);
        $cmd = [PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", '-t', $docroot];
        if ($router !== null) {
            $cmd[] = $router;
        }
        self::spawn($name, $cmd, $env, self::$work . "/$name.log");
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
            if ($s) {
                fclose($s);
                return $port;
            }
            usleep(100000);
        }
        throw new \RuntimeException("php -S $name no arranco");
    }

    /** Arranca el SMTP falso (lib/smtp_mock.php). Devuelve el puerto. */
    public static function smtpMock(string $name, string $log, string $modeFile = ''): int
    {
        $port = self::freePort(25800);
        self::spawn($name, [PHP_BINARY, __DIR__ . '/smtp_mock.php', (string) $port, $log, $modeFile], [], self::$work . "/$name.log");
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
            if ($s) {
                fclose($s);
                return $port;
            }
            usleep(100000);
        }
        throw new \RuntimeException("smtp mock $name no arranco");
    }

    /** Lineas JSON de un log de mock. @return list<array> */
    public static function readLog(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 'is_array'));
    }

    public static function stop(string $name): void
    {
        if (!isset(self::$procs[$name])) {
            return;
        }
        $p = self::$procs[$name];
        @proc_terminate($p['proc'], 15);
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline && (proc_get_status($p['proc'])['running'] ?? false)) {
            usleep(100000);
        }
        if (proc_get_status($p['proc'])['running'] ?? false) {
            @proc_terminate($p['proc'], 9);
        }
        @proc_close($p['proc']);
        unset(self::$procs[$name]);
    }

    public static function stopAll(): void
    {
        foreach (array_keys(self::$procs) as $n) {
            if ($n !== 'mariadb') {
                self::stop($n);
            }
        }
        self::stop('mariadb');
    }

    /** Ejecuta un script PHP por CLI con entorno propio. @return array{code:int,out:string} */
    public static function cli(array $args, array $env = [], ?string $stdin = null): array
    {
        $cmd = array_merge([PHP_BINARY, '-d', 'display_errors=0'], $args);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($proc), 'out' => $out];
    }

    // --- Cliente HTTP ----------------------------------------------------------------------------------------

    /**
     * @param array{headers?:list<string>,form?:array,json?:mixed,raw?:string,jar?:string,timeout?:int} $o
     * @return array{status:int,headers:array<string,list<string>>,body:string}
     */
    public static function http(string $method, string $url, array $o = []): array
    {
        $ch = curl_init($url);
        $headers = $o['headers'] ?? [];
        $body = null;
        if (isset($o['form'])) {
            $body = http_build_query($o['form']);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (array_key_exists('json', $o)) {
            $body = json_encode($o['json']);
            $headers[] = 'Content-Type: application/json';
        } elseif (isset($o['raw'])) {
            $body = $o['raw'];
        }
        $resp = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $o['timeout'] ?? 30,
            CURLOPT_PROXY          => '',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$resp): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $resp[strtolower(trim($k))][] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (isset($o['jar'])) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $o['jar']);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $o['jar']);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $resp, 'body' => is_string($out) ? $out : ''];
    }

    public static function header(array $r, string $name): string
    {
        return $r['headers'][strtolower($name)][0] ?? '';
    }
}
