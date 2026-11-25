<?php
/**
 * Cliente IMAP mínimo y de SOLO LECTURA (fase AT) para ProspectReplies: LOGIN, EXAMINE (nunca SELECT: el buzón queda intacto), UID SEARCH y UID FETCH de
 * cabeceras con BODY.PEEK (no marca como leído). Sin dependencias (la extensión imap de PHP ya no viene en PHP 8.4 y no está garantizada en Hostinger).
 */

declare(strict_types=1);

namespace Captacion\Services;

final class Imap
{
    /** @var resource|null */
    private $s = null;
    private int $tag = 0;

    public function connect(string $host, int $port, string $secure = 'ssl', int $timeout = 15): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $err, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            throw new \RuntimeException('No se pudo conectar con el buzón (' . $host . ':' . $port . '): ' . $err);
        }
        stream_set_timeout($s, $timeout);
        $this->s = $s;
        $hello = (string) fgets($s);
        if (!str_starts_with($hello, '* OK')) {
            throw new \RuntimeException('El buzón no saludó como un servidor IMAP.');
        }
    }

    private static function quote(string $v): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }

    /** @return list<array{line:string,lit:?string}> respuestas sin etiqueta; lanza si el comando no termina en OK */
    private function cmd(string $c): array
    {
        if ($this->s === null) {
            throw new \RuntimeException('Sin conexión con el buzón.');
        }
        $t = 'A' . (++$this->tag);
        fwrite($this->s, $t . ' ' . $c . "\r\n");
        $out = [];
        while (true) {
            $line = fgets($this->s);
            if ($line === false) {
                throw new \RuntimeException('El buzón cerró la conexión.');
            }
            if (str_starts_with($line, $t . ' ')) {
                if (!preg_match('/^' . $t . ' OK/i', $line)) {
                    throw new \RuntimeException('El buzón respondió: ' . trim(substr($line, strlen($t) + 1, 120)));
                }
                return $out;
            }
            $lit = null;
            if (preg_match('/\{(\d{1,7})\}\r?\n$/', $line, $m) === 1) {
                $n = (int) $m[1];
                $lit = '';
                while (strlen($lit) < $n) {
                    $chunk = fread($this->s, $n - strlen($lit));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('Respuesta del buzón incompleta.');
                    }
                    $lit .= $chunk;
                }
            }
            $out[] = ['line' => $line, 'lit' => $lit];
        }
    }

    public function login(string $user, string $pass): void
    {
        $this->cmd('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
    }

    /** @return int UIDVALIDITY del buzón */
    public function examine(string $box): int
    {
        foreach ($this->cmd('EXAMINE ' . self::quote($box)) as $r) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $r['line'], $m) === 1) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /** @return list<int> */
    public function uidSearch(string $criteria): array
    {
        $ids = [];
        foreach ($this->cmd('UID SEARCH ' . $criteria) as $r) {
            if (preg_match('/^\* SEARCH ?([\d ]*)/i', $r['line'], $m) === 1) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $u) {
                    if ($u !== '' && ctype_digit($u)) {
                        $ids[] = (int) $u;
                    }
                }
            }
        }
        return $ids;
    }

    /** @param list<int> $uids @param list<string> $fields @return array<int,string> uid => cabeceras crudas */
    public function fetchHeaders(array $uids, array $fields): array
    {
        $out = [];
        foreach (array_chunk($uids, 50) as $chunk) {
            foreach ($this->cmd('UID FETCH ' . implode(',', $chunk) . ' (UID BODY.PEEK[HEADER.FIELDS (' . implode(' ', $fields) . ')])') as $r) {
                if ($r['lit'] !== null && preg_match('/UID (\d+)/i', $r['line'], $m) === 1) {
                    $out[(int) $m[1]] = $r['lit'];
                }
            }
        }
        return $out;
    }

    public function logout(): void
    {
        try {
            $this->cmd('LOGOUT');
        } catch (\Throwable) {
            // da igual: se cierra igual
        }
        $this->close();
    }

    public function close(): void
    {
        if ($this->s !== null) {
            @fclose($this->s);
            $this->s = null;
        }
    }
}
