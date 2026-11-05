<?php
/**
 * Reportes (doc §9.9 y §12): metricas de captacion sobre filas reales de la base, para /reportes (admin), /resultados (lector, solo agregados) y los informes.
 *
 * DEFINICIONES (se muestran en la interfaz):
 *  - Cohorte: los leads RECIBIDOS en el rango (created_at, hora ET). Embudo y conversiones cuentan, de esa cohorte, cuantos LLEGARON a cada etapa
 *    (etapa actual o cualquier etapa del historial `stage_changed`; first_contact_at ⇒ Contactado; handed_off_at ⇒ Presentado; signed_at ⇒ Firmado). Los company drivers
 *    no tienen «Llamada agendada»: cuentan en esa fila si llegaron mas lejos.
 *  - Calificado = llego a «Calificado» (A) o «Precalificado» (B). «Puntuacion alta» = temperatura caliente o tibia (el «% calificados» del dashboard).
 *  - Firmados = leads de la cohorte con firma (signed_at o etapa Firmado/Contratado). «Firmados en el periodo» cuenta por FECHA DE FIRMA, sea cual sea el mes de llegada.
 *  - Primera respuesta = first_contact_at − created_at (mediana y percentil 90). Tiempo hasta la firma = dias entre created_at y signed_at (mediana).
 *  - Costos (channel_costs) son MENSUALES: CPL y costo por firmado se calculan SOLO con los meses completos contenidos en el rango, dividiendo por los leads/firmados de esos meses y esas
 *    fuentes. Sin meses completos o sin costos cargados: «Sin costos cargados». Nunca se prorratea ni se inventa un numero.
 * Todo con consultas sobre indices (leads(deleted_at, created_at), leads(deleted_at, signed_at), lead_events(lead_id…)).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Reports
{
    public const RANK = ['new' => 1, 'contacted' => 2, 'qualified' => 3, 'prequalified' => 3, 'call_booked' => 4, 'presented' => 5, 'signed' => 6, 'hired' => 6];
    public const FUNNEL = [1 => ['new', 'Nuevo'], 2 => ['contacted', 'Contactado'], 3 => ['qualified', 'Calificado'], 4 => ['call_booked', 'Llamada agendada'], 5 => ['presented', 'Presentado a NorthStar'], 6 => ['signed', 'Firmado']];
    public const PRESETS = ['7d' => 'Últimos 7 días', '30d' => 'Últimos 30 días', 'month' => 'Este mes', 'lastmonth' => 'Mes pasado', '90d' => 'Últimos 90 días', 'year' => 'Este año'];
    public const VIEWER_PRESETS = ['30d' => 'Últimos 30 días', 'lastmonth' => 'Mes pasado', '90d' => 'Últimos 90 días', 'year' => 'Este año'];
    public const MAX_DAYS = 400;
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public static function monthName(string $period): string
    {
        return self::MONTHS[(int) substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4);
    }

    // --- Rango y comparacion ----------------------------------------------------------------------------------------------------

    /**
     * Rango pedido por la URL (r = preset, o from/to AAAA-MM-DD en hora ET, ambos inclusive). Devuelve el rango, el periodo anterior con el que se compara y, si algo no era valido, `error`.
     * @param array<string,string> $presets presets permitidos
     * @return array{key:string,label:string,from:int,to:int,prev_from:int,prev_to:int,prev_label:string,custom:bool,from_in:string,to_in:string,error:?string}
     */
    public static function resolve(array $q, array $presets = self::PRESETS, ?int $now = null): array
    {
        $now ??= Clock::time();
        $key = is_string($q['r'] ?? null) && isset($presets[$q['r']]) ? $q['r'] : (isset($presets['30d']) ? '30d' : (string) array_key_first($presets));
        $err = null;
        $custom = false;
        $today = Fmt::dayStart($now);
        $tomorrow = Fmt::dayStart($now, 1);
        $from = $to = 0;
        $label = $presets[$key];
        $calMonth = false;
        if (isset($q['from']) || isset($q['to'])) {
            $f = is_string($q['from'] ?? null) ? Fmt::fromEtInput($q['from']) : null;
            $t = is_string($q['to'] ?? null) ? Fmt::fromEtInput($q['to'], true) : null;
            if ($f === null || $t === null || !isset($presets['7d']) || (int) Clock::parse($t) < (int) Clock::parse($f)) {
                $err = isset($presets['7d']) ? 'Las fechas no son válidas: usa AAAA-MM-DD y que «Desde» no sea posterior a «Hasta». Se muestra el rango anterior.' : null;
            } elseif (((int) Clock::parse($t) - (int) Clock::parse($f)) / 86400 > self::MAX_DAYS) {
                $err = 'El rango máximo es de ' . self::MAX_DAYS . ' días. Se muestra el rango anterior.';
            } else {
                $custom = true;
                $from = (int) Clock::parse($f);
                $to = (int) Clock::parse($t) + 1;
                $key = 'custom';
                $label = Fmt::dayLabel(Fmt::et($from)) . ' a ' . Fmt::dayLabel(Fmt::et($to - 1)) . ' ' . Fmt::et($to - 1)->format('Y');
            }
        }
        if (!$custom) {
            switch ($key) {
                case '7d':
                    [$from, $to] = [Fmt::dayStart($now, -6), $tomorrow];
                    break;
                case 'month':
                    [$from, $to] = [Fmt::monthStart($now), $tomorrow];
                    $calMonth = true;
                    break;
                case 'lastmonth':
                    [$from, $to] = [Fmt::monthStart($now, -1), Fmt::monthStart($now)];
                    $calMonth = true;
                    break;
                case '90d':
                    [$from, $to] = [Fmt::dayStart($now, -89), $tomorrow];
                    break;
                case 'year':
                    $y = Fmt::et($now)->format('Y');
                    [$from, $to] = [(int) Clock::parse((string) Fmt::fromEtInput($y . '-01-01')), $tomorrow];
                    break;
                default:
                    $key = '30d';
                    [$from, $to] = [Fmt::dayStart($now, -29), $tomorrow];
            }
        }
        $days = max(1, (int) round(($to - $from) / 86400));
        if ($calMonth) {
            $pf = Fmt::monthStart($from, -1);
            $pt = $key === 'lastmonth' ? $from : min($from, $pf + ($to - $from));
            $prevLabel = $key === 'lastmonth' ? 'vs el mes anterior' : 'vs el mismo tramo del mes anterior';
        } else {
            $pf = Fmt::et($from)->modify('-' . $days . ' days')->setTime(0, 0, 0)->getTimestamp();
            $pt = $from;
            $prevLabel = 'vs los ' . $days . ' días anteriores';
        }
        return [
            'key' => $key, 'label' => $label, 'from' => $from, 'to' => $to, 'prev_from' => $pf, 'prev_to' => $pt, 'prev_label' => $prevLabel, 'custom' => $custom,
            'from_in' => Fmt::et($from)->format('Y-m-d'), 'to_in' => Fmt::et($to - 1)->format('Y-m-d'), 'error' => $err,
        ];
    }

    // --- Metricas ----------------------------------------------------------------------------------------------------------------------

    private static function pct(int $n, int $d): ?float
    {
        return $d > 0 ? round($n / $d * 100, 1) : null;
    }

    /** @param list<int> $values */
    public static function percentile(array $values, float $p): ?int
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        return $values[max(0, (int) ceil($p * $n) - 1)];
    }

    /** Agrupa filas [clave => [leads, qualified, presented, signed]] ordenadas por leads. */
    private static function group(array $rows, int $limit = 0): array
    {
        uasort($rows, static fn($a, $b) => [$b['leads'], $b['signed']] <=> [$a['leads'], $a['signed']]);
        $out = [];
        foreach ($rows as $k => $r) {
            $out[] = ['key' => (string) $k] + $r + ['conv' => self::pct($r['signed'], $r['leads'])];
        }
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    private static function bump(array &$bucket, string $key, int $rank, bool $hotwarm): void
    {
        $b = &$bucket[$key];
        $b ??= ['leads' => 0, 'qualified' => 0, 'presented' => 0, 'signed' => 0, 'hotwarm' => 0];
        $b['leads']++;
        $b['qualified'] += $rank >= 3 ? 1 : 0;
        $b['presented'] += $rank >= 5 ? 1 : 0;
        $b['signed'] += $rank >= 6 ? 1 : 0;
        $b['hotwarm'] += $hotwarm ? 1 : 0;
    }

    /** Todas las metricas del rango [$from, $to) (timestamps). Solo agregados: ningun dato personal ni id de lead. */
    public static function compute(int $from, int $to): array
    {
        $leads = Db::all(
            'SELECT id, profile, state, source, landing_page, equipment_types, temperature, stage, first_contact_at, handed_off_at, signed_at, lost_reason, created_at
               FROM leads WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?',
            [Fmt::utc($from), Fmt::utc($to)]
        );
        // etapa mas lejana alcanzada segun el historial
        $maxEvent = [];
        $ids = array_map(static fn($l) => (int) $l['id'], $leads);
        foreach (array_chunk($ids, 800) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all("SELECT lead_id, payload FROM lead_events WHERE type = 'stage_changed' AND lead_id IN ($in)", $chunk) as $e) {
                $to2 = (string) (Db::decode($e['payload'])['to'] ?? '');
                $maxEvent[(int) $e['lead_id']] = max($maxEvent[(int) $e['lead_id']] ?? 0, self::RANK[$to2] ?? 0);
            }
        }
        $reach = array_fill(1, 6, 0);
        $hotwarm = 0;
        $resp = [];
        $signDays = [];
        $loss = [];
        $lost = 0;
        $chan = $prof = $equip = $state = $land = $monthKeyLeads = [];
        foreach ($leads as $l) {
            $rank = max(self::RANK[$l['stage']] ?? 1, $maxEvent[(int) $l['id']] ?? 1, $l['first_contact_at'] !== null ? 2 : 1, $l['handed_off_at'] !== null ? 5 : 1, $l['signed_at'] !== null ? 6 : 1);
            for ($k = 1; $k <= $rank; $k++) {
                $reach[$k]++;
            }
            $hw = in_array($l['temperature'], ['hot', 'warm'], true);
            $hotwarm += $hw ? 1 : 0;
            if ($l['first_contact_at'] !== null) {
                $s = Fmt::secondsBetween((string) $l['created_at'], (string) $l['first_contact_at']);
                if ($s !== null && $s >= 0) {
                    $resp[] = $s;
                }
            }
            if ($rank >= 6 && $l['signed_at'] !== null) {
                $s = Fmt::secondsBetween((string) $l['created_at'], (string) $l['signed_at']);
                if ($s !== null && $s >= 0) {
                    $signDays[] = $s / 86400;
                }
            }
            if ($l['stage'] === 'lost') {
                $lost++;
                $r = (string) ($l['lost_reason'] ?? 'other');
                $loss[$r] = ($loss[$r] ?? 0) + 1;
            }
            self::bump($chan, (string) $l['source'], $rank, $hw);
            self::bump($prof, (string) $l['profile'], $rank, $hw);
            self::bump($state, $l['state'] !== null && $l['state'] !== '' ? (string) $l['state'] : '—', $rank, $hw);
            $path = $l['landing_page'] !== null ? (string) (parse_url((string) $l['landing_page'], PHP_URL_PATH) ?: '') : '';
            self::bump($land, $path !== '' ? mb_substr($path, 0, 120) : '(sin página de llegada)', $rank, $hw);
            foreach (array_unique(array_map('strval', Db::decode($l['equipment_types']))) as $eq) {
                self::bump($equip, $eq, $rank, $hw);
            }
            $mk = Fmt::et((string) $l['created_at'])->format('Y-m');
            $monthKeyLeads[$mk][(string) $l['source']]['leads'] = ($monthKeyLeads[$mk][(string) $l['source']]['leads'] ?? 0) + 1;
            $monthKeyLeads[$mk][(string) $l['source']]['signed'] = ($monthKeyLeads[$mk][(string) $l['source']]['signed'] ?? 0) + ($rank >= 6 ? 1 : 0);
        }
        $funnel = [];
        foreach (self::FUNNEL as $k => [$key, $label]) {
            $funnel[] = ['key' => $key, 'label' => $label, 'n' => $reach[$k], 'of_prev' => $k > 1 ? self::pct($reach[$k], $reach[$k - 1]) : null, 'of_total' => self::pct($reach[$k], $reach[1])];
        }
        arsort($loss);
        $lossRows = [];
        foreach ($loss as $k => $n) {
            $lossRows[] = ['key' => $k, 'label' => Catalog::label(Catalog::LOSS_REASONS, $k, 'Sin motivo'), 'n' => $n, 'pct' => self::pct($n, $lost)];
        }
        $signedIn = (int) Db::value('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND signed_at >= ? AND signed_at < ?', [Fmt::utc($from), Fmt::utc($to)]);
        sort($signDays);
        $med = $signDays ? (count($signDays) % 2 ? $signDays[intdiv(count($signDays), 2)] : ($signDays[count($signDays) / 2 - 1] + $signDays[count($signDays) / 2]) / 2) : null;

        return [
            'from' => gmdate('c', $from), 'to' => gmdate('c', $to), 'received' => $reach[1], 'hotwarm' => $hotwarm, 'qualified' => $reach[3], 'presented' => $reach[5], 'signed' => $reach[6],
            'signed_in_period' => $signedIn, 'funnel' => $funnel,
            'response' => ['median' => Dashboard::median($resp), 'p90' => self::percentile($resp, 0.9), 'n' => count($resp)],
            'sign_time' => ['median_days' => $med !== null ? round($med, 1) : null, 'n' => count($signDays)],
            'loss' => ['total' => $lost, 'rows' => $lossRows],
            'channels' => self::channels($from, $to, $chan, $monthKeyLeads),
            'landing' => self::group($land, 15), 'profiles' => self::group($prof), 'equipment' => self::group($equip), 'states' => self::group($state, 20),
            'trend' => self::trend($from, $to, $leads),
        ];
    }

    /** Filas por canal con costos de los meses completos del rango. */
    private static function channels(int $from, int $to, array $chan, array $monthLeads): array
    {
        $months = [];
        $d = Fmt::et($from)->modify('first day of this month')->setTime(0, 0, 0);
        if ($d->getTimestamp() < $from) {
            $d = $d->modify('first day of next month')->setTime(0, 0, 0);
        }
        while (true) {
            $next = $d->modify('first day of next month')->setTime(0, 0, 0);
            if ($next->getTimestamp() > $to) {
                break;
            }
            $months[] = $d->format('Y-m');
            $d = $next;
        }
        $cost = [];
        if ($months) {
            $in = implode(',', array_fill(0, count($months), '?'));
            foreach (Db::all("SELECT source, SUM(amount) AS a FROM channel_costs WHERE period IN ($in) GROUP BY source", $months) as $r) {
                $cost[(string) $r['source']] = (float) $r['a'];
            }
        }
        $inMonthLeads = $inMonthSigned = [];
        foreach ($months as $m) {
            foreach ($monthLeads[$m] ?? [] as $src => $v) {
                $inMonthLeads[$src] = ($inMonthLeads[$src] ?? 0) + $v['leads'];
                $inMonthSigned[$src] = ($inMonthSigned[$src] ?? 0) + $v['signed'];
            }
        }
        foreach (array_keys($cost) as $src) {
            $chan[$src] ??= ['leads' => 0, 'qualified' => 0, 'presented' => 0, 'signed' => 0, 'hotwarm' => 0];
        }
        $rows = [];
        $tot = ['cost' => 0.0, 'leads' => 0, 'signed' => 0];
        foreach (self::group($chan) as $r) {
            $src = $r['key'];
            $c = $cost[$src] ?? null;
            $cl = $inMonthLeads[$src] ?? 0;
            $cs = $inMonthSigned[$src] ?? 0;
            $r += [
                'label' => Catalog::source($src), 'cost' => $c, 'cost_leads' => $cl, 'cost_signed' => $cs,
                'cpl' => $c !== null && $c > 0 && $cl > 0 ? round($c / $cl, 2) : null, 'cps' => $c !== null && $c > 0 && $cs > 0 ? round($c / $cs, 2) : null,
            ];
            if ($c !== null && $c > 0) {
                $tot['cost'] += $c;
                $tot['leads'] += $cl;
                $tot['signed'] += $cs;
            }
            $rows[] = $r;
        }
        return [
            'rows' => $rows, 'cost_months' => $months,
            'total' => ['cost' => $tot['cost'], 'leads' => $tot['leads'], 'signed' => $tot['signed'], 'cpl' => $tot['cost'] > 0 && $tot['leads'] > 0 ? round($tot['cost'] / $tot['leads'], 2) : null, 'cps' => $tot['cost'] > 0 && $tot['signed'] > 0 ? round($tot['cost'] / $tot['signed'], 2) : null],
        ];
    }

    /** Serie de leads recibidos y firmados (por fecha de firma): por dia hasta 62 dias, por semana (lunes) despues. */
    private static function trend(int $from, int $to, array $leads): array
    {
        $days = (int) round(($to - $from) / 86400);
        $daily = $days <= 62;
        $starts = [];
        $cur = Fmt::et($from)->setTime(0, 0, 0);
        if (!$daily) {
            $cur = $cur->modify('-' . ((int) $cur->format('N') - 1) . ' days')->setTime(0, 0, 0);
        }
        while ($cur->getTimestamp() < $to) {
            $starts[] = $cur->getTimestamp();
            $cur = $cur->modify($daily ? '+1 day' : '+7 days')->setTime(0, 0, 0);
        }
        $idx = static function (int $t) use ($starts): ?int {
            $i = count($starts) - 1;
            while ($i > 0 && $starts[$i] > $t) {
                $i--;
            }
            return $i >= 0 && $t >= $starts[0] ? $i : null;
        };
        $rec = array_fill(0, count($starts), 0);
        $sig = array_fill(0, count($starts), 0);
        foreach ($leads as $l) {
            $i = $idx((int) Clock::parse((string) $l['created_at']));
            if ($i !== null) {
                $rec[$i]++;
            }
        }
        foreach (Db::all('SELECT signed_at FROM leads WHERE deleted_at IS NULL AND signed_at >= ? AND signed_at < ?', [Fmt::utc($from), Fmt::utc($to)]) as $r) {
            $i = $idx((int) Clock::parse((string) $r['signed_at']));
            if ($i !== null) {
                $sig[$i]++;
            }
        }
        $labels = $tips = [];
        $n = count($starts);
        $every = max(1, (int) ceil($n / 8));
        foreach ($starts as $i => $t) {
            $d = Fmt::et($t);
            $tips[] = $daily ? Fmt::weekdayShort((int) $d->format('N')) . ' ' . Fmt::dayLabel($d) : 'Semana del ' . Fmt::dayLabel($d);
            $labels[] = $i % $every === 0 ? Fmt::dayLabel($d) : '';
        }
        return ['unit' => $daily ? 'day' : 'week', 'labels' => $labels, 'tips' => $tips, 'received' => $rec, 'signed' => $sig];
    }

    // --- Exportacion --------------------------------------------------------------------------------------------------------------------

    public const TABLES = ['embudo' => 'Embudo', 'canales' => 'Canales y costos', 'paginas' => 'Páginas de llegada', 'perfiles' => 'Perfiles', 'equipos' => 'Equipos', 'estados' => 'Estados', 'perdidas' => 'Motivos de pérdida'];

    /** @return array{header:list<string>,rows:list<list<mixed>>} */
    public static function table(string $name, array $m): array
    {
        $p = static fn($v) => $v === null ? '' : $v;
        $money = static fn($v) => $v === null ? 'Sin costos cargados' : number_format((float) $v, 2, '.', '');
        $grp = static fn(array $rows, string $first) => ['header' => [$first, 'Leads', 'Calificados', 'Presentados', 'Firmados', 'Firmados / leads (%)'], 'rows' => array_map(static fn($r) => [$r['key'], $r['leads'], $r['qualified'], $r['presented'], $r['signed'], $p($r['conv'])], $rows)];
        return match ($name) {
            'embudo'   => ['header' => ['Etapa', 'Leads que llegaron', '% de la etapa anterior', '% del total'], 'rows' => array_map(static fn($f) => [$f['label'], $f['n'], $p($f['of_prev']), $p($f['of_total'])], $m['funnel'])],
            'canales'  => ['header' => ['Canal', 'Leads', 'Calificados', 'Presentados', 'Firmados', 'Costo (meses completos)', 'Leads de esos meses', 'Firmados de esos meses', 'Costo por lead', 'Costo por firmado'],
                'rows' => array_map(static fn($r) => [$r['label'], $r['leads'], $r['qualified'], $r['presented'], $r['signed'], $money($r['cost']), $r['cost_leads'], $r['cost_signed'], $r['cpl'] !== null ? $money($r['cpl']) : ($r['cost'] === null ? 'Sin costos cargados' : 'Sin leads'), $r['cps'] !== null ? $money($r['cps']) : ($r['cost'] === null ? 'Sin costos cargados' : 'Sin firmados')], $m['channels']['rows'])],
            'paginas'  => $grp($m['landing'], 'Página de llegada'),
            'perfiles' => (static function () use ($m, $grp) {
                $t = $grp($m['profiles'], 'Perfil');
                $t['rows'] = array_map(static fn($r) => [Catalog::label(Catalog::PROFILES, $r[0])] + $r, $t['rows']);
                return $t;
            })(),
            'equipos'  => (static function () use ($m, $grp) {
                $t = $grp($m['equipment'], 'Equipo');
                $t['rows'] = array_map(static fn($r) => [Catalog::label(Catalog::EQUIPMENT, $r[0])] + $r, $t['rows']);
                return $t;
            })(),
            'estados'  => $grp($m['states'], 'Estado'),
            'perdidas' => ['header' => ['Motivo', 'Leads perdidos', '% de las pérdidas'], 'rows' => array_map(static fn($r) => [$r['label'], $r['n'], $p($r['pct'])], $m['loss']['rows'])],
            default    => ['header' => [], 'rows' => []],
        };
    }

    public static function csv(string $name, array $m): string
    {
        $t = self::table($name, $m);
        $out = "\xEF\xBB\xBF" . Csv::line($t['header']);
        foreach ($t['rows'] as $r) {
            $out .= Csv::line($r);
        }
        return $out;
    }

    /** Proyeccion SEGURA para el lector de NorthStar: solo totales, embudo, costos por canal, tendencia y motivos de perdida. */
    public static function forViewer(array $m): array
    {
        return [
            'received' => $m['received'], 'qualified' => $m['qualified'], 'presented' => $m['presented'], 'signed' => $m['signed'], 'signed_in_period' => $m['signed_in_period'], 'funnel' => $m['funnel'],
            'loss' => $m['loss'], 'trend' => $m['trend'], 'sign_time' => $m['sign_time'],
            'channels' => ['cost_months' => $m['channels']['cost_months'], 'total' => $m['channels']['total'], 'rows' => array_map(static fn($r) => array_intersect_key($r, array_flip(['key', 'label', 'leads', 'qualified', 'presented', 'signed', 'cost', 'cost_leads', 'cost_signed', 'cpl', 'cps'])), $m['channels']['rows'])],
        ];
    }
}
