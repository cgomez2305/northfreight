<?php
/**
 * Endpoints JSON de la experiencia del panel (Q5a): preferencias, vistazo rapido de un lead, acciones rapidas, alta manual, deshacer y contadores; ademas las vistas guardadas
 * (formularios que funcionan sin JS) y POST /undo. Todo POST con sesion, 2FA, CSRF (X-CSRF-Token o _csrf) y rol en el servidor: solo `prefs` (y solo la clave `theme`)
 * esta abierto al lector de NorthStar; el resto responde 403 al lector sin ningun dato. Sin datos personales en la URL.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;
use Captacion\Services\ActivityLog;
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\Kanban;
use Captacion\Services\LeadActions;
use Captacion\Services\LeadIngest;
use Captacion\Services\LeadQuery;
use Captacion\Services\Notifications;
use Captacion\Services\Prefs;
use Captacion\Services\RateLimiter;
use Captacion\Services\SavedViews;
use Captacion\Services\Sequences;
use Captacion\Services\Tasks;
use Captacion\Services\Timeline;
use Captacion\Services\Undo;
use Captacion\View;

final class UxController extends Responder
{
    public const PEEK_LIMIT = 240;   // vistazos por minuto y usuario
    public const ACT_LIMIT = 120;    // acciones rapidas por minuto y usuario

    private static function limit(string $bucket, array $user, int $max): void
    {
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany($bucket, $key, $max, 60)) {
            self::json(['ok' => false, 'error' => 'rate_limited', 'msg' => 'Demasiadas peticiones seguidas. Espera un momento.'], 429, ['Retry-After: 20']);
        }
        RateLimiter::hit($bucket, $key, 60);
    }

    /** POST /ajax/prefs {key, value}. El lector solo puede cambiar el tema. */
    public static function prefs(): never
    {
        [$user, $b] = self::jsonPost(2048, true);
        $key = is_string($b['key'] ?? null) ? $b['key'] : '';
        if (($user['role'] ?? '') === 'viewer' && $key !== 'theme') {
            self::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        self::limit('ux_prefs', $user, 90);
        if (!Prefs::set((int) $user['id'], $key, $b['value'] ?? null)) {
            self::json(['ok' => false, 'error' => 'invalid'], 422);
        }
        self::json(['ok' => true, 'key' => $key, 'value' => Prefs::get((int) $user['id'], $key)]);
    }

    /** POST /ajax/peek {id} -> {ok, html}: el contenido del vistazo rapido ya escapado. */
    public static function peek(): never
    {
        [$user, $b] = self::jsonPost();
        self::limit('ux_peek', $user, self::PEEK_LIMIT);
        $id = (int) ($b['id'] ?? 0);
        $lead = $id > 0 ? Lead::find($id) : null;
        if ($lead === null) {
            self::json(['ok' => false, 'error' => 'not_found', 'msg' => 'El lead no existe o fue eliminado.'], 404);
        }
        Prefs::touchRecent((int) $user['id'], $id);
        $tasks = Db::all('SELECT id, title, due_at, priority FROM tasks WHERE lead_id = ? AND deleted_at IS NULL AND done_at IS NULL ORDER BY due_at IS NULL, due_at ASC, id ASC LIMIT 4', [$id]);
        $events = Db::all('SELECT type, payload, user_id, created_at FROM lead_events WHERE lead_id = ? ORDER BY created_at DESC, id DESC LIMIT 5', [$id]);
        $users = [];
        foreach (Db::all('SELECT id, name FROM users') as $u) {
            $users[(int) $u['id']] = (string) $u['name'];
        }
        $html = View::render('_peek', [
            'lead' => $lead, 'tasks' => $tasks, 'timeline' => array_slice(Timeline::build($lead, $events, [], [], $users), 0, 5), 'stages' => Catalog::STAGES[(string) $lead['pipeline']] ?? Catalog::STAGES['A'],
            'openTasks' => (int) Db::value('SELECT COUNT(*) FROM tasks WHERE lead_id = ? AND deleted_at IS NULL AND done_at IS NULL', [$id]), 'now' => Clock::time(),
        ], false);
        self::json(['ok' => true, 'id' => $id, 'name' => Fmt::name($lead), 'code' => Fmt::code($id), 'html' => $html]);
    }

    /** POST /ajax/lead/act {id, act, ...}: nota, tarea, tarea hecha, llamada, respondio. Mismas funciones (y validaciones) que la ficha. */
    public static function act(): never
    {
        [$user, $b] = self::jsonPost(8192);
        self::limit('ux_act', $user, self::ACT_LIMIT);
        $uid = (int) $user['id'];
        $id = (int) ($b['id'] ?? 0);
        $act = is_string($b['act'] ?? null) ? $b['act'] : '';
        if ($id < 1 || Lead::find($id) === null) {
            self::json(['ok' => false, 'error' => 'not_found', 'msg' => 'El lead no existe.'], 404);
        }
        $undo = null;
        switch ($act) {
            case 'note':
                $r = LeadActions::addNote($id, $b['body'] ?? '', $uid);
                break;
            case 'task':
                $r = LeadActions::addTask($id, $b['title'] ?? '', $b['due'] ?? '', $b['priority'] ?? 2, $uid);
                break;
            case 'task_done':
                $tid = (int) ($b['task_id'] ?? 0);
                $r = LeadActions::completeTask($id, $tid, $uid);
                $undo = $r['ok'] ? Undo::task($uid, $tid) : null;
                break;
            case 'call':
                $r = LeadActions::logCall($id, $b['outcome'] ?? '', $b['minutes'] ?? null, $b['note'] ?? '', $uid);
                break;
            case 'responded':
                $r = Sequences::responded($id, $uid);
                break;
            default:
                self::json(['ok' => false, 'error' => 'invalid', 'msg' => 'Acción no válida.'], 422);
        }
        self::json(['ok' => (bool) $r['ok'], 'msg' => $r['msg'], 'undo' => $undo], $r['ok'] ? 200 : 422);
    }

    /** POST /ajax/lead/create {name, phone, profile}: alta manual rapida por el motor (validacion, dedupe, puntuacion). Sin consentimiento de mensajeria ni alertas. */
    public static function create(): never
    {
        [$user, $b] = self::jsonPost(4096);
        self::limit('ux_create', $user, 30);
        $in = ['name' => is_string($b['name'] ?? null) ? $b['name'] : '', 'phone' => is_string($b['phone'] ?? null) ? $b['phone'] : '', 'profile' => is_string($b['profile'] ?? null) ? $b['profile'] : ''];
        if (trim($in['phone']) === '') {
            self::json(['ok' => false, 'error' => 'invalid', 'msg' => 'Escribe el teléfono del lead.', 'errors' => ['phone' => 'required']], 422);
        }
        $r = LeadIngest::handle($in, 'manual', ['skip_antispam' => true, 'quiet' => true]);
        if (!$r['ok']) {
            $msg = isset($r['errors']['profile']) ? 'Elige el perfil del lead.' : (isset($r['errors']['name']) ? 'Escribe el nombre del lead.' : (isset($r['errors']['contact']) ? 'El teléfono no es válido.' : 'No se pudo crear el lead.'));
            self::json(['ok' => false, 'error' => 'invalid', 'msg' => $msg, 'errors' => $r['errors'] ?? []], 422);
        }
        $new = $r['status'] === 'created';
        ActivityLog::record('lead_create_manual', (int) $user['id'], 'lead', (int) $r['lead_id'], ['status' => $r['status']]);
        self::json(['ok' => true, 'created' => $new, 'lead_id' => (int) $r['lead_id'], 'url' => '/leads/' . (int) $r['lead_id'],
            'msg' => $new ? 'Lead creado: ' . Fmt::code((int) $r['lead_id']) . '.' : 'Ese teléfono ya estaba en el CRM: se abrió el lead existente (' . Fmt::code((int) $r['lead_id']) . ').']);
    }

    /** POST /ajax/undo {token} */
    public static function undo(): never
    {
        [$user, $b] = self::jsonPost(8192);
        self::limit('ux_act', $user, self::ACT_LIMIT);
        $r = Undo::apply(is_string($b['token'] ?? null) ? $b['token'] : '', (int) $user['id']);
        self::json(['ok' => (bool) $r['ok'], 'msg' => $r['msg'], 'lead_ids' => $r['lead_ids'] ?? []], $r['ok'] ? 200 : 422);
    }

    /** POST /undo (formulario, sin JS): deshace y vuelve a la pagina de origen. */
    public static function undoForm(): never
    {
        $user = self::user();
        $back = self::backUrl('/');
        self::requirePost($back);
        $r = Undo::apply(is_string($_POST['token'] ?? null) ? $_POST['token'] : '', (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    /** POST /ajax/counters: contadores en vivo de la barra lateral (vistas fijadas y bandeja). */
    public static function counters(): never
    {
        [$user] = self::jsonPost();
        self::limit('ux_counters', $user, 30);
        $views = [];
        foreach (SavedViews::sidebar((int) $user['id']) as $v) {
            $views[$v['id']] = $v['count'];
        }
        $notes = Notifications::all((int) $user['id']);
        self::json(['ok' => true, 'views' => $views, 'inbox' => Notifications::unread($notes) + Tasks::overdueCount(), 'unread' => Notifications::unread($notes)]);
    }

    /** POST /vistas/new | /vistas/{id}/{rename|pin|unpin|delete|update} (formularios; vuelven a la pantalla de origen). */
    public static function views(?string $id, ?string $action): never
    {
        $user = self::user();
        $uid = (int) $user['id'];
        $back = self::backUrl('/leads');
        self::requirePost($back);
        parse_str(is_string($_POST['qs'] ?? null) ? mb_substr((string) $_POST['qs'], 0, 800) : '', $q);
        $screen = is_string($_POST['screen'] ?? null) && $_POST['screen'] === 'pipeline' ? 'pipeline' : 'leads';
        $filters = SavedViews::cleanFilters($q);
        $sort = isset(LeadQuery::SORTS[(string) ($q['sort'] ?? '')]) ? (string) $q['sort'] : null;
        $dir = ($q['dir'] ?? '') === 'asc' ? 'asc' : null;
        $pl = $screen === 'pipeline' && ($q['pl'] ?? '') === 'B' ? 'B' : null;
        $vid = $id !== null && ctype_digit($id) ? (int) $id : 0;
        if ($id === 'new') {
            $r = SavedViews::create($uid, $_POST['name'] ?? '', $filters, $sort, $dir, $pl);
            if ($r['ok']) {
                $v = SavedViews::find($uid, (string) $r['id']);
                self::flash(true, $r['msg']);
                self::redirect($v !== null ? SavedViews::href($v, $screen) : $back);
            }
        } else {
            $r = match ($action) {
                'rename' => SavedViews::rename($uid, $vid, $_POST['name'] ?? ''),
                'pin' => SavedViews::pin($uid, $vid, true),
                'unpin' => SavedViews::pin($uid, $vid, false),
                'delete' => SavedViews::delete($uid, $vid),
                'update' => SavedViews::update($uid, $vid, $filters, $sort, $dir, $pl),
                default => ['ok' => false, 'msg' => 'Acción no válida.'],
            };
            if ($r['ok'] && $action === 'delete' && str_contains($back, 'v=' . $vid)) {
                $back = $screen === 'pipeline' ? '/pipeline' : '/leads';
            }
        }
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    /** Vuelta a una ruta interna (parametro `back`), solo caracteres seguros. */
    private static function backUrl(string $default): string
    {
        $b = is_string($_POST['back'] ?? null) ? $_POST['back'] : $default;
        return preg_match('#^/[A-Za-z0-9_/\-]{0,60}(\?[A-Za-z0-9_%=&.\-+]{0,800})?(\#[A-Za-z0-9_-]{1,40})?$#', $b) === 1 ? $b : $default;
    }
}
