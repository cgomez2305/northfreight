<?php
/**
 * «Deshacer» (Q5a-G): avisos con un boton de 6 s para las acciones reversibles. El servidor emite un token firmado (HMAC con APP_KEY, atado al usuario y con caducidad de 10 min) que describe
 * QUE se deshace; el cliente solo lo devuelve. Al aplicarlo se vuelven a validar las condiciones actuales (el lead sigue en la etapa, la tarea sigue hecha, la nota sigue borrada),
 * con las mismas funciones que el resto del panel, y queda evento en el historial. Reversibles: cambio de etapa (individual, tablero y masivo; no el que genera una entrega a NorthStar),
 * completar una tarea y borrar una nota.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;

final class Undo
{
    public const TTL = 600;

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @param array<string,mixed> $data */
    private static function sign(string $kind, int $userId, array $data): string
    {
        $p = self::b64((string) json_encode(['k' => $kind, 'u' => $userId, 'x' => Clock::time() + self::TTL, 'd' => $data], JSON_UNESCAPED_SLASHES));
        return $p . '.' . self::b64(hash_hmac('sha256', 'undo|' . $p, Crypto::key(), true));
    }

    /** Cambio de etapa de un lead; null si no es reversible. */
    public static function stage(int $userId, int $leadId, string $from, string $to): ?string
    {
        return LeadActions::undoable($from, $to) ? self::sign('stage', $userId, ['l' => $leadId, 'f' => $from, 't' => $to]) : null;
    }

    /** Cambio masivo: solo los reversibles. @param list<array{0:int,1:string,2:string}> $changes */
    public static function bulk(int $userId, array $changes): ?string
    {
        $items = [];
        foreach ($changes as [$id, $from, $to]) {
            if (LeadActions::undoable((string) $from, (string) $to)) {
                $items[] = [(int) $id, (string) $from, (string) $to];
            }
        }
        return $items ? self::sign('bulk', $userId, ['i' => $items]) : null;
    }

    public static function task(int $userId, int $taskId): string
    {
        return self::sign('task', $userId, ['t' => $taskId]);
    }

    public static function note(int $userId, int $leadId, int $noteId): string
    {
        return self::sign('note', $userId, ['l' => $leadId, 'n' => $noteId]);
    }

    /** @return ?array{k:string,d:array} carga del token si es autentico, del usuario y no ha caducado */
    public static function verify(string $token, int $userId): ?array
    {
        if (preg_match('/^([A-Za-z0-9_-]{10,6000})\.([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            return null;
        }
        if (!hash_equals(self::b64(hash_hmac('sha256', 'undo|' . $m[1], Crypto::key(), true)), $m[2])) {
            return null;
        }
        $raw = base64_decode(strtr($m[1], '-_', '+/'), true);
        $p = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($p) || ($p['u'] ?? 0) !== $userId || ($p['x'] ?? 0) < Clock::time() || !is_string($p['k'] ?? null) || !is_array($p['d'] ?? null)) {
            return null;
        }
        return ['k' => $p['k'], 'd' => $p['d']];
    }

    /** @return array{ok:bool,msg:string,lead_ids?:list<int>} */
    public static function apply(string $token, int $userId): array
    {
        $p = self::verify($token, $userId);
        if ($p === null) {
            return ['ok' => false, 'msg' => 'Ya no se puede deshacer (el aviso caducó o no es válido).'];
        }
        $d = $p['d'];
        switch ($p['k']) {
            case 'stage':
                $r = LeadActions::undoStage((int) ($d['l'] ?? 0), (string) ($d['f'] ?? ''), (string) ($d['t'] ?? ''), $userId);
                return $r + ['lead_ids' => [(int) ($d['l'] ?? 0)]];
            case 'bulk':
                $ok = 0;
                $skip = 0;
                $ids = [];
                foreach ((array) ($d['i'] ?? []) as $it) {
                    $r = is_array($it) && count($it) === 3 ? LeadActions::undoStage((int) $it[0], (string) $it[1], (string) $it[2], $userId) : ['ok' => false];
                    if ($r['ok']) {
                        $ok++;
                        $ids[] = (int) $it[0];
                    } else {
                        $skip++;
                    }
                }
                return ['ok' => $ok > 0, 'msg' => $ok . ($ok === 1 ? ' cambio deshecho' : ' cambios deshechos') . ($skip > 0 ? ' · ' . $skip . ' no se pudieron deshacer (ya habían cambiado)' : '') . '.', 'lead_ids' => $ids];
            case 'task':
                return Tasks::reopen((int) ($d['t'] ?? 0), $userId);
            case 'note':
                return LeadActions::restoreNote((int) ($d['l'] ?? 0), (int) ($d['n'] ?? 0), $userId);
        }
        return ['ok' => false, 'msg' => 'No se reconoce qué deshacer.'];
    }
}
