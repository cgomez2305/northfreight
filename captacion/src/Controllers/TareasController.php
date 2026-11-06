<?php
/**
 * /tareas: lista de tareas y citas (Hoy, Vencidas, Próximas, Hechas, Semana) y sus acciones (POST con CSRF, redireccion a la misma pestaña).
 * Es la misma tabla que las tareas de la ficha del lead (Services\Tasks). Las cuentas de solo lectura (viewer) ven todo pero no modifican nada.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Services\Tasks;
use Captacion\Services\Undo;

final class TareasController extends Responder
{
    public static function index(): never
    {
        $user = self::user();
        $now = Clock::time();
        $tab = is_string($_GET['tab'] ?? null) && isset(Tasks::TABS[$_GET['tab']]) ? $_GET['tab'] : 'today';
        $counts = Tasks::counts($now);
        $vars = ['title' => 'Tareas', 'css' => ['tareas'], 'js' => ['tareas'], 'tab' => $tab, 'counts' => $counts, 'canWrite' => ($user['role'] ?? '') !== 'viewer', 'now' => $now];
        if ($tab === 'week') {
            $vars['week'] = Tasks::week(is_string($_GET['w'] ?? null) ? $_GET['w'] : null, $now);
        } else {
            $vars['groups'] = Tasks::listTab($tab, $now);
        }
        self::page('tareas', $user, 'tareas', [['Tareas', null]], $vars);
    }

    /** Vuelta a la misma pestaña (solo rutas internas /tareas...). */
    private static function backUrl(): string
    {
        $b = is_string($_POST['back'] ?? null) ? $_POST['back'] : '/tareas';
        return preg_match('#^/tareas(\?[A-Za-z0-9_%=&.\-+]{0,80})?$#', $b) === 1 ? $b : '/tareas';
    }

    /** POST /tareas/new */
    public static function create(): never
    {
        $user = self::user();
        $back = self::backUrl();
        self::requirePost($back);
        self::requireWriter($user, $back);
        $r = Tasks::create($_POST['title'] ?? '', $_POST['due'] ?? '', $_POST['priority'] ?? 2, $_POST['lead_id'] ?? null, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    /** POST /tareas/{id}/{done|reopen|snooze|edit|delete} */
    public static function handle(int $id, string $action): never
    {
        $user = self::user();
        $back = self::backUrl();
        if (!in_array($action, ['done', 'reopen', 'snooze', 'edit', 'delete'], true)) {
            self::notFound();
        }
        self::requirePost($back);
        self::requireWriter($user, $back);
        $uid = (int) $user['id'];
        $r = match ($action) {
            'done'   => Tasks::complete($id, $uid),
            'reopen' => Tasks::reopen($id, $uid),
            'snooze' => Tasks::snooze($id, is_string($_POST['mode'] ?? null) ? $_POST['mode'] : '', $_POST['date'] ?? '', $uid),
            'edit'   => Tasks::update($id, $_POST['title'] ?? '', $_POST['due'] ?? '', $_POST['priority'] ?? 2, $_POST['lead_id'] ?? null, $uid),
            'delete' => Tasks::delete($id, $uid),
        };
        self::flash($r['ok'], $r['msg'], $r['ok'] && $action === 'done' ? Undo::task($uid, $id) : null);   // Q5a-G: «Deshacer» al completar
        self::redirect($back);
    }
}
