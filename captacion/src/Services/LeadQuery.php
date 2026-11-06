<?php
/**
 * Bandeja de leads: filtros (todos desde la URL, con lista blanca), orden, paginacion, conteos por temperatura y filas para exportar.
 * Claves de la URL: t temperatura · pf perfil · src fuente · st etapa · eq equipo · us estado · lg idioma · from/to fechas (ET, AAAA-MM-DD) · q texto · ct=none (sin contactar)
 * · sort (created|score|name|stage) · dir (asc|desc) · page · per (25|50|100). Los datos personales nunca viajan en la URL salvo lo que la persona escribe en q.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class LeadQuery
{
    public const SORTS = ['created' => 'l.created_at', 'score' => 'l.score', 'name' => 'l.first_name', 'stage' => 'l.stage'];
    public const PER = [25, 50, 100];

    /** Normaliza los parametros de la URL a un arreglo de filtros validos (lo no valido se descarta). */
    public static function filters(array $in): array
    {
        $s = static fn(string $k, int $max = 80): string => is_string($in[$k] ?? null) ? mb_substr(trim($in[$k]), 0, $max) : '';
        $f = [];
        if (isset(Catalog::TEMPS[$s('t')])) {
            $f['t'] = $s('t');
        }
        if (isset(Catalog::PROFILES[$s('pf')])) {
            $f['pf'] = $s('pf');
        }
        if (preg_match('/^[a-z0-9_]{2,40}$/', $s('src')) === 1) {
            $f['src'] = $s('src');
        }
        if (isset(Catalog::allStages()[$s('st')])) {
            $f['st'] = $s('st');
        }
        if (isset(Catalog::EQUIPMENT[$s('eq')])) {
            $f['eq'] = $s('eq');
        }
        if (isset(UsStates::NAMES[strtoupper($s('us'))])) {
            $f['us'] = strtoupper($s('us'));
        }
        if (isset(Catalog::LANGS[$s('lg')])) {
            $f['lg'] = $s('lg');
        }
        foreach (['from', 'to'] as $k) {
            if (Fmt::fromEtInput($s($k)) !== null) {
                $f[$k] = $s($k);
            }
        }
        if ($s('q', 100) !== '') {
            $f['q'] = $s('q', 100);
        }
        if ($s('ct') === 'none') {
            $f['ct'] = 'none';
        }
        if ($s('nt') === '1') {
            $f['nt'] = '1';   // Q5a: sin ninguna tarea abierta
        }
        if ($s('op') === '1') {
            $f['op'] = '1';   // Q5a: solo leads abiertos (ni perdidos, ni firmados, ni descartados)
        }
        if ($s('hs') === 'wait') {
            $f['hs'] = 'wait';   // Q5a: presentados a NorthStar que aun no responden
        }
        if ($s('fl') === 'fmcsa_pending' || $s('fl') === 'review_queue' || preg_match('/^tag:[a-z0-9][a-z0-9-]{0,23}$/D', $s('fl')) === 1) {
            $f['fl'] = $s('fl');   // fmcsa_pending, review_queue (cola de revision de las reglas, Q5b) o tag:etiqueta
        }
        return $f;
    }

    /** Orden y paginacion. @return array{sort:string,dir:string,page:int,per:int} */
    public static function paging(array $in): array
    {
        $sort = is_string($in['sort'] ?? null) && isset(self::SORTS[$in['sort']]) ? $in['sort'] : 'created';
        $dir = ($in['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        $per = (int) ($in['per'] ?? 25);
        return ['sort' => $sort, 'dir' => $dir, 'page' => max(1, min(100000, (int) ($in['page'] ?? 1))), 'per' => in_array($per, self::PER, true) ? $per : 25];
    }

    private static function like(string $s): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
    }

    /** Condicion de texto libre (nombre, correo, telefono, USDOT). @return array{0:string,1:list<string>} */
    public static function textClause(string $q, string $alias = ''): array
    {
        $a = $alias !== '' ? $alias . '.' : '';
        $q = trim($q);
        $or = ["CONCAT_WS(' ', {$a}first_name, {$a}last_name) LIKE ?", "{$a}email LIKE ?"];
        $params = [self::like($q), self::like($q)];
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        if (preg_match('/^ld-?(\d{1,9})$/i', $q, $cm) === 1) {   // codigo corto del lead (LD-0142)
            $or[] = "{$a}id = ?";
            $params[] = (int) $cm[1];
        }
        if (strlen($digits) >= 3 && preg_match('/^[\d\s().+\-]+$/', $q) === 1) {
            $or[] = "{$a}phone_e164 LIKE ?";
            $params[] = self::like($digits);
            $or[] = "{$a}phone_raw LIKE ?";
            $params[] = self::like($digits);
        }
        if (preg_match('/^\d{4,8}$/', $digits) === 1 && $digits === $q) {
            $or[] = "{$a}usdot LIKE ?";
            $params[] = $digits . '%';
        }
        return ['(' . implode(' OR ', $or) . ')', $params];
    }

    /** Cuenta los leads que cumplen los filtros (vistas fijadas de la barra lateral). */
    public static function count(array $f): int
    {
        [$where, $params] = self::where($f);
        return (int) Db::value("SELECT COUNT(*) FROM leads l WHERE $where", $params);
    }

    /** @return array{0:string,1:list<mixed>} WHERE (con alias l) y parametros; $skip omite una clave de filtro (para contar por temperatura). */
    public static function where(array $f, ?string $skip = null): array
    {
        $w = ['l.deleted_at IS NULL'];
        $p = [];
        $simple = ['t' => 'l.temperature', 'pf' => 'l.profile', 'src' => 'l.source', 'st' => 'l.stage', 'us' => 'l.state', 'lg' => 'l.language'];
        foreach ($simple as $k => $col) {
            if (isset($f[$k]) && $k !== $skip) {
                $w[] = "$col = ?";
                $p[] = $f[$k];
            }
        }
        if (isset($f['eq'])) {
            $w[] = 'l.equipment_types LIKE ?';
            $p[] = '%"' . $f['eq'] . '"%';
        }
        if (isset($f['from'])) {
            $w[] = 'l.created_at >= ?';
            $p[] = Fmt::fromEtInput($f['from']);
        }
        if (isset($f['to'])) {
            $w[] = 'l.created_at <= ?';
            $p[] = Fmt::fromEtInput($f['to'], true);
        }
        if (isset($f['q'])) {
            [$sql, $params] = self::textClause($f['q'], 'l');
            $w[] = $sql;
            array_push($p, ...$params);
        }
        if (isset($f['fl'])) {
            $w[] = 'l.flags LIKE ?';
            $p[] = '%"' . $f['fl'] . '"%';
        }
        if (isset($f['ct'])) {
            $w[] = "l.first_contact_at IS NULL AND l.stage = 'new'";
        }
        if (isset($f['nt'])) {
            $w[] = 'NOT EXISTS (SELECT 1 FROM tasks tk WHERE tk.lead_id = l.id AND tk.done_at IS NULL AND tk.deleted_at IS NULL)';
        }
        if (isset($f['op'])) {
            $w[] = "l.stage NOT IN ('lost','signed','hired') AND l.temperature <> 'disqualified'";
        }
        if (isset($f['hs'])) {
            $w[] = "l.stage = 'presented' AND l.handoff_status = 'pending'";
        }
        return [implode(' AND ', $w), $p];
    }

    public static function list(array $f, array $paging): array
    {
        [$where, $params] = self::where($f);
        $total = (int) Db::value("SELECT COUNT(*) FROM leads l WHERE $where", $params);
        $pages = max(1, (int) ceil($total / $paging['per']));
        $page = min($paging['page'], $pages);
        $order = self::SORTS[$paging['sort']] . ' ' . strtoupper($paging['dir']) . ($paging['sort'] === 'name' ? ', l.last_name ' . strtoupper($paging['dir']) : '') . ', l.id DESC';
        $rows = Db::all(
            "SELECT l.id, l.first_name, l.last_name, l.profile, l.state, l.language, l.equipment_types, l.truck_count, l.score, l.temperature, l.stage, l.pipeline, l.source,
                    l.phone_e164, l.first_contact_at, l.created_at, l.flags
               FROM leads l WHERE $where ORDER BY $order LIMIT " . (int) $paging['per'] . ' OFFSET ' . (int) (($page - 1) * $paging['per']),
            $params
        );
        foreach ($rows as &$r) {
            $r['equipment_types'] = Db::decode($r['equipment_types']);
            $r['flags'] = Db::decode($r['flags']);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $paging['per']];
    }

    /** Conteo por temperatura con el resto de filtros aplicados (pestanas de la bandeja). @return array<string,int> con 'all' */
    public static function tempCounts(array $f): array
    {
        [$where, $params] = self::where($f, 't');
        $out = ['all' => 0, 'hot' => 0, 'warm' => 0, 'cold' => 0, 'disqualified' => 0];
        foreach (Db::all("SELECT l.temperature AS t, COUNT(*) AS n FROM leads l WHERE $where GROUP BY l.temperature", $params) as $r) {
            $out[(string) $r['t']] = (int) $r['n'];
            $out['all'] += (int) $r['n'];
        }
        return $out;
    }

    /** Valores distintos para los filtros (fuentes y estados presentes en la base). */
    public static function facets(): array
    {
        return [
            'sources' => array_column(Db::all('SELECT DISTINCT source FROM leads WHERE deleted_at IS NULL ORDER BY source'), 'source'),
            'states'  => array_column(Db::all('SELECT DISTINCT state FROM leads WHERE deleted_at IS NULL AND state IS NOT NULL ORDER BY state'), 'state'),
        ];
    }

    /** Filas completas para exportar a CSV (sin los borrados). $ids = seleccion; si es null, todo lo que cumpla $f. Maximo 10.000. */
    public static function exportRows(?array $f, ?array $ids = null): array
    {
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
            if (!$ids) {
                return [];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $where = "l.deleted_at IS NULL AND l.id IN ($in)";
            $params = $ids;
        } else {
            [$where, $params] = self::where($f ?? []);
        }
        return Db::all(
            "SELECT l.id, l.created_at, l.first_name, l.last_name, l.profile, l.phone_e164, l.email, l.state, l.language, l.equipment_types, l.truck_count, l.years_experience, l.usdot,
                    l.score, l.temperature, l.stage, l.lost_reason, l.source, l.landing_page, l.first_contact_at
               FROM leads l WHERE $where ORDER BY l.created_at DESC, l.id DESC LIMIT 10000",
            $params
        );
    }
}
