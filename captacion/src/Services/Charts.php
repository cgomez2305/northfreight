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

    /** Micrografico de linea con area degradada del 16 % a 0 (tarjeta KPI, Q6). El degradado usa currentColor, asi que el color lo pone la clase .spark. */
    public static function spark(array $values, int $w = 96, int $h = 36): string
    {
        static $n = 0;
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
        $id = 'sg' . (++$n);
        $line = self::smooth($pts);
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true" focusable="false"><defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop class="gs0" offset="0"/><stop class="gs1" offset="1"/></linearGradient></defs>'
            . '<path class="spark__area" fill="url(#' . $id . ')" d="' . $line . ' L' . self::n($last[0]) . ' ' . $h . ' L' . self::n($pts[0][0]) . ' ' . $h . ' Z"/>'
            . '<path class="spark__line" d="' . $line . '" fill="none"/><circle class="spark__dot" cx="' . self::n($last[0]) . '" cy="' . self::n($last[1]) . '" r="2.6"/></svg>';
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

    /** Barra horizontal de proporcion (0 a 100) para embudos y rankings. $fg: clase extra del relleno (p. ej. seg-1, el color de una fuente en el dashboard). */
    public static function hbar(float $pct, string $cls = '', string $fg = ''): string
    {
        $pct = max(0.0, min(100.0, $pct));
        return '<svg class="hb' . ($cls !== '' ? ' ' . htmlspecialchars($cls, ENT_QUOTES) : '') . '" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="hb__bg seg-e" width="100" height="8" rx="4"/>'
            . ($pct > 0 ? '<rect class="hb__fg' . ($fg !== '' ? ' ' . htmlspecialchars($fg, ENT_QUOTES) : '') . '" width="' . self::n(max(2.0, $pct)) . '" height="8" rx="4"/>' : '') . '</svg>';
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
     * @param list<string> $labels etiquetas del eje X; @param list<array{name:string,cls:string,values:list<int|float>,end?:string}> $series ($series[k]['end'] = rotulo directo al final de la linea)
     * @param list<string> $tipLabels titulo del tooltip por punto
     * Q6, opciones ($o) del grafico «vivo» del dashboard (sin ellas el marcado es el de siempre, el de los reportes):
     *   uid  identificador unico del panel (para el degradado); area  clase de la serie con area degradada; note  {i,l1,l2} anotacion sobre el punto i; avg  promedio (linea punteada)
     *   delta  indice de la serie cuya variacion dice el tooltip (sin el, la suma de todas); prev  frase del periodo anterior del tooltip («frente al día anterior»); avgu  unidad del promedio (« al día»)
     *   Todo lo que va sobre las curvas se pinta en un SVG de superposicion SIN viewBox: sus coordenadas son porcentajes (atributos), asi el texto no se deforma y no hace falta ningun estilo en linea.
     */
    public static function line(string $id, array $labels, array $series, array $tipLabels, string $unit = 'leads', array $o = []): string
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
        $live = isset($o['uid']);
        $esc = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $px = static fn(int $i): float => 100 * ($i + 0.5) / max(1, $n);
        $py = static fn(float $v): float => 100 * (1 - (($H - 6) * ($v / $top) + 1) / $H);
        $out = '<div class="lc' . ($live ? ' lc--d' : '') . '" data-chart="' . htmlspecialchars((string) json_encode([
            'labels' => $tipLabels, 'max' => $top, 'unit' => $unit,
            'dk' => isset($o['delta']) ? (int) $o['delta'] : null, 'pv' => isset($o['prev']) ? (string) $o['prev'] : null,
            'series' => array_map(static fn($s) => ['n' => $s['name'], 'c' => $s['cls'], 'v' => array_values($s['values'])], $series),
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"' . ($live ? ' data-lc2' : '') . '>';
        $out .= '<div class="lc__y" aria-hidden="true">';
        for ($i = $ticks; $i >= 0; $i--) {
            $out .= '<span>' . self::n($stepY * $i) . '</span>';
        }
        $out .= '</div><div class="lc__plot" tabindex="0" role="group" aria-label="' . $esc($id) . '">';
        $out .= '<svg viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
        if ($live) {
            $out .= '<defs><linearGradient id="lg-' . $esc((string) $o['uid']) . '" x1="0" y1="0" x2="0" y2="1"><stop class="gs0" offset="0"/><stop class="gs1" offset="1"/></linearGradient></defs>';
        }
        for ($i = 0; $i <= $ticks; $i++) {
            $y = $H * $i / $ticks;
            $out .= '<line class="lc__grid' . ($i === $ticks ? ' is-base' : '') . '" x1="0" x2="' . $W . '" y1="' . self::n($y) . '" y2="' . self::n($y) . '" vector-effect="non-scaling-stroke"/>';
        }
        $ends = [];
        foreach ($series as $k => $s) {
            $pts = [];
            foreach (array_values($s['values']) as $i => $v) {
                $pts[] = [$W * ($i + 0.5) / max(1, $n), $H - ($H - 6) * ((float) $v / $top) - 1];
            }
            $d = self::smooth($pts);
            if ($live && ($o['area'] ?? '') === $s['cls'] && count($pts) > 1) {
                $out .= '<path class="lc__area lc__area--' . $esc($s['cls']) . '" fill="url(#lg-' . $esc((string) $o['uid']) . ')" d="' . $d . ' L' . self::n($pts[count($pts) - 1][0]) . ' ' . $H . ' L' . self::n($pts[0][0]) . ' ' . $H . ' Z"/>';
            }
            $out .= '<path class="lc__line lc__line--' . $esc($s['cls']) . '" d="' . $d . '" fill="none" vector-effect="non-scaling-stroke"/>';
            $vals = array_values($s['values']);
            $ends[$k] = $py((float) ($vals ? $vals[count($vals) - 1] : 0));
        }
        $out .= '</svg>';
        if ($live && $n > 0) {
            // Rotulos al final de cada linea: si dos caen a menos de 9 % de alto, el de abajo se corre (sin pisarse)
            $order = array_keys($ends);
            usort($order, static fn($a, $b) => $ends[$a] <=> $ends[$b]);
            $ly = [];
            $prev = null;
            foreach ($order as $k) {
                $y = $ends[$k];
                if ($prev !== null && $y - $prev < 9.0) {
                    $y = $prev + 9.0;
                }
                $ly[$k] = $y;
                $prev = $y;
            }
            $ov = '<svg class="lc__ov" aria-hidden="true" focusable="false">';
            if (isset($o['avg']) && $o['avg'] !== null) {
                $ay = $py((float) $o['avg']);
                $ov .= '<line class="lc__ref" x1="0" x2="100%" y1="' . self::n($ay) . '%" y2="' . self::n($ay) . '%"/><text class="lc__ref-t" x="4" y="' . self::n($ay) . '%" dy="-5">Promedio ' . self::n(round((float) $o['avg'], 1)) . $esc((string) ($o['avgu'] ?? '')) . '</text>';
            }
            if (is_array($o['note'] ?? null)) {
                $note = $o['note'];
                $i = (int) $note['i'];
                $x = $px($i);
                $yp = 100.0;
                foreach ($series as $s) {
                    $yp = min($yp, $py((float) ($s['values'][$i] ?? 0)));
                }
                $ty = $yp > 26 ? $yp - 12 : $yp + 10;
                if (isset($ay)) {
                    // R2: la anotacion (dos lineas: ~10 % de alto) no puede cruzarse con la linea del promedio ni con su rotulo (arriba de la linea, ~6 %):
                    // se prueba el lado contrario del punto y, si tambien choca, se coloca justo encima o justo debajo de la linea
                    $hits = static fn(float $y): bool => $y + 5.5 >= $ay - 6.5 && $y - 4.5 <= $ay + 2.0;
                    if ($hits($ty)) {
                        $alt = $yp > 26 && $ty < $yp ? $yp + 10 : $yp - 12;
                        if (!$hits($alt) && $alt >= 6 && $alt <= 94) {
                            $ty = $alt;
                        } else {
                            $ty = $ay - 6.5 - 5.5 >= 6 ? $ay - 12.5 : min(94, $ay + 2.0 + 4.5);
                        }
                    }
                }
                $anchor = $i <= 1 ? 'start' : ($i >= $n - 3 ? 'end' : 'middle');
                $ov .= '<g class="lc__note"><circle class="lc__ring" cx="' . self::n($x) . '%" cy="' . self::n($yp) . '%" r="8"/>'
                    . '<text x="' . self::n($x) . '%" y="' . self::n($ty) . '%" text-anchor="' . $anchor . '"><tspan class="lc__n1" x="' . self::n($x) . '%">' . $esc((string) $note['l1']) . '</tspan><tspan class="lc__n2" x="' . self::n($x) . '%" dy="13">' . $esc((string) $note['l2']) . '</tspan></text></g>';
            }
            foreach ($series as $k => $s) {
                $vals = array_values($s['values']);
                $ov .= '<circle class="lc__pt lc__pt--' . $esc($s['cls']) . '" cx="' . self::n($px($n - 1)) . '%" cy="' . self::n($ends[$k]) . '%" r="4"/>';
                if (($s['end'] ?? '') !== '') {
                    $ov .= '<text class="lc__end lc__end--' . $esc($s['cls']) . '" x="100%" dx="12" y="' . self::n($ly[$k]) . '%" dy="4">' . $esc((string) $s['end']) . '<tspan class="lc__ev" dx="6">' . (int) ($vals ? $vals[count($vals) - 1] : 0) . '</tspan></text>';
                }
            }
            $out .= $ov . '</svg><i class="lc__cross" hidden></i>';
        }
        $out .= '<div class="lc__band" hidden></div>';
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
     * Mapa de calor tipo dot-matrix (Q6): 7 filas (lunes a domingo) por $weeks columnas de puntos de 7 px de radio. $cells[w][d] = ['n'=>int,'label'=>string]. Niveles 0 a 4 por cuartiles del maximo,
     * un solo tono (las celdas vacias se ven: nivel 0 con borde fino). @return string SVG (las cabeceras de mes y dia las pone la vista en HTML)
     */
    public static function heat(array $cells, int $weeks): string
    {
        $max = 0;
        foreach ($cells as $col) {
            foreach ($col as $c) {
                $max = max($max, (int) $c['n']);
            }
        }
        $pitch = 24;
        $W = $weeks * $pitch;
        $H = 7 * $pitch;
        $out = '<svg class="heat" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Mapa de calor de leads por día de la semana, últimas ' . $weeks . ' semanas">';
        for ($w = 0; $w < $weeks; $w++) {
            for ($d = 0; $d < 7; $d++) {
                $c = $cells[$w][$d] ?? null;
                if ($c === null) {
                    continue;   // dia futuro
                }
                $n = (int) $c['n'];
                $lvl = $n === 0 || $max === 0 ? 0 : min(4, max(1, (int) ceil(4 * $n / $max)));
                $out .= '<circle class="ht' . $lvl . '" cx="' . ($w * $pitch + $pitch / 2) . '" cy="' . ($d * $pitch + $pitch / 2) . '" r="7" data-n="' . $n . '" data-d="' . htmlspecialchars((string) $c['label'], ENT_QUOTES, 'UTF-8') . '"/>';
            }
        }
        return $out . '</svg>';
    }
}
