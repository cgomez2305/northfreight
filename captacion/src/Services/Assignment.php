<?php
/**
 * Responsable del lead (fase AM). Columna `leads.assigned_to` (id de usuario, NULL = sin asignar). Solo se asigna a administradores ACTIVOS: no hay un rol nuevo
 * (el equipo es pequeno y un rol nuevo obligaria a rehacer la matriz de autorizacion). Cada cambio deja un evento `assigned` en la linea de tiempo (con los nombres
 * dentro del payload, para que siga legible aunque el usuario se desactive) y un registro en activity_log.
 *
 * Reparto automatico (OPCIONAL, apagado por defecto, ajuste `assign` = {auto, users, last}): al CREAR un lead nuevo, round-robin entre los administradores marcados como
 * «reciben leads». El cursor `last` guarda el ultimo id repartido, asi que el orden es estable aunque cambie la lista. Un reingreso conserva el responsable y las
 * altas con `quiet` (importaciones y alta manual) no se reparten. LeadIngest::create lo llama dentro de su transaccion y bajo el candado del motor: nunca rompe la alta.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;
use Captacion\Models\Lead;

final class Assignment
{
    public const KEY = 'assign';
    public const MAX_BULK = 500;

    /** Administradores activos a los que se puede asignar. @return array<int,string> id => nombre */
    public static function admins(): array
    {
        $out = [];
        foreach (Db::all("SELECT id, name FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL ORDER BY name ASC, id ASC") as $u) {
            $out[(int) $u['id']] = (string) $u['name'];
        }
        return $out;
    }

    public static function assignable(int $userId): bool
    {
        return isset(self::admins()[$userId]);
    }

    /** Nombre de un usuario (aunque ya no este activo) o null. */
    public static function name(?int $userId): ?string
    {
        if ($userId === null || $userId < 1) {
            return null;
        }
        $n = Db::value('SELECT name FROM users WHERE id = ?', [$userId]);
        return $n !== null ? (string) $n : null;
    }

    /** @return array{auto:bool,users:list<int>,last:int} */
    public static function config(): array
    {
        try {
            $c = Settings::get(self::KEY, []);
        } catch (\Throwable) {
            $c = [];
        }
        $c = is_array($c) ? $c : [];
        $users = array_values(array_unique(array_filter(array_map('intval', (array) ($c['users'] ?? [])), static fn($i) => $i > 0)));
        sort($users);
        return ['auto' => (bool) ($c['auto'] ?? false), 'users' => $users, 'last' => max(0, (int) ($c['last'] ?? 0))];
    }

    /** Valida el formulario de Configuracion > Alertas (campos assign_auto y assign_users[]). @return array{ok:bool,errors:array<string,string>,values:array{auto:bool,users:list<int>,last:int}} */
    public static function validate(array $in): array
    {
        $auto = ($in['assign_auto'] ?? '') === '1';
        $admins = self::admins();
        $users = [];
        foreach ((array) ($in['assign_users'] ?? []) as $v) {
            if (is_string($v) && ctype_digit($v) && isset($admins[(int) $v])) {
                $users[] = (int) $v;
            }
        }
        $users = array_values(array_unique($users));
        sort($users);
        $err = [];
        if ($auto && !$users) {
            $err['assign_users'] = 'Marca al menos un administrador que reciba leads, o apaga el reparto automático.';
        }
        return ['ok' => !$err, 'errors' => $err, 'values' => ['auto' => $auto, 'users' => $users, 'last' => self::config()['last']]];
    }

    /** Siguiente administrador del reparto (o null si esta apagado o no hay nadie valido) y avanza el cursor. */
    public static function pickNext(): ?int
    {
        $c = self::config();
        if (!$c['auto']) {
            return null;
        }
        $admins = self::admins();
        $valid = array_values(array_filter($c['users'], static fn($i) => isset($admins[$i])));
        if (!$valid) {
            return null;
        }
        $next = $valid[0];
        foreach ($valid as $id) {
            if ($id > $c['last']) {
                $next = $id;
                break;
            }
        }
        Settings::set(self::KEY, ['auto' => true, 'users' => $c['users'], 'last' => $next]);
        return $next;
    }

    /** Evento `assigned` de la linea de tiempo. $userId = quien lo hizo (null = reparto automatico). */
    private static function event(int $leadId, ?int $from, ?int $to, ?int $userId, bool $auto): void
    {
        LeadActions::event($leadId, 'assigned', array_filter([
            'from' => $from, 'to' => $to, 'from_name' => self::name($from), 'to_name' => self::name($to), 'auto' => $auto ? true : null,
        ], static fn($v) => $v !== null), $userId);
    }

    /** @return array{ok:bool,msg:string,changed:bool,from:?int,to:?int} */
    public static function assign(int $leadId, ?int $to, int $by): array
    {
        $r = Db::transaction(static fn(): array => self::apply($leadId, $to, $by));
        if ($r['changed']) {
            ActivityLog::record('lead_assign', $by, 'lead', $leadId, ['from' => $r['from'], 'to' => $r['to']]);
        }
        return $r;
    }

    /** Dentro de una transaccion: valida, escribe y deja el evento. No registra en activity_log (lo hace quien llama). */
    private static function apply(int $leadId, ?int $to, int $by): array
    {
        $lead = Lead::find($leadId, true);
        if ($lead === null) {
            return ['ok' => false, 'msg' => 'El lead no existe.', 'changed' => false, 'from' => null, 'to' => null];
        }
        if ($to !== null && !self::assignable($to)) {
            return ['ok' => false, 'msg' => 'Solo se puede asignar a administradores activos.', 'changed' => false, 'from' => null, 'to' => null];
        }
        $from = $lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : null;
        if ($from === $to) {
            return ['ok' => true, 'msg' => $to === null ? 'El lead ya estaba sin asignar.' : 'El lead ya era de ' . (self::name($to) ?? 'esa persona') . '.', 'changed' => false, 'from' => $from, 'to' => $to];
        }
        Db::update('leads', $leadId, ['assigned_to' => $to]);
        self::event($leadId, $from, $to, $by, false);
        return ['ok' => true, 'msg' => $to === null ? 'Lead sin asignar.' : 'Lead asignado a ' . (self::name($to) ?? 'esa persona') . '.', 'changed' => true, 'from' => $from, 'to' => $to];
    }

    /**
     * Asignacion masiva (barra de seleccion de Lista y Tabla). Un solo registro en activity_log; un evento por lead.
     * @param list<int> $ids
     * @return array{ok:bool,msg:string,changed:int,skipped:int,changes:list<array{0:int,1:int,2:int}>} changes = [lead, antes (0 = nadie), despues (0 = nadie)] para «Deshacer»
     */
    public static function bulkAssign(array $ids, ?int $to, int $by): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0))), 0, self::MAX_BULK);
        if (!$ids) {
            return ['ok' => false, 'msg' => 'Selecciona al menos un lead.', 'changed' => 0, 'skipped' => 0, 'changes' => []];
        }
        if ($to !== null && !self::assignable($to)) {
            return ['ok' => false, 'msg' => 'Solo se puede asignar a administradores activos.', 'changed' => 0, 'skipped' => 0, 'changes' => []];
        }
        $changes = [];
        $skipped = 0;
        Db::transaction(static function () use ($ids, $to, $by, &$changes, &$skipped): void {
            foreach ($ids as $id) {
                $r = self::apply($id, $to, $by);
                if ($r['changed']) {
                    $changes[] = [$id, (int) ($r['from'] ?? 0), (int) ($r['to'] ?? 0)];
                } else {
                    $skipped++;
                }
            }
        });
        $n = count($changes);
        if ($n > 0) {
            ActivityLog::record('leads_bulk_assign', $by, 'lead', null, ['to' => $to, 'changed' => $n, 'skipped' => $skipped, 'ids' => count($ids)]);
        }
        $who = $to === null ? 'sin responsable' : 'a ' . (self::name($to) ?? 'esa persona');
        return ['ok' => $n > 0 || $skipped > 0, 'msg' => $n === 0 ? 'Ningún cambio: los leads ya estaban ' . ($to === null ? 'sin asignar' : 'con esa persona') . '.' : $n . ($n === 1 ? ' lead asignado ' : ' leads asignados ') . $who . ($skipped > 0 ? ' · ' . $skipped . ' sin cambios' : '') . '.',
            'changed' => $n, 'skipped' => $skipped, 'changes' => $changes];
    }

    /** «Deshacer» de la asignacion masiva: solo si el lead sigue con el responsable que se puso (nadie lo movio despues). @return array{ok:bool,msg:string,lead_ids:list<int>} */
    public static function undo(array $items, int $by): array
    {
        $ok = 0;
        $skip = 0;
        $ids = [];
        foreach ($items as $it) {
            if (!is_array($it) || count($it) !== 3) {
                $skip++;
                continue;
            }
            [$id, $from, $to] = [(int) $it[0], (int) $it[1], (int) $it[2]];
            $done = Db::transaction(static function () use ($id, $from, $to, $by): bool {
                $lead = Lead::find($id, true);
                if ($lead === null || (int) ($lead['assigned_to'] ?? 0) !== $to) {
                    return false;
                }
                $back = $from > 0 && self::assignable($from) ? $from : null;
                Db::update('leads', $id, ['assigned_to' => $back]);
                LeadActions::event($id, 'assigned', array_filter(['from' => $to > 0 ? $to : null, 'to' => $back, 'from_name' => self::name($to), 'to_name' => self::name($back), 'undo' => true], static fn($v) => $v !== null), $by);
                return true;
            });
            if ($done) {
                $ok++;
                $ids[] = $id;
            } else {
                $skip++;
            }
        }
        if ($ok > 0) {
            ActivityLog::record('leads_assign_undo', $by, 'lead', null, ['undone' => $ok, 'skipped' => $skip]);
        }
        return ['ok' => $ok > 0, 'msg' => $ok . ($ok === 1 ? ' asignación deshecha' : ' asignaciones deshechas') . ($skip > 0 ? ' · ' . $skip . ' no se pudieron deshacer (ya habían cambiado)' : '') . '.', 'lead_ids' => $ids];
    }
}
