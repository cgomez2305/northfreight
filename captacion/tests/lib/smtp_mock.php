<?php
/**
 * Servidor SMTP falso para las pruebas (CLI): php smtp_mock.php PUERTO LOG [ARCHIVO_DE_MODO]. Acepta EHLO, AUTH LOGIN/PLAIN, MAIL, RCPT, DATA, RSET, QUIT y guarda cada mensaje
 * (remitente, destinatarios y cuerpo crudo) como una linea JSON en LOG. Si ARCHIVO_DE_MODO contiene "reject", responde 550 al RCPT (simula un SMTP que rechaza).
 * No envia nada a ninguna parte.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
[, $port, $log] = $argv + [null, '0', '/dev/null'];
$modeFile = $argv[3] ?? '';
$srv = stream_socket_server('tcp://127.0.0.1:' . (int) $port, $errno, $err);
if ($srv === false) {
    fwrite(STDERR, "no se pudo abrir el puerto: $err\n");
    exit(1);
}
while (true) {
    $c = @stream_socket_accept($srv, 3600);
    if ($c === false) {
        continue;
    }
    stream_set_timeout($c, 15);
    $send = static fn(string $s) => fwrite($c, $s . "\r\n");
    $send('220 mock ESMTP');
    $from = '';
    $to = [];
    $data = false;
    $raw = '';
    $mode = static fn() => $modeFile !== '' && is_file($modeFile) ? trim((string) file_get_contents($modeFile)) : 'ok';
    while (($line = fgets($c)) !== false) {
        if ($data) {
            if ($line === ".\r\n") {
                $data = false;
                file_put_contents($log, json_encode(['from' => $from, 'to' => $to, 'raw' => $raw], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
                $send('250 OK queued');
                $raw = '';
                $to = [];
                continue;
            }
            $raw .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            continue;
        }
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            fwrite($c, "250-mock\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n");
        } elseif ($cmd === 'AUTH') {
            if (stripos($line, 'AUTH LOGIN') === 0 && trim($line) === 'AUTH LOGIN') {
                $send('334 VXNlcm5hbWU6');
                fgets($c);
                $send('334 UGFzc3dvcmQ6');
                fgets($c);
            }
            $send('235 2.7.0 Authentication successful');
        } elseif ($cmd === 'MAIL') {
            preg_match('/<([^>]*)>/', $line, $m);
            $from = $m[1] ?? '';
            $send('250 OK');
        } elseif ($cmd === 'RCPT') {
            if ($mode() === 'reject') {
                $send('550 5.1.1 Recipient rejected');
                continue;
            }
            preg_match('/<([^>]*)>/', $line, $m);
            $to[] = $m[1] ?? '';
            $send('250 OK');
        } elseif ($cmd === 'DATA') {
            $data = true;
            $send('354 End data with <CR><LF>.<CR><LF>');
        } elseif ($cmd === 'RSET' || $cmd === 'NOOP') {
            $send('250 OK');
        } elseif ($cmd === 'QUIT') {
            $send('221 Bye');
            break;
        } else {
            $send('250 OK');
        }
    }
    fclose($c);
}
