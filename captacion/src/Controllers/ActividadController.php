<?php
/** /actividad (solo administradores): registro de actividad con filtros, paginacion, exportacion CSV y retencion configurable (con confirmacion 2FA reciente). */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Db;
use Captacion\Services\ActivityLog;
use Captacion\Services\ActivityView;
use Captacion\Services\Reauth;

final class ActividadController extends Responder
{
    public static function route(?string $sub): never
    {
        $user = self::user();
        self::requireAdmin($user);
        match ($sub) {
            null        => self::index($user),
            'export'    => self::export($user),
            'retention' => self::retention($user),
            default     => self::notFound(),
        };
    }

    private static function index(array $user): never
    {
        $f = ActivityView::filters($_GET);
        $page = ActivityView::query($f, max(1, min(100000, (int) ($_GET['page'] ?? 1))));
        $users = Db::all('SELECT id, name FROM users ORDER BY name');
        self::page('actividad', $user, 'actividad', [['Administración', null], ['Actividad', null]], [
            'title' => 'Actividad', 'css' => ['config', 'actividad'], 'js' => ['config'], 'f' => $f, 'res' => $page, 'users' => $users, 'retention' => ActivityView::retentionDays(),
            'locked' => !Reauth::fresh(), 'reauthLeft' => Reauth::remaining(), 'reauthTtl' => Reauth::ttl(), 'total' => (int) Db::value('SELECT COUNT(*) FROM activity_log'),
            'oldest' => Db::value('SELECT MIN(created_at) FROM activity_log'),
        ]);
    }

    private static function export(array $user): never
    {
        self::requirePost('/actividad');
        parse_str(is_string($_POST['qs'] ?? null) ? mb_substr($_POST['qs'], 0, 600) : '', $q);
        $f = ActivityView::filters($q);
        $res = ActivityView::query($f, 1, ActivityView::EXPORT_MAX);
        ActivityLog::record('activity_export', (int) $user['id'], 'activity', null, ['rows' => count($res['rows']), 'filters' => $f]);
        self::download('actividad-' . gmdate('Ymd-His') . '.csv', 'text/csv; charset=utf-8', ActivityView::csv($res['rows']));
    }

    private static function retention(array $user): never
    {
        self::requirePost('/actividad#retencion');
        if (!Reauth::fresh()) {
            self::flash(false, 'Confirma tu identidad (contraseña y código) para cambiar la retención.');
            self::redirect('/actividad#retencion');
        }
        $r = \Captacion\Services\ActivityView::setRetention($_POST['days'] ?? '', (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect('/actividad#retencion');
    }
}
