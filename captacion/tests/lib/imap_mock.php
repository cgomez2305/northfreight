<?php
/**
 * Servidor IMAP falso para las pruebas (CLI, fase AT): php imap_mock.php PUERTO MENSAJES.json LOG. Atiende LOGIN, EXAMINE, UID SEARCH, UID FETCH y LOGOUT
 * con los mensajes de MENSAJES.json ([{uid, headers}], se relee en cada orden) y anota cada orden en LOG (para comprobar que nunca se usa SELECT ni STORE).
 * No toca ningún buzón real.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
[, $port, $msgFile, $log] = $argv + [null, '0', '', '/dev/null'];
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
    fwrite($c, "* OK IMAP4rev1 mock ready\r\n");
    while (($line = fgets($c)) !== false) {
        file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
        if (preg_match('/^(\S+) (.*)\r?\n$/', $line, $m) !== 1) {
            continue;
        }
        [$tag, $cmd] = [$m[1], $m[2]];
        $msgs = is_file($msgFile) ? (json_decode((string) file_get_contents($msgFile), true) ?: []) : [];
        if (stripos($cmd, 'LOGIN ') === 0) {
            fwrite($c, str_contains($cmd, '"mal"') ? "$tag NO [AUTHENTICATIONFAILED] Invalid credentials\r\n" : "$tag OK LOGIN completed\r\n");
        } elseif (stripos($cmd, 'EXAMINE ') === 0) {
            fwrite($c, '* ' . count($msgs) . " EXISTS\r\n* OK [UIDVALIDITY 77] UIDs valid\r\n$tag OK [READ-ONLY] EXAMINE completed\r\n");
        } elseif (stripos($cmd, 'UID SEARCH') === 0) {
            $min = preg_match('/UID (\d+):\*/i', $cmd, $u) === 1 ? (int) $u[1] : 0;
            $ids = array_map(static fn($x) => (int) $x['uid'], array_filter($msgs, static fn($x) => (int) $x['uid'] >= $min));
            if (!$ids && $min > 0 && $msgs) {   // como un servidor real: «N:*» devuelve al menos el último
                $ids = [max(array_map(static fn($x) => (int) $x['uid'], $msgs))];
            }
            fwrite($c, '* SEARCH' . ($ids ? ' ' . implode(' ', $ids) : '') . "\r\n$tag OK SEARCH completed\r\n");
        } elseif (stripos($cmd, 'UID FETCH ') === 0) {
            $want = preg_match('/UID FETCH ([\d,]+)/i', $cmd, $f) === 1 ? array_map('intval', explode(',', $f[1])) : [];
            $i = 0;
            foreach ($msgs as $x) {
                $i++;
                if (!in_array((int) $x['uid'], $want, true)) {
                    continue;
                }
                $h = str_replace("\n", "\r\n", rtrim((string) $x['headers'])) . "\r\n\r\n";
                fwrite($c, "* $i FETCH (UID {$x['uid']} BODY[HEADER.FIELDS (FROM DATE SUBJECT)] {" . strlen($h) . "}\r\n" . $h . ")\r\n");
            }
            fwrite($c, "$tag OK FETCH completed\r\n");
        } elseif (stripos($cmd, 'LOGOUT') === 0) {
            fwrite($c, "* BYE\r\n$tag OK LOGOUT completed\r\n");
            break;
        } else {
            fwrite($c, "$tag BAD unsupported in mock\r\n");
        }
    }
    @fclose($c);
}
