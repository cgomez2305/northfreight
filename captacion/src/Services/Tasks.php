<?php
/**
 * Tareas y agenda (/tareas). Es la MISMA tabla `tasks` que usa la ficha del lead (LeadActions::addTask / completeTask): lo que se crea o se cierra en una
 * pantalla aparece en la otra. Cuando la tarea tiene lead, cada accion deja un evento en su linea de tiempo; todas dejan registro en activity_log.
 *
 * Particion por tiempo (sin solapes): Vencidas = abiertas con fecha anterior a ahora (el contador de la barra lateral y de la campana) · Hoy = abiertas
 * con fecha entre ahora y el fin del dia · Proximas = abiertas de mañana en adelante y las que no tienen fecha · Hechas = completadas en los ultimos 30 dias.
 * Las citas (appointments) se listan en Hoy (todas las de hoy), Proximas y Hechas (ya pasadas); la sincronizacion con Cal.com es de otra fase.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class Tasks
{
    public const TABS = ['today' => 'Hoy', 'overdue' => 'Vencidas', 'upcoming' => 'Próximas', 'done' => 'Hechas', 'week' => 'Semana'];
    public const LIMIT = 300;

    private static ?int $overdue = null;

    public static function overdueCount(): int
    {
        return self::$overdue ??= (int) Db::value('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at < ?', [Clock::now()]);
    }

    public static function resetCache(): void
    {
        self::$overdue = null;
    }

    private static function ok(string $msg, array $extra = []): array
    {
        self::$overdue = null;
        return ['ok' => true, 'msg' => $msg] + $extra;
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }

    private static function clean(mixed $v, int $max): string
    {
        $s = is_string($v) ? str_replace("\0", '', trim($v)) : '';
        return mb_substr((string) preg_replace('/[^\P{C}]/u', '', $s), 0, $max);
    }

    /** Fecha escrita en la zona de visualizacion ('AAAA-MM-DD' o 'AAAA-MM-DDTHH:MM') a UTC. Solo fecha = hora de inicio del horario comercial. */
    public static function parseDue(mixed $due): array
    {
        if (!is_string($due) || trim($due) === '') {
            return [true, null];
        }
        $v = trim($due);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
            $v .= 'T' . Display::hours()['start'];
        }
        $utc = Fmt::fromEtInput($v);
        return $utc === null ? [false, null] : [true, $utc];
    }

    private static function event(?int $leadId, string $type, array $payload, int $userId): void
    {
        if ($leadId !== null) {
            LeadActions::event($leadId, $type, $payload, $userId);
        }
    }

    public static function find(int $id, bool $lock = false): ?array
    {
        return Db::one('SELECT * FROM tasks WHERE id = ? AND deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    }

    public static function create(mixed $title, mixed $due, mixed $priority, mixed $leadId, int $userId): array
    {
        $title = self::clean($title, 190);
        if ($title === '') {
            return self::fail('Escribe qué hay que hacer.');
        }
        [$okDue, $dueUtc] = self::parseDue($due);
        if (!$okDue) {
            return self::fail('La fecha de la tarea no es válida.');
        }
        $prio = (int) $priority;
        $prio = isset(Catalog::PRIORITIES[$prio]) ? $prio : 2;
        $lid = is_numeric($leadId) && (int) $leadId > 0 ? (int) $leadId : null;
        if ($lid !== null && Lead::find($lid) === null) {
            return self::fail('El lead elegido no existe.');
        }
        return Db::transaction(static function () use ($title, $dueUtc, $prio, $lid, $userId): array {
            $id = Db::insert('tasks', ['title' => $title, 'lead_id' => $lid, 'due_at' => $dueUtc, 'priority' => $prio, 'created_by' => $userId, 'created_at' => Clock::now()]);
            self::event($lid, 'task_created', ['task_id' => $id, 'title' => $title, 'due_at' => $dueUtc, 'priority' => $prio], $userId);
            ActivityLog::record('task_add', $userId, $lid !== null ? 'lead' : 'task', $lid ?? $id, ['task_id' => $id]);
            return self::ok('Tarea creada.', ['id' => $id]);
        });
    }

    public static function complete(int $taskId, int $userId): array
    {
        return Db::transaction(static function () use ($taskId, $userId): array {
            $t = Db::one('SELECT id, title, lead_id FROM tasks WHERE id = ? AND deleted_at IS NULL AND done_at IS NULL FOR UPDATE', [$taskId]);
            if ($t === null) {
                return self::fail('La tarea no existe o ya estaba completada.');
            }
            Db::update('tasks', $taskId, ['done_at' => Clock::now()]);
            self::event($t['lead_id'] !== null ? (int) $t['lead_id'] : null, 'task_done', ['task_id' => $taskId, 'title' => $t['title']], $userId);
            ActivityLog::record('task_done', $userId, $t['lead_id'] !== null ? 'lead' : 'task', $t['lead_id'] ?? $taskId, ['task_id' => $taskId]);
            return self::ok('Tarea completada.');
        });
    }

    public static function reopen(int $taskId, int $userId): array
    {
        return Db::transaction(static function () use ($taskId, $userId): array {
            $t = Db::one('SELECT id, title, lead_id FROM tasks WHERE id = ? AND deleted_at IS NULL AND done_at IS NOT NULL FOR UPDATE', [$taskId]);
            if ($t === null) {
                return self::fail('La tarea no existe o ya estaba abierta.');
            }
            Db::update('tasks', $taskId, ['done_at' => null]);
            self::event($t['lead_id'] !== null ? (int) $t['lead_id'] : null, 'task_reopened', ['task_id' => $taskId, 'title' => $t['title']], $userId);
            ActivityLog::record('task_reopen', $userId, $t['lead_id'] !== null ? 'lead' : 'task', $t['lead_id'] ?? $taskId, ['task_id' => $taskId]);
            return self::ok('Tarea reabierta.');
        });
    }

    /** $mode: '1h' (una hora mas tarde que ahora o que su fecha, lo que sea posterior) · 'tomorrow' (mañana a la hora de inicio del horario) · 'date' (la fecha de $date). */
    public static function snooze(int $taskId, string $mode, mixed $date, int $userId): array
    {
        return Db::transaction(static function () use ($taskId, $mode, $date, $userId): array {
            $t = Db::one('SELECT id, title, lead_id, due_at FROM tasks WHERE id = ? AND deleted_at IS NULL AND done_at IS NULL FOR UPDATE', [$taskId]);
            if ($t === null) {
                return self::fail('La tarea no existe o ya está completada.');
            }
            $now = Clock::time();
            if ($mode === '1h') {
                $base = max($now, (int) (Clock::parse((string) $t['due_at']) ?? 0));
                $new = Clock::at($base + 3600);
            } elseif ($mode === 'tomorrow') {
                $tomorrow = Fmt::et($now)->modify('+1 day')->format('Y-m-d');
                $new = Fmt::fromEtInput($tomorrow . 'T' . Display::hours()['start']);
            } elseif ($mode === 'date') {
                [$ok, $new] = self::parseDue($date);
                if (!$ok || $new === null) {
                    return self::fail('Elige una fecha válida para posponer.');
                }
                if ((int) Clock::parse($new) <= $now) {
                    return self::fail('La nueva fecha debe ser posterior a ahora.');
                }
            } else {
                return self::fail('Opción para posponer no válida.');
            }
            Db::update('tasks', $taskId, ['due_at' => $new]);
            self::event($t['lead_id'] !== null ? (int) $t['lead_id'] : null, 'task_snoozed', ['task_id' => $taskId, 'title' => $t['title'], 'from' => $t['due_at'], 'to' => $new], $userId);
            ActivityLog::record('task_snooze', $userId, $t['lead_id'] !== null ? 'lead' : 'task', $t['lead_id'] ?? $taskId, ['task_id' => $taskId, 'from' => $t['due_at'], 'to' => $new]);
            return self::ok('Tarea pospuesta al ' . Fmt::dateTime($new) . '.', ['due_at' => $new, 'from' => $t['due_at']]);
        });
    }

    /**
     * Mueve una tarea ABIERTA a otro dia conservando su hora local (arrastrar en el calendario, o Alt + flechas). $ymd es 'AAAA-MM-DD' en la zona de visualizacion; la hora del
     * dia se calcula en esa zona, asi que un cambio de horario de verano no corre la tarea una hora. Sin fecha previa toma la hora de inicio del horario comercial.
     * @return array{ok:bool,msg:string,due_at?:string,from?:?string}
     */
    public static function reschedule(int $taskId, string $ymd, int $userId): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) !== 1 || Fmt::fromEtInput($ymd) === null) {
            return self::fail('El día elegido no es válido.');
        }
        $limit = 800 * 86400;
        $dayTs = (int) Clock::parse((string) Fmt::fromEtInput($ymd));
        if (abs($dayTs - Clock::time()) > $limit) {
            return self::fail('El día elegido está demasiado lejos.');
        }
        return Db::transaction(static function () use ($taskId, $ymd, $userId): array {
            $t = Db::one('SELECT id, title, lead_id, due_at FROM tasks WHERE id = ? AND deleted_at IS NULL AND done_at IS NULL FOR UPDATE', [$taskId]);
            if ($t === null) {
                return self::fail('La tarea no existe o ya está completada.');
            }
            $hm = $t['due_at'] !== null ? Fmt::et((string) $t['due_at'])->format('H:i') : Display::hours()['start'];
            $new = Fmt::fromEtInput($ymd . 'T' . $hm);
            if ($new === null) {
                return self::fail('El día elegido no es válido.');
            }
            if ($new === $t['due_at']) {
                return self::fail('La tarea ya está en ese día.');
            }
            Db::update('tasks', $taskId, ['due_at' => $new]);
            self::event($t['lead_id'] !== null ? (int) $t['lead_id'] : null, 'task_rescheduled', ['task_id' => $taskId, 'title' => $t['title'], 'from' => $t['due_at'], 'to' => $new, 'via' => 'calendar'], $userId);
            ActivityLog::record('task_resched', $userId, $t['lead_id'] !== null ? 'lead' : 'task', $t['lead_id'] ?? $taskId, ['task_id' => $taskId, 'from' => $t['due_at'], 'to' => $new]);
            return self::ok('Tarea movida al ' . Fmt::dateTime($new) . '.', ['due_at' => $new, 'from' => $t['due_at']]);
        });
    }

    /** «Deshacer» de reschedule/posponer: devuelve la fecha anterior SOLO si la tarea sigue abierta y con la fecha que se le puso ($expected); si cambio otra vez, no se toca. */
    public static function restoreDue(int $taskId, ?string $from, ?string $expected, int $userId): array
    {
        return Db::transaction(static function () use ($taskId, $from, $expected, $userId): array {
            $t = Db::one('SELECT id, title, lead_id, due_at FROM tasks WHERE id = ? AND deleted_at IS NULL AND done_at IS NULL FOR UPDATE', [$taskId]);
            if ($t === null) {
                return self::fail('La tarea ya no está abierta.');
            }
            if ($expected !== null && $t['due_at'] !== $expected) {
                return self::fail('La tarea cambió de fecha otra vez: no se deshizo.');
            }
            Db::update('tasks', $taskId, ['due_at' => $from]);
            self::event($t['lead_id'] !== null ? (int) $t['lead_id'] : null, 'task_rescheduled', ['task_id' => $taskId, 'title' => $t['title'], 'from' => $t['due_at'], 'to' => $from, 'undo' => true], $userId);
            ActivityLog::record('task_resched', $userId, $t['lead_id'] !== null ? 'lead' : 'task', $t['lead_id'] ?? $taskId, ['task_id' => $taskId, 'from' => $t['due_at'], 'to' => $from, 'undo' => true]);
            return self::ok('Fecha restaurada.', ['due_at' => $from]);
        });
    }

    public static function update(int $taskId, mixed $title, mixed $due, mixed $priority, mixed $leadId, int $userId): array
    {
        $title = self::clean($title, 190);
        if ($title === '') {
            return self::fail('El título no puede quedar vacío.');
        }
        [$okDue, $dueUtc] = self::parseDue($due);
        if (!$okDue) {
            return self::fail('La fecha de la tarea no es válida.');
        }
        $prio = (int) $priority;
        $prio = isset(Catalog::PRIORITIES[$prio]) ? $prio : 2;
        $lid = is_numeric($leadId) && (int) $leadId > 0 ? (int) $leadId : null;
        if ($lid !== null && Lead::find($lid) === null) {
            return self::fail('El lead elegido no existe.');
        }
        return Db::transaction(static function () use ($taskId, $title, $dueUtc, $prio, $lid, $userId): array {
            $t = self::find($taskId, true);
            if ($t === null) {
                return self::fail('La tarea no existe.');
            }
            Db::update('tasks', $taskId, ['title' => $title, 'due_at' => $dueUtc, 'priority' => $prio, 'lead_id' => $lid]);
            self::event($lid, 'task_edited', ['task_id' => $taskId, 'title' => $title, 'due_at' => $dueUtc], $userId);
            ActivityLog::record('task_edit', $userId, $lid !== null ? 'lead' : 'task', $lid ?? $taskId, ['task_id' => $taskId, 'title' => $title !== $t['title'] ? [$t['title'], $title] : null, 'due' => $dueUtc !== $t['due_at'] ? [$t['due_at'], $dueUtc] : null]);
            return self::ok('Tarea actualizada.');
        });
    }

    public static function delete(int $taskId, int $userId): array
    {
        return Db::transaction(static function () use ($taskId, $userId): array {
            $t = self::find($taskId, true);
            if ($t === null) {
                return self::fail('La tarea no existe.');
            }
            Db::update('tasks', $taskId, ['deleted_at' => Clock::now()]);
            self::event($t['lead_id'] !== null ? (int) $t['lead_id'] : null, 'task_deleted', ['task_id' => $taskId, 'title' => $t['title']], $userId);
            ActivityLog::record('task_delete', $userId, $t['lead_id'] !== null ? 'lead' : 'task', $t['lead_id'] ?? $taskId, ['task_id' => $taskId, 'title' => $t['title']]);
            return self::ok('Tarea eliminada.');
        });
    }

    // --- Listados ------------------------------------------------------------------------------------------------------

    /** Conteos de las pestañas (para los numeritos). @return array<string,int> */
    public static function counts(int $now): array
    {
        $nowS = Clock::at($now);
        $endToday = Fmt::utc(Fmt::dayStart($now, 1));
        $startToday = Fmt::utc(Fmt::dayStart($now));
        $row = Db::one(
            'SELECT SUM(due_at < ?) AS overdue, SUM(due_at >= ? AND due_at < ?) AS today, SUM(due_at >= ? OR due_at IS NULL) AS upcoming
               FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL',
            [$nowS, $nowS, $endToday, $endToday]
        ) ?? [];
        $apptToday = (int) Db::value('SELECT COUNT(*) FROM appointments WHERE deleted_at IS NULL AND status <> "cancelled" AND starts_at >= ? AND starts_at < ?', [$startToday, $endToday]);
        $apptNext = (int) Db::value('SELECT COUNT(*) FROM appointments WHERE deleted_at IS NULL AND status <> "cancelled" AND starts_at >= ?', [$endToday]);
        return [
            'overdue'  => (int) ($row['overdue'] ?? 0),
            'today'    => (int) ($row['today'] ?? 0) + $apptToday,
            'upcoming' => (int) ($row['upcoming'] ?? 0) + $apptNext,
            'done'     => (int) Db::value('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND done_at >= ?', [Clock::at($now - 30 * 86400)]),
        ];
    }

    private const TASK_SQL = 'SELECT t.id, t.title, t.due_at, t.priority, t.done_at, t.lead_id, l.first_name, l.last_name, l.stage AS lead_stage
                                FROM tasks t LEFT JOIN leads l ON l.id = t.lead_id AND l.deleted_at IS NULL';
    private const APPT_SQL = 'SELECT a.id, a.starts_at, a.ends_at, a.status, a.title, a.lead_id, l.first_name, l.last_name
                                FROM appointments a JOIN leads l ON l.id = a.lead_id AND l.deleted_at IS NULL';

    /**
     * Items de una pestaña, ya agrupados por dia (en la zona de visualizacion).
     * @return list<array{key:string,label:string,items:list<array>}> cada item: ['kind'=>'task'|'appt', 'at'=>UTC|null, ...fila]
     */
    public static function listTab(string $tab, int $now): array
    {
        $nowS = Clock::at($now);
        $endToday = Fmt::utc(Fmt::dayStart($now, 1));
        $startToday = Fmt::utc(Fmt::dayStart($now));
        $tasks = [];
        $appts = [];
        switch ($tab) {
            case 'overdue':
                $tasks = Db::all(self::TASK_SQL . ' WHERE t.deleted_at IS NULL AND t.done_at IS NULL AND t.due_at IS NOT NULL AND t.due_at < ? ORDER BY t.due_at ASC LIMIT ' . self::LIMIT, [$nowS]);
                break;
            case 'upcoming':
                $tasks = Db::all(self::TASK_SQL . ' WHERE t.deleted_at IS NULL AND t.done_at IS NULL AND (t.due_at >= ? OR t.due_at IS NULL) ORDER BY t.due_at IS NULL, t.due_at ASC, t.id ASC LIMIT ' . self::LIMIT, [$endToday]);
                $appts = Db::all(self::APPT_SQL . ' WHERE a.deleted_at IS NULL AND a.status <> "cancelled" AND a.starts_at >= ? ORDER BY a.starts_at ASC LIMIT ' . self::LIMIT, [$endToday]);
                break;
            case 'done':
                $tasks = Db::all(self::TASK_SQL . ' WHERE t.deleted_at IS NULL AND t.done_at >= ? ORDER BY t.done_at DESC LIMIT ' . self::LIMIT, [Clock::at($now - 30 * 86400)]);
                $appts = Db::all(self::APPT_SQL . ' WHERE a.deleted_at IS NULL AND a.status <> "cancelled" AND a.starts_at < ? AND a.starts_at >= ? ORDER BY a.starts_at DESC LIMIT 100', [$nowS, Clock::at($now - 30 * 86400)]);
                break;
            default:   // today
                $tasks = Db::all(self::TASK_SQL . ' WHERE t.deleted_at IS NULL AND t.done_at IS NULL AND t.due_at >= ? AND t.due_at < ? ORDER BY t.due_at ASC LIMIT ' . self::LIMIT, [$nowS, $endToday]);
                $appts = Db::all(self::APPT_SQL . ' WHERE a.deleted_at IS NULL AND a.status <> "cancelled" AND a.starts_at >= ? AND a.starts_at < ? ORDER BY a.starts_at ASC LIMIT 100', [$startToday, $endToday]);
        }
        return self::group($tasks, $appts, $tab === 'done', $now);
    }

    /** @param list<array> $tasks @param list<array> $appts */
    public static function group(array $tasks, array $appts, bool $desc, int $now): array
    {
        $items = [];
        foreach ($tasks as $t) {
            $items[] = $t + ['kind' => 'task', 'at' => $desc ? $t['done_at'] : $t['due_at']];
        }
        foreach ($appts as $a) {
            $items[] = $a + ['kind' => 'appt', 'at' => $a['starts_at']];
        }
        usort($items, static function ($a, $b) use ($desc) {
            if ($a['at'] === null || $b['at'] === null) {
                return $a['at'] === $b['at'] ? 0 : ($a['at'] === null ? 1 : -1);
            }
            return $desc ? strcmp($b['at'], $a['at']) : strcmp($a['at'], $b['at']);
        });
        $groups = [];
        foreach ($items as $it) {
            $key = $it['at'] === null ? 'none' : Fmt::et($it['at'])->format('Y-m-d');
            $groups[$key]['key'] = $key;
            $groups[$key]['label'] = $key === 'none' ? 'Sin fecha' : self::dayName($key, $now);
            $groups[$key]['items'][] = $it;
        }
        return array_values($groups);
    }

    /** "Hoy · mié 4 nov", "Mañana · jue 5 nov", "Ayer · …", "vie 6 nov". */
    public static function dayName(string $ymd, int $now): string
    {
        $d = new \DateTimeImmutable($ymd . ' 00:00:00', Fmt::tz());
        $label = Fmt::weekdayShort((int) $d->format('N')) . ' ' . Fmt::dayLabel($d);
        $today = Fmt::et($now)->format('Y-m-d');
        $diff = (int) round(((new \DateTimeImmutable($ymd, Fmt::tz()))->setTime(12, 0)->getTimestamp() - (new \DateTimeImmutable($today, Fmt::tz()))->setTime(12, 0)->getTimestamp()) / 86400);
        $prefix = match ($diff) { 0 => 'Hoy · ', 1 => 'Mañana · ', -1 => 'Ayer · ', default => '' };
        return $prefix . $label;
    }

    /**
     * Vista de semana: 7 dias (lunes a domingo) de la semana que contiene $anchor ('AAAA-MM-DD' en la zona de visualizacion).
     * @return array{days:list<array{ymd:string,label:string,short:string,today:bool,items:list<array>}>,prev:string,next:string,from:string,to:string}
     */
    public static function week(?string $anchor, int $now): array
    {
        $ts = $now;
        if ($anchor !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) === 1 && ($u = Fmt::fromEtInput($anchor)) !== null) {
            $ts = (int) Clock::parse($u) + 12 * 3600;
        }
        $start = Fmt::weekStart($ts);
        $startD = Fmt::et($start);
        $end = Fmt::weekStart($ts, 1);
        $tasks = Db::all(self::TASK_SQL . ' WHERE t.deleted_at IS NULL AND t.due_at >= ? AND t.due_at < ? ORDER BY t.due_at ASC LIMIT 400', [Fmt::utc($start), Fmt::utc($end)]);
        $appts = Db::all(self::APPT_SQL . ' WHERE a.deleted_at IS NULL AND a.status <> "cancelled" AND a.starts_at >= ? AND a.starts_at < ? ORDER BY a.starts_at ASC LIMIT 200', [Fmt::utc($start), Fmt::utc($end)]);
        $by = [];
        foreach ($tasks as $t) {
            $by[Fmt::et($t['due_at'])->format('Y-m-d')][] = $t + ['kind' => 'task', 'at' => $t['due_at']];
        }
        foreach ($appts as $a) {
            $by[Fmt::et($a['starts_at'])->format('Y-m-d')][] = $a + ['kind' => 'appt', 'at' => $a['starts_at']];
        }
        $today = Fmt::et($now)->format('Y-m-d');
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $startD->modify('+' . $i . ' days');
            $ymd = $d->format('Y-m-d');
            $items = $by[$ymd] ?? [];
            usort($items, static fn($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
            $days[] = ['ymd' => $ymd, 'label' => Fmt::weekdayShort($i + 1) . ' ' . Fmt::dayLabel($d), 'short' => Fmt::weekdayShort($i + 1), 'day' => (int) $d->format('j'), 'today' => $ymd === $today, 'items' => $items];
        }
        return ['days' => $days, 'prev' => $startD->modify('-7 days')->format('Y-m-d'), 'next' => $startD->modify('+7 days')->format('Y-m-d'), 'from' => $startD->format('Y-m-d'), 'to' => $startD->modify('+6 days')->format('Y-m-d'), 'this' => Fmt::et($now)->format('Y-m-d')];
    }
}
