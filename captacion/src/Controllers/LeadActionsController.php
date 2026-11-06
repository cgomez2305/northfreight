<?php
/** POST /leads/{id}/{accion}: stage, note, note-edit, note-delete, task, task-done, call, reopen, revoke. Siempre con sesion, CSRF y registro en activity_log (lo hace LeadActions). */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Db;
use Captacion\Services\Handoff;
use Captacion\Services\LeadActions;
use Captacion\Models\Lead;
use Captacion\Services\Sequences;
use Captacion\Services\Undo;

final class LeadActionsController extends Responder
{
    private const ANCHORS = ['stage' => '', 'note' => '#notas', 'note-edit' => '#notas', 'note-delete' => '#notas', 'task' => '#tareas', 'task-done' => '#tareas', 'call' => '#linea', 'reopen' => '', 'revoke' => '#consentimientos',
        'handoff-signed' => '#entrega', 'handoff-rejected' => '#entrega', 'handoff-resend' => '#entrega', 'seq-pause' => '#secuencias', 'seq-resume' => '#secuencias', 'seq-remove' => '#secuencias', 'responded' => '#secuencias'];

    public static function handle(int $id, string $action): never
    {
        $user = self::user();
        $back = '/leads/' . $id;
        if (!isset(self::ANCHORS[$action])) {
            self::notFound();
        }
        self::requirePost($back);
        $uid = (int) $user['id'];
        $from = $action === 'stage' ? (string) (Lead::find($id)['stage'] ?? '') : '';
        $r = match ($action) {
            'stage'       => LeadActions::setStage($id, (string) ($_POST['stage'] ?? ''), ['reason' => (string) ($_POST['reason'] ?? ''), 'note' => $_POST['note'] ?? '', 'override' => ($_POST['override'] ?? '') === '1'], $uid),
            'note'        => LeadActions::addNote($id, $_POST['body'] ?? '', $uid),
            'note-edit'   => LeadActions::editNote($id, (int) ($_POST['note_id'] ?? 0), $_POST['body'] ?? '', $uid),
            'note-delete' => LeadActions::deleteNote($id, (int) ($_POST['note_id'] ?? 0), $uid),
            'task'        => LeadActions::addTask($id, $_POST['title'] ?? '', $_POST['due'] ?? '', $_POST['priority'] ?? 2, $uid),
            'task-done'   => LeadActions::completeTask($id, (int) ($_POST['task_id'] ?? 0), $uid),
            'call'        => LeadActions::logCall($id, $_POST['outcome'] ?? '', $_POST['minutes'] ?? null, $_POST['note'] ?? '', $uid),
            'reopen'      => LeadActions::reopen($id, $uid),
            'revoke'      => LeadActions::revokeConsent($id, $uid),
            'handoff-signed'   => Handoff::confirmFromPanel($id, 'signed', $uid),
            'handoff-rejected' => Handoff::confirmFromPanel($id, 'rejected', $uid, ['reason' => is_string($_POST['reason'] ?? null) ? $_POST['reason'] : 'rejected', 'note' => $_POST['note'] ?? '']),
            'handoff-resend'   => Handoff::resend($id, $uid),
            'seq-pause', 'seq-resume', 'seq-remove' => self::sequence($id, $action, $uid),
            'responded'        => Sequences::responded($id, $uid),
        };
        // Q5a-G: «Deshacer» para el cambio de etapa (si es reversible), completar una tarea y borrar una nota
        $undo = null;
        if ($r['ok']) {
            $undo = match ($action) {
                'stage' => Undo::stage($uid, $id, $from, (string) ($_POST['stage'] ?? '')),
                'task-done' => Undo::task($uid, (int) ($_POST['task_id'] ?? 0)),
                'note-delete' => Undo::note($uid, $id, (int) ($_POST['note_id'] ?? 0)),
                default => null,
            };
        }
        self::flash($r['ok'], $r['msg'], $undo);
        self::redirect($back . self::ANCHORS[$action]);
    }

    /** Pausar / reanudar / sacar una inscripcion; solo si pertenece a este lead. */
    private static function sequence(int $leadId, string $action, int $userId): array
    {
        $eid = is_string($_POST['enrollment_id'] ?? null) && ctype_digit($_POST['enrollment_id']) ? (int) $_POST['enrollment_id'] : 0;
        $own = $eid > 0 ? Db::value('SELECT COUNT(*) FROM sequence_enrollments WHERE id = ? AND lead_id = ?', [$eid, $leadId]) : 0;
        if ((int) $own !== 1) {
            return ['ok' => false, 'msg' => 'Esa inscripción no es de este lead.'];
        }
        return Sequences::manage($eid, substr($action, 4), $userId);
    }
}
