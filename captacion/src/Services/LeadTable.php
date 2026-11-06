<?php
/**
 * Vista Tabla de los leads (Q5c-B): tabla densa para COMPARAR muchos leads. Mismos filtros que la Lista (LeadQuery), pero con mas columnas, orden por cualquier columna,
 * columnas elegibles y reordenables (preferencia `tcols` del usuario) y exportacion CSV de lo filtrado con esas columnas.
 *
 * El orden se valida con LISTA BLANCA (SORTS): la clave de la URL nunca llega al SQL; lo que va al ORDER BY son expresiones fijas de esta clase. Es estable: siempre cierra con `l.id DESC`,
 * y las columnas que pueden estar vacias dejan los vacios al final en cualquier direccion. La etapa se ordena por su posicion en el embudo, no alfabeticamente.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class LeadTable
{
    /** Columnas opcionales (clave => etiqueta). «Lead» (nombre) siempre se ve y va fija a la izquierda. */
    public const COLUMNS = [
        'code' => 'Código', 'prof' => 'Perfil', 'st' => 'Estado', 'lang' => 'Idioma', 'eq' => 'Equipo', 'trucks' => 'Camiones', 'exp' => 'Años de experiencia', 'usdot' => 'USDOT',
        'phone' => 'Teléfono', 'email' => 'Correo', 'pts' => 'Puntos', 'temp' => 'Temperatura', 'stage' => 'Etapa', 'src' => 'Fuente', 'contact' => 'Primer contacto',
        'when' => 'Recibido', 'changed' => 'Último cambio', 'task' => 'Próxima tarea',
    ];
    /** Etiqueta corta de la cabecera. */
    public const HEAD = [
        'code' => 'Código', 'prof' => 'Perfil', 'st' => 'Est.', 'lang' => 'Idioma', 'eq' => 'Equipo', 'trucks' => 'Camiones', 'exp' => 'Años', 'usdot' => 'USDOT', 'phone' => 'Teléfono', 'email' => 'Correo',
        'pts' => 'Pts', 'temp' => 'Temp.', 'stage' => 'Etapa', 'src' => 'Fuente', 'contact' => '1.er contacto', 'when' => 'Recibido', 'changed' => 'Cambio', 'task' => 'Próxima tarea',
    ];
    /** Claves de orden (URL) => [expresion SQL, puede ser NULL]. «name» y las de la Lista (created, score) tienen alias. */
    public const SORTS = [
        'name' => ["CONCAT_WS(' ', l.first_name, l.last_name)", false], 'code' => ['l.id', false], 'prof' => ['l.profile', false], 'st' => ['l.state', true], 'lang' => ['l.language', false],
        'trucks' => ['l.truck_count', true], 'exp' => ['l.years_experience', true], 'usdot' => ['l.usdot', true], 'pts' => ['l.score', false], 'temp' => ['l.temperature', false],
        'stage' => ['STAGE', false], 'src' => ['l.source', false], 'contact' => ['l.first_contact_at', true], 'when' => ['l.created_at', false], 'changed' => ['l.updated_at', false],
    ];
    private const ALIAS = ['created' => 'when', 'score' => 'pts'];
    public const PER = [25, 50, 100];

    /** Orden y paginacion validados. @return array{sort:string,dir:string,page:int,per:int} */
    public static function paging(array $in): array
    {
        $s = is_string($in['sort'] ?? null) ? $in['sort'] : '';
        $s = self::ALIAS[$s] ?? $s;
        $sort = isset(self::SORTS[$s]) ? $s : 'when';
        $dir = ($in['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        $per = (int) ($in['per'] ?? 25);
        return ['sort' => $sort, 'dir' => $dir, 'page' => max(1, min(100000, (int) ($in['page'] ?? 1))), 'per' => in_array($per, self::PER, true) ? $per : 25];
    }

    /** Clave de orden valida para guardarla en una vista (la de la Lista o la de la Tabla), o null. */
    public static function cleanSort(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        return isset(LeadQuery::SORTS[$s]) || isset(self::SORTS[$s]) ? $s : null;
    }

    /** ORDER BY completo (solo expresiones fijas de esta clase; $paging ya viene validado). */
    public static function orderBy(array $paging): string
    {
        [$expr, $nullable] = self::SORTS[$paging['sort']] ?? self::SORTS['when'];
        $dir = $paging['dir'] === 'asc' ? 'ASC' : 'DESC';
        if ($expr === 'STAGE') {
            $keys = array_values(array_unique(array_filter(array_keys(Catalog::allStages()), static fn($k) => preg_match('/^[a-z_]{2,30}$/', (string) $k) === 1)));
            $expr = "FIELD(l.stage, '" . implode("','", $keys) . "')";
        }
        return ($nullable ? "($expr IS NULL), " : '') . "$expr $dir, l.id DESC";
    }

    private const SELECT = 'l.id, l.first_name, l.last_name, l.profile, l.state, l.language, l.equipment_types, l.truck_count, l.years_experience, l.usdot, l.phone_e164, l.email, l.score, l.temperature, l.stage,
                    l.pipeline, l.source, l.first_contact_at, l.created_at, l.updated_at, l.flags';

    /** @return array{rows:list<array>,total:int,page:int,pages:int,per:int} */
    public static function list(array $f, array $paging): array
    {
        [$where, $params] = LeadQuery::where($f);
        $total = (int) Db::value("SELECT COUNT(*) FROM leads l WHERE $where", $params);
        $pages = max(1, (int) ceil($total / $paging['per']));
        $page = min($paging['page'], $pages);
        $rows = Db::all('SELECT ' . self::SELECT . " FROM leads l WHERE $where ORDER BY " . self::orderBy($paging) . ' LIMIT ' . (int) $paging['per'] . ' OFFSET ' . (int) (($page - 1) * $paging['per']), $params);
        foreach ($rows as &$r) {
            $r['equipment_types'] = Db::decode($r['equipment_types']);
            $r['flags'] = Db::decode($r['flags']);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $paging['per']];
    }

    /** Fecha de la proxima tarea abierta de cada lead de la pagina (null = sin tarea, '' = sin fecha). @param list<array> $rows @return array<int,string> */
    public static function nextTasks(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $out = [];
        foreach (Db::all('SELECT lead_id, due_at FROM tasks WHERE lead_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND done_at IS NULL AND deleted_at IS NULL ORDER BY due_at IS NULL, due_at ASC', $ids) as $t) {
            $out[(int) $t['lead_id']] ??= (string) ($t['due_at'] ?? '');
        }
        return $out;
    }

    /** Columnas elegidas, ya validadas, en el orden del usuario. @param list<string> $cols @return list<string> */
    public static function cleanCols(array $cols): array
    {
        $out = array_values(array_unique(array_filter($cols, static fn($c) => is_string($c) && isset(self::COLUMNS[$c]))));
        return $out ?: explode(',', Prefs::DEFAULTS['tcols']);
    }

    /** Valor de texto de una celda (el mismo que se pinta y que va al CSV). */
    public static function text(string $col, array $r, array $nextTasks = []): string
    {
        $tz = static fn(?string $v): string => $v === null || $v === '' ? '' : (Fmt::et($v)?->format('Y-m-d H:i') ?? '');
        return match ($col) {
            'code' => Fmt::code((int) $r['id']),
            'prof' => Catalog::label(Catalog::PROFILES, (string) $r['profile']),
            'st' => (string) ($r['state'] ?? ''),
            'lang' => Catalog::label(Catalog::LANGS, (string) $r['language']),
            'eq' => implode('; ', array_map(static fn($t) => Catalog::label(Catalog::EQUIPMENT, (string) $t), (array) $r['equipment_types'])),
            'trucks' => $r['truck_count'] === null ? '' : (string) $r['truck_count'],
            'exp' => $r['years_experience'] === null ? '' : (string) $r['years_experience'],
            'usdot' => (string) ($r['usdot'] ?? ''),
            'phone' => (string) ($r['phone_e164'] ?? ''),
            'email' => (string) ($r['email'] ?? ''),
            'pts' => (string) (int) $r['score'],
            'temp' => Catalog::label(Catalog::TEMPS, (string) $r['temperature']),
            'stage' => Catalog::stageLabel((string) $r['pipeline'], (string) $r['stage']),
            'src' => Catalog::source((string) $r['source']),
            'contact' => $tz($r['first_contact_at']),
            'when' => $tz($r['created_at']),
            'changed' => $tz($r['updated_at']),
            'task' => !isset($nextTasks[(int) $r['id']]) ? '' : ($nextTasks[(int) $r['id']] === '' ? 'Sin fecha' : $tz($nextTasks[(int) $r['id']])),
            default => '',
        };
    }

    /**
     * CSV de lo filtrado (o de una seleccion) con las columnas visibles, en su orden: UTF-8 con BOM y proteccion contra formulas (Csv::cell). Maximo 10.000 filas.
     * @param list<string> $cols @param ?list<int> $ids
     * @return array{csv:string,rows:int}
     */
    public static function csv(array $f, array $paging, array $cols, ?array $ids = null): array
    {
        $cols = self::cleanCols($cols);
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
            $ids = array_slice($ids, 0, 1000);
            if (!$ids) {
                return ['csv' => self::csvBody([], $cols, []), 'rows' => 0];
            }
            $where = 'l.deleted_at IS NULL AND l.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        } else {
            [$where, $params] = LeadQuery::where($f);
        }
        $rows = Db::all('SELECT ' . self::SELECT . " FROM leads l WHERE $where ORDER BY " . self::orderBy($paging) . ' LIMIT 10000', $params);
        foreach ($rows as &$r) {
            $r['equipment_types'] = Db::decode($r['equipment_types']);
        }
        unset($r);
        $next = [];
        if (in_array('task', $cols, true) && $rows) {
            foreach (Db::all("SELECT t.lead_id, MIN(t.due_at) AS due, COUNT(*) AS n FROM tasks t JOIN leads l ON l.id = t.lead_id WHERE $where AND t.done_at IS NULL AND t.deleted_at IS NULL GROUP BY t.lead_id", $params) as $t) {
                $next[(int) $t['lead_id']] = (string) ($t['due'] ?? '');
            }
        }
        return ['csv' => self::csvBody($rows, $cols, $next), 'rows' => count($rows)];
    }

    /** @param list<array> $rows @param list<string> $cols @param array<int,string> $next */
    private static function csvBody(array $rows, array $cols, array $next): string
    {
        $zone = ' (' . Fmt::zone() . ')';
        $head = ['Nombre'];
        foreach ($cols as $c) {
            $head[] = self::COLUMNS[$c] . (in_array($c, ['contact', 'when', 'changed', 'task'], true) ? $zone : '');
        }
        $out = "\xEF\xBB\xBF" . Csv::line($head);
        foreach ($rows as $r) {
            $line = [Fmt::name($r)];
            foreach ($cols as $c) {
                $line[] = self::text($c, $r, $next);
            }
            $out .= Csv::line($line);
        }
        return $out;
    }
}
