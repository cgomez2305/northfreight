<?php
/**
 * Métricas de la captación saliente (fase AT): llamadas a prospectos FMCSA y correos en lote en un rango (los mismos rangos de /reportes).
 * Solo agregados: conteos por resultado, conversión por flota, estado y antigüedad de la autoridad, y resultados de los correos. El lector (NorthStar) ve
 * `summary()`; el administrador ve además los desgloses. Nunca nombres, teléfonos ni correos.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class ProspectReport
{
    public const FLEET_BUCKETS = ['1' => '1 unidad', '2-5' => '2 a 5', '6+' => '6 o más', '?' => 'Sin dato'];
    public const AGE_BUCKETS = ['3' => '3 meses o menos', '6' => '4 a 6 meses', '12' => '7 a 12 meses', '+' => 'Más de 12 meses', '?' => 'Sin fecha'];

    /**
     * @return array{calls:int,prospects:int,by_result:array<string,int>,reached:int,reach_pct:?float,interested:int,converted:int,
     *   mail:array{sent:int,followups:int,opened:int,clicked:int,replied:int,unsub:int}}
     */
    public static function summary(int $from, int $to): array
    {
        $a = Clock::at($from);
        $b = Clock::at($to);
        $by = array_fill_keys(array_keys(Prospects::RESULTS), 0);
        foreach (Db::all('SELECT result, COUNT(*) n FROM prospect_calls WHERE created_at >= ? AND created_at < ? GROUP BY result', [$a, $b]) as $r) {
            $by[(string) $r['result']] = (int) $r['n'];
        }
        $calls = array_sum($by);
        $reached = $by['talked'] + $by['interested'] + $by['not_interested'] + $by['dnc'];   // contestó alguien
        $mail = ['sent' => 0, 'followups' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0, 'unsub' => 0];
        try {
            $m = Db::one("SELECT SUM(status = 'sent' AND sent_at >= ? AND sent_at < ?) s, SUM(fu_status = 'sent' AND fu_sent_at >= ? AND fu_sent_at < ?) f,
                                 SUM(opened_at >= ? AND opened_at < ?) o, SUM(clicked_at >= ? AND clicked_at < ?) k, SUM(replied_at >= ? AND replied_at < ?) r FROM prospect_campaign_recipients",
                [$a, $b, $a, $b, $a, $b, $a, $b, $a, $b]) ?? [];
            $mail = ['sent' => (int) ($m['s'] ?? 0), 'followups' => (int) ($m['f'] ?? 0), 'opened' => (int) ($m['o'] ?? 0), 'clicked' => (int) ($m['k'] ?? 0), 'replied' => (int) ($m['r'] ?? 0),
                'unsub' => (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE email_opt_out_at >= ? AND email_opt_out_at < ?', [$a, $b])];
        } catch (\Throwable) {
            // sin las tablas de correos (antes de la migración 018/019): solo llamadas
        }
        return [
            'calls' => $calls,
            'prospects' => (int) Db::value('SELECT COUNT(DISTINCT prospect_id) FROM prospect_calls WHERE created_at >= ? AND created_at < ?', [$a, $b]),
            'by_result' => $by, 'reached' => $reached, 'reach_pct' => $calls > 0 ? $reached / $calls : null, 'interested' => $by['interested'],
            'converted' => (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE converted_at >= ? AND converted_at < ?', [$a, $b]),
            'mail' => $mail,
        ];
    }

    /** Prospectos llamados en el rango, agrupados: llamados, contestaron, interesados y convertidos. @return list<array{key:string,label:string,called:int,reached:int,interested:int,converted:int}> */
    public static function breakdown(string $dim, int $from, int $to): array
    {
        $now = Clock::time();
        $expr = match ($dim) {
            'fleet' => "CASE WHEN p.fleet_size IS NULL THEN '?' WHEN p.fleet_size <= 1 THEN '1' WHEN p.fleet_size <= 5 THEN '2-5' ELSE '6+' END",
            'age' => 'CASE WHEN p.authority_granted_at IS NULL THEN \'?\' WHEN p.authority_granted_at >= ? THEN \'3\' WHEN p.authority_granted_at >= ? THEN \'6\' WHEN p.authority_granted_at >= ? THEN \'12\' ELSE \'+\' END',
            default => "COALESCE(NULLIF(p.state, ''), '?')",
        };
        $params = [];
        if ($dim === 'age') {   // antigüedad de la autoridad AL LLAMAR no se guarda: se mide contra hoy, que es lo que sirve para decidir a quién llamar
            foreach ([3, 6, 12] as $mo) {
                $params[] = (new \DateTimeImmutable('@' . $now))->modify("-$mo months")->format('Y-m-d');
            }
        }
        $params = array_merge($params, [Clock::at($from), Clock::at($to)]);
        $rows = Db::all(
            "SELECT $expr k, COUNT(*) called, SUM(x.reached) reached, SUM(x.interested) interested, SUM(p.converted_at IS NOT NULL) converted
               FROM (SELECT prospect_id, MAX(result IN ('talked', 'interested', 'not_interested', 'dnc')) reached, MAX(result = 'interested') interested
                       FROM prospect_calls WHERE created_at >= ? AND created_at < ? GROUP BY prospect_id) x
               JOIN prospects_fmcsa p ON p.id = x.prospect_id GROUP BY k ORDER BY called DESC LIMIT 60",
            $params
        );
        $labels = $dim === 'fleet' ? self::FLEET_BUCKETS : ($dim === 'age' ? self::AGE_BUCKETS : []);
        $out = [];
        foreach ($rows as $r) {
            $k = (string) $r['k'];
            $out[] = ['key' => $k, 'label' => $labels[$k] ?? ($k === '?' ? 'Sin dato' : $k . (isset(UsStates::NAMES[$k]) ? ' · ' . UsStates::NAMES[$k] : '')), 'called' => (int) $r['called'],
                'reached' => (int) $r['reached'], 'interested' => (int) $r['interested'], 'converted' => (int) $r['converted']];
        }
        if ($labels) {   // orden natural de los tramos
            $order = array_flip(array_keys($labels));
            usort($out, static fn($x, $y) => ($order[$x['key']] ?? 99) <=> ($order[$y['key']] ?? 99));
        }
        return $out;
    }

    /** Campañas con envíos en el rango y sus resultados. @return list<array{name:string,sent:int,opened:int,clicked:int,replied:int}> */
    public static function campaigns(int $from, int $to): array
    {
        try {
            return array_map(static fn($r) => ['name' => (string) $r['name'], 'sent' => (int) $r['s'], 'opened' => (int) $r['o'], 'clicked' => (int) $r['k'], 'replied' => (int) $r['rp']], Db::all(
                "SELECT c.name, SUM(r.status = 'sent') s, SUM(r.opened_at IS NOT NULL) o, SUM(r.clicked_at IS NOT NULL) k, SUM(r.replied_at IS NOT NULL) rp
                   FROM prospect_campaigns c JOIN prospect_campaign_recipients r ON r.campaign_id = c.id
                  WHERE c.deleted_at IS NULL AND r.sent_at >= ? AND r.sent_at < ? GROUP BY c.id, c.name ORDER BY MIN(r.sent_at) DESC LIMIT 20",
                [Clock::at($from), Clock::at($to)]
            ));
        } catch (\Throwable) {
            return [];
        }
    }
}
