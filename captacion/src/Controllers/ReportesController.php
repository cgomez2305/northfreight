<?php
/**
 * /reportes (solo administradores): rango de fechas con comparacion, embudo, tiempos, costos por canal, paginas de llegada, perfiles, equipos, estados y motivos de perdida
 * (definiciones en Services\Reports). POST /reportes/export descarga el CSV de una tabla (con proteccion contra formulas) y lo registra en activity_log.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Services\ActivityLog;
use Captacion\Services\Reports;

final class ReportesController extends Responder
{
    public static function route(?string $sub): never
    {
        $user = self::user();
        self::requireAdmin($user);
        if ($sub === 'export') {
            self::export($user);
        }
        if ($sub !== null) {
            self::notFound();
        }
        self::index($user);
    }

    private static function index(array $user): never
    {
        $r = Reports::resolve($_GET);
        $cur = Reports::compute($r['from'], $r['to']);
        $prev = Reports::compute($r['prev_from'], $r['prev_to']);
        self::page('reportes', $user, 'reportes', [['Reportes', null], [$r['label'], null]], [
            'title' => 'Reportes', 'css' => ['charts', 'reportes'], 'range' => $r, 'm' => $cur, 'p' => $prev, 'presets' => Reports::PRESETS, 'tables' => Reports::TABLES,
        ]);
    }

    private static function export(array $user): never
    {
        self::requirePost('/reportes');
        $table = is_string($_POST['table'] ?? null) ? $_POST['table'] : '';
        if (!isset(Reports::TABLES[$table])) {
            self::flash(false, 'Esa tabla no existe.');
            self::redirect('/reportes');
        }
        $r = Reports::resolve($_POST);
        $m = Reports::compute($r['from'], $r['to']);
        ActivityLog::record('report_export', (int) $user['id'], 'report', null, ['table' => $table, 'range' => $r['key'], 'from' => $r['from_in'], 'to' => $r['to_in'], 'rows' => count(Reports::table($table, $m)['rows'])]);
        self::download('reporte-' . $table . '-' . $r['from_in'] . '_' . $r['to_in'] . '.csv', 'text/csv; charset=utf-8', Reports::csv($table, $m));
    }
}
