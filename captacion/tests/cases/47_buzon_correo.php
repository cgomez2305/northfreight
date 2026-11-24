<?php
declare(strict_types=1);

use CaptacionTests\T;

/**
 * Buzón contact@ (2026-11-23): el servidor guarda el buzón SMTP en archivos aparte, fuera de git (app/config.mail.php y
 * captacion/config/mail.env), que ganan a config.local.php y al .env SOLO en las claves de correo. Se prueba en procesos hijos
 * para no tocar el Env del runner (un SMTP_USER de prueba haría que otros casos intentaran enviar).
 */
return static function (array $ctx): void {
    $root = $ctx['root'];
    $repo = dirname($root);
    $tmp = sys_get_temp_dir() . '/ns-buzon-' . bin2hex(random_bytes(4));
    @mkdir($tmp . '/config', 0700, true);
    @mkdir($tmp . '/app', 0700, true);
    $run = static function (string $code): array {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 -r ' . escapeshellarg($code) . ' 2>&1', $out);
        return [implode("\n", $out), json_decode((string) end($out), true)];
    };

    // ------------------------------------------------------------------ CRM: config/mail.env junto al .env
    file_put_contents($tmp . '/config/.env', "APP_KEY=abc\nSMTP_USER=viejo@example.com\nSMTP_PASS=viejo\nMAIL_TO=viejo@example.com\n");
    file_put_contents($tmp . '/config/mail.env', "SMTP_USER=contact@example.com\nSMTP_PASS=\"n#ueva x\"\nMAIL_TO=contact@example.com\nAPP_KEY=pisado\nDB_PASS=pisado\n");
    $env = var_export($root . '/src/Env.php', true);
    $code = 'define("CAPTACION_ROOT", ' . var_export($tmp, true) . '); require ' . $env . '; \Captacion\Env::load(' . var_export($tmp . '/config/.env', true) . ');'
        . ' echo json_encode(array_map(fn($k) => \Captacion\Env::get($k), ["SMTP_USER" => "SMTP_USER", "SMTP_PASS" => "SMTP_PASS", "MAIL_TO" => "MAIL_TO", "APP_KEY" => "APP_KEY", "DB_PASS" => "DB_PASS"]));';
    [, $v] = $run($code);
    T::eq($v['SMTP_USER'] ?? null, 'contact@example.com', 'CRM: mail.env gana al .env en el buzón SMTP');
    T::eq($v['SMTP_PASS'] ?? null, 'n#ueva x', '  la contraseña entre comillas conserva # y espacios');
    T::eq($v['MAIL_TO'] ?? null, 'contact@example.com', '  y en el destino de los avisos');
    T::eq($v['APP_KEY'] ?? null, 'abc', '  pero NO puede pisar otras claves (APP_KEY)');
    T::eq($v['DB_PASS'] ?? null, null, '  ni añadir claves que no son de correo');
    unlink($tmp . '/config/mail.env');
    [, $v] = $run($code);
    T::eq($v['SMTP_USER'] ?? null, 'viejo@example.com', '  sin mail.env todo sigue como estaba');

    // ------------------------------------------------------------------ Sitio: app/config.mail.php
    copy($repo . '/app/config.php', $tmp . '/app/config.php');
    file_put_contents($tmp . '/app/config.local.php', "<?php\ndefine('ENV', 'staging');\ndefine('SMTP_USER', 'viejo@example.com');\ndefine('MAIL_TO', 'viejo@example.com');\n");
    file_put_contents($tmp . '/app/config.mail.php', "<?php\nreturn ['SMTP_USER' => 'contact@example.com', 'SMTP_PASS' => 'x', 'MAIL_TO' => 'contact@example.com', 'MAIL_FROM' => 'contact@example.com', 'ENV' => 'production', 'SMTP_PORT' => 465];\n");
    $site = 'require ' . var_export($tmp . '/app/config.php', true) . '; echo "\n", json_encode(["u" => SMTP_USER, "to" => MAIL_TO, "from" => MAIL_FROM, "env" => ENV, "port" => SMTP_PORT, "email" => EMAIL]);';
    [$raw, $v] = $run($site);
    T::eq($v['u'] ?? null, 'contact@example.com', 'sitio: config.mail.php gana a config.local.php en SMTP_USER');
    T::eq([$v['to'] ?? null, $v['from'] ?? null, $v['port'] ?? null], ['contact@example.com', 'contact@example.com', 465], '  y en MAIL_TO, MAIL_FROM y SMTP_PORT');
    T::eq($v['env'] ?? null, 'staging', '  pero no puede cambiar ENV (solo claves de correo)');
    T::ok(!str_contains($raw, 'already defined') && !str_contains($raw, 'Warning'), '  sin avisos «Constant already defined» por las claves que repite config.local.php');
    T::eq($v['email'] ?? null, 'contact@northstarfreightsolutions.us', 'correo visible por defecto: contact@ del dominio');
    file_put_contents($tmp . '/app/config.local.php', "<?php\ndefine('ENV', 'staging');\ndefine('ENV', 'otra');\n");
    [$raw] = $run($site);
    T::ok(str_contains($raw, 'already defined'), '  un aviso de otra clave repetida en config.local.php sigue saliendo (solo se calla el de las claves de correo)');
    unlink($tmp . '/app/config.mail.php');
    file_put_contents($tmp . '/app/config.local.php', "<?php\ndefine('SMTP_USER', 'viejo@example.com');\n");
    [, $v] = $run($site);
    T::eq($v['u'] ?? null, 'viejo@example.com', '  sin config.mail.php manda config.local.php como siempre');

    // ------------------------------------------------------------------ Nunca en git
    $gi = (string) file_get_contents($repo . '/.gitignore');
    T::ok(str_contains($gi, '/app/config.mail.php') && str_contains($gi, '/captacion/config/mail.env'), 'los dos archivos del buzón están en .gitignore');
    array_map('unlink', glob($tmp . '/{app,config}/*', GLOB_BRACE) ?: []);
    @rmdir($tmp . '/app'); @rmdir($tmp . '/config'); @rmdir($tmp);
};
