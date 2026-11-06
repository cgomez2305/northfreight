<?php
/**
 * Datos del dashboard (doc §9.1 y §12), calculados SOLO con filas reales de la base. Las ventanas de dia/semana/mes se miden en hora del este (ET);
 * el agrupado por dia se hace en PHP (el servidor compartido puede no tener las tablas de zonas horarias de MySQL para CONVERT_TZ).
 *
 * Periodos: today = hoy (comparado con ayer a la misma hora) · week = ultimos 7 dias vs los 7 previos · month = ultimos 30 dias vs los 30 previos.
 * El grafico, el desglose por fuente, la franja secundaria y el mapa de calor tienen su propia ventana fija (12 semanas, 90 dias, 12 semanas, 13 semanas).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Dashboard
{
    public const PERIODS = ['today' => 'Hoy', 'week' => 'Semana', 'month' => 'Mes'];
    private const PREV_LABEL = ['today' => 'vs ayer a esta hora', 'week' => 'vs 7 días previos', 'month' => 'vs 30 días previos'];
    private const WINDOW_LABEL = ['today' => 'hoy', 'week' => 'los últimos 7 días', 'month' => 'los últimos 30 días'];
    public const WEEKS = 12;
    public const HEAT_WEEKS = 13;
    /** Q6: con menos leads que esto en la base previa NO se muestra un porcentaje de variacion (un +400 % sobre 1 lead engaña): se dice el conteo. */
    public const MIN_BASE = 5;
    /** Q6: contactados minimos (en cada periodo) para comparar la mediana de primera respuesta. */
    public const MIN_SAMPLES = 3;

    /** @return array{0:int,1:int,2:int,3:int} [inicio actual, fin actual, inicio previo, fin previo] como timestamps */
    public static function window(string $period, ?int $now = null): array
    {
        $now ??= Clock::time();
        return match ($period) {
            'week'  => [Fmt::dayStart($now, -6), $now, Fmt::dayStart($now, -13), Fmt::dayStart($now, -6)],
            'month' => [Fmt::dayStart($now, -29), $now, Fmt::dayStart($now, -59), Fmt::dayStart($now, -29)],
            default => [Fmt::dayStart($now), $now, Fmt::dayStart($now, -1), Fmt::dayStart($now, -1) + ($now - Fmt::dayStart($now))],
        };
    }

    /** Pastilla de variacion. $betterWhenUp: false para metricas donde bajar es bueno (tiempo de respuesta). */
    public static function delta(float $cur, float $prev, bool $betterWhenUp = true, bool $points = false): array
    {
        if ($points) {
            $d = $cur - $prev;
            $txt = ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . Fmt::num(abs($d), 1) . ' pts';
            $dir = abs($d) < 0.05 ? 'flat' : ($d > 0 ? 'up' : 'down');
        } elseif ($prev <= 0) {
            $txt = $cur > 0 ? '+' . Fmt::num($cur) . ' nuevos' : 'Sin cambio';
            $dir = $cur > 0 ? 'up' : 'flat';
        } else {
            $d = ($cur - $prev) / $prev * 100;
            $txt = ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . Fmt::num(abs($d), abs($d) >= 100 ? 0 : 1) . '%';
            $dir = abs($d) < 0.05 ? 'flat' : ($d > 0 ? 'up' : 'down');
        }
        $good = $dir === 'flat' ? null : (($dir === 'up') === $betterWhenUp);
        return ['text' => $txt, 'dir' => $dir, 'good' => $good];
    }

    /**
     * Variacion de un CONTEO (leads) con la regla de base minima (Q6): con 5 o mas en el periodo previo, porcentaje; con 1 a 4, la diferencia en leads; con 0, «+N nuevos».
     * Nunca devuelve un porcentaje sobre una base minuscula.
     */
    public static function deltaCount(int $cur, int $prev, string $one = 'lead', string $many = 'leads'): array
    {
        if ($prev >= self::MIN_BASE || $prev === 0) {
            return self::delta($cur, $prev);
        }
        $d = $cur - $prev;
        if ($d === 0) {
            return ['text' => 'Sin cambio', 'dir' => 'flat', 'good' => null];
        }
        $abs = abs($d);
        return ['text' => ($d > 0 ? '+' : '−') . $abs . ' ' . ($abs === 1 ? $one : $many), 'dir' => $d > 0 ? 'up' : 'down', 'good' => $d > 0];
    }

    public static function median(array $values): ?int
    {
        $values = array_values(array_map('intval', $values));
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        return $n % 2 === 1 ? $values[intdiv($n, 2)] : (int) round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }

    /**
     * Filas livianas de las ultimas 26 semanas (suficiente para el periodo, el grafico semanal, su comparativa y el mapa de calor). Q6: cada fila lleva `t` (marca de tiempo ya
     * convertida, una sola vez) y $from permite pedir menos (el refresco ligero solo necesita 60 dias).
     */
    public static function recentRows(int $now, ?int $from = null): array
    {
        $from ??= Fmt::weekStart($now, -(self::HEAT_WEEKS * 2 - 1));
        $rows = Db::all(
            'SELECT created_at, profile, temperature, source, first_contact_at FROM leads WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= ?',
            [Fmt::utc($from), Fmt::utc($now)]
        );
        foreach ($rows as &$r) {
            $r['t'] = (int) Clock::parse((string) $r['created_at']);
        }
        return $rows;
    }

    /** @param list<array> $rows */
    private static function between(array $rows, int $from, int $to): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['t'] >= $from && $r['t'] < $to) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** @return list<int> conteos diarios (ET) de los ultimos $days dias, del mas viejo al de hoy (medianoches reales: respeta el cambio de hora). */
    private static function daily(array $rows, int $days, int $now): array
    {
        $starts = [];
        for ($i = 0; $i < $days; $i++) {
            $starts[] = Fmt::dayStart($now, -($days - 1 - $i));
        }
        $buckets = array_fill(0, $days, 0);
        foreach ($rows as $r) {
            $t = $r['t'];
            if ($t < $starts[0]) {
                continue;
            }
            $i = $days - 1;
            while ($i > 0 && $starts[$i] > $t) {
                $i--;
            }
            $buckets[$i]++;
        }
        return $buckets;
    }

    public static function build(string $period, ?int $now = null): array
    {
        $now ??= Clock::time();
        $period = isset(self::PERIODS[$period]) ? $period : 'today';
        $rows = self::recentRows($now);
        $out = self::kpis($period, $now, $rows);

        // Grafico de leads por semana (12 semanas, dos series), reparto por fuente, franja, mapa de calor y lista de hoy
        $out['chart'] = self::charts($rows, $now);
        $out['sources'] = self::sources($now);
        $out['strip'] = self::strip($rows, $now);
        $out['heat'] = self::heat($rows, $now);
        $out['today'] = self::today($now);
        return self::finish($out, $now);
    }

    /**
     * Q6: refresco ligero (POST /ajax/dashboard): solo lo que cambia con el tiempo (KPI, subtitulo y siguientes acciones) y solo AGREGADOS: ningun nombre, telefono ni id de lead.
     * Mira 60 dias atras (lo que piden el mes y su periodo previo), no las 26 semanas del dashboard completo.
     */
    public static function pulse(string $period, ?int $now = null): array
    {
        $now ??= Clock::time();
        $period = isset(self::PERIODS[$period]) ? $period : 'today';
        $out = self::kpis($period, $now, self::recentRows($now, Fmt::dayStart($now, -59)), false);
        return self::finish($out, $now);
    }

    /** Subtitulo-conclusion y siguientes acciones (reglas deterministas en DashboardNarrative y DashboardActions) sobre los KPI ya calculados. */
    private static function finish(array $out, int $now): array
    {
        $out['any'] = (int) Db::value('SELECT COUNT(*) FROM (SELECT 1 FROM leads WHERE deleted_at IS NULL LIMIT 1) x') > 0;   // hay al menos un lead en toda la base
        $out['actions'] = DashboardActions::compute($now, $out['hot']);
        $out['narrative'] = DashboardNarrative::headline($out);
        $out['updated_at'] = $now;
        return $out;
    }

    /** @param list<array> $rows filas de recentRows() */
    private static function kpis(string $period, int $now, array $rows, bool $withHot = true): array
    {
        [$cs, $ce, $ps, $pe] = self::window($period, $now);
        $cur = self::between($rows, $cs, $ce + 1);
        $prev = self::between($rows, $ps, $pe);

        $out = ['period' => $period, 'window' => ['from' => $cs, 'to' => $ce], 'prev_label' => self::PREV_LABEL[$period], 'window_label' => self::WINDOW_LABEL[$period]];

        // KPI 1: leads (la microlinea es de 7 dias; en el periodo «mes», de 30)
        $spDays = $period === 'month' ? 30 : 7;
        $out['leads'] = ['n' => count($cur), 'prev' => count($prev), 'delta' => self::deltaCount(count($cur), count($prev)), 'spark' => self::daily($rows, $spDays, $now), 'spark_days' => $spDays];

        // KPI 2: calificados (caliente + tibio) sobre el total del periodo. La comparacion en puntos exige base minima en AMBOS periodos.
        $qual = static fn(array $set): int => count(array_filter($set, static fn($r) => in_array($r['temperature'], ['hot', 'warm'], true)));
        $qc = $qual($cur);
        $qp = $qual($prev);
        $pc = count($cur) > 0 ? $qc / count($cur) * 100 : 0.0;
        $pp = count($prev) > 0 ? $qp / count($prev) * 100 : 0.0;
        $both = count($cur) >= self::MIN_BASE && count($prev) >= self::MIN_BASE;
        $out['qualified'] = ['n' => $qc, 'of' => count($cur), 'pct' => $pc, 'prev_pct' => $pp, 'delta' => self::delta($pc, $pp, true, true), 'has_prev' => $both];

        // Mezcla del periodo (para el subtitulo): calientes, tibios y calificados por fuente
        $hotC = count(array_filter($cur, static fn($r) => $r['temperature'] === 'hot'));
        $by = [];
        foreach ($cur as $r) {
            $k = (string) $r['source'];
            $by[$k] ??= ['key' => $k, 'label' => Catalog::source($k), 'n' => 0, 'q' => 0];
            $by[$k]['n']++;
            $by[$k]['q'] += in_array($r['temperature'], ['hot', 'warm'], true) ? 1 : 0;
        }
        uasort($by, static fn($a, $b) => [$b['n'], $a['key']] <=> [$a['n'], $b['key']]);
        $out['mix'] = ['hot' => $hotC, 'warm' => $qc - $hotC, 'sources' => array_values($by)];

        // KPI 3: calientes sin contactar (estado actual, no depende del periodo). Cada raya del dial = 15 min de espera del mas antiguo (tope 16 = 4 h).
        $where = "deleted_at IS NULL AND temperature = 'hot' AND first_contact_at IS NULL AND stage = 'new'";
        $hotN = (int) Db::value("SELECT COUNT(*) FROM leads WHERE $where");
        $hot = Db::all("SELECT id, first_name, last_name, created_at FROM leads WHERE $where ORDER BY created_at ASC, id ASC LIMIT 4");
        $oldest = $hot ? Fmt::secondsBetween((string) $hot[0]['created_at'], Clock::now()) : null;
        $out['hot'] = ['n' => $hotN, 'sample' => $withHot ? $hot : [], 'oldest_secs' => $oldest, 'late' => $oldest !== null && $oldest > 900, 'ticks' => $oldest === null ? 0 : max(1, min(16, (int) ceil($oldest / 900)))];

        // KPI 4: primera respuesta (mediana) y que parte se atendio en menos de 15 minutos
        $resp = static function (array $set): array {
            $v = [];
            foreach ($set as $r) {
                $s = Fmt::secondsBetween((string) $r['created_at'], $r['first_contact_at']);
                if ($s !== null && $s >= 0) {
                    $v[] = $s;
                }
            }
            return $v;
        };
        $rc = $resp($cur);
        $rp = $resp($prev);
        $mc = self::median($rc);
        $mp = self::median($rp);
        $bands = [0, 0, 0, 0];   // <15 min, <1 h, <4 h, mas
        foreach ($rc as $s) {
            $bands[$s < 900 ? 0 : ($s < 3600 ? 1 : ($s < 14400 ? 2 : 3))]++;
        }
        $out['response'] = [
            'median' => $mc, 'prev' => $mp, 'n' => count($rc), 'bands' => $bands, 'share15' => $rc ? $bands[0] / count($rc) * 100 : null,
            'delta' => $mc !== null && $mp !== null && count($rc) >= self::MIN_SAMPLES && count($rp) >= self::MIN_SAMPLES ? self::delta($mc, $mp, false) : null,
        ];
        return $out;
    }

    private static function charts(array $rows, int $now): array
    {
        $weeks = self::WEEKS;
        $start = Fmt::weekStart($now, -($weeks - 1));
        $labels = [];
        $tips = [];
        $ops = array_fill(0, $weeks, 0);
        $drv = array_fill(0, $weeks, 0);
        $starts = [];
        for ($i = 0; $i < $weeks; $i++) {
            $ws = Fmt::weekStart($now, -($weeks - 1 - $i));
            $starts[] = $ws;
            $d = Fmt::et($ws);
            $labels[] = Fmt::dayLabel($d);
            $tips[] = 'Semana del ' . Fmt::dayLabel($d);
        }
        $prevTotal = 0;
        $prevFrom = Fmt::weekStart($now, -($weeks * 2 - 1));
        foreach ($rows as $r) {
            $t = $r['t'];
            if ($t >= $start) {
                $i = $weeks - 1;
                while ($i > 0 && $starts[$i] > $t) {
                    $i--;
                }
                if ($r['profile'] === 'company_driver') {
                    $drv[$i]++;
                } else {
                    $ops[$i]++;
                }
            } elseif ($t >= $prevFrom) {
                $prevTotal++;
            }
        }
        $total = array_sum($ops) + array_sum($drv);
        $tips[$weeks - 1] .= ' · en curso';
        $tot = array_map(static fn($a, $b) => $a + $b, $ops, $drv);
        $weekly = ['labels' => $labels, 'tips' => $tips, 'ops' => $ops, 'drv' => $drv, 'total' => $total, 'prev_total' => $prevTotal, 'delta' => self::deltaCount($total, $prevTotal)] + self::annotate($tot, $tips);

        // Mensual (12 meses) y anual: todo el historial, agrupado en ET
        $all = Db::all('SELECT created_at, profile FROM leads WHERE deleted_at IS NULL');
        $first = null;
        foreach ($all as $r) {
            $t = (int) Clock::parse((string) $r['created_at']);
            $first = $first === null ? $t : min($first, $t);
        }
        $out = ['weekly' => $weekly, 'monthly' => null, 'yearly' => null];
        if ($first !== null) {
            $firstMonth = Fmt::monthStart($first);
            $thisMonth = Fmt::monthStart($now);
            if ($firstMonth < $thisMonth) {
                $months = 12;
                $mStarts = [];
                $mLabels = [];
                $mTips = [];
                for ($i = 0; $i < $months; $i++) {
                    $ms = Fmt::monthStart($now, -($months - 1 - $i));
                    $mStarts[] = $ms;
                    $d = Fmt::et($ms);
                    $mLabels[] = Fmt::monthLabel((int) $d->format('n'));
                    $mTips[] = ucfirst(Fmt::monthLabel((int) $d->format('n'))) . ' ' . $d->format('Y');
                }
                $mo = array_fill(0, $months, 0);
                $md = array_fill(0, $months, 0);
                foreach ($all as $r) {
                    $t = (int) Clock::parse((string) $r['created_at']);
                    if ($t < $mStarts[0]) {
                        continue;
                    }
                    $i = $months - 1;
                    while ($i > 0 && $mStarts[$i] > $t) {
                        $i--;
                    }
                    if ($r['profile'] === 'company_driver') {
                        $md[$i]++;
                    } else {
                        $mo[$i]++;
                    }
                }
                $mTips[$months - 1] .= ' · en curso';
                $out['monthly'] = ['labels' => $mLabels, 'tips' => $mTips, 'ops' => $mo, 'drv' => $md, 'total' => array_sum($mo) + array_sum($md)] + self::annotate(array_map(static fn($a, $b) => $a + $b, $mo, $md), $mTips);
            }
            $y0 = (int) Fmt::et($first)->format('Y');
            $y1 = (int) Fmt::et($now)->format('Y');
            if ($y1 > $y0) {
                $years = range($y0, $y1);
                $yo = array_fill(0, count($years), 0);
                $yd = array_fill(0, count($years), 0);
                foreach ($all as $r) {
                    $i = (int) Fmt::et((string) $r['created_at'])->format('Y') - $y0;
                    if ($r['profile'] === 'company_driver') {
                        $yd[$i]++;
                    } else {
                        $yo[$i]++;
                    }
                }
                $out['yearly'] = ['labels' => array_map('strval', $years), 'tips' => array_map('strval', $years), 'ops' => $yo, 'drv' => $yd, 'total' => array_sum($yo) + array_sum($yd), 'note' => null, 'avg' => null];
            }
        }
        return $out;
    }

    /**
     * Q6: anotacion y promedio del grafico, calculados SOLO de los datos mostrados. El ultimo periodo esta en curso, asi que no entra en el promedio.
     * Anotacion (una sola, solo con datos suficientes: 8 leads o mas repartidos en 3 periodos o mas): el pico, o «+N frente al periodo anterior» si el periodo en curso ya es el maximo y supera al anterior.
     * Promedio (linea punteada): solo con 4 periodos completos con datos.
     * @param list<int> $tot total por periodo; @param list<string> $tips titulo de cada periodo
     * @return array{note:?array{i:int,l1:string,l2:string},avg:?float}
     */
    public static function annotate(array $tot, array $tips): array
    {
        $tot = array_values($tot);
        $n = count($tot);
        $full = array_slice($tot, 0, max(0, $n - 1));
        $avg = count(array_filter($full)) >= 4 ? array_sum($full) / count($full) : null;
        $note = null;
        if ($n >= 3 && array_sum($tot) >= 8 && count(array_filter($tot)) >= 3) {
            $max = max($tot);
            $idx = array_keys($tot, $max);
            $i = (int) end($idx);   // si hay empate, el mas reciente
            $diff = $i === $n - 1 ? $tot[$n - 1] - $tot[$n - 2] : 0;
            if ($i === $n - 1 && $diff > 0) {
                $note = ['i' => $i, 'l1' => '+' . $diff . ' ' . ($diff === 1 ? 'lead' : 'leads') . ' en total', 'l2' => 'frente al periodo anterior'];
            } else {
                $note = ['i' => $i, 'l1' => 'Pico: ' . $max . ' ' . ($max === 1 ? 'lead' : 'leads') . ' en total', 'l2' => (string) ($tips[$i] ?? '')];
            }
        }
        return ['note' => $note, 'avg' => $avg];
    }

    /** Leads por fuente en los ultimos 90 dias + una linea de insight calculada (la fuente que mas crece) solo si hay datos suficientes. */
    private static function sources(int $now): array
    {
        $from = Fmt::dayStart($now, -89);
        $mid = Fmt::dayStart($now, -29);
        $prevFrom = Fmt::dayStart($now, -59);
        $rows = Db::all('SELECT source, created_at FROM leads WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= ?', [Fmt::utc($prevFrom), Fmt::utc($now)]);
        $count = [];
        $last30 = [];
        $prev30 = [];
        $total90 = 0;
        $rows90 = Db::all('SELECT source, COUNT(*) AS n FROM leads WHERE deleted_at IS NULL AND created_at >= ? AND created_at <= ? GROUP BY source ORDER BY n DESC', [Fmt::utc($from), Fmt::utc($now)]);
        foreach ($rows90 as $r) {
            $count[(string) $r['source']] = (int) $r['n'];
            $total90 += (int) $r['n'];
        }
        foreach ($rows as $r) {
            $t = (int) Clock::parse((string) $r['created_at']);
            if ($t >= $mid) {
                $last30[$r['source']] = ($last30[$r['source']] ?? 0) + 1;
            } elseif ($t >= $prevFrom) {
                $prev30[$r['source']] = ($prev30[$r['source']] ?? 0) + 1;
            }
        }
        $list = [];
        $i = 0;
        $other = 0;
        foreach ($count as $src => $n) {
            if ($i < 4) {
                $n30 = $last30[$src] ?? 0;
                $p30 = $prev30[$src] ?? 0;
                $list[] = ['key' => $src, 'label' => Catalog::source($src), 'n' => $n, 'pct' => $total90 > 0 ? $n / $total90 * 100 : 0, 'delta' => $n30 + $p30 >= self::MIN_BASE ? self::deltaCount($n30, $p30) : null];
            } else {
                $other += $n;
            }
            $i++;
        }
        if ($other > 0) {
            $list[] = ['key' => '_other', 'label' => 'Otras fuentes', 'n' => $other, 'pct' => $total90 > 0 ? $other / $total90 * 100 : 0];
        }
        $insight = null;
        if ($total90 >= 10) {
            $best = null;
            foreach ($last30 as $src => $n) {
                $p = $prev30[$src] ?? 0;
                if ($n >= 3 && $n > $p && $p >= 1) {
                    $g = ($n - $p) / $p;
                    if ($best === null || $g > $best['g']) {
                        $best = ['src' => $src, 'n' => $n, 'p' => $p, 'g' => $g];
                    }
                }
            }
            if ($best !== null) {
                $insight = Catalog::source($best['src']) . ' sube ' . Fmt::num($best['g'] * 100) . '% frente a los 30 días anteriores (de ' . $best['p'] . ' a ' . $best['n'] . ' leads).';
            }
        }
        return ['total' => $total90, 'list' => $list, 'insight' => $insight, 'label' => 'Últimos 90 días'];
    }

    /** Franja secundaria bajo el grafico: calificados, descarte y costo por lead de las ultimas 12 semanas. */
    private static function strip(array $rows, int $now): array
    {
        $from = Fmt::weekStart($now, -(self::WEEKS - 1));
        $set = self::between($rows, $from, $now + 1);
        $n = count($set);
        $q = count(array_filter($set, static fn($r) => in_array($r['temperature'], ['hot', 'warm'], true)));
        $dq = count(array_filter($set, static fn($r) => $r['temperature'] === 'disqualified'));
        $cost = Costs::currentMonth($now);
        return ['n' => $n, 'qualified_pct' => $n > 0 ? $q / $n * 100 : null, 'discard_pct' => $n > 0 ? $dq / $n * 100 : null, 'cpl' => $cost['cpl'], 'cps' => $cost['cps'], 'has_costs' => $cost['has_costs']];
    }

    private static function heat(array $rows, int $now): array
    {
        $weeks = self::HEAT_WEEKS;
        $start = Fmt::weekStart($now, -($weeks - 1));
        $today = Fmt::dayStart($now);
        $counts = [];   // 'w-d' => n
        $prevTotal = 0;
        $prevFrom = Fmt::weekStart($now, -($weeks * 2 - 1));
        $wStarts = [];   // Q6: inicios de semana calculados UNA vez (antes se recalculaban por cada lead: 2,5 s con 4.500 leads)
        for ($w = 0; $w < $weeks; $w++) {
            $wStarts[$w] = Fmt::weekStart($now, -($weeks - 1 - $w));
        }
        foreach ($rows as $r) {
            $t = $r['t'];
            if ($t < $start) {
                if ($t >= $prevFrom) {
                    $prevTotal++;
                }
                continue;
            }
            $w = $weeks - 1;
            while ($w > 0 && $wStarts[$w] > $t) {
                $w--;
            }
            $key = $w . '-' . ((int) Fmt::et($t)->format('N') - 1);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $cells = [];
        $total = 0;
        $byDow = array_fill(0, 7, 0);
        $busiest = ['n' => -1, 'label' => ''];
        $dayNames = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        $daysElapsed = 0;
        for ($w = 0; $w < $weeks; $w++) {
            $ws = Fmt::weekStart($now, -($weeks - 1 - $w));
            for ($d = 0; $d < 7; $d++) {
                $dt = Fmt::et($ws)->modify('+' . $d . ' days')->setTime(0, 0, 0);
                if ($dt->getTimestamp() > $today) {
                    continue;
                }
                $n = $counts[$w . '-' . $d] ?? 0;
                $total += $n;
                $byDow[$d] += $n;
                $daysElapsed++;
                $label = ucfirst($dayNames[$d]) . ' ' . Fmt::dayLabel($dt);
                $cells[$w][$d] = ['n' => $n, 'label' => $label];
                if ($n > $busiest['n']) {
                    $busiest = ['n' => $n, 'label' => $label];
                }
            }
        }
        $bestDow = array_keys($byDow, max($byDow))[0] ?? 0;
        // etiquetas de mes sobre las columnas
        $months = [];
        $lastM = -1;
        for ($w = 0; $w < $weeks; $w++) {
            $m = (int) Fmt::et(Fmt::weekStart($now, -($weeks - 1 - $w)))->format('n');
            $months[] = $m !== $lastM ? Fmt::monthLabel($m) : '';
            $lastM = $m;
        }
        return [
            'cells' => $cells, 'weeks' => $weeks, 'total' => $total, 'prev_total' => $prevTotal, 'delta' => self::deltaCount($total, $prevTotal),
            'avg_day' => $daysElapsed > 0 ? $total / $daysElapsed : 0, 'busiest_dow' => $total > 0 ? $dayNames[$bestDow] : null, 'busiest' => $busiest, 'months' => $months,
        ];
    }

    /** Tareas vencidas o de hoy y citas de hoy (ET). */
    public static function today(int $now): array
    {
        $endToday = Fmt::dayStart($now, 1);
        $startToday = Fmt::dayStart($now);
        $totalTasks = (int) Db::value('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at < ?', [Fmt::utc($endToday)]);
        $tasks = Db::all(
            'SELECT t.id, t.title, t.due_at, t.priority, t.lead_id, l.first_name, l.last_name
               FROM tasks t LEFT JOIN leads l ON l.id = t.lead_id
              WHERE t.deleted_at IS NULL AND t.done_at IS NULL AND t.due_at IS NOT NULL AND t.due_at < ?
              ORDER BY t.due_at ASC LIMIT 5',
            [Fmt::utc($endToday)]
        );
        $appts = Db::all(
            'SELECT a.id, a.starts_at, a.status, a.lead_id, l.first_name, l.last_name
               FROM appointments a JOIN leads l ON l.id = a.lead_id
              WHERE a.deleted_at IS NULL AND a.status <> "cancelled" AND a.starts_at >= ? AND a.starts_at < ?
              ORDER BY a.starts_at ASC LIMIT 4',
            [Fmt::utc($startToday), Fmt::utc($endToday)]
        );
        return ['tasks' => $tasks, 'appointments' => $appts, 'start' => $startToday, 'more' => max(0, $totalTasks - count($tasks))];
    }

    /** Leads recientes para la tabla del dashboard: busqueda por nombre/correo/telefono y paginacion. */
    public static function recent(string $q, int $page, int $per = 10): array
    {
        $where = 'deleted_at IS NULL';
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            [$sql, $p] = LeadQuery::textClause($q);
            $where .= ' AND ' . $sql;
            $params = $p;
        }
        $total = (int) Db::value("SELECT COUNT(*) FROM leads WHERE $where", $params);
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($page, $pages));
        $rows = Db::all(
            "SELECT id, first_name, last_name, profile, state, equipment_types, score, temperature, stage, pipeline, source, created_at FROM leads WHERE $where ORDER BY created_at DESC, id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $params
        );
        foreach ($rows as &$r) {
            $r['equipment_types'] = Db::decode($r['equipment_types']);
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
    }
}
