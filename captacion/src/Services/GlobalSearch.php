<?php
/** Buscador global (barra superior, atajo Ctrl/Cmd K): nombre, telefono, correo o USDOT. Devuelve pocas filas ligeras. */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class GlobalSearch
{
    public const MIN = 2;
    public const LIMIT = 8;

    /** @return array{items:list<array>,total:int} con $recent (ids), una busqueda vacia devuelve esos leads (los ultimos abiertos por el usuario). */
    public static function run(string $q, array $recent = []): array
    {
        $q = trim(mb_substr($q, 0, 100));
        if ($q === '' && $recent) {
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $recent), static fn($i) => $i > 0))), 0, 5);
            $rows = $ids ? Db::all('SELECT l.id, l.first_name, l.last_name, l.profile, l.state, l.temperature, l.score, l.stage, l.phone_e164, l.email, l.usdot FROM leads l WHERE l.deleted_at IS NULL AND l.id IN (' . implode(',', $ids) . ')') : [];
            usort($rows, static fn($a, $b) => array_search((int) $a['id'], $ids, true) <=> array_search((int) $b['id'], $ids, true));
            $total = count($rows);
        } elseif (mb_strlen($q) < self::MIN) {
            return ['items' => [], 'total' => 0];
        } else {
            [$sql, $params] = LeadQuery::textClause($q, 'l');
            $total = (int) Db::value("SELECT COUNT(*) FROM leads l WHERE l.deleted_at IS NULL AND $sql", $params);
            $rows = Db::all(
                "SELECT l.id, l.first_name, l.last_name, l.profile, l.state, l.temperature, l.score, l.stage, l.phone_e164, l.email, l.usdot
                   FROM leads l WHERE l.deleted_at IS NULL AND $sql ORDER BY l.created_at DESC, l.id DESC LIMIT " . self::LIMIT,
                $params
            );
        }
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int) $r['id'], 'name' => Fmt::name($r), 'initials' => Fmt::initials(Fmt::name($r)), 'av' => Fmt::avatar(Fmt::name($r)),
                'profile' => Catalog::label(Catalog::PROFILES_SHORT, $r['profile']), 'state' => $r['state'] ?? '', 'temp' => $r['temperature'], 'temp_label' => Catalog::label(Catalog::TEMPS, $r['temperature']),
                'score' => (int) $r['score'], 'stage' => Catalog::stageAny($r['stage']), 'phone' => Fmt::phone($r['phone_e164']), 'email' => (string) ($r['email'] ?? ''), 'usdot' => (string) ($r['usdot'] ?? ''),
                'url' => '/leads/' . (int) $r['id'],
            ];
        }
        return ['items' => $items, 'total' => $total];
    }
}
