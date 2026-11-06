<?php
/**
 * Q6, fase I: lo que /resultados (la vista del lector de NorthStar) calcula para las piezas del «dashboard vivo»: subtítulo-conclusión, tarjetas de indicadores (con medidor de rayas o microlínea),
 * anotación y promedio del gráfico y nota del embudo. Todo sale de los AGREGADOS que ya devuelve Reports::forViewer() (totales, embudo, canales, motivos de pérdida, serie por día o semana):
 * ninguna función de aquí consulta la base, y por tanto ninguna puede tocar un nombre, teléfono, correo, USDOT o id de lead. Sin IA, sin cifras inventadas.
 *
 * Regla de base mínima (la misma de Dashboard::MIN_BASE = 5): con menos de 5 en la base previa NO hay porcentaje de variación (se dice el conteo, «+4 leads»); con menos de 5 recibidos
 * tampoco hay «% de los recibidos» (se dice «3 de 4 recibidos»). Con la base vacía o minúscula el texto lo dice con sinceridad.
 *
 * Subtítulo (cada frase es opcional, negrita solo en cifras y sustantivos clave):
 *  1. Llegadas: «{En los últimos 30 días|El mes pasado|…} llegaron **N leads**», con «(**+12.5%** frente al mes anterior)» solo si el periodo previo tiene 5 o más.
 *     Con 0: «Aún no hay leads {rango}; en el periodo anterior llegaron **N leads**.» (si hubo) o «Aún no hay leads {rango}. Cuando llegue el primero aparecerá aquí.»
 *  2. Embudo: «De ellos, **21 calificados**, **7 presentados** y **2 firmados**» (ceros: «ninguno firmado»); si nadie calificó: «Ninguno ha calificado todavía.»
 *  3. Canal: con 2 o más canales de 5 o más leads y 15 puntos o más de diferencia en % calificados, «**X** califica mejor (**62%** calificados)»; si no, «Todos vienen de **X**»
 *     (o «Vino de **X**» con 1 lead) o «**X** es el canal principal (**a de n**)» (más de la mitad).
 *  4. Una sola de: el costo por firmado («El costo por firmado fue de **$1,234**») si hay costos de meses completos y firmados; si no, el motivo de pérdida más frecuente
 *     («**No contesta** es el motivo de pérdida más frecuente (**4 de 9**)», solo con 2 o más y sin empate en el primer puesto) o «Se perdió **1 lead** (motivo)».
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;

final class ResultadosBoard
{
    private const WHEN = ['30d' => 'En los últimos 30 días', 'lastmonth' => 'El mes pasado', '90d' => 'En los últimos 90 días', 'year' => 'Este año'];
    private const WHEN_NONE = ['30d' => 'en los últimos 30 días', 'lastmonth' => 'del mes pasado', '90d' => 'en los últimos 90 días', 'year' => 'este año'];
    /** Rayas de los medidores (calificados y presentados sobre los recibidos). */
    public const TICKS = 28;

    private static function pl(int $n, string $one, string $many): string
    {
        return Fmt::num($n) . ' ' . ($n === 1 ? $one : $many);
    }

    /** «vs el mes anterior» → «frente al mes anterior»; «vs los 30 días anteriores» → «frente a los 30 días anteriores». */
    private static function against(string $prevLabel): string
    {
        return str_starts_with($prevLabel, 'vs el ') ? 'frente al ' . substr($prevLabel, 6) : (str_starts_with($prevLabel, 'vs ') ? 'frente a ' . substr($prevLabel, 3) : '');
    }

    /** Canales con leads, del que más tiene al que menos (desempate por nombre: determinista). @return list<array> */
    private static function channelRows(array $m): array
    {
        $rows = array_values(array_filter($m['channels']['rows'] ?? [], static fn($r) => (int) $r['leads'] > 0));
        usort($rows, static fn($a, $b) => [(int) $b['leads'], (string) $a['label']] <=> [(int) $a['leads'], (string) $b['label']]);
        return $rows;
    }

    /**
     * @param array $m agregados del rango (Reports::forViewer); $p los del periodo anterior; $range Reports::resolve()
     * @return array{segments:list<array{0:string,1:bool}>,text:string}
     */
    public static function headline(array $m, array $p, array $range): array
    {
        $key = (string) ($range['key'] ?? '');
        $when = self::WHEN[$key] ?? 'En este rango';
        $whenNone = self::WHEN_NONE[$key] ?? 'en este rango';
        $n = (int) $m['received'];
        $prev = (int) ($p['received'] ?? 0);
        $seg = [];
        $add = static function (string $t, bool $b = false) use (&$seg): void {
            $seg[] = [$t, $b];
        };
        if ($n === 0) {
            $add('Aún no hay leads ' . $whenNone);
            if ($prev > 0) {
                $add('; en el periodo anterior ' . ($prev === 1 ? 'llegó ' : 'llegaron '));
                $add(self::pl($prev, 'lead', 'leads'), true);
                $add('.');
            } else {
                $add('. Cuando llegue el primero aparecerá aquí.');
            }
            return self::done($seg);
        }
        $add($when . ' ' . ($n === 1 ? 'llegó ' : 'llegaron '));
        $add(self::pl($n, 'lead', 'leads'), true);
        $vs = self::against((string) ($range['prev_label'] ?? ''));
        if ($prev >= Dashboard::MIN_BASE && $vs !== '') {
            $dl = Dashboard::delta((float) $n, (float) $prev);
            if ($dl['dir'] !== 'flat') {
                $add(' (');
                $add($dl['text'], true);
                $add(' ' . $vs . ')');
            }
        }
        $add('.');

        $q = (int) $m['qualified'];
        $pr = (int) $m['presented'];
        $s = (int) $m['signed'];
        if ($q === 0 && $pr === 0 && $s === 0) {
            $add(' Ninguno ha calificado todavía.');
        } else {
            $add(' De ellos, ');
            foreach ([[$q, 'calificado', 'calificados', 'ninguno calificado'], [$pr, 'presentado', 'presentados', 'ninguno presentado'], [$s, 'firmado', 'firmados', 'ninguno firmado']] as $i => [$c, $one, $many, $none]) {
                if ($i > 0) {
                    $add($i === 2 ? ' y ' : ', ');
                }
                if ($c > 0) {
                    $add(self::pl($c, $one, $many), true);
                } else {
                    $add($none);
                }
            }
            $add('.');
        }
        self::channelSentence(self::channelRows($m), $n, $add);

        $cps = $m['channels']['total']['cps'] ?? null;
        $loss = $m['loss'] ?? ['total' => 0, 'rows' => []];
        if ($cps !== null) {
            $add(' El costo por firmado fue de ');
            $add(RepView::money((float) $cps, 0), true);
            $add('.');
        } elseif ((int) $loss['total'] === 1 && $loss['rows']) {
            $add(' Se perdió ');
            $add('1 lead', true);
            $add(' (' . $loss['rows'][0]['label'] . ').');
        } elseif ((int) $loss['total'] >= 2 && count($loss['rows']) >= 1 && (int) $loss['rows'][0]['n'] >= 2 && (int) $loss['rows'][0]['n'] > (int) ($loss['rows'][1]['n'] ?? 0)) {
            $add(' ');
            $add($loss['rows'][0]['label'], true);
            $add(' es el motivo de pérdida más frecuente (');
            $add($loss['rows'][0]['n'] . ' de ' . $loss['total'], true);
            $add(').');
        }
        return self::done($seg);
    }

    /** Tercera frase: el canal (reglas en la cabecera de la clase). */
    private static function channelSentence(array $rows, int $n, callable $add): void
    {
        if (!$rows) {
            return;
        }
        $elig = array_values(array_filter($rows, static fn($r) => (int) $r['leads'] >= Dashboard::MIN_BASE));
        if (count($elig) >= 2) {
            usort($elig, static fn($a, $b) => [$b['qualified'] / $b['leads'], (int) $b['leads'], (string) $a['key']] <=> [$a['qualified'] / $a['leads'], (int) $a['leads'], (string) $b['key']]);
            $best = $elig[0];
            $worst = $elig[count($elig) - 1];
            $gap = ($best['qualified'] / $best['leads'] - $worst['qualified'] / $worst['leads']) * 100;
            if ($gap >= 15) {
                $add(' ');
                $add((string) $best['label'], true);
                $add(' califica mejor (');
                $add(Fmt::pct($best['qualified'] / $best['leads'] * 100) . ' calificados', true);
                $add(').');
                return;
            }
        }
        $top = $rows[0];
        if ($n === 1) {
            $add(' Vino de ');
            $add((string) $top['label'], true);
            $add('.');
        } elseif (count($rows) === 1) {
            $add(' Todos vienen de ');
            $add((string) $top['label'], true);
            $add('.');
        } elseif ((int) $top['leads'] * 2 > $n) {
            $add(' ');
            $add((string) $top['label'], true);
            $add(' es el canal principal (');
            $add($top['leads'] . ' de ' . $n, true);
            $add(').');
        }
    }

    /** @param list<array{0:string,1:bool}> $seg */
    private static function done(array $seg): array
    {
        return ['segments' => $seg, 'text' => implode('', array_map(static fn($s) => $s[0], $seg))];
    }

    /** Variación de un conteo con base mínima (nunca un porcentaje sobre 1 a 4). */
    private static function countDelta(int $cur, int $prev, string $one, string $many): array
    {
        return Dashboard::deltaCount($cur, $prev, $one, $many);
    }

    /**
     * Tarjetas de indicadores del rango. Cada una: k (clave), label, tip (qué mide), text (valor visible), count (['v','dec','suf','pre'] para el conteo o null), na (texto en vez de cifra),
     * delta (pastilla), sub (línea de contexto) y viz (null | ['type'=>'spark','values'=>…] | ['type'=>'ticks','on','total','label','cls','cap']).
     * @return list<array>
     */
    public static function kpis(array $m, array $p, array $range): array
    {
        $rec = (int) $m['received'];
        $ofRec = static fn(int $c): string => $rec === 0 ? 'Sin leads en el rango' : ($rec >= Dashboard::MIN_BASE ? Fmt::num($c / $rec * 100, 0) . '% de los recibidos' : Fmt::num($c) . ' de ' . Fmt::num($rec) . ' recibidos');
        $tr = $m['trend'] ?? ['received' => [], 'signed' => []];
        $sparkOf = static fn(array $v): ?array => count($v) >= 2 && array_sum($v) > 0 ? ['type' => 'spark', 'values' => array_values($v)] : null;
        $meter = static fn(int $c, string $what, string $cls): ?array => $rec > 0 ? ['type' => 'ticks', 'on' => (int) round($c / $rec * self::TICKS), 'total' => self::TICKS, 'label' => $what . ': ' . $c . ' de ' . $rec . ' recibidos', 'cls' => $cls, 'cap' => $c . ' de ' . $rec] : null;
        $int = static fn(int $v): array => ['v' => $v, 'dec' => 0, 'suf' => ''];
        $qd = (int) $m['qualified'];
        $pd = (int) $m['presented'];
        $sd = (int) $m['signed'];
        $out = [
            ['k' => 'received', 'label' => 'Recibidos', 'tip' => 'Leads que llegaron en el rango. La microlínea es su evolución por ' . (($tr['unit'] ?? 'day') === 'day' ? 'día' : 'semana') . '.',
                'text' => Fmt::num($rec), 'count' => $int($rec), 'na' => false, 'delta' => self::countDelta($rec, (int) ($p['received'] ?? 0), 'lead', 'leads'), 'sub' => 'Leads que llegaron en el rango', 'viz' => $sparkOf($tr['received'] ?? [])],
            ['k' => 'qualified', 'label' => 'Calificados', 'tip' => 'Leads recibidos en el rango que llegaron a la etapa Calificado. Cada raya del medidor es 1/' . self::TICKS . ' de los recibidos.',
                'text' => Fmt::num($qd), 'count' => $int($qd), 'na' => false, 'delta' => self::countDelta($qd, (int) ($p['qualified'] ?? 0), 'calificado', 'calificados'), 'sub' => $ofRec($qd), 'viz' => $meter($qd, 'Calificados', 'tk--q')],
            ['k' => 'presented', 'label' => 'Presentados', 'tip' => 'Leads recibidos en el rango que ya fueron presentados a NorthStar. Cada raya del medidor es 1/' . self::TICKS . ' de los recibidos.',
                'text' => Fmt::num($pd), 'count' => $int($pd), 'na' => false, 'delta' => self::countDelta($pd, (int) ($p['presented'] ?? 0), 'presentado', 'presentados'), 'sub' => $ofRec($pd), 'viz' => $meter($pd, 'Presentados', 'tk--q')],
            ['k' => 'signed', 'label' => 'Firmados', 'tip' => 'Leads recibidos en el rango que firmaron. Debajo, cuántas firmas hubo en el rango sea cual sea el mes de llegada. La microlínea cuenta las firmas por ' . (($tr['unit'] ?? 'day') === 'day' ? 'día' : 'semana') . '.',
                'text' => Fmt::num($sd), 'count' => $int($sd), 'na' => false, 'delta' => self::countDelta($sd, (int) ($p['signed'] ?? 0), 'firmado', 'firmados'), 'sub' => 'De los recibidos · firmas por fecha en el rango: ' . (int) $m['signed_in_period'], 'viz' => $sparkOf($tr['signed'] ?? [])],
        ];
        // Costo por firmado: dinero, no conteo; su variacion exige 5 o mas firmados en AMBOS periodos
        [$cpsText, $cpsSub] = RepView::cpsText($m);
        $cps = $m['channels']['total']['cps'] ?? null;
        $pcps = $p['channels']['total']['cps'] ?? null;
        $delta = null;
        if ($cps !== null) {
            $delta = $pcps !== null && (int) $m['channels']['total']['signed'] >= Dashboard::MIN_BASE && (int) ($p['channels']['total']['signed'] ?? 0) >= Dashboard::MIN_BASE
                ? Dashboard::delta((float) $cps, (float) $pcps, false) : ['text' => 'Pocos firmados para comparar', 'dir' => 'flat', 'good' => null];
        }
        $out[] = ['k' => 'cps', 'label' => 'Costo por firmado', 'tip' => 'Costo cargado de los meses completos del rango entre los firmados de esos meses. Los costos se cargan por mes: sin mes completo no hay cifra. La variación exige ' . Dashboard::MIN_BASE . ' o más firmados en cada periodo.',
            'text' => $cpsText, 'count' => $cps !== null ? ['v' => round((float) $cps), 'dec' => 0, 'suf' => '', 'pre' => '$'] : null, 'na' => $cps === null, 'delta' => $delta, 'sub' => $cpsSub, 'viz' => null];
        return $out;
    }

    /**
     * Anotación y promedio del gráfico, calculados SOLO de la serie de recibidos que se ve (nada inventado). Con el periodo en curso (el rango llega hasta hoy) el último punto no entra en el promedio.
     * Anotación (una sola, con 8 leads o más repartidos en 3 periodos o más): el pico, o «+N frente al periodo anterior» si el periodo en curso es el máximo y supera al anterior.
     * Promedio (línea punteada): solo con 4 periodos completos con datos.
     * @return array{note:?array{i:int,l1:string,l2:string},avg:?float,prev:string,avgu:string}
     */
    public static function chartNote(array $m, int $to, ?int $now = null): array
    {
        $now ??= Clock::time();
        $t = $m['trend'] ?? ['unit' => 'day', 'received' => [], 'tips' => []];
        $tot = array_values($t['received']);
        $tips = array_values($t['tips']);
        $n = count($tot);
        $day = ($t['unit'] ?? 'day') === 'day';
        $inProgress = $to > $now;
        $full = $inProgress ? array_slice($tot, 0, max(0, $n - 1)) : $tot;
        $avg = count(array_filter($full)) >= 4 ? array_sum($full) / count($full) : null;
        $note = null;
        if ($n >= 3 && array_sum($tot) >= 8 && count(array_filter($tot)) >= 3) {
            $max = max($tot);
            $idx = array_keys($tot, $max);
            $i = (int) end($idx);   // con empate, el mas reciente
            $diff = $i === $n - 1 ? $tot[$n - 1] - $tot[$n - 2] : 0;
            if ($inProgress && $i === $n - 1 && $diff > 0) {
                $note = ['i' => $i, 'l1' => '+' . $diff . ' ' . ($diff === 1 ? 'lead' : 'leads') . ' recibidos', 'l2' => $day ? 'frente al día anterior' : 'frente a la semana anterior'];
            } else {
                $note = ['i' => $i, 'l1' => 'Pico: ' . $max . ' ' . ($max === 1 ? 'lead' : 'leads') . ' recibidos', 'l2' => (string) ($tips[$i] ?? '')];
            }
        }
        return ['note' => $note, 'avg' => $avg, 'prev' => $day ? 'frente al día anterior' : 'frente a la semana anterior', 'avgu' => $day ? ' al día' : ' por semana'];
    }

    /**
     * Nota del embudo: la mayor caída entre dos etapas consecutivas, en conteos (nunca un porcentaje sobre una base minúscula). Sin recibidos o sin caída: null.
     * @return ?array{segments:list<array{0:string,1:bool}>,text:string}
     */
    public static function funnelNote(array $m): ?array
    {
        $f = array_values($m['funnel'] ?? []);
        $best = 0;
        $at = null;
        for ($i = 1; $i < count($f); $i++) {
            $drop = (int) $f[$i - 1]['n'] - (int) $f[$i]['n'];
            if ($drop > $best) {
                [$best, $at] = [$drop, $i];
            }
        }
        if ($at === null || (int) $m['received'] < 1) {
            return null;
        }
        return self::done([['Mayor caída: de ', false], [(string) $f[$at - 1]['label'], true], [' a ', false], [(string) $f[$at]['label'], true], [' (−' . self::pl($best, 'lead', 'leads') . ').', false]]);
    }
}
