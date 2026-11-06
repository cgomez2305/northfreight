<?php
/**
 * /resultados: el dashboard de resultados para NorthStar (rol lector) y, para el administrador, la misma vista que ve el cliente. SOLO agregados (Reports::forViewer):
 * totales, embudo, firmados, costo por firmado, tendencia y motivos de perdida. Nunca nombres, telefonos, correos, USDOT ni ids de lead, y sin exportaciones.
 * Q6-I: con las piezas del «dashboard vivo» (subtitulo-conclusion, medidor de rayas, microlinea, grafico anotado, conteo y entrada coreografiada), todas sobre esos mismos agregados:
 * sin «siguiente mejor accion», sin acciones del administrador y sin refresco ni endpoint propio (es un informe por rango, no un tablero en vivo: no hay nada que refrescar).
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Services\Dashboard;
use Captacion\Services\Reports;
use Captacion\Services\ResultadosBoard;

final class ResultadosController extends Responder
{
    public static function index(): never
    {
        $user = self::user(true);
        $r = Reports::resolve(['r' => $_GET['r'] ?? 'lastmonth'], Reports::VIEWER_PRESETS);
        $cur = Reports::forViewer(Reports::compute($r['from'], $r['to']));
        $prev = Reports::forViewer(Reports::compute($r['prev_from'], $r['prev_to']));
        self::page('resultados', $user, 'resultados', [['Resultados', null], [$r['label'], null]], [
            'title' => 'Resultados', 'css' => ['charts', 'viz', 'reportes'], 'js' => ['dashfx', 'resultados'], 'liveV' => (string) (@filemtime(CAPTACION_ROOT . '/public/assets/dashlive.css') ?: 1),
            'range' => $r, 'm' => $cur, 'p' => $prev, 'presets' => Reports::VIEWER_PRESETS, 'minBase' => Dashboard::MIN_BASE,
            // Q6-I: piezas del dashboard vivo calculadas SOLO de los agregados anteriores (ninguna consulta, ningun dato personal)
            'narr' => ResultadosBoard::headline($cur, $prev, $r), 'kpis' => ResultadosBoard::kpis($cur, $prev, $r), 'cnote' => ResultadosBoard::chartNote($cur, $r['to']), 'fnote' => ResultadosBoard::funnelNote($cur),
        ]);
    }
}
