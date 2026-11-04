<?php
/** POST /leads/{id}/{accion}: stage, note, note-edit, note-delete, task, task-done, call, reopen, revoke. Siempre con sesion, CSRF y registro en activity_log (lo hace LeadActions). */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Services\LeadActions;

final class LeadActionsController extends Responder
{
    private const ANCHORS = ['stage' => '', 'note' => '#notas', 'note-edit' => '#notas', 'note-delete' => '#notas', 'task' => '#tareas', 'task-done' => '#tareas', 'call' => '#linea', 'reopen' => '', 'revoke' => '#consentimientos'];

    public static function handle(int $id, string $action): never
    {
        $user = self::user();
        $back = '/leads/' . $id;
        if (!isset(self::ANCHORS[$action])) {
            self::notFound();
        }
        self::requirePost($back);
        $uid = (int) $user['id'];
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
        };
        self::flash($r['ok'], $r['msg']);
        self::redirect($back . self::ANCHORS[$action]);
    }
}
