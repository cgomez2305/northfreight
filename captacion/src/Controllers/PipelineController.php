<?php
/**
 * /pipeline (kanban). GET /pipeline = pagina; POST /ajax/pipeline/cards = una pagina de tarjetas de una columna; POST /ajax/pipeline/move = cambio de etapa por
 * arrastre o menu «Mover a…». El movimiento llama a LeadActions::setStage (las mismas validaciones, transaccion, evento y activity_log que la ficha);
 * si falta un dato (motivo, nota u override) responde 409 con `needs` para que la interfaz lo pida en un dialogo y reenvie.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Models\Lead;
use Captacion\Services\Kanban;
use Captacion\Services\LeadActions;
use Captacion\Services\LeadQuery;
use Captacion\Services\Prefs;
use Captacion\Services\RateLimiter;
use Captacion\Services\SavedViews;
use Captacion\Services\Undo;
use Captacion\View;

final class PipelineController extends Responder
{
    public const MOVE_LIMIT = 120;   // movimientos por minuto y usuario

    /** @param list<array> $cards */
    private static function renderCards(array $cards, bool $canWrite): string
    {
        $html = '';
        foreach ($cards as $c) {
            $html .= View::render('_kcard', ['c' => $c, 'canWrite' => $canWrite], false);
        }
        return $html;
    }

    public static function index(): never
    {
        $user = self::user();
        $pl = Kanban::pipeline($_GET['pl'] ?? 'A');
        $f = Kanban::filters($_GET);
        $stages = Kanban::stages($pl);
        $counts = Kanban::counts($pl, $f);
        $canWrite = ($user['role'] ?? '') !== 'viewer';
        $columns = [];
        foreach (array_keys($stages) as $st) {
            if ($st !== 'lost' && $counts[$st] > 0) {
                $columns[$st] = Kanban::cards($pl, $st, $f, 0, $counts[$st]);
            }
        }
        $uid = (int) $user['id'];
        self::page('pipeline', $user, 'pipeline', [['Leads', '/leads'], ['Tablero', null]], [
            'title' => 'Pipeline', 'css' => ['kanban', 'views'], 'js' => ['kanban', 'peek', 'views'], 'pl' => $pl, 'activeView' => SavedViews::active($uid, $_GET, $f), 'sysViews' => SavedViews::system(), 'myViews' => SavedViews::mine($uid),
            'density' => Prefs::get($uid, 'density'), 'peekId' => (int) ($_GET['peek'] ?? 0), 'f' => $f, 'stages' => $stages, 'counts' => $counts, 'columns' => $columns,
            'totals' => Kanban::pipelineTotals(), 'canWrite' => $canWrite, 'facets' => LeadQuery::facets(),
            'render' => static fn(array $cards): string => self::renderCards($cards, $canWrite),
        ]);
    }

    public static function cards(): never
    {
        [$user, $b] = self::jsonPost();
        if (RateLimiter::tooMany('pipeline_cards', 'u' . (int) $user['id'], 240, 60)) {
            self::json(['ok' => false, 'error' => 'rate_limited'], 429, ['Retry-After: 15']);
        }
        RateLimiter::hit('pipeline_cards', 'u' . (int) $user['id'], 60);
        $pl = Kanban::pipeline($b['pl'] ?? 'A');
        $st = is_string($b['stage'] ?? null) ? $b['stage'] : '';
        if (!isset(Kanban::stages($pl)[$st])) {
            self::json(['ok' => false, 'error' => 'invalid'], 422);
        }
        parse_str(is_string($b['qs'] ?? null) ? mb_substr($b['qs'], 0, 600) : '', $q);
        $page = Kanban::cards($pl, $st, Kanban::filters($q), max(0, min(100000, (int) ($b['offset'] ?? 0))));
        $canWrite = ($user['role'] ?? '') !== 'viewer';
        self::json(['ok' => true, 'html' => self::renderCards($page['cards'], $canWrite), 'total' => $page['total'], 'next' => $page['next'], 'shown' => count($page['cards'])]);
    }

    /** POST /ajax/pipeline/card {lead_id, qs}: la tarjeta actual de un lead (tras deshacer o un cambio hecho desde el vistazo) y los conteos de su pipeline. */
    public static function card(): never
    {
        [$user, $b] = self::jsonPost();
        if (RateLimiter::tooMany('pipeline_cards', 'u' . (int) $user['id'], 240, 60)) {
            self::json(['ok' => false, 'error' => 'rate_limited'], 429, ['Retry-After: 15']);
        }
        RateLimiter::hit('pipeline_cards', 'u' . (int) $user['id'], 60);
        $id = (int) ($b['lead_id'] ?? 0);
        $lead = $id > 0 ? Lead::find($id) : null;
        if ($lead === null) {
            self::json(['ok' => false, 'error' => 'not_found'], 404);
        }
        parse_str(is_string($b['qs'] ?? null) ? mb_substr($b['qs'], 0, 600) : '', $q);
        $card = Kanban::card($id);
        self::json(['ok' => true, 'lead_id' => $id, 'to' => (string) $lead['stage'], 'pipeline' => (string) $lead['pipeline'], 'card' => $card !== null ? self::renderCards([$card], true) : '', 'counts' => Kanban::counts((string) $lead['pipeline'], Kanban::filters($q))]);
    }

    public static function move(): never
    {
        [$user, $b] = self::jsonPost();
        if (($user['role'] ?? '') === 'viewer') {
            self::json(['ok' => false, 'error' => 'readonly', 'msg' => 'Tu cuenta es de solo lectura.'], 403);
        }
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany('pipeline_move', $key, self::MOVE_LIMIT, 60)) {
            self::json(['ok' => false, 'error' => 'rate_limited', 'msg' => 'Demasiados movimientos seguidos. Espera un momento.'], 429, ['Retry-After: 20']);
        }
        RateLimiter::hit('pipeline_move', $key, 60);
        $id = (int) ($b['lead_id'] ?? 0);
        $to = is_string($b['to'] ?? null) ? $b['to'] : '';
        $lead = $id > 0 ? Lead::find($id) : null;
        if ($lead === null) {
            self::json(['ok' => false, 'error' => 'not_found', 'msg' => 'El lead no existe.'], 404);
        }
        $opt = [
            'reason' => is_string($b['reason'] ?? null) ? $b['reason'] : '',
            'note' => is_string($b['note'] ?? null) ? $b['note'] : '',
            'override' => ($b['override'] ?? false) === true,
        ];
        $need = Kanban::needs($lead, $to, $opt);
        if ($need !== null) {
            self::json(['ok' => false, 'error' => 'needs_input', 'needs' => $need['needs'], 'msg' => $need['msg']], 409);
        }
        $r = LeadActions::setStage($id, $to, $opt, (int) $user['id']);
        if (!$r['ok']) {
            self::json(['ok' => false, 'error' => 'rejected', 'msg' => $r['msg']], 422);
        }
        parse_str(is_string($b['qs'] ?? null) ? mb_substr($b['qs'], 0, 600) : '', $q);
        $card = Kanban::card($id);
        self::json([
            'ok' => true, 'msg' => $r['msg'], 'lead_id' => $id, 'from' => (string) $lead['stage'], 'to' => $to, 'undo' => Undo::stage((int) $user['id'], $id, (string) $lead['stage'], $to),
            'card' => $card !== null ? self::renderCards([$card], true) : '', 'counts' => Kanban::counts((string) $lead['pipeline'], Kanban::filters($q)),
        ]);
    }
}
