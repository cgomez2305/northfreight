<?php
/**
 * /resultados: el dashboard de resultados para NorthStar (rol lector) y, para el administrador, la misma vista que ve el cliente. SOLO agregados (Reports::forViewer):
 * totales, embudo, firmados, costo por firmado, tendencia y motivos de perdida. Nunca nombres, telefonos, correos, USDOT ni ids de lead, y sin exportaciones.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Services\Reports;

final class ResultadosController extends Responder
{
    public static function index(): never
    {
        $user = self::user(true);
        $r = Reports::resolve(['r' => $_GET['r'] ?? 'lastmonth'], Reports::VIEWER_PRESETS);
        $cur = Reports::forViewer(Reports::compute($r['from'], $r['to']));
        $prev = Reports::forViewer(Reports::compute($r['prev_from'], $r['prev_to']));
        self::page('resultados', $user, 'resultados', [['Resultados', null], [$r['label'], null]], [
            'title' => 'Resultados', 'css' => ['charts', 'reportes'], 'range' => $r, 'm' => $cur, 'p' => $prev, 'presets' => Reports::VIEWER_PRESETS,
        ]);
    }
}
