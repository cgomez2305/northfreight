<?php
/**
 * Q6: subtitulo-conclusion del dashboard, escrito por reglas simples y deterministas sobre los datos REALES del periodo (nada de IA, nada inventado).
 *
 * Devuelve segmentos [texto, negrita] (la vista imprime cada uno escapado; la negrita solo va en cifras y sustantivos clave) y la version en texto plano.
 * Reglas (en este orden; cada frase es opcional):
 *  1. Llegadas: «{Hoy|En los ultimos 7 dias|En los ultimos 30 dias} llegaron **N leads**» + calientes/tibios del periodo. Con 0 leads: «Aun no hay leads …».
 *     Si el periodo previo tiene 5 o mas leads se anade la variacion en porcentaje; con menos, NUNCA un porcentaje (la tarjeta ya dice el conteo).
 *  2. Fuente: con 2 o mas fuentes de 5 o mas leads cada una y una diferencia de 15 puntos o mas en % de calificados, «**X** califica mejor (**62%** calificados)»;
 *     si no, con 1 lead «Vino de **X**», con todos de una sola fuente «Todos vienen de **X**» y con una fuente con mas de la mitad «**X** es la fuente principal (**a de n**)».
 *  3. Pendientes: «**N calientes** esperan contacto» (con el tiempo del mas antiguo) o «Ningun caliente espera contacto.»
 * Con la base vacia: «Aun no hay leads. Cuando llegue el primero aparecera aqui.»
 */

declare(strict_types=1);

namespace Captacion\Services;

final class DashboardNarrative
{
    private const WHEN = ['today' => 'Hoy', 'week' => 'En los últimos 7 días', 'month' => 'En los últimos 30 días'];
    private const WHEN_NONE = ['today' => 'hoy', 'week' => 'en los últimos 7 días', 'month' => 'en los últimos 30 días'];
    private const PREV_NAME = ['today' => 'ayer a esta hora', 'week' => 'los 7 días previos', 'month' => 'los 30 días previos'];

    private static function pl(int $n, string $one, string $many): string
    {
        return Fmt::num($n) . ' ' . ($n === 1 ? $one : $many);
    }

    /**
     * @param array $d resultado de Dashboard::kpis() + finish() (period, leads, qualified, hot, mix, any)
     * @return array{segments:list<array{0:string,1:bool}>,text:string}
     */
    public static function headline(array $d): array
    {
        $period = isset(self::WHEN[$d['period'] ?? '']) ? $d['period'] : 'today';
        $n = (int) $d['leads']['n'];
        $prev = (int) $d['leads']['prev'];
        $hotWait = (int) $d['hot']['n'];
        $seg = [];
        $add = static function (string $t, bool $b = false) use (&$seg): void {
            $seg[] = [$t, $b];
        };

        if ($n === 0) {
            if (empty($d['any'])) {
                $add('Aún no hay leads. Cuando llegue el primero aparecerá aquí.');
                return self::done($seg);
            }
            $add('Aún no hay leads ' . self::WHEN_NONE[$period]);
            if ($prev > 0) {
                $add('; en ' . self::PREV_NAME[$period] . ' ' . ($prev === 1 ? 'llegó ' : 'llegaron '));
                $add(self::pl($prev, 'lead', 'leads'), true);
            }
            $add('.');
        } else {
            $add(self::WHEN[$period] . ' ' . ($n === 1 ? 'llegó ' : 'llegaron '));
            $add(self::pl($n, 'lead', 'leads'), true);
            if ($prev >= Dashboard::MIN_BASE) {
                $dl = Dashboard::delta($n, $prev);
                if ($dl['dir'] !== 'flat') {
                    $add(' (');
                    $add($dl['text'], true);
                    $add(' frente a ' . self::PREV_NAME[$period] . ')');
                }
            }
            $hot = (int) $d['mix']['hot'];
            $warm = (int) $d['mix']['warm'];
            if ($hot > 0) {
                $add(', ');
                $add(self::pl($hot, 'caliente', 'calientes'), true);
            } elseif ($warm > 0) {
                $add(', ');
                $add(self::pl($warm, 'tibio', 'tibios'), true);
                $add(' y ninguno caliente');
            } else {
                $add(', ninguno caliente ni tibio');
            }
            $add('.');
            self::sourceSentence($d['mix']['sources'], $n, $add);
        }

        if ($hotWait > 0) {
            $add(' ');
            $add(self::pl($hotWait, 'caliente', 'calientes'), true);
            $add($hotWait === 1 ? ' espera contacto' : ' esperan contacto');
            $secs = $d['hot']['oldest_secs'];
            if ($secs !== null && $secs >= 60) {
                $add(' (el más antiguo, desde hace ' . Fmt::dur((int) $secs) . ')');
            }
            $add('.');
        } else {
            $add(' Ningún caliente espera contacto.');
        }
        return self::done($seg);
    }

    /** Segunda frase: la fuente (reglas en la cabecera de la clase). */
    private static function sourceSentence(array $sources, int $n, callable $add): void
    {
        if (!$sources) {
            return;
        }
        $elig = array_values(array_filter($sources, static fn($s) => $s['n'] >= Dashboard::MIN_BASE));
        if (count($elig) >= 2) {
            usort($elig, static fn($a, $b) => [$b['q'] / $b['n'], $b['n'], $a['key']] <=> [$a['q'] / $a['n'], $a['n'], $b['key']]);
            $best = $elig[0];
            $worst = $elig[count($elig) - 1];
            $gap = ($best['q'] / $best['n'] - $worst['q'] / $worst['n']) * 100;
            if ($gap >= 15) {
                $add(' ');
                $add($best['label'], true);
                $add(' califica mejor (');
                $add(Fmt::pct($best['q'] / $best['n'] * 100) . ' calificados', true);
                $add(').');
                return;
            }
        }
        $top = $sources[0];
        if ($n === 1) {
            $add(' Vino de ');
            $add($top['label'], true);
            $add('.');
        } elseif (count($sources) === 1) {
            $add(' Todos vienen de ');
            $add($top['label'], true);
            $add('.');
        } elseif ($top['n'] * 2 > $n) {
            $add(' ');
            $add($top['label'], true);
            $add(' es la fuente principal (');
            $add($top['n'] . ' de ' . $n, true);
            $add(').');
        }
    }

    /** @param list<array{0:string,1:bool}> $seg */
    private static function done(array $seg): array
    {
        return ['segments' => $seg, 'text' => implode('', array_map(static fn($s) => $s[0], $seg))];
    }
}
