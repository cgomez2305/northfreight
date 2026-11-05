<?php
/**
 * Graficos SVG generados en PHP (sin librerias ni estilos en linea: el color lo ponen las clases de panel.css; los atributos de presentacion de SVG
 * estan permitidos por la CSP). Devuelven HTML ya escapado/numerico, listo para imprimir con echo.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class Charts
{
    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** Mantiene los puntos de control entre los dos extremos del tramo (sin rebasar valores: evita bajar de cero). */
    private static function clampY(float $y, float $a, float $b): float
    {
        return max(min($a, $b), min(max($a, $b), $y));
    }

    /** Curva suave (Catmull-Rom convertida a Bezier) por los puntos [[x,y],...]. */
    public static function smooth(array $pts): string
    {
        $c = count($pts);
        if ($c === 0) {
            return '';
        }
        $d = 'M' . self::n($pts[0][0]) . ' ' . self::n($pts[0][1]);
        if ($c === 1) {
            return $d;
        }
        for ($i = 0; $i < $c - 1; $i++) {
            $p0 = $pts[max(0, $i - 1)];
            $p1 = $pts[$i];
            $p2 = $pts[$i + 1];
            $p3 = $pts[min($c - 1, $i + 2)];
            $t = 0.12;
            $d .= ' C' . self::n($p1[0] + ($p2[0] - $p0[0]) * $t) . ' ' . self::n(self::clampY($p1[1] + ($p2[1] - $p0[1]) * $t, $p1[1], $p2[1]))
                . ' ' . self::n($p2[0] - ($p3[0] - $p1[0]) * $t) . ' ' . self::n(self::clampY($p2[1] - ($p3[1] - $p1[1]) * $t, $p1[1], $p2[1]))
                . ' ' . self::n($p2[0]) . ' ' . self::n($p2[1]);
        }
        return $d;
    }

    /** Micrografico de linea (tarjeta KPI). */
    public static function spark(array $values, int $w = 96, int $h = 36): string
    {
        $values = array_map('floatval', array_values($values));
        $c = count($values);
        if ($c < 2) {
            return '';
        }
        $max = max($values);
        $min = min($values);
        $span = max(1.0, $max - $min);
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = [3 + ($w - 6) * $i / ($c - 1), 4 + ($h - 8) * (1 - ($v - $min) / $span)];
        }
        $last = $pts[$c - 1];
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true" focusable="false">'
            . '<path class="spark__line" d="' . self::smooth($pts) . '" fill="none"/><circle class="spark__dot" cx="' . self::n($last[0]) . '" cy="' . self::n($last[1]) . '" r="2.6"/></svg>';
    }

    /** Barras crecientes (tarjeta KPI): la ultima en navy. */
    public static function bars(array $values, int $w = 76, int $h = 36): string
    {
        $values = array_map('floatval', array_values($values));
        $c = count($values);
        if ($c === 0) {
            return '';
        }
        $max = max(1.0, max($values));
        $gap = 3;
        $bw = ($w - $gap * ($c - 1)) / $c;
        $out = '<svg class="bars" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true" focusable="false">';
        foreach ($values as $i => $v) {
            $bh = max(3.0, ($h - 2) * $v / $max);
            $out .= '<rect class="bars__b' . ($i === $c - 1 ? ' is-on' : '') . '" x="' . self::n($i * ($bw + $gap)) . '" y="' . self::n($h - $bh) . '" width="' . self::n($bw) . '" height="' . self::n($bh) . '" rx="1.5"/>';
        }
        return $out . '</svg>';
    }

    /** Anillo de progreso (0 a 100). */
    public static function donut(float $pct, int $size = 44): string
    {
        $pct = max(0.0, min(100.0, $pct));
        $r = 16.0;
        $circ = 2 * M_PI * $r;
        return '<svg class="donut" viewBox="0 0 44 44" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">'
            . '<circle class="donut__bg" cx="22" cy="22" r="16" fill="none" stroke-width="5"/>'
            . '<circle class="donut__fg" cx="22" cy="22" r="16" fill="none" stroke-width="5" stroke-linecap="round" transform="rotate(-90 22 22)" stroke-dasharray="' . self::n($circ * $pct / 100) . ' ' . self::n($circ) . '"/></svg>';
    }

    /** Barra horizontal de proporcion (0 a 100) para embudos y rankings. */
    public static function hbar(float $pct, string $cls = ''): string
    {
        $pct = max(0.0, min(100.0, $pct));
        return '<svg class="hb' . ($cls !== '' ? ' ' . htmlspecialchars($cls, ENT_QUOTES) : '') . '" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="hb__bg" width="100" height="8" rx="4"/>'
            . ($pct > 0 ? '<rect class="hb__fg" width="' . self::n(max(2.0, $pct)) . '" height="8" rx="4"/>' : '') . '</svg>';
    }

    /**
     * Barra segmentada. @param list<array{value:float|int,label:string}> $segments; las clases seg-0..seg-4 ponen el color.
     */
    public static function segbar(array $segments): string
    {
        $total = array_sum(array_map(static fn($s) => (float) $s['value'], $segments));
        $out = '<svg class="segbar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
        if ($total <= 0) {
            return $out . '<rect class="seg-e" x="0" y="0" width="100" height="10"/></svg>';
        }
        $x = 0.0;
        $gap = 0.7;
        $n = count($segments);
        foreach ($segments as $i => $s) {
            $w = 100 * (float) $s['value'] / $total;
            if ($w <= 0) {
                continue;
            }
            $out .= '<rect class="seg-' . min(4, $i) . '" x="' . self::n($x) . '" y="0" width="' . self::n(max(0.6, $w - ($i < $n - 1 ? $gap : 0))) . '" height="10"/>';
            $x += $w;
        }
        return $out . '</svg>';
    }

    /**
     * Grafico de lineas con ejes en HTML (el texto no se deforma) y curvas en SVG sin escala de trazo.
     * @param list<string> $labels etiquetas del eje X; @param list<array{name:string,cls:string,values:list<int|float>}> $series
     * @param list<string> $tipLabels titulo del tooltip por punto
     */
    public static function line(string $id, array $labels, array $series, array $tipLabels, string $unit = 'leads'): string
    {
        $n = count($labels);
        $max = 1;
        foreach ($series as $s) {
            $max = max($max, (int) max($s['values'] ?: [0]));
        }
        $step = $max <= 4 ? 1 : (int) ceil($max / 4);
        $top = $step * (int) ceil($max / $step);
        if ($top < 4) {
            $top = 4;
        }
        $ticks = 4;
        $stepY = $top / $ticks;
        $W = 600;
        $H = 220;
        $out = '<div class="lc" data-chart="' . htmlspecialchars((string) json_encode([
            'labels' => $tipLabels, 'max' => $top, 'unit' => $unit,
            'series' => array_map(static fn($s) => ['n' => $s['name'], 'c' => $s['cls'], 'v' => array_values($s['values'])], $series),
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '">';
        $out .= '<div class="lc__y" aria-hidden="true">';
        for ($i = $ticks; $i >= 0; $i--) {
            $out .= '<span>' . self::n($stepY * $i) . '</span>';
        }
        $out .= '</div><div class="lc__plot" tabindex="0" role="group" aria-label="' . htmlspecialchars($id, ENT_QUOTES) . '">';
        $out .= '<svg viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
        for ($i = 0; $i <= $ticks; $i++) {
            $y = $H * $i / $ticks;
            $out .= '<line class="lc__grid' . ($i === $ticks ? ' is-base' : '') . '" x1="0" x2="' . $W . '" y1="' . self::n($y) . '" y2="' . self::n($y) . '" vector-effect="non-scaling-stroke"/>';
        }
        foreach ($series as $s) {
            $pts = [];
            foreach (array_values($s['values']) as $i => $v) {
                $pts[] = [$W * ($i + 0.5) / max(1, $n), $H - ($H - 6) * ((float) $v / $top) - 1];
            }
            $out .= '<path class="lc__line lc__line--' . htmlspecialchars($s['cls'], ENT_QUOTES) . '" d="' . self::smooth($pts) . '" fill="none" vector-effect="non-scaling-stroke"/>';
        }
        $out .= '</svg><div class="lc__band" hidden></div>';
        foreach ($series as $s) {
            $out .= '<span class="lc__dot lc__dot--' . htmlspecialchars($s['cls'], ENT_QUOTES) . '" hidden></span>';
        }
        $out .= '<div class="lc__tip" role="status" hidden></div></div><div class="lc__x" aria-hidden="true">';
        foreach ($labels as $l) {
            $out .= '<span>' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $out .= '</div><table class="sr"><caption>' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '</caption><thead><tr><th scope="col">Periodo</th>';
        foreach ($series as $s) {
            $out .= '<th scope="col">' . htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($tipLabels as $i => $tl) {
            $out .= '<tr><th scope="row">' . htmlspecialchars($tl, ENT_QUOTES, 'UTF-8') . '</th>';
            foreach ($series as $s) {
                $out .= '<td>' . (int) ($s['values'][$i] ?? 0) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table></div>';
    }

    /**
     * Mapa de calor: 7 filas (lunes a domingo) por $weeks columnas. $cells[w][d] = ['n'=>int,'label'=>string]. Niveles 0 a 4 por cuartiles del maximo.
     * @return string SVG (las cabeceras de mes y dia las pone la vista en HTML)
     */
    public static function heat(array $cells, int $weeks): string
    {
        $max = 0;
        foreach ($cells as $col) {
            foreach ($col as $c) {
                $max = max($max, (int) $c['n']);
            }
        }
        $cell = 14;
        $gap = 3;
        $W = $weeks * ($cell + $gap) - $gap;
        $H = 7 * ($cell + $gap) - $gap;
        $out = '<svg class="heat" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Mapa de calor de leads por día de la semana, últimas ' . $weeks . ' semanas">';
        for ($w = 0; $w < $weeks; $w++) {
            for ($d = 0; $d < 7; $d++) {
                $c = $cells[$w][$d] ?? null;
                if ($c === null) {
                    continue;   // dia futuro
                }
                $n = (int) $c['n'];
                $lvl = $n === 0 || $max === 0 ? 0 : min(4, max(1, (int) ceil(4 * $n / $max)));
                $out .= '<rect class="ht' . $lvl . '" x="' . $w * ($cell + $gap) . '" y="' . $d * ($cell + $gap) . '" width="' . $cell . '" height="' . $cell . '" rx="4" data-n="' . $n . '" data-d="' . htmlspecialchars((string) $c['label'], ENT_QUOTES, 'UTF-8') . '"/>';
            }
        }
        return $out . '</svg>';
    }
}
