<?php
/**
 * Acciones del panel sobre un lead (ficha y bandeja): cambio de etapa con validaciones, notas, tareas, llamadas manuales, reabrir y revocar consentimiento.
 * Cada accion: transaccion, evento en la linea de tiempo (lead_events con user_id) y registro en activity_log. Devuelven ['ok'=>bool,'msg'=>string].
 *
 * Reglas de etapa (doc §7):
 *  - "Perdido" (pipeline A) o "Descartado" (B) exige un motivo de la lista fija; "Otro" exige ademas un texto.
 *  - "Contactado" (y cualquier etapa posterior) fija first_contact_at si estaba vacio y cancela la segunda alerta A3 pendiente.
 *  - "Presentado" solo desde Calificado/Llamada agendada (A) o Precalificado (B); "Firmado"/"Contratado" solo desde Presentado. Saltarse el orden o salir de
 *    Firmado/Contratado exige confirmar el override.
 *  - Un lead perdido no cambia de etapa: se reabre (accion aparte).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class LeadActions
{
    private const REQUIRES = [
        'A' => ['presented' => ['qualified', 'call_booked'], 'signed' => ['presented']],
        'B' => ['presented' => ['prequalified'], 'hired' => ['presented']],
    ];
    private const CLOSED = ['signed', 'hired'];

    private static function ok(string $msg): array
    {
        return ['ok' => true, 'msg' => $msg];
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }

    public static function event(int $leadId, string $type, array $payload, ?int $userId): void
    {
        Db::insert('lead_events', ['lead_id' => $leadId, 'type' => $type, 'payload' => $payload ?: null, 'user_id' => $userId, 'created_at' => Clock::now()]);
    }

    private static function clean(mixed $v, int $max): string
    {
        $s = is_string($v) ? str_replace("\0", '', trim($v)) : '';
        $s = (string) preg_replace('/[^\P{C}\n\t]/u', '', $s);
        return mb_substr($s, 0, $max);
    }

    /** Etapas validas para elegir desde la ficha: todas las del pipeline salvo la actual. @return array<string,string> */
    public static function stageOptions(array $lead): array
    {
        $map = Catalog::STAGES[$lead['pipeline']] ?? Catalog::STAGES['A'];
        unset($map[$lead['stage']]);
        return $map;
    }

    /** ¿Esta transicion necesita override explicito? */
    public static function needsOverride(array $lead, string $to): bool
    {
        $p = (string) $lead['pipeline'];
        $from = (string) $lead['stage'];
        if (in_array($from, self::CLOSED, true) && $to !== $from) {
            return true;
        }
        $req = self::REQUIRES[$p][$to] ?? null;
        return $req !== null && !in_array($from, $req, true);
    }

    /** Cancela la segunda alerta A3 (telegram_alert_followup) de este intento si sigue pendiente. */
    public static function cancelA3(int $leadId, int $attempt, ?int $userId, string $why): void
    {
        $n = Db::run(
            "UPDATE jobs SET status = 'cancelled', finished_at = ?, last_error = ? WHERE dedupe_key = ? AND status = 'pending'",
            [Clock::now(), 'Cancelado: ' . $why, 'telegram_followup:' . $leadId . ':' . $attempt]
        )->rowCount();
        if ($n > 0) {
            self::event($leadId, 'a3_cancelled', ['why' => $why], $userId);
        }
    }

    /** @param array{reason?:string,note?:string,override?:bool,auto?:bool} $o */
    public static function setStage(int $leadId, string $to, array $o, ?int $userId): array
    {
        return Db::transaction(static function () use ($leadId, $to, $o, $userId): array {
            $lead = Lead::find($leadId, true);
            if ($lead === null) {
                return self::fail('El lead no existe.');
            }
            $pipeline = (string) $lead['pipeline'];
            $stages = Catalog::STAGES[$pipeline] ?? Catalog::STAGES['A'];
            $from = (string) $lead['stage'];
            if (!isset($stages[$to])) {
                return self::fail('Esa etapa no existe en este pipeline.');
            }
            if ($from === $to) {
                return self::fail('El lead ya está en «' . $stages[$to] . '».');
            }
            if ($from === 'lost') {
                return self::fail('Este lead está en «' . $stages['lost'] . '». Reábrelo antes de cambiar su etapa.');
            }
            $note = self::clean($o['note'] ?? '', 500);
            $reason = null;
            if ($to === 'lost') {
                $reason = (string) ($o['reason'] ?? '');
                if (!isset(Catalog::LOSS_REASONS[$reason])) {
                    return self::fail('Elige un motivo de la lista para marcar «' . $stages['lost'] . '».');
                }
                if ($reason === 'other' && mb_strlen($note) < 3) {
                    return self::fail('Con el motivo «Otro» escribe una nota que lo explique.');
                }
            }
            $override = !empty($o['override']);
            if (self::needsOverride($lead, $to) && !$override) {
                return self::fail(in_array($from, self::CLOSED, true)
                    ? 'Sacar un lead de «' . $stages[$from] . '» necesita confirmar que quieres saltarte el orden.'
                    : '«' . $stages[$to] . '» solo se alcanza en orden (desde la etapa anterior). Confirma el salto si es lo que quieres.');
            }
            $now = Clock::now();
            $u = ['stage' => $to, 'stage_changed_at' => $now];
            $firstContactSet = false;
            if ($to === 'lost') {
                $u += ['lost_reason' => $reason, 'lost_at' => $now];
            } elseif ($to !== 'new' && $lead['first_contact_at'] === null) {
                $u['first_contact_at'] = $now;
                $firstContactSet = true;
            }
            if ($to === 'presented') {
                $u += ['handoff_status' => 'pending'];
                if ($lead['handed_off_at'] === null) {
                    $u['handed_off_at'] = $now;
                }
            } elseif (in_array($to, self::CLOSED, true)) {
                $u += ['handoff_status' => 'signed', 'signed_at' => $now];
                if ($lead['handed_off_at'] === null) {
                    $u['handed_off_at'] = $now;
                }
            } elseif (in_array($from, self::CLOSED, true)) {
                $u += ['signed_at' => null, 'handoff_status' => $to === 'lost' ? 'rejected' : 'pending'];
            }
            Db::update('leads', $leadId, $u);
            self::event($leadId, 'stage_changed', array_filter([
                'from' => $from, 'to' => $to, 'pipeline' => $pipeline, 'reason' => $reason, 'note' => $note !== '' ? $note : null,
                'override' => $override && self::needsOverride($lead, $to) ? true : null, 'auto' => !empty($o['auto']) ? true : null, 'fc' => $firstContactSet ? true : null,
            ], static fn($v) => $v !== null), $userId);
            if ($firstContactSet || ($to !== 'new' && $lead['first_contact_at'] !== null) || $to === 'lost') {
                self::cancelA3($leadId, (int) $lead['attempt'], $userId, 'el lead pasó a «' . $stages[$to] . '»');
            }
            ActivityLog::record('lead_stage', $userId, 'lead', $leadId, ['from' => $from, 'to' => $to, 'reason' => $reason, 'override' => $override ?: null]);
            // Q3: A7 (el avance del lead pausa sus secuencias), A9 (entrega a NorthStar al presentar) y conversion al firmar
            if ($to !== 'new') {
                Sequences::pauseForLead($leadId, 'stage', $userId);
            }
            try {
                Referrals::onStage($leadId, $to);
            } catch (\Throwable $t) {
                Storage::error('referral onStage failed', get_class($t) . ': ' . $t->getMessage());
            }
            if ($to === 'presented') {
                Handoff::onPresented($leadId, $userId);
            } elseif (in_array($to, self::CLOSED, true)) {
                Handoff::afterSigned(array_merge($lead, ['stage' => $to]), $userId);
            }
            return self::ok('Etapa cambiada a «' . $stages[$to] . '».');
        });
    }

    /**
     * Cambio masivo. $generic: 'contacted' | 'qualified' | 'lost' (qualified se traduce a prequalified en el pipeline B). Salta lo que no cumple las reglas.
     * @return array{ok:bool,msg:string,changed:int,skipped:int,changes?:list<array{0:int,1:string,2:string}>}
     */
    public static function bulkStage(array $ids, string $generic, array $o, int $userId): array
    {
        if (!in_array($generic, ['contacted', 'qualified', 'lost'], true)) {
            return ['ok' => false, 'msg' => 'Etapa no válida para el cambio masivo.', 'changed' => 0, 'skipped' => 0];
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0))), 0, 200);
        if (!$ids) {
            return ['ok' => false, 'msg' => 'No hay leads seleccionados.', 'changed' => 0, 'skipped' => 0];
        }
        $changed = 0;
        $skipped = 0;
        $firstError = '';
        $changes = [];
        foreach ($ids as $id) {
            $lead = Lead::find($id);
            if ($lead === null) {
                $skipped++;
                continue;
            }
            $to = $generic === 'qualified' && $lead['pipeline'] === 'B' ? 'prequalified' : $generic;
            $from = (string) $lead['stage'];
            $r = self::setStage($id, $to, $o + ['note' => 'Cambio masivo'], $userId);
            if ($r['ok']) {
                $changed++;
                $changes[] = [$id, $from, $to];
            } else {
                $skipped++;
                $firstError = $firstError ?: $r['msg'];
            }
        }
        ActivityLog::record('leads_bulk_stage', $userId, 'lead', null, ['stage' => $generic, 'changed' => $changed, 'skipped' => $skipped, 'ids' => count($ids)]);
        $msg = $changed . ' lead' . ($changed === 1 ? '' : 's') . ' actualizado' . ($changed === 1 ? '' : 's') . ($skipped > 0 ? ' · ' . $skipped . ' omitido' . ($skipped === 1 ? '' : 's') . ' (' . $firstError . ')' : '') . '.';
        return ['ok' => $changed > 0, 'msg' => $msg, 'changed' => $changed, 'skipped' => $skipped, 'changes' => $changes];
    }

    /** Etapas cuyo cambio genera efectos que no se pueden revertir (entrega a NorthStar, conversion): no se ofrece «Deshacer» con ellas. */
    private const NO_UNDO = ['presented', 'signed', 'hired'];

    /** ¿Se puede ofrecer «Deshacer» para este cambio de etapa? */
    public static function undoable(string $from, string $to): bool
    {
        return $from !== $to && $from !== 'lost' && !in_array($from, self::NO_UNDO, true) && !in_array($to, self::NO_UNDO, true);
    }

    /**
     * Revierte un cambio de etapa (Q5a-G): solo si el lead sigue en $to y el cambio es reversible (undoable). Devuelve el lead a $from, quita el motivo de perdida si lo hubo
     * y el primer contacto si ese cambio lo fijo; deja el evento stage_changed con `undo`. No restaura la alerta A3 cancelada ni las secuencias pausadas (decision de Q5a).
     */
    public static function undoStage(int $leadId, string $from, string $to, int $userId): array
    {
        if (!self::undoable($from, $to)) {
            return self::fail('Este cambio ya generó efectos (entrega a NorthStar) y no se puede deshacer.');
        }
        return Db::transaction(static function () use ($leadId, $from, $to, $userId): array {
            $lead = Lead::find($leadId, true);
            if ($lead === null) {
                return self::fail('El lead no existe.');
            }
            $stages = Catalog::STAGES[(string) $lead['pipeline']] ?? Catalog::STAGES['A'];
            if ((string) $lead['stage'] !== $to || !isset($stages[$from])) {
                return self::fail('El lead ya cambió de etapa: no se puede deshacer.');
            }
            $ev = Db::one("SELECT id, payload, created_at FROM lead_events WHERE lead_id = ? AND type = 'stage_changed' ORDER BY id DESC LIMIT 1", [$leadId]);
            $p = $ev !== null ? Db::decode($ev['payload']) : [];
            if ($ev === null || ($p['from'] ?? null) !== $from || ($p['to'] ?? null) !== $to) {
                return self::fail('El último cambio de etapa no coincide: no se puede deshacer.');
            }
            $prev = Db::value("SELECT MAX(created_at) FROM lead_events WHERE lead_id = ? AND id < ? AND type IN ('stage_changed','reopened_manual','reopened')", [$leadId, (int) $ev['id']]);
            $u = ['stage' => $from, 'stage_changed_at' => $prev ?: $lead['created_at']];
            if ($to === 'lost') {
                $u += ['lost_reason' => null, 'lost_at' => null];
            }
            if (!empty($p['fc'])) {
                $u['first_contact_at'] = null;
            }
            Db::update('leads', $leadId, $u);
            self::event($leadId, 'stage_changed', ['from' => $to, 'to' => $from, 'pipeline' => (string) $lead['pipeline'], 'undo' => true], $userId);
            ActivityLog::record('lead_stage_undo', $userId, 'lead', $leadId, ['from' => $to, 'to' => $from]);
            return self::ok('Cambio deshecho: «' . ($stages[$from] ?? $from) . '».');
        });
    }

    /** Restaura una nota eliminada (borrado logico). */
    public static function restoreNote(int $leadId, int $noteId, int $userId): array
    {
        return Db::transaction(static function () use ($leadId, $noteId, $userId): array {
            $n = Db::run('UPDATE lead_notes SET deleted_at = NULL WHERE id = ? AND lead_id = ? AND deleted_at IS NOT NULL', [$noteId, $leadId])->rowCount();
            if ($n !== 1) {
                return self::fail('La nota no existe o ya estaba restaurada.');
            }
            self::event($leadId, 'note_added', ['note_id' => $noteId, 'restored' => true], $userId);
            ActivityLog::record('note_restore', $userId, 'lead', $leadId, ['note_id' => $noteId]);
            return self::ok('Nota restaurada.');
        });
    }

    public static function reopen(int $leadId, int $userId): array
    {
        return Db::transaction(static function () use ($leadId, $userId): array {
            $lead = Lead::find($leadId, true);
            if ($lead === null) {
                return self::fail('El lead no existe.');
            }
            if ($lead['stage'] !== 'lost') {
                return self::fail('Solo se reabren los leads perdidos o descartados.');
            }
            $to = $lead['first_contact_at'] !== null ? 'contacted' : 'new';
            Db::update('leads', $leadId, ['stage' => $to, 'stage_changed_at' => Clock::now(), 'lost_reason' => null, 'lost_at' => null]);
            self::event($leadId, 'reopened_manual', ['from_reason' => $lead['lost_reason'], 'to' => $to], $userId);
            ActivityLog::record('lead_reopen', $userId, 'lead', $leadId, ['to' => $to]);
            Sequences::pauseForLead($leadId, 'stage', $userId);   // A11: un lead reabierto ya no se reactiva
            return self::ok('Lead reabierto en «' . Catalog::stageLabel((string) $lead['pipeline'], $to) . '».');
        });
    }

    public static function addNote(int $leadId, mixed $body, int $userId): array
    {
        $body = self::clean($body, 4000);
        if ($body === '') {
            return self::fail('Escribe la nota antes de guardarla.');
        }
        if (Lead::find($leadId) === null) {
            return self::fail('El lead no existe.');
        }
        return Db::transaction(static function () use ($leadId, $body, $userId): array {
            $id = Db::insert('lead_notes', ['lead_id' => $leadId, 'user_id' => $userId, 'body' => $body, 'created_at' => Clock::now()]);
            self::event($leadId, 'note_added', ['note_id' => $id], $userId);
            ActivityLog::record('note_add', $userId, 'lead', $leadId, ['note_id' => $id]);
            return self::ok('Nota guardada.');
        });
    }

    public static function editNote(int $leadId, int $noteId, mixed $body, int $userId): array
    {
        $body = self::clean($body, 4000);
        if ($body === '') {
            return self::fail('La nota no puede quedar vacía. Para quitarla, elimínala.');
        }
        return Db::transaction(static function () use ($leadId, $noteId, $body, $userId): array {
            $n = Db::run('UPDATE lead_notes SET body = ?, updated_at = ? WHERE id = ? AND lead_id = ? AND deleted_at IS NULL', [$body, Clock::now(), $noteId, $leadId])->rowCount();
            if ($n !== 1) {
                return self::fail('La nota no existe o no cambió.');
            }
            self::event($leadId, 'note_edited', ['note_id' => $noteId], $userId);
            ActivityLog::record('note_edit', $userId, 'lead', $leadId, ['note_id' => $noteId]);
            return self::ok('Nota actualizada.');
        });
    }

    public static function deleteNote(int $leadId, int $noteId, int $userId): array
    {
        return Db::transaction(static function () use ($leadId, $noteId, $userId): array {
            $n = Db::run('UPDATE lead_notes SET deleted_at = ? WHERE id = ? AND lead_id = ? AND deleted_at IS NULL', [Clock::now(), $noteId, $leadId])->rowCount();
            if ($n !== 1) {
                return self::fail('La nota no existe.');
            }
            self::event($leadId, 'note_deleted', ['note_id' => $noteId], $userId);
            ActivityLog::record('note_delete', $userId, 'lead', $leadId, ['note_id' => $noteId]);
            return self::ok('Nota eliminada.');
        });
    }

    /** $due: AAAA-MM-DDTHH:MM en hora ET (input datetime-local) o vacio. */
    public static function addTask(int $leadId, mixed $title, mixed $due, mixed $priority, int $userId): array
    {
        $title = self::clean($title, 190);
        if ($title === '') {
            return self::fail('Escribe qué hay que hacer.');
        }
        $dueUtc = null;
        if (is_string($due) && trim($due) !== '') {
            $dueUtc = Fmt::fromEtInput($due);
            if ($dueUtc === null) {
                return self::fail('La fecha de la tarea no es válida.');
            }
        }
        $prio = (int) $priority;
        $prio = isset(Catalog::PRIORITIES[$prio]) ? $prio : 2;
        if (Lead::find($leadId) === null) {
            return self::fail('El lead no existe.');
        }
        return Db::transaction(static function () use ($leadId, $title, $dueUtc, $prio, $userId): array {
            $id = Db::insert('tasks', ['title' => $title, 'lead_id' => $leadId, 'due_at' => $dueUtc, 'priority' => $prio, 'created_by' => $userId, 'created_at' => Clock::now()]);
            self::event($leadId, 'task_created', ['task_id' => $id, 'title' => $title, 'due_at' => $dueUtc, 'priority' => $prio], $userId);
            ActivityLog::record('task_add', $userId, 'lead', $leadId, ['task_id' => $id]);
            return self::ok('Tarea creada.');
        });
    }

    public static function completeTask(int $leadId, int $taskId, int $userId): array
    {
        return Db::transaction(static function () use ($leadId, $taskId, $userId): array {
            $t = Db::one('SELECT id, title FROM tasks WHERE id = ? AND lead_id = ? AND deleted_at IS NULL AND done_at IS NULL FOR UPDATE', [$taskId, $leadId]);
            if ($t === null) {
                return self::fail('La tarea no existe o ya estaba completada.');
            }
            Db::update('tasks', $taskId, ['done_at' => Clock::now()]);
            self::event($leadId, 'task_done', ['task_id' => $taskId, 'title' => $t['title']], $userId);
            ActivityLog::record('task_done', $userId, 'lead', $leadId, ['task_id' => $taskId]);
            return self::ok('Tarea completada.');
        });
    }

    /** Llamada registrada a mano. $minutes: duracion en minutos (opcional, 0 a 600). Si el lead contesto y sigue en «Nuevo», pasa a «Contactado». */
    public static function logCall(int $leadId, mixed $outcome, mixed $minutes, mixed $note, int $userId): array
    {
        $outcome = is_string($outcome) ? $outcome : '';
        if (!isset(Catalog::CALL_OUTCOMES[$outcome])) {
            return self::fail('Elige el resultado de la llamada.');
        }
        $min = is_numeric($minutes) ? (int) $minutes : null;
        if ($min !== null && ($min < 0 || $min > 600)) {
            return self::fail('La duración debe estar entre 0 y 600 minutos.');
        }
        $note = self::clean($note, 500);
        return Db::transaction(static function () use ($leadId, $outcome, $min, $note, $userId): array {
            $lead = Lead::find($leadId, true);
            if ($lead === null) {
                return self::fail('El lead no existe.');
            }
            $now = Clock::now();
            Db::insert('messages', [
                'lead_id' => $leadId, 'channel' => 'call', 'direction' => 'out', 'body' => $note !== '' ? $note : null, 'status' => $outcome,
                'duration_sec' => $min !== null ? $min * 60 : null, 'user_id' => $userId, 'created_at' => $now,
            ]);
            self::event($leadId, 'call_logged', array_filter(['outcome' => $outcome, 'minutes' => $min, 'note' => $note !== '' ? $note : null], static fn($v) => $v !== null), $userId);
            if ($outcome === 'answered') {
                if ($lead['first_contact_at'] === null) {
                    Db::update('leads', $leadId, ['first_contact_at' => $now]);
                    self::cancelA3($leadId, (int) $lead['attempt'], $userId, 'el lead contestó una llamada');
                }
                if ($lead['stage'] === 'new') {
                    self::setStage($leadId, 'contacted', ['auto' => true, 'note' => 'Llamada contestada'], $userId);
                }
            }
            ActivityLog::record('call_log', $userId, 'lead', $leadId, ['outcome' => $outcome]);
            return self::ok('Llamada registrada: ' . Catalog::CALL_OUTCOMES[$outcome] . '.');
        });
    }

    public static function revokeConsent(int $leadId, int $userId): array
    {
        if (Lead::find($leadId) === null) {
            return self::fail('El lead no existe.');
        }
        if ((int) Db::value('SELECT COUNT(*) FROM consents WHERE lead_id = ? AND revoked_at IS NULL', [$leadId]) === 0) {
            return self::fail('No hay consentimientos vigentes que revocar.');
        }
        $n = Consent::revoke($leadId, 'all', 'panel');
        ActivityLog::record('consent_revoke', $userId, 'lead', $leadId, ['revoked' => $n]);
        return self::ok('Consentimiento revocado (' . $n . '). El lead queda marcado como STOP.');
    }
}
