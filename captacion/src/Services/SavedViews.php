<?php
/**
 * Vistas de leads (Q5a-C). Una vista = filtros de LeadQuery + orden (+ pipeline del tablero). Hay dos tipos:
 *  - del sistema (SYSTEM, en codigo, iguales para todos): Calientes pendientes, Llegaron hoy, Sin tarea, Descartados, Presentados sin respuesta;
 *  - del usuario (tabla saved_views): privadas, nunca compartidas; se pueden renombrar, fijar (barra lateral, con contador) y borrar (borrado logico).
 * Se identifican en la URL con `v`: `sys-<clave>` o el id numerico. Todos los modos (lista, tablero, tabla, calendario y cronologia, Q5c) comparten filtros: el mismo conjunto sirve para todos,
 * y una vista del usuario recuerda ademas el modo en que se guardo (columna `mode`; null = se abre en la pantalla actual).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class SavedViews
{
    public const MAX = 30;
    public const SYSTEM = [
        'hot_pending'    => ['name' => 'Calientes pendientes', 'icon' => 'zap', 'pin' => true],
        'today'          => ['name' => 'Llegaron hoy', 'icon' => 'inbox', 'pin' => true],
        'no_task'        => ['name' => 'Sin tarea', 'icon' => 'list-check', 'pin' => false],
        'discarded'      => ['name' => 'Descartados', 'icon' => 'flag', 'pin' => true],
        'presented_wait' => ['name' => 'Presentados sin respuesta', 'icon' => 'send', 'pin' => false],
        'review'         => ['name' => 'Por revisar', 'icon' => 'eye', 'pin' => false],   // Q5b: cola de revision de las reglas
    ];
    /** Pantallas (modos) en que se puede abrir una vista: clave => ruta. */
    public const SCREENS = ['leads' => '/leads', 'pipeline' => '/pipeline', 'tabla' => '/tabla', 'calendario' => '/calendario', 'cronologia' => '/cronologia'];
    /** Modo de la vista guardada (valor de la columna `mode` y de Prefs::MODES) => pantalla. */
    public const MODE_SCREEN = ['lista' => 'leads', 'tablero' => 'pipeline', 'tabla' => 'tabla', 'calendario' => 'calendario', 'cronologia' => 'cronologia'];
    /** Claves de la URL que forman una vista (los filtros de LeadQuery). */
    private const FILTER_KEYS = ['t', 'pf', 'src', 'st', 'eq', 'us', 'lg', 'from', 'to', 'q', 'ct', 'fl', 'nt', 'op', 'hs'];

    /** @return array<string,string> */
    private static function systemFilters(string $key): array
    {
        return match ($key) {
            'hot_pending'    => ['t' => 'hot', 'ct' => 'none'],
            'today'          => ['from' => Fmt::et(Clock::time())->format('Y-m-d')],
            'no_task'        => ['nt' => '1', 'op' => '1'],
            'discarded'      => ['t' => 'disqualified'],
            'presented_wait' => ['hs' => 'wait'],
            'review'         => ['fl' => 'review_queue'],
            default          => [],
        };
    }

    /** Solo las claves de filtro validas de un arreglo cualquiera (URL, JSON guardado). @return array<string,string> */
    public static function cleanFilters(array $in): array
    {
        return LeadQuery::filters(array_intersect_key(array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $in), array_flip(self::FILTER_KEYS)));
    }

    /** @return array{id:string,sys:bool,name:string,icon:string,pinned:bool,filters:array<string,string>,sort:?string,dir:?string,pl:?string,mode:?string} */
    private static function shape(string $id, bool $sys, string $name, string $icon, bool $pinned, array $filters, ?string $sort = null, ?string $dir = null, ?string $pl = null, ?string $mode = null): array
    {
        return ['id' => $id, 'sys' => $sys, 'name' => $name, 'icon' => $icon, 'pinned' => $pinned, 'filters' => $filters, 'sort' => $sort, 'dir' => $dir, 'pl' => $pl, 'mode' => $mode];
    }

    /** Modo valido para guardar (o null). */
    public static function cleanMode(mixed $m): ?string
    {
        return is_string($m) && isset(self::MODE_SCREEN[$m]) ? $m : null;
    }

    /** Modo (clave de Prefs::MODES) de una pantalla. */
    public static function modeOf(string $screen): ?string
    {
        $k = array_search($screen, self::MODE_SCREEN, true);
        return $k === false ? null : (string) $k;
    }

    /** @return list<array> vistas del sistema */
    public static function system(): array
    {
        $out = [];
        foreach (self::SYSTEM as $k => $d) {
            $out[] = self::shape('sys-' . $k, true, $d['name'], $d['icon'], $d['pin'], self::systemFilters($k));
        }
        return $out;
    }

    /** @return list<array> vistas del usuario, fijadas primero */
    public static function mine(int $userId): array
    {
        $out = [];
        foreach (Db::all('SELECT id, name, filters, sort, dir, pipeline, pinned, mode FROM saved_views WHERE user_id = ? AND deleted_at IS NULL ORDER BY pinned DESC, position ASC, id ASC', [$userId]) as $r) {
            $out[] = self::shape((string) $r['id'], false, (string) $r['name'], 'star', (int) $r['pinned'] === 1, self::cleanFilters(Db::decode($r['filters'])),
                LeadTable::cleanSort((string) $r['sort']), in_array($r['dir'], ['asc', 'desc'], true) ? (string) $r['dir'] : null, in_array($r['pipeline'], ['A', 'B'], true) ? (string) $r['pipeline'] : null, self::cleanMode($r['mode']));
        }
        return $out;
    }

    /** Busca una vista por el valor de `v` (solo las del sistema o las DE ESTE usuario). */
    public static function find(int $userId, string $v): ?array
    {
        foreach (self::system() as $s) {
            if ($s['id'] === $v) {
                return $s;
            }
        }
        if (preg_match('/^\d{1,12}$/', $v) === 1) {
            foreach (self::mine($userId) as $m) {
                if ($m['id'] === $v) {
                    return $m;
                }
            }
        }
        return null;
    }

    /** Pantalla en la que se abre una vista: la de su modo guardado, o $fallback (la pantalla actual) si no recuerda ninguno. */
    public static function screenFor(array $view, string $fallback = 'leads'): string
    {
        $m = $view['mode'] ?? null;
        return $m !== null && isset(self::MODE_SCREEN[$m]) ? self::MODE_SCREEN[$m] : (isset(self::SCREENS[$fallback]) ? $fallback : 'leads');
    }

    /** Parametros de URL que representan la vista en una pantalla ('leads' | 'pipeline' | 'tabla' | 'calendario' | 'cronologia'). @return array<string,string|null> */
    public static function params(array $view, string $screen): array
    {
        $p = $view['filters'];
        if ($screen === 'leads') {
            $p += ['sort' => $view['sort'] !== null && isset(LeadQuery::SORTS[$view['sort']]) && $view['sort'] !== 'created' ? $view['sort'] : null, 'dir' => $view['dir'] === 'asc' ? 'asc' : null];
        } elseif ($screen === 'tabla') {
            $p += ['sort' => $view['sort'] !== null && $view['sort'] !== 'when' && $view['sort'] !== 'created' ? $view['sort'] : null, 'dir' => $view['dir'] === 'asc' ? 'asc' : null];
        } elseif ($screen === 'pipeline' && ($view['pl'] ?? null) === 'B') {
            $p += ['pl' => 'B'];
        }
        return $p + ['v' => $view['id']];
    }

    /** $screen null = la pantalla de su modo guardado (barra lateral y paleta); un nombre fuerza esa pantalla solo si la vista no recuerda un modo. */
    public static function href(array $view, ?string $screen = null): string
    {
        $scr = self::screenFor($view, $screen ?? 'leads');
        return self::SCREENS[$scr] . \Captacion\Ui::qs(self::params($view, $scr));
    }

    /** Vista activa segun la URL (`v`) y si los filtros actuales ya no coinciden con los guardados. @return array{view:array,dirty:bool}|null */
    public static function active(int $userId, array $get, array $currentFilters): ?array
    {
        $v = is_string($get['v'] ?? null) ? $get['v'] : '';
        $view = $v !== '' ? self::find($userId, $v) : null;
        if ($view === null) {
            return null;
        }
        $a = $view['filters'];
        $b = array_diff_key($currentFilters, ['v' => 1]);
        ksort($a);
        ksort($b);
        return ['view' => $view, 'dirty' => $a !== $b];
    }

    /** Vistas de la barra lateral con contador en vivo: las del sistema fijadas y las del usuario fijadas. @return list<array> */
    public static function sidebar(int $userId): array
    {
        $out = [];
        foreach ([...self::system(), ...self::mine($userId)] as $v) {
            if ($v['pinned']) {
                $v['count'] = LeadQuery::count($v['filters']);
                $v['href'] = self::href($v);
                $out[] = $v;
            }
        }
        return $out;
    }

    /** @return array{ok:bool,msg:string,id?:int} */
    public static function create(int $userId, mixed $name, array $filters, ?string $sort, ?string $dir, ?string $pl, ?string $mode = null): array
    {
        $n = self::cleanName($name);
        if ($n === '') {
            return ['ok' => false, 'msg' => 'Ponle un nombre a la vista (hasta 60 caracteres).'];
        }
        if (self::nameTaken($userId, $n, null)) {
            return ['ok' => false, 'msg' => 'Ya tienes una vista con ese nombre.'];
        }
        if ((int) Db::value('SELECT COUNT(*) FROM saved_views WHERE user_id = ? AND deleted_at IS NULL', [$userId]) >= self::MAX) {
            return ['ok' => false, 'msg' => 'Llegaste al máximo de ' . self::MAX . ' vistas. Borra alguna para crear otra.'];
        }
        $f = self::cleanFilters($filters);
        if (!$f && $sort === null) {
            return ['ok' => false, 'msg' => 'Aplica algún filtro u orden antes de guardar la vista.'];
        }
        $id = Db::insert('saved_views', ['user_id' => $userId, 'name' => $n, 'filters' => $f, 'sort' => LeadTable::cleanSort($sort), 'dir' => $dir === 'asc' ? 'asc' : ($dir === 'desc' ? 'desc' : null),
            'pipeline' => $pl === 'B' ? 'B' : ($pl === 'A' ? 'A' : null), 'mode' => self::cleanMode($mode), 'created_at' => Clock::now()]);
        ActivityLog::record('view_create', $userId, 'view', $id, ['name' => $n]);
        return ['ok' => true, 'msg' => 'Vista «' . $n . '» guardada.', 'id' => $id];
    }

    /** Sobrescribe los filtros y el orden de una vista propia. */
    public static function update(int $userId, int $id, array $filters, ?string $sort, ?string $dir, ?string $pl, ?string $mode = null): array
    {
        $v = self::owned($userId, $id);
        if ($v === null) {
            return ['ok' => false, 'msg' => 'La vista no existe.'];
        }
        $f = self::cleanFilters($filters);
        Db::run('UPDATE saved_views SET filters = ?, sort = ?, dir = ?, pipeline = ?, mode = ? WHERE id = ? AND user_id = ?', [json_encode($f), LeadTable::cleanSort($sort),
            $dir === 'asc' ? 'asc' : ($dir === 'desc' ? 'desc' : null), $pl === 'B' ? 'B' : ($pl === 'A' ? 'A' : null), self::cleanMode($mode), $id, $userId]);
        ActivityLog::record('view_update', $userId, 'view', $id, ['name' => $v['name']]);
        return ['ok' => true, 'msg' => 'Vista «' . $v['name'] . '» actualizada.'];
    }

    public static function rename(int $userId, int $id, mixed $name): array
    {
        $v = self::owned($userId, $id);
        $n = self::cleanName($name);
        if ($v === null) {
            return ['ok' => false, 'msg' => 'La vista no existe.'];
        }
        if ($n === '') {
            return ['ok' => false, 'msg' => 'Ponle un nombre a la vista (hasta 60 caracteres).'];
        }
        if (self::nameTaken($userId, $n, $id)) {
            return ['ok' => false, 'msg' => 'Ya tienes una vista con ese nombre.'];
        }
        Db::run('UPDATE saved_views SET name = ? WHERE id = ? AND user_id = ?', [$n, $id, $userId]);
        ActivityLog::record('view_rename', $userId, 'view', $id, ['from' => $v['name'], 'to' => $n]);
        return ['ok' => true, 'msg' => 'Vista renombrada.'];
    }

    public static function pin(int $userId, int $id, bool $pinned): array
    {
        if (self::owned($userId, $id) === null) {
            return ['ok' => false, 'msg' => 'La vista no existe.'];
        }
        $pos = $pinned ? (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM saved_views WHERE user_id = ? AND deleted_at IS NULL', [$userId]) : 0;
        Db::run('UPDATE saved_views SET pinned = ?, position = ? WHERE id = ? AND user_id = ?', [$pinned ? 1 : 0, $pos, $id, $userId]);
        return ['ok' => true, 'msg' => $pinned ? 'Vista fijada en la barra lateral.' : 'Vista quitada de la barra lateral.'];
    }

    public static function delete(int $userId, int $id): array
    {
        $v = self::owned($userId, $id);
        if ($v === null) {
            return ['ok' => false, 'msg' => 'La vista no existe.'];
        }
        Db::run('UPDATE saved_views SET deleted_at = ?, pinned = 0 WHERE id = ? AND user_id = ?', [Clock::now(), $id, $userId]);
        ActivityLog::record('view_delete', $userId, 'view', $id, ['name' => $v['name']]);
        return ['ok' => true, 'msg' => 'Vista «' . $v['name'] . '» eliminada.'];
    }

    private static function owned(int $userId, int $id): ?array
    {
        return Db::one('SELECT id, name FROM saved_views WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, $userId]);
    }

    private static function cleanName(mixed $name): string
    {
        $n = is_string($name) ? trim((string) preg_replace('/[^\P{C}]+/u', '', $name)) : '';
        return mb_strlen($n) >= 1 && mb_strlen($n) <= 60 ? $n : '';
    }

    private static function nameTaken(int $userId, string $name, ?int $exceptId): bool
    {
        return (int) Db::value('SELECT COUNT(*) FROM saved_views WHERE user_id = ? AND deleted_at IS NULL AND LOWER(name) = LOWER(?) AND id <> ?', [$userId, $name, $exceptId ?? 0]) > 0;
    }
}
