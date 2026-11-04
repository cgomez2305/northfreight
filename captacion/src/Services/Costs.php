<?php
/**
 * Costos por canal (Configuracion > Costos por canal): un monto por fuente y mes (channel_costs, unico por fuente+mes). Con eso se calcula el costo por lead (CPL)
 * y, cuando hay firmados, el costo por firmado. Calculo (el mismo del dashboard): CPL del mes = suma de los montos de ese mes / leads creados ese mes (hora del panel)
 * por las fuentes que tienen monto > 0; costo por firmado = esa misma suma / leads de esas fuentes creados ese mes que ya firmaron (signed_at o etapa Firmado/Contratado).
 * Nunca se inventa un numero: sin costos o sin leads/firmados el valor es null y la pantalla dice por que.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Costs
{
    public const MAX_AMOUNT = 1000000;

    /** Fuentes a las que se les puede cargar un costo (todas las conocidas). */
    public static function sources(): array
    {
        return Catalog::SOURCES;
    }

    /** @return array{ok:bool,errors:array<string,string>,values:array{source:string,period:string,amount:string}} */
    public static function validate(array $in): array
    {
        $err = [];
        $source = is_string($in['source'] ?? null) ? $in['source'] : '';
        if (!isset(Catalog::SOURCES[$source])) {
            $err['source'] = 'Elige una fuente de la lista.';
        }
        $period = is_string($in['period'] ?? null) ? trim($in['period']) : '';
        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period, $m) !== 1) {
            $err['period'] = 'Elige un mes válido.';
        } elseif ($period > Fmt::et(Clock::time())->modify('+1 month')->format('Y-m')) {
            $err['period'] = 'No se cargan costos de meses futuros (más allá del próximo).';
        }
        $amountRaw = is_string($in['amount'] ?? null) ? trim(str_replace([',', '$', ' '], '', $in['amount'])) : '';
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $amountRaw) !== 1) {
            $err['amount'] = 'Escribe el monto en dólares, por ejemplo 350 o 349.90.';
        } elseif ((float) $amountRaw > self::MAX_AMOUNT) {
            $err['amount'] = 'El monto máximo es ' . number_format(self::MAX_AMOUNT) . ' USD.';
        }
        return ['ok' => !$err, 'errors' => $err, 'values' => ['source' => $source, 'period' => $period, 'amount' => $amountRaw]];
    }

    /** Alta o edicion (upsert por fuente+mes). @return array{ok:bool,msg:string} */
    public static function save(array $v, int $userId): array
    {
        $amount = number_format((float) $v['amount'], 2, '.', '');
        $before = Db::one('SELECT id, amount FROM channel_costs WHERE source = ? AND period = ?', [$v['source'], $v['period']]);
        if ($before === null) {
            $id = Db::insert('channel_costs', ['source' => $v['source'], 'period' => $v['period'], 'amount' => $amount]);
            ActivityLog::record('cost_save', $userId, 'channel_cost', $id, ['source' => $v['source'], 'period' => $v['period'], 'before' => null, 'after' => $amount]);
            return ['ok' => true, 'msg' => 'Costo de ' . Catalog::source($v['source']) . ' (' . $v['period'] . ') guardado.'];
        }
        if ((float) $before['amount'] === (float) $amount) {
            return ['ok' => true, 'msg' => 'Sin cambios: ese costo ya estaba cargado.'];
        }
        Db::update('channel_costs', (int) $before['id'], ['amount' => $amount]);
        ActivityLog::record('cost_save', $userId, 'channel_cost', (int) $before['id'], ['source' => $v['source'], 'period' => $v['period'], 'before' => number_format((float) $before['amount'], 2, '.', ''), 'after' => $amount]);
        return ['ok' => true, 'msg' => 'Costo de ' . Catalog::source($v['source']) . ' (' . $v['period'] . ') actualizado.'];
    }

    public static function delete(int $id, int $userId): array
    {
        $row = Db::one('SELECT source, period, amount FROM channel_costs WHERE id = ?', [$id]);
        if ($row === null) {
            return ['ok' => false, 'msg' => 'Ese costo no existe.'];
        }
        Db::run('DELETE FROM channel_costs WHERE id = ?', [$id]);
        ActivityLog::record('cost_delete', $userId, 'channel_cost', $id, ['source' => $row['source'], 'period' => $row['period'], 'before' => number_format((float) $row['amount'], 2, '.', ''), 'after' => null]);
        return ['ok' => true, 'msg' => 'Costo eliminado.'];
    }

    /**
     * Ventana UTC [desde, hasta) de un mes 'AAAA-MM' en la zona del panel.
     * @return array{0:string,1:string}
     */
    public static function window(string $period): array
    {
        $from = (string) Fmt::fromEtInput($period . '-01');
        $next = (new \DateTimeImmutable($period . '-01', Fmt::tz()))->modify('+1 month')->format('Y-m-d');
        return [$from, (string) Fmt::fromEtInput($next)];
    }

    /** Leads y firmados creados en el mes, por fuente. @return array<string,array{leads:int,signed:int}> */
    public static function leadsBySource(string $period): array
    {
        [$from, $to] = self::window($period);
        $out = [];
        foreach (Db::all(
            "SELECT source, COUNT(*) AS n, SUM(signed_at IS NOT NULL OR stage IN ('signed','hired')) AS s FROM leads WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ? GROUP BY source",
            [$from, $to]
        ) as $r) {
            $out[(string) $r['source']] = ['leads' => (int) $r['n'], 'signed' => (int) $r['s']];
        }
        return $out;
    }

    /**
     * Costos con sus metricas, del mes mas reciente al mas antiguo.
     * @return array{rows:list<array>,months:list<array>}
     */
    public static function table(int $limit = 200): array
    {
        $rows = Db::all('SELECT id, source, period, amount FROM channel_costs ORDER BY period DESC, source ASC LIMIT ' . (int) $limit);
        $cache = [];
        $months = [];
        foreach ($rows as &$r) {
            $p = (string) $r['period'];
            $cache[$p] ??= self::leadsBySource($p);
            $m = $cache[$p][$r['source']] ?? ['leads' => 0, 'signed' => 0];
            $amount = (float) $r['amount'];
            $r['leads'] = $m['leads'];
            $r['signed'] = $m['signed'];
            $r['cpl'] = $amount > 0 && $m['leads'] > 0 ? $amount / $m['leads'] : null;
            $r['cps'] = $amount > 0 && $m['signed'] > 0 ? $amount / $m['signed'] : null;
            $months[$p]['period'] = $p;
            $months[$p]['amount'] = ($months[$p]['amount'] ?? 0.0) + $amount;
            if ($amount > 0) {
                $months[$p]['leads'] = ($months[$p]['leads'] ?? 0) + $m['leads'];
                $months[$p]['signed'] = ($months[$p]['signed'] ?? 0) + $m['signed'];
            }
        }
        unset($r);
        foreach ($months as &$m) {
            $m['leads'] = $m['leads'] ?? 0;
            $m['signed'] = $m['signed'] ?? 0;
            $m['cpl'] = $m['amount'] > 0 && $m['leads'] > 0 ? $m['amount'] / $m['leads'] : null;
            $m['cps'] = $m['amount'] > 0 && $m['signed'] > 0 ? $m['amount'] / $m['signed'] : null;
        }
        return ['rows' => $rows, 'months' => array_values($months)];
    }

    /** Costo por lead y por firmado del mes de $now (para el dashboard). @return array{has_costs:bool,cpl:?float,cps:?float,amount:float,leads:int,signed:int} */
    public static function currentMonth(int $now): array
    {
        $has = (int) Db::value('SELECT COUNT(*) FROM channel_costs') > 0;
        $out = ['has_costs' => $has, 'cpl' => null, 'cps' => null, 'amount' => 0.0, 'leads' => 0, 'signed' => 0];
        if (!$has) {
            return $out;
        }
        $period = Fmt::et($now)->format('Y-m');
        $by = self::leadsBySource($period);
        foreach (Db::all('SELECT source, amount FROM channel_costs WHERE period = ?', [$period]) as $r) {
            $out['amount'] += (float) $r['amount'];
            if ((float) $r['amount'] > 0) {
                $out['leads'] += $by[$r['source']]['leads'] ?? 0;
                $out['signed'] += $by[$r['source']]['signed'] ?? 0;
            }
        }
        $out['cpl'] = $out['amount'] > 0 && $out['leads'] > 0 ? $out['amount'] / $out['leads'] : null;
        $out['cps'] = $out['amount'] > 0 && $out['signed'] > 0 ? $out['amount'] / $out['signed'] : null;
        return $out;
    }
}
