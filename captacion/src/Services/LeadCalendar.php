<?php
/**
 * Calendario de seguimiento (Q5c-C): mes, semana y dia. Une, para el RANGO VISIBLE (nunca todo el historico), lo que tiene fecha:
 *   - tareas (por vencer, vencidas y hechas), citas de Cal.com (`appointments`), primer contacto pendiente (leads nuevos sin contactar, el dia que llegaron),
 *   - proximos pasos de secuencias de correo (`sequence_enrollments.next_run_at`) y entregas a NorthStar programadas (jobs `handoff_deliver` pendientes).
 * Usa los MISMOS filtros de lead que la Lista (LeadQuery::where): con algun filtro solo entran eventos de leads que cumplen; sin filtros entran ademas las tareas sin lead.
 *
 * Las horas y los limites de dia se calculan en la zona de visualizacion (Configuracion > Horario y zona; ET por defecto) con DateTimeImmutable, asi que un dia de cambio de horario
 * de verano mide 23 o 25 horas y un evento de las 23:30 locales cae en SU dia aunque en UTC ya sea el siguiente. Cada tipo de evento tiene un tope (CAP) y, si se alcanza,
 * `truncated` avisa a la pantalla («hay mas: acota con un filtro»).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class LeadCalendar
{
    /** clave => [etiqueta, icono, tono semantico (clase .ev--<tono> del tema)] */
    public const TYPES = [
        'task'    => ['Tareas', 'list-check', 'task'],
        'appt'    => ['Citas', 'calendar', 'appt'],
        'first'   => ['Primer contacto', 'phone', 'first'],
        'seq'     => ['Secuencias', 'mail', 'seq'],
        'handoff' => ['Entregas', 'send', 'handoff'],
    ];
    public const MODES = ['mes' => 'Mes', 'semana' => 'Semana', 'dia' => 'Día'];
    public const CAP = 250;

    /** Tipos de evento validos de una lista (`ev` de la URL); vacio = todos. @return list<string> */
    public static function types(mixed $raw): array
    {
        $in = is_string($raw) ? explode(',', $raw) : [];
        $out = array_values(array_filter(array_keys(self::TYPES), static fn($k) => in_array($k, $in, true)));
        return $out ?: array_keys(self::TYPES);
    }

    public static function mode(mixed $m, string $fallback = 'mes'): string
    {
        return is_string($m) && isset(self::MODES[$m]) ? $m : (isset(self::MODES[$fallback]) ? $fallback : 'mes');
    }

    /** 'AAAA-MM-DD' valido (fecha real) o null. */
    public static function ymd(mixed $v): ?string
    {
        return is_string($v) && Fmt::fromEtInput(trim($v)) !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) === 1 ? trim($v) : null;
    }

    /**
     * Dias de la cuadricula visible (con sus limites en UTC). Mes: semanas completas (lunes a domingo) que cubren el mes; semana: 7 dias; dia: 1.
     * @return array{days:list<array{ymd:string,day:int,dow:int,start:int,end:int,out:bool}>,from:int,to:int,anchor:\DateTimeImmutable}
     */
    public static function grid(string $mode, string $anchorYmd): array
    {
        $tz = Fmt::tz();
        $a = new \DateTimeImmutable($anchorYmd . ' 00:00:00', $tz);
        if ($mode === 'mes') {
            $first = $a->modify('first day of this month')->setTime(0, 0);
            $last = $a->modify('last day of this month')->setTime(0, 0);
            $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days')->setTime(0, 0);
            $end = $last->modify('+' . (7 - (int) $last->format('N')) . ' days')->setTime(0, 0);
        } elseif ($mode === 'semana') {
            $start = $a->modify('-' . ((int) $a->format('N') - 1) . ' days')->setTime(0, 0);
            $end = $start->modify('+6 days')->setTime(0, 0);
        } else {
            $start = $a;
            $end = $a;
        }
        $days = [];
        $month = $a->format('Y-m');
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')->setTime(0, 0)) {
            $next = $d->modify('+1 day')->setTime(0, 0);
            $days[] = ['ymd' => $d->format('Y-m-d'), 'day' => (int) $d->format('j'), 'dow' => (int) $d->format('N'), 'start' => $d->getTimestamp(), 'end' => $next->getTimestamp(), 'out' => $mode === 'mes' && $d->format('Y-m') !== $month];
        }
        return ['days' => $days, 'from' => $days[0]['start'], 'to' => $days[count($days) - 1]['end'], 'anchor' => $a];
    }

    /**
     * Todo lo de la pantalla: cuadricula con los eventos de cada dia, navegacion y avisos.
     * @param array<string,string> $f filtros de LeadQuery @param list<string> $types
     * @return array<string,mixed>
     */
    public static function view(string $mode, string $anchorYmd, array $f, array $types, int $now): array
    {
        $g = self::grid($mode, $anchorYmd);
        $res = self::events($f, $types, $g['from'], $g['to'], $now);
        $by = [];
        foreach ($res['events'] as $ev) {
            $by[Fmt::et((string) $ev['at'])->format('Y-m-d')][] = $ev;
        }
        $today = Fmt::et($now)->format('Y-m-d');
        $days = [];
        foreach ($g['days'] as $d) {
            $items = $by[$d['ymd']] ?? [];
            usort($items, static fn($a, $b) => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
            $days[] = $d + ['today' => $d['ymd'] === $today, 'events' => $items, 'label' => Fmt::weekdayLong($d['dow']) . ' ' . $d['day'] . ' de ' . Fmt::monthLong((int) substr($d['ymd'], 5, 2))];
        }
        $a = $g['anchor'];
        if ($mode === 'mes') {
            $prev = $a->modify('first day of previous month')->format('Y-m-d');
            $next = $a->modify('first day of next month')->format('Y-m-d');
            $title = ucfirst(Fmt::monthLong((int) $a->format('n'))) . ' ' . $a->format('Y');
        } elseif ($mode === 'semana') {
            $s = $g['days'][0]['ymd'];
            $e = $g['days'][6]['ymd'];
            $prev = $a->modify('-7 days')->format('Y-m-d');
            $next = $a->modify('+7 days')->format('Y-m-d');
            $title = (int) substr($s, 8, 2) . ' ' . Fmt::monthLong((int) substr($s, 5, 2)) . ' a ' . (int) substr($e, 8, 2) . ' ' . Fmt::monthLong((int) substr($e, 5, 2)) . ' ' . substr($e, 0, 4);
        } else {
            $prev = $a->modify('-1 day')->format('Y-m-d');
            $next = $a->modify('+1 day')->format('Y-m-d');
            $title = ucfirst(Fmt::weekdayLong((int) $a->format('N'))) . ' ' . (int) $a->format('j') . ' de ' . Fmt::monthLong((int) $a->format('n')) . ' ' . $a->format('Y');
        }
        return ['mode' => $mode, 'anchor' => $anchorYmd, 'title' => $title, 'prev' => $prev, 'next' => $next, 'today' => $today, 'days' => $days, 'total' => count($res['events']), 'truncated' => $res['truncated'], 'counts' => $res['counts'],
            'from' => $g['days'][0]['ymd'], 'to' => $g['days'][count($g['days']) - 1]['ymd']];
    }

    /**
     * Eventos con fecha en [$fromTs, $toTs) (timestamps; limites ya calculados en la zona de visualizacion).
     * @param array<string,string> $f @param list<string> $types
     * @return array{events:list<array>,truncated:bool,counts:array<string,int>}
     */
    public static function events(array $f, array $types, int $fromTs, int $toTs, int $now): array
    {
        $from = Fmt::utc($fromTs);
        $to = Fmt::utc($toTs);
        [$where, $wp] = LeadQuery::where($f);
        $filtered = count($f) > 0;
        $cap = (string) self::CAP;
        $out = [];
        $counts = array_fill_keys(array_keys(self::TYPES), 0);
        $truncated = false;
        $take = static function (string $kind, array $rows) use (&$out, &$counts, &$truncated): void {
            $counts[$kind] = count($rows);
            if (count($rows) >= self::CAP) {
                $truncated = true;
            }
            foreach ($rows as $r) {
                $out[] = $r;
            }
        };
        $name = static fn(array $r): ?string => ($r['first_name'] ?? null) === null ? null : Fmt::name($r);
        if (in_array('task', $types, true)) {
            $join = $filtered ? "JOIN leads l ON l.id = t.lead_id AND $where" : 'LEFT JOIN leads l ON l.id = t.lead_id AND l.deleted_at IS NULL';
            $rows = Db::all("SELECT t.id, t.title, t.due_at, t.priority, t.done_at, t.lead_id, l.first_name, l.last_name FROM tasks t $join WHERE t.deleted_at IS NULL AND t.due_at >= ? AND t.due_at < ? ORDER BY t.due_at ASC, t.id ASC LIMIT $cap", [...($filtered ? $wp : []), $from, $to]);
            $take('task', array_map(static function ($r) use ($name, $now) {
                $done = $r['done_at'] !== null;
                $late = !$done && (int) Clock::parse((string) $r['due_at']) < $now;
                return ['id' => 'task-' . $r['id'], 'kind' => 'task', 'at' => (string) $r['due_at'], 'title' => (string) $r['title'], 'lead_id' => $r['lead_id'] !== null && $r['first_name'] !== null ? (int) $r['lead_id'] : null, 'lead' => $name($r),
                    'done' => $done, 'late' => $late, 'prio' => (int) $r['priority'], 'task_id' => (int) $r['id'], 'drag' => !$done];
            }, $rows));
        }
        if (in_array('appt', $types, true)) {
            $rows = Db::all("SELECT a.id, a.starts_at, a.title, a.status, a.lead_id, l.first_name, l.last_name FROM appointments a JOIN leads l ON l.id = a.lead_id AND $where WHERE a.deleted_at IS NULL AND a.status <> 'cancelled' AND a.starts_at >= ? AND a.starts_at < ? ORDER BY a.starts_at ASC, a.id ASC LIMIT $cap", [...$wp, $from, $to]);
            $take('appt', array_map(static fn($r) => ['id' => 'appt-' . $r['id'], 'kind' => 'appt', 'at' => (string) $r['starts_at'], 'title' => trim((string) ($r['title'] ?? '')) !== '' ? (string) $r['title'] : 'Cita con el lead', 'lead_id' => (int) $r['lead_id'], 'lead' => $name($r),
                'done' => false, 'late' => false, 'prio' => null, 'task_id' => null, 'drag' => false], $rows));
        }
        if (in_array('first', $types, true)) {
            $rows = Db::all("SELECT l.id, l.first_name, l.last_name, l.created_at, l.temperature FROM leads l WHERE $where AND l.first_contact_at IS NULL AND l.stage = 'new' AND l.temperature <> 'disqualified' AND l.created_at >= ? AND l.created_at < ? ORDER BY l.created_at ASC, l.id ASC LIMIT $cap", [...$wp, $from, $to]);
            $take('first', array_map(static fn($r) => ['id' => 'first-' . $r['id'], 'kind' => 'first', 'at' => (string) $r['created_at'], 'title' => 'Primer contacto pendiente', 'lead_id' => (int) $r['id'], 'lead' => $name($r),
                'done' => false, 'late' => $r['temperature'] === 'hot', 'prio' => null, 'task_id' => null, 'drag' => false], $rows));
        }
        if (in_array('seq', $types, true)) {
            $rows = Db::all("SELECT e.id, e.sequence_key, e.step, e.next_run_at, e.lead_id, l.first_name, l.last_name FROM sequence_enrollments e JOIN leads l ON l.id = e.lead_id AND $where WHERE e.status = 'active' AND e.next_run_at >= ? AND e.next_run_at < ? ORDER BY e.next_run_at ASC, e.id ASC LIMIT $cap", [...$wp, $from, $to]);
            $take('seq', array_map(static function ($r) use ($name) {
                $def = Sequences::DEFS[$r['sequence_key']] ?? null;
                $label = $def['steps'][(int) $r['step']]['label'] ?? ('Paso ' . ((int) $r['step'] + 1));
                return ['id' => 'seq-' . $r['id'], 'kind' => 'seq', 'at' => (string) $r['next_run_at'], 'title' => 'Correo programado · ' . $label, 'lead_id' => (int) $r['lead_id'], 'lead' => $name($r),
                    'done' => false, 'late' => false, 'prio' => null, 'task_id' => null, 'drag' => false];
            }, $rows));
        }
        if (in_array('handoff', $types, true)) {
            $rows = Db::all("SELECT j.id, j.run_at, JSON_UNQUOTE(JSON_EXTRACT(j.payload, '\$.target')) AS target, h.lead_id, l.first_name, l.last_name FROM jobs j
                               JOIN handoffs h ON h.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload, '\$.handoff_id')) AS UNSIGNED)
                               JOIN leads l ON l.id = h.lead_id AND $where
                              WHERE j.type = 'handoff_deliver' AND j.status = 'pending' AND j.run_at >= ? AND j.run_at < ? ORDER BY j.run_at ASC, j.id ASC LIMIT $cap", [...$wp, $from, $to]);
            $take('handoff', array_map(static fn($r) => ['id' => 'handoff-' . $r['id'], 'kind' => 'handoff', 'at' => (string) $r['run_at'], 'title' => 'Entrega a NorthStar · ' . (['email' => 'correo', 'webhook' => 'webhook'][(string) $r['target']] ?? 'pendiente'),
                'lead_id' => (int) $r['lead_id'], 'lead' => $name($r), 'done' => false, 'late' => false, 'prio' => null, 'task_id' => null, 'drag' => false], $rows));
        }
        usort($out, static fn($a, $b) => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);
        return ['events' => $out, 'truncated' => $truncated, 'counts' => $counts];
    }
}
