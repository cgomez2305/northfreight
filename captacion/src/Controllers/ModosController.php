<?php
/**
 * Modos de vista de los leads (Q5c): Tabla (/tabla), Calendario (/calendario) y Cronología (/cronologia). Comparten con la Lista y el Tablero los MISMOS filtros (LeadQuery::filters),
 * chips, vistas guardadas, densidad y vistazo rápido. Todo se sirve desde el servidor (enlaces y formularios); el JS solo mejora (arrastrar tareas, «Cargar más»).
 *
 * Seguridad: solo administradores (user() deniega al lector por defecto; los endpoints JSON usan jsonPost() sin viewerOk). Todo POST con sesión, 2FA y CSRF. Orden, rango, modo, tipos y cursor
 * se validan con lista blanca; en la URL solo viaja lo que ya viajaba en la Lista (filtros) y ids, nunca teléfonos ni correos.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Db;
use Captacion\QueryTimeout;
use Captacion\Services\ActivityLog;
use Captacion\Services\Fmt;
use Captacion\Services\LeadCalendar;
use Captacion\Services\LeadFeed;
use Captacion\Services\LeadQuery;
use Captacion\Services\LeadTable;
use Captacion\Services\Prefs;
use Captacion\Services\RateLimiter;
use Captacion\Services\SavedViews;
use Captacion\Services\Tasks;
use Captacion\Services\Undo;
use Captacion\Ui;
use Captacion\View;

final class ModosController extends Responder
{
    public const EXPORT_LIMIT = 20;   // exportaciones de la Tabla por hora y usuario
    public const MOVE_LIMIT = 120;    // tareas movidas por minuto y usuario
    public const FEED_LIMIT = 120;    // páginas de «Cargar más» por minuto y usuario

    private static function limit(string $bucket, array $user, int $max, int $window = 60): void
    {
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany($bucket, $key, $max, $window)) {
            self::json(['ok' => false, 'error' => 'rate_limited', 'msg' => 'Demasiadas peticiones seguidas. Espera un momento.'], 429, ['Retry-After: 20']);
        }
        RateLimiter::hit($bucket, $key, $window);
    }

    /** Variables que comparten las tres pantallas (barra de vistas, filtros, contadores). */
    private static function common(array $user, array $f): array
    {
        $uid = (int) $user['id'];
        return [
            'f' => $f, 'facets' => LeadQuery::facets(), 'activeView' => SavedViews::active($uid, $_GET, $f), 'sysViews' => SavedViews::system(), 'myViews' => SavedViews::mine($uid),
            'peekId' => (int) ($_GET['peek'] ?? 0), 'canWrite' => ($user['role'] ?? '') !== 'viewer', 'grandTotal' => (int) Db::value('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL'),
        ];
    }

    // ------------------------------------------------------------------------------------------------ Tabla

    public static function tabla(): never
    {
        $user = self::user();
        $uid = (int) $user['id'];
        Prefs::remember($uid, 'tabla');
        $f = LeadQuery::filters($_GET);
        $paging = LeadTable::paging($_GET);
        $list = LeadTable::list($f, $paging);
        self::page('tabla', $user, 'leads', [['Leads', '/leads'], ['Tabla', null]], self::common($user, $f) + [
            'title' => 'Tabla de leads', 'css' => ['leads', 'views', 'filtros', 'tabla'], 'js' => ['peek', 'views'], 'paging' => $paging, 'list' => $list, 'counts' => LeadQuery::tempCounts($f),
            'tcols' => Prefs::tableColumns($uid), 'nextTasks' => LeadTable::nextTasks($list['rows']),
        ]);
    }

    /** POST /tabla/export: CSV de lo filtrado (o de la selección) con las columnas visibles, en su orden. Protección contra fórmulas (Csv::cell) y registro en la actividad. */
    public static function tablaExport(): never
    {
        $user = self::user();
        self::requirePost('/tabla');
        if (RateLimiter::tooMany('tabla_export', 'u' . (int) $user['id'], self::EXPORT_LIMIT, 3600)) {
            self::flash(false, 'Hiciste muchas exportaciones seguidas. Espera un rato y vuelve a intentarlo.');
            self::redirect('/tabla');
        }
        RateLimiter::hit('tabla_export', 'u' . (int) $user['id'], 3600);
        parse_str(is_string($_POST['qs'] ?? null) ? mb_substr((string) $_POST['qs'], 0, 800) : '', $q);
        $selection = ($_POST['scope'] ?? '') === 'selection' || ($_POST['do'] ?? '') === 'export';
        $ids = $selection && is_array($_POST['ids'] ?? null) ? array_map('intval', $_POST['ids']) : null;
        if ($selection && $ids === null) {
            $ids = [];
        }
        $f = LeadQuery::filters($q);
        $paging = LeadTable::paging($q);
        $cols = Prefs::tableColumns((int) $user['id']);
        $r = LeadTable::csv($f, $paging, $cols, $ids);
        ActivityLog::record('leads_export', (int) $user['id'], 'lead', null, ['rows' => $r['rows'], 'scope' => $ids !== null ? 'selection' : 'filters', 'mode' => 'tabla', 'filters' => $f, 'cols' => $cols]);
        self::download('leads-tabla-' . ($ids !== null ? 'seleccion' : 'filtro') . '-' . gmdate('Ymd-His') . '.csv', 'text/csv; charset=utf-8', $r['csv']);
    }

    /** POST /tabla/cols (formulario, funciona sin JS): elegir columnas, subir o bajar una, o restablecer. Se guarda por usuario en `tcols`. */
    public static function tablaCols(): never
    {
        $user = self::user();
        $uid = (int) $user['id'];
        $b = is_string($_POST['back'] ?? null) ? $_POST['back'] : '/tabla';
        $back = preg_match('#^/tabla(\?[A-Za-z0-9_%=&.\-+]{0,600})?$#', $b) === 1 ? $b : '/tabla';
        self::requirePost($back);
        $cur = Prefs::tableColumns($uid);
        $new = $cur;
        if (isset($_POST['reset'])) {
            $new = explode(',', Prefs::DEFAULTS['tcols']);
        } elseif (is_string($_POST['move'] ?? null) && preg_match('/^([a-z]{2,10}):(up|down)$/', $_POST['move'], $m) === 1) {
            $i = array_search($m[1], $cur, true);
            $j = $i === false ? false : ($m[2] === 'up' ? $i - 1 : $i + 1);
            if ($i !== false && $j !== false && $j >= 0 && $j < count($cur)) {
                [$new[$i], $new[$j]] = [$new[$j], $new[$i]];
            }
        } elseif (is_array($_POST['col'] ?? null)) {
            $checked = array_values(array_filter(array_map('strval', $_POST['col']), static fn($c) => isset(LeadTable::COLUMNS[$c])));
            $new = array_values(array_filter($cur, static fn($c) => in_array($c, $checked, true)));
            foreach (array_keys(LeadTable::COLUMNS) as $c) {
                if (in_array($c, $checked, true) && !in_array($c, $new, true)) {
                    $new[] = $c;
                }
            }
        }
        if (!Prefs::set($uid, 'tcols', implode(',', $new))) {
            self::flash(false, 'Deja al menos una columna visible.');
        }
        self::redirect($back);
    }

    // ------------------------------------------------------------------------------------------------ Calendario

    public static function calendario(): never
    {
        $user = self::user();
        $uid = (int) $user['id'];
        Prefs::remember($uid, 'calendario');
        $f = LeadQuery::filters($_GET);
        $explicit = is_string($_GET['vm'] ?? null) && isset(LeadCalendar::MODES[$_GET['vm']]);
        $mode = LeadCalendar::mode($_GET['vm'] ?? null, Prefs::get($uid, 'calm'));
        if ($explicit && Prefs::get($uid, 'calm') !== $mode) {
            Prefs::set($uid, 'calm', $mode);
        }
        $now = Clock::time();
        $today = Fmt::et($now)->format('Y-m-d');
        $anchor = LeadCalendar::ymd($_GET['d'] ?? null) ?? $today;
        $types = LeadCalendar::types($_GET['ev'] ?? null);
        $cal = null;
        $error = false;
        try {
            $cal = Db::withTimeout(5, static fn() => LeadCalendar::view($mode, $anchor, $f, $types, $now));
        } catch (QueryTimeout) {
            $error = true;
        }
        $sel = $anchor;
        if ($cal !== null && !in_array($sel, array_column($cal['days'], 'ymd'), true)) {
            $sel = $cal['days'][0]['ymd'];
        }
        self::page('calendario', $user, 'leads', [['Leads', '/leads'], ['Calendario', null]], self::common($user, $f) + [
            'title' => 'Calendario', 'css' => ['views', 'filtros', 'calendario'], 'js' => ['peek', 'views', 'calendario'], 'cal' => $cal, 'calError' => $error, 'mode' => $mode, 'anchor' => $anchor, 'sel' => $sel, 'types' => $types,
            'allTypes' => count($types) === count(LeadCalendar::TYPES), 'explicitAnchor' => isset($_GET['d']) && LeadCalendar::ymd($_GET['d']) !== null, 'newDay' => LeadCalendar::ymd($_GET['nueva'] ?? null), 'now' => $now,
            'prios' => \Captacion\Services\Catalog::PRIORITIES, 'startTime' => \Captacion\Services\Display::hours()['start'],
        ]);
    }

    /**
     * POST /ajax/calendar/move {task_id, ymd} | {task_id, mode: '1h'|'tomorrow'}: reprograma una tarea abierta (arrastrar a otro día, Alt + flechas, «Posponer»). Devuelve el token de «Deshacer».
     * La hora del día se conserva en la zona de visualización (Tasks::reschedule).
     */
    public static function calMove(): never
    {
        [$user, $b] = self::jsonPost(2048);
        self::limit('cal_move', $user, self::MOVE_LIMIT);
        $uid = (int) $user['id'];
        $id = (int) ($b['task_id'] ?? 0);
        $mode = is_string($b['mode'] ?? null) ? $b['mode'] : 'date';
        if ($id < 1) {
            self::json(['ok' => false, 'error' => 'invalid', 'msg' => 'Falta la tarea.'], 422);
        }
        if ($mode === 'date') {
            $r = Tasks::reschedule($id, is_string($b['ymd'] ?? null) ? $b['ymd'] : '', $uid);
        } elseif (in_array($mode, ['1h', 'tomorrow'], true)) {
            $r = Tasks::snooze($id, $mode, null, $uid);
        } else {
            self::json(['ok' => false, 'error' => 'invalid', 'msg' => 'Opción no válida.'], 422);
        }
        if (!$r['ok']) {
            self::json(['ok' => false, 'error' => 'rejected', 'msg' => $r['msg']], 422);
        }
        $d = Fmt::et((string) $r['due_at']);
        self::json(['ok' => true, 'msg' => $r['msg'], 'undo' => Undo::resched($uid, $id, $r['from'] ?? null, (string) $r['due_at']), 'due_at' => $r['due_at'], 'ymd' => $d->format('Y-m-d'), 'time' => $d->format('H:i')]);
    }

    /** POST /calendario/move (formulario, sin JS): mueve una tarea abierta a otro día conservando su hora; el aviso trae «Deshacer». */
    public static function calMoveForm(): never
    {
        $user = self::user();
        $b = is_string($_POST['back'] ?? null) ? $_POST['back'] : '/calendario';
        $back = preg_match('#^/calendario(\?[A-Za-z0-9_%=&.\-+]{0,500})?$#', $b) === 1 ? $b : '/calendario';
        self::requirePost($back);
        self::requireWriter($user, $back);
        $uid = (int) $user['id'];
        $id = (int) ($_POST['task_id'] ?? 0);
        $r = Tasks::reschedule($id, is_string($_POST['ymd'] ?? null) ? $_POST['ymd'] : '', $uid);
        self::flash($r['ok'], $r['msg'], $r['ok'] ? Undo::resched($uid, $id, $r['from'] ?? null, (string) $r['due_at']) : null);
        self::redirect($back);
    }

    // ------------------------------------------------------------------------------------------------ Cronología

    public static function cronologia(): never
    {
        $user = self::user();
        $uid = (int) $user['id'];
        Prefs::remember($uid, 'cronologia');
        $f = LeadQuery::filters($_GET);
        $types = LeadFeed::types($_GET['tp'] ?? null);
        $ld = LeadFeed::leadId($_GET['ld'] ?? null);
        $cur = is_string($_GET['cur'] ?? null) && LeadFeed::decodeCursor($_GET['cur']) !== null ? $_GET['cur'] : null;
        $feed = null;
        $error = false;
        try {
            $feed = LeadFeed::page($f, $types, $ld, $cur, Clock::time());
        } catch (QueryTimeout) {
            $error = true;
        }
        $leadRow = $ld !== null ? Db::one('SELECT id, first_name, last_name FROM leads WHERE id = ? AND deleted_at IS NULL', [$ld]) : null;
        self::page('cronologia', $user, 'leads', [['Leads', '/leads'], ['Cronología', null]], self::common($user, $f) + [
            'title' => 'Cronología', 'css' => ['views', 'filtros', 'cronologia'], 'js' => ['peek', 'views', 'cronologia'], 'feed' => $feed, 'feedError' => $error, 'types' => $types, 'allTypes' => count($types) === count(LeadFeed::TYPES),
            'ld' => $ld, 'ldName' => $leadRow !== null ? \Captacion\Services\Fmt::name($leadRow) . ' · ' . Fmt::code((int) $leadRow['id']) : null, 'cursor' => $cur, 'render' => static fn(array $days): string => View::render('_feed', ['days' => $days], false),
        ]);
    }

    /** POST /ajax/timeline {qs, cursor} -> {ok, html, next, count}: la siguiente página de la cronología («Cargar más»). */
    public static function cronoMore(): never
    {
        [$user, $b] = self::jsonPost(4096);
        self::limit('feed_more', $user, self::FEED_LIMIT);
        parse_str(is_string($b['qs'] ?? null) ? mb_substr($b['qs'], 0, 800) : '', $q);
        $cursor = is_string($b['cursor'] ?? null) && LeadFeed::decodeCursor($b['cursor']) !== null ? $b['cursor'] : null;
        if ($cursor === null) {
            self::json(['ok' => false, 'error' => 'invalid', 'msg' => 'Cursor no válido.'], 422);
        }
        try {
            $page = LeadFeed::page(LeadQuery::filters($q), LeadFeed::types($q['tp'] ?? null), LeadFeed::leadId($q['ld'] ?? null), $cursor, Clock::time());
        } catch (QueryTimeout) {
            self::json(['ok' => false, 'error' => 'timeout', 'msg' => 'La consulta tardó demasiado. Acota con un filtro o vuelve a intentarlo.'], 503);
        }
        self::json(['ok' => true, 'html' => View::render('_feed', ['days' => $page['days']], false), 'next' => $page['next'], 'count' => $page['count']]);
    }
}
