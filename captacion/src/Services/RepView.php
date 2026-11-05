<?php
/** Ayudas de presentacion de los reportes (indicadores, dinero, texto de costos) compartidas por /reportes, /resultados y los informes. Solo formato: no consulta la base. */

declare(strict_types=1);

namespace Captacion\Services;

final class RepView
{
    public static function money(?float $v, int $dec = 2): string
    {
        return $v === null ? '—' : '$' . Fmt::num($v, $dec);
    }

    /** Texto del costo por firmado total. */
    public static function cpsText(array $m): array
    {
        $t = $m['channels']['total'];
        if ($t['cps'] !== null) {
            return [self::money($t['cps'], 0), 'Costo de ' . self::money($t['cost'], 0) . ' entre ' . $t['signed'] . ' firmado' . ($t['signed'] === 1 ? '' : 's') . ' de los meses completos.'];
        }
        if ($t['cost'] > 0) {
            return ['Aún sin firmados', 'Hay costos cargados (' . self::money($t['cost'], 0) . '), pero ningún firmado en esos meses.'];
        }
        return ['Sin costos cargados', $m['channels']['cost_months'] ? 'No hay costos cargados para ' . implode(', ', array_map([Reports::class, 'monthName'], $m['channels']['cost_months'])) . '.' : 'El rango no incluye ningún mes completo: los costos son mensuales.'];
    }

    /**
     * Indicadores (tarjetas). $p = periodo anterior para las variaciones (null = sin comparar). $full = incluye tiempos (solo administrador).
     * @return list<array{label:string,value:string,delta:?array,sub:string,na:bool}>
     */
    public static function kpis(array $m, ?array $p, bool $full): array
    {
        $d = static fn($c, $pv, bool $up = true) => $p === null ? null : Dashboard::delta((float) $c, (float) $pv, $up);
        $pctOf = static fn(int $n) => $m['received'] > 0 ? Fmt::num($n / $m['received'] * 100, 0) . '% de los recibidos' : 'Sin leads en el rango';
        [$cpsV, $cpsSub] = self::cpsText($m);
        $out = [
            ['label' => 'RECIBIDOS', 'value' => Fmt::num($m['received']), 'delta' => $d($m['received'], $p['received'] ?? 0), 'sub' => 'Leads que llegaron en el rango', 'na' => false],
            ['label' => 'CALIFICADOS', 'value' => Fmt::num($m['qualified']), 'delta' => $d($m['qualified'], $p['qualified'] ?? 0), 'sub' => $pctOf($m['qualified']), 'na' => false],
            ['label' => 'PRESENTADOS', 'value' => Fmt::num($m['presented']), 'delta' => $d($m['presented'], $p['presented'] ?? 0), 'sub' => $pctOf($m['presented']), 'na' => false],
            ['label' => 'FIRMADOS', 'value' => Fmt::num($m['signed']), 'delta' => $d($m['signed'], $p['signed'] ?? 0), 'sub' => 'De los recibidos · por fecha de firma en el rango: ' . $m['signed_in_period'], 'na' => false],
            ['label' => 'COSTO POR FIRMADO', 'value' => $cpsV, 'delta' => $p !== null && $m['channels']['total']['cps'] !== null && ($p['channels']['total']['cps'] ?? null) !== null ? Dashboard::delta((float) $m['channels']['total']['cps'], (float) $p['channels']['total']['cps'], false) : null, 'sub' => $cpsSub, 'na' => $m['channels']['total']['cps'] === null],
        ];
        if ($full) {
            $r = $m['response'];
            $out[] = ['label' => 'PRIMERA RESPUESTA', 'value' => $r['median'] !== null ? Fmt::dur($r['median']) : '—', 'delta' => $r['median'] !== null && ($p['response']['median'] ?? null) !== null ? Dashboard::delta((float) $r['median'], (float) $p['response']['median'], false) : null,
                'sub' => $r['median'] !== null ? 'Mediana · p90 ' . Fmt::dur($r['p90']) . ' · ' . $r['n'] . ' con contacto' : 'Ningún lead contactado', 'na' => $r['median'] === null];
            $s = $m['sign_time'];
            $out[] = ['label' => 'TIEMPO HASTA LA FIRMA', 'value' => $s['median_days'] !== null ? Fmt::num($s['median_days'], 1) . ' d' : '—', 'delta' => null, 'sub' => $s['median_days'] !== null ? 'Mediana · ' . $s['n'] . ' firmado' . ($s['n'] === 1 ? '' : 's') : 'Aún sin firmados', 'na' => $s['median_days'] === null];
        }
        return $out;
    }

    /** Fila de variacion para la tabla «vs periodo anterior» del informe. @return list<array{label:string,cur:string,prev:string,delta:array}> */
    public static function compareRows(array $m, array $p): array
    {
        $rows = [];
        foreach ([['Recibidos', 'received'], ['Calificados', 'qualified'], ['Presentados', 'presented'], ['Firmados', 'signed']] as [$l, $k]) {
            $rows[] = ['label' => $l, 'cur' => Fmt::num($m[$k]), 'prev' => Fmt::num($p[$k]), 'delta' => Dashboard::delta((float) $m[$k], (float) $p[$k])];
        }
        $c = $m['channels']['total']['cps'];
        $pc = $p['channels']['total']['cps'] ?? null;
        $rows[] = ['label' => 'Costo por firmado', 'cur' => $c !== null ? self::money($c, 0) : 'Sin dato', 'prev' => $pc !== null ? self::money($pc, 0) : 'Sin dato', 'delta' => $c !== null && $pc !== null ? Dashboard::delta((float) $c, (float) $pc, false) : ['text' => 'No comparable', 'dir' => 'flat', 'good' => null]];
        return $rows;
    }
}
