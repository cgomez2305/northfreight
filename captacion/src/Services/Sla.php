<?php
/**
 * SLA de primer contacto (fase AM). Un lead que NO tiene `first_contact_at`, no esta perdido y no esta descartado automaticamente (temperatura «disqualified») tiene un plazo
 * para el primer contacto: desde que llego (o desde que se reabrio como un intento nuevo: `stage_changed_at`) mas N minutos.
 *
 * Ajustes (tabla settings, editables en Configuracion > Alertas):
 *   sla.first_contact  minutos del plazo (5 a 1440; 60 por defecto)
 *   sla.escalate       al vencer, alerta de Telegram por la cola (activado por defecto; respeta el horario de silencio de Alerts)
 *   sla.auto_task      al crear el lead, tarea «Primer contacto con <nombre>» con vencimiento = llegada + plazo (activado por defecto)
 *
 * Avisos: el indicador «vence en 23 min» / «vencido hace 2 h» (Ui::sla) y la campana (Notifications, para el responsable o, si el lead no tiene, para todos los administradores)
 * se calculan al vuelo. tick() lo ejecuta process_jobs.php en cada pasada: cierra las tareas de primer contacto de los leads ya contactados o perdidos y encola UNA alerta por lead e
 * intento (clave `sla_breach:lead:intento`) para los que vencieron en las ultimas 24 h (los mas viejos no se escalan: nadie los esta esperando).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Sla
{
    public const DEFAULT_MINUTES = 60;
    public const MIN = 5;
    public const MAX = 1440;
    public const TASK_KIND = 'sla_first_contact';
    /** Los vencidos hace mas de esto no se escalan por Telegram. */
    public const ESCALATE_WINDOW = 86400;
    public const BATCH = 200;

    public static function minutes(): int
    {
        try {
            $v = Settings::get('sla.first_contact', self::DEFAULT_MINUTES);
        } catch (\Throwable) {
            return self::DEFAULT_MINUTES;
        }
        $v = is_numeric($v) ? (int) $v : self::DEFAULT_MINUTES;
        return $v >= self::MIN && $v <= self::MAX ? $v : self::DEFAULT_MINUTES;
    }

    public static function escalate(): bool
    {
        try {
            return (bool) Settings::get('sla.escalate', true);
        } catch (\Throwable) {
            return true;
        }
    }

    public static function autoTask(): bool
    {
        try {
            return (bool) Settings::get('sla.auto_task', true);
        } catch (\Throwable) {
            return true;
        }
    }

    /** @return array{minutes:int,escalate:bool,auto_task:bool} */
    public static function config(): array
    {
        return ['minutes' => self::minutes(), 'escalate' => self::escalate(), 'auto_task' => self::autoTask()];
    }

    /** Valida los campos sla_minutes, sla_escalate y sla_auto_task del formulario de Alertas. @return array{ok:bool,errors:array<string,string>,values:array{minutes:int,escalate:bool,auto_task:bool}} */
    public static function validate(array $in): array
    {
        $m = $in['sla_minutes'] ?? '';
        $err = [];
        if (!is_string($m) || preg_match('/^\d{1,4}$/', trim($m)) !== 1 || (int) $m < self::MIN || (int) $m > self::MAX) {
            $err['sla_minutes'] = 'Escribe un número de minutos entre ' . self::MIN . ' y ' . self::MAX . '.';
        }
        return ['ok' => !$err, 'errors' => $err, 'values' => ['minutes' => is_string($m) && ctype_digit(trim($m)) && (int) $m >= self::MIN && (int) $m <= self::MAX ? (int) $m : self::minutes(),
            'escalate' => ($in['sla_escalate'] ?? '') === '1', 'auto_task' => ($in['sla_auto_task'] ?? '') === '1']];
    }

    public static function save(array $values): void
    {
        Settings::set('sla.first_contact', (int) $values['minutes']);
        Settings::set('sla.escalate', (bool) $values['escalate']);
        Settings::set('sla.auto_task', (bool) $values['auto_task']);
    }

    /** Condicion SQL de los leads sujetos a SLA (alias $a). */
    public static function trackedSql(string $a = 'l'): string
    {
        return "$a.deleted_at IS NULL AND $a.first_contact_at IS NULL AND $a.stage <> 'lost' AND $a.temperature <> 'disqualified'";
    }

    /** Instante desde el que corre el plazo (SQL). */
    public static function startSql(string $a = 'l'): string
    {
        return "COALESCE($a.stage_changed_at, $a.created_at)";
    }

    /** Condicion SQL de «plazo vencido» y su parametro (alias $a). @return array{0:string,1:list<string>} */
    public static function overdueSql(string $a = 'l', ?int $now = null): array
    {
        $now ??= Clock::time();
        return [self::trackedSql($a) . ' AND ' . self::startSql($a) . ' < ?', [Clock::at($now - self::minutes() * 60)]];
    }

    /** Vencimiento (timestamp) de una fila de lead; null si no esta sujeto a SLA. */
    public static function dueAt(array $r): ?int
    {
        if (($r['first_contact_at'] ?? null) !== null || ($r['stage'] ?? '') === 'lost' || ($r['temperature'] ?? '') === 'disqualified') {
            return null;
        }
        $start = Clock::parse((string) (($r['stage_changed_at'] ?? null) ?: ($r['created_at'] ?? '')));
        return $start === null ? null : $start + self::minutes() * 60;
    }

    /**
     * Estado del SLA de una fila de lead (necesita stage, temperature, first_contact_at, created_at y, si existe, stage_changed_at).
     * @return array{late:bool,secs:int,due:int,label:string}|null null si el lead no tiene SLA (ya contactado, perdido o descartado)
     */
    public static function status(array $r, ?int $now = null): ?array
    {
        $due = self::dueAt($r);
        if ($due === null) {
            return null;
        }
        $now ??= Clock::time();
        $late = $now >= $due;
        $secs = abs($due - $now);
        return ['late' => $late, 'secs' => $secs, 'due' => $due, 'label' => $late ? 'vencido hace ' . Fmt::dur($secs) : 'vence en ' . Fmt::dur($secs)];
    }

    /** Cuantos leads vencidos le tocan a un usuario (los suyos y los que no tienen responsable). */
    public static function overdueFor(int $userId, ?int $now = null): array
    {
        [$sql, $p] = self::overdueSql('l', $now);
        $row = Db::one("SELECT COUNT(*) AS n, MAX(l.id) AS max_id, MIN(" . self::startSql('l') . ") AS oldest FROM leads l WHERE $sql AND (l.assigned_to = ? OR l.assigned_to IS NULL)", [...$p, $userId]);
        return ['n' => (int) ($row['n'] ?? 0), 'max_id' => (int) ($row['max_id'] ?? 0), 'oldest' => $row['oldest'] ?? null];
    }

    /** Crea (una sola vez) la tarea «Primer contacto con …» de un lead. Devuelve true si la creo. Dentro de la transaccion de quien llama. */
    public static function ensureTask(array $lead): bool
    {
        if (!self::autoTask()) {
            return false;
        }
        $id = (int) $lead['id'];
        if ((int) Db::value('SELECT COUNT(*) FROM tasks WHERE lead_id = ? AND kind = ? AND done_at IS NULL AND deleted_at IS NULL', [$id, self::TASK_KIND]) > 0) {
            return false;
        }
        $due = self::dueAt($lead + ['stage' => $lead['stage'] ?? 'new', 'temperature' => $lead['temperature'] ?? 'cold', 'first_contact_at' => null]);
        if ($due === null) {
            return false;
        }
        $title = mb_substr('Primer contacto con ' . (trim(Fmt::name($lead)) !== '' ? Fmt::name($lead) : 'el lead'), 0, 190);
        // Sin evento en la linea de tiempo: la crea el sistema en cada alta y seria ruido junto a «Lead recibido»; la tarea se ve en la ficha y en /tareas.
        Db::insert('tasks', ['title' => $title, 'lead_id' => $id, 'due_at' => Clock::at($due), 'priority' => ($lead['temperature'] ?? '') === 'hot' ? 1 : 2, 'kind' => self::TASK_KIND, 'created_at' => Clock::now()]);
        Tasks::resetCache();
        return true;
    }

    /**
     * Pasada periodica (process_jobs.php, cada minuto). Idempotente.
     * @return array{closed:int,escalated:int}
     */
    public static function tick(?int $now = null): array
    {
        $now ??= Clock::time();
        $closed = Db::run(
            "UPDATE tasks t JOIN leads l ON l.id = t.lead_id SET t.done_at = ? WHERE t.kind = ? AND t.done_at IS NULL AND t.deleted_at IS NULL AND (l.first_contact_at IS NOT NULL OR l.stage = 'lost' OR l.deleted_at IS NOT NULL)",
            [Clock::at($now), self::TASK_KIND]
        )->rowCount();
        if ($closed > 0) {
            Tasks::resetCache();
        }
        $escalated = 0;
        if (self::escalate()) {
            [$sql, $p] = self::overdueSql('l', $now);
            $rows = Db::all(
                "SELECT l.id, l.attempt FROM leads l WHERE $sql AND " . self::startSql('l') . ' >= ? ORDER BY ' . self::startSql('l') . ' ASC, l.id ASC LIMIT ' . self::BATCH,
                [...$p, Clock::at($now - self::minutes() * 60 - self::ESCALATE_WINDOW)]
            );
            $at = Alerts::nonUrgentRunAt($now);
            foreach ($rows as $r) {
                if (Queue::push('sla_breach', ['lead_id' => (int) $r['id']], $at, 'sla_breach:' . (int) $r['id'] . ':' . (int) $r['attempt']) !== null) {
                    $escalated++;
                }
            }
        }
        return ['closed' => $closed, 'escalated' => $escalated];
    }

    /** Handler de la cola `sla_breach`: vuelve a comprobar y avisa por Telegram (si el escalado sigue activo y el lead sigue sin contactar y vencido). */
    public static function jobBreach(array $payload): void
    {
        $id = (int) ($payload['lead_id'] ?? 0);
        $lead = $id > 0 ? \Captacion\Models\Lead::find($id) : null;
        if ($lead === null) {
            throw new PermanentJobFailure('El lead #' . $id . ' no existe o fue borrado.');
        }
        $st = self::status($lead);
        if (!self::escalate() || $st === null || !$st['late']) {
            return;   // ya lo contactaron, lo perdieron, se apago el escalado o se amplio el plazo
        }
        $assignee = $lead['assigned_to'] !== null ? Assignment::name((int) $lead['assigned_to']) : null;
        Telegram::send(Telegram::leadText($lead + ['assignee' => $assignee], 'sla', self::minutes()));
        LeadIngest::event($id, 'sla_breached', ['minutes' => self::minutes(), 'channel' => 'telegram']);
    }
}
