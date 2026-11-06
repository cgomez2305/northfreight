<?php
/**
 * Conjuntos de reglas versionados (Q5b): crear, guardar borrador, publicar (atomico), historial, diferencia entre versiones, revertir, retirar y borrar.
 *
 * Invariantes (los garantiza el esquema, 012_rules.sql, no solo este codigo): UN borrador y UNA version publicada por conjunto como maximo; los numeros de version no se repiten ni se reutilizan.
 * «Publicar» archiva la publicada anterior y activa el borrador en la misma transaccion. «Revertir» copia una version archivada como NUEVA version publicada: la historia nunca se borra.
 * Cada cambio de comportamiento (publicar, revertir, retirar, borrar) deja su registro en activity_log con la diferencia legible (anterior / nuevo), sin secretos.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class RuleSets
{
    /** @var list<array>|null */
    private static ?array $engineCache = null;

    public static function clearCache(): void
    {
        self::$engineCache = null;
    }

    private static function ok(string $msg, array $x = []): array
    {
        return ['ok' => true, 'msg' => $msg] + $x;
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }

    // --- Lectura ------------------------------------------------------------------------------------------------------------

    /** @return list<array> conjuntos con su version publicada y su borrador, agrupados por tipo */
    public static function all(): array
    {
        $rows = Db::all(
            'SELECT s.*, p.id AS pid, p.version AS pver, p.published_at, p.rule_count AS prc, p.rules AS prules, up.name AS pby, d.id AS did, d.version AS dver, d.updated_at AS dupd, d.rule_count AS drc,
                    (SELECT COUNT(*) FROM rule_versions v WHERE v.set_id = s.id) AS nver
             FROM rule_sets s
             LEFT JOIN rule_versions p ON p.set_id = s.id AND p.pub_slot = 1
             LEFT JOIN users up ON up.id = p.published_by
             LEFT JOIN rule_versions d ON d.set_id = s.id AND d.draft_slot = 1
             WHERE s.deleted_at IS NULL ORDER BY FIELD(s.kind, \'scoring\', \'alerts\', \'routing_tasks\'), s.position, s.id'
        );
        $out = [];
        foreach ($rows as $r) {
            $rules = Db::decode($r['prules']);
            $enabled = count(array_filter($rules, static fn($x) => !empty($x['enabled'])));
            $pub = $r['pid'] !== null;
            $dr = $r['did'] !== null;
            $out[] = [
                'id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'name' => (string) $r['name'], 'description' => (string) $r['description'],
                'published' => $pub ? ['id' => (int) $r['pid'], 'version' => (int) $r['pver'], 'at' => (string) $r['published_at'], 'by' => $r['pby'], 'count' => (int) $r['prc'], 'enabled' => $enabled,
                    'names' => array_map(static fn($x) => ['name' => (string) ($x['name'] ?? ''), 'enabled' => !empty($x['enabled'])], array_slice($rules, 0, 4))] : null,
                'draft' => $dr ? ['id' => (int) $r['did'], 'version' => (int) $r['dver'], 'at' => (string) $r['dupd'], 'count' => (int) $r['drc']] : null,
                'versions' => (int) $r['nver'],
                'status' => $pub ? ($dr ? 'published_draft' : 'published') : ($dr ? 'draft' : ((int) $r['nver'] > 0 ? 'retired' : 'empty')),
            ];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM rule_sets WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function draft(int $setId): ?array
    {
        return self::hydrate(Db::one('SELECT * FROM rule_versions WHERE set_id = ? AND draft_slot = 1', [$setId]));
    }

    public static function published(int $setId): ?array
    {
        return self::hydrate(Db::one('SELECT * FROM rule_versions WHERE set_id = ? AND pub_slot = 1', [$setId]));
    }

    public static function version(int $setId, int $versionId): ?array
    {
        return self::hydrate(Db::one('SELECT * FROM rule_versions WHERE set_id = ? AND id = ?', [$setId, $versionId]));
    }

    private static function hydrate(?array $v): ?array
    {
        if ($v === null) {
            return null;
        }
        $v['rules'] = Db::decode($v['rules']);
        return $v;
    }

    /** Reglas con las que se abre el editor: el borrador si hay; si no, la publicada; si no, nada. */
    public static function editable(int $setId): array
    {
        return (self::draft($setId) ?? self::published($setId))['rules'] ?? [];
    }

    /** @return list<array> historial, la mas nueva primero */
    public static function history(int $setId): array
    {
        $rows = Db::all(
            'SELECT v.id, v.version, v.status, v.rule_count, v.note, v.created_at, v.updated_at, v.published_at, v.reverted_from, uc.name AS created_by_name, up.name AS published_by_name
             FROM rule_versions v LEFT JOIN users uc ON uc.id = v.created_by LEFT JOIN users up ON up.id = v.published_by WHERE v.set_id = ? ORDER BY v.version DESC',
            [$setId]
        );
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'version' => (int) $r['version'], 'status' => (string) $r['status'], 'count' => (int) $r['rule_count'], 'note' => (string) ($r['note'] ?? ''),
            'at' => (string) ($r['published_at'] ?? $r['updated_at']), 'by' => $r['published_by_name'] ?? $r['created_by_name'], 'reverted_from' => $r['reverted_from'] !== null ? (int) $r['reverted_from'] : null], $rows);
    }

    // --- Conjuntos -----------------------------------------------------------------------------------------------------------

    public static function create(string $kind, mixed $name, mixed $desc, int $userId): array
    {
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($name) ? (string) $name : '') ?? ''), 0, 80);
        $desc = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($desc) ? (string) $desc : '') ?? ''), 0, 300);
        if (!isset(Rules::KINDS[$kind])) {
            return self::fail('Elige un tipo de conjunto.');
        }
        if ($name === '') {
            return self::fail('Ponle un nombre al conjunto.');
        }
        if (preg_match('/[<>]/', $name . $desc) === 1) {
            return self::fail('El nombre y la descripción no admiten los signos < y >.');
        }
        return Db::transaction(static function () use ($kind, $name, $desc, $userId): array {
            if ((int) Db::value('SELECT COUNT(*) FROM rule_sets WHERE deleted_at IS NULL FOR UPDATE') >= Rules::MAX_SETS) {
                return self::fail('Ya hay ' . Rules::MAX_SETS . ' conjuntos. Borra uno antes de crear otro.');
            }
            $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM rule_sets WHERE deleted_at IS NULL AND kind = ?', [$kind]);
            $id = Db::insert('rule_sets', ['kind' => $kind, 'name' => $name, 'description' => $desc, 'position' => $pos, 'created_by' => $userId, 'created_at' => Clock::now()]);
            ActivityLog::record('rules_set_create', $userId, 'rule_set', $id, ['kind' => $kind, 'name' => $name]);
            return self::ok('Conjunto creado. Añade reglas y guarda un borrador.', ['id' => $id]);
        });
    }

    public static function updateMeta(int $setId, mixed $name, mixed $desc, int $userId): array
    {
        $set = self::find($setId);
        if ($set === null) {
            return self::fail('El conjunto no existe.');
        }
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($name) ? (string) $name : '') ?? ''), 0, 80);
        $desc = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($desc) ? (string) $desc : '') ?? ''), 0, 300);
        if ($name === '') {
            return self::fail('Ponle un nombre al conjunto.');
        }
        if (preg_match('/[<>]/', $name . $desc) === 1) {
            return self::fail('El nombre y la descripción no admiten los signos < y >.');
        }
        if ($name === $set['name'] && $desc === $set['description']) {
            return self::ok('No había cambios.');
        }
        Db::update('rule_sets', $setId, ['name' => $name, 'description' => $desc]);
        ActivityLog::settingsChange($userId, 'rule_set:' . $setId, ['name' => $set['name'], 'description' => $set['description']], ['name' => $name, 'description' => $desc]);
        self::clearCache();
        return self::ok('Conjunto actualizado.');
    }

    // --- Borrador ----------------------------------------------------------------------------------------------------------------

    /** @param list<array> $rules reglas YA normalizadas por Rules::normalize */
    public static function saveDraft(int $setId, array $rules, ?string $note, int $userId): array
    {
        $note = $note === null ? null : (mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $note) ?? ''), 0, 200) ?: null);
        if ($note !== null && preg_match('/[<>]/', $note) === 1) {
            return self::fail('La nota no admite los signos < y >.');
        }
        return Db::transaction(static function () use ($setId, $rules, $note, $userId): array {
            $set = Db::one('SELECT id, name FROM rule_sets WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$setId]);
            if ($set === null) {
                return self::fail('El conjunto no existe.');
            }
            $before = (self::draft($setId) ?? self::published($setId))['rules'] ?? [];
            $d = Db::one('SELECT id, version FROM rule_versions WHERE set_id = ? AND draft_slot = 1 FOR UPDATE', [$setId]);
            if ($d !== null) {
                Db::update('rule_versions', (int) $d['id'], ['rules' => $rules, 'rule_count' => count($rules), 'note' => $note]);
                $vid = (int) $d['id'];
                $ver = (int) $d['version'];
            } else {
                $ver = (int) Db::value('SELECT COALESCE(MAX(version), 0) + 1 FROM rule_versions WHERE set_id = ?', [$setId]);
                $vid = Db::insert('rule_versions', ['set_id' => $setId, 'version' => $ver, 'status' => 'draft', 'rules' => $rules, 'rule_count' => count($rules), 'note' => $note, 'created_by' => $userId, 'created_at' => Clock::now(), 'draft_slot' => 1]);
            }
            $diff = self::diffLines($before, $rules);
            if ($diff) {
                ActivityLog::record('rules_draft_save', $userId, 'rule_set', $setId, ['version' => $ver, 'diff' => $diff]);
            }
            return self::ok('Borrador v' . $ver . ' guardado. Todavía no se aplica: publícalo cuando estés listo.', ['version' => $ver, 'version_id' => $vid]);
        });
    }

    public static function discardDraft(int $setId, int $userId): array
    {
        return Db::transaction(static function () use ($setId, $userId): array {
            $d = Db::one('SELECT id, version FROM rule_versions WHERE set_id = ? AND draft_slot = 1 FOR UPDATE', [$setId]);
            if ($d === null) {
                return self::fail('No hay un borrador que descartar.');
            }
            Db::run('DELETE FROM rule_versions WHERE id = ?', [(int) $d['id']]);
            ActivityLog::record('rules_draft_discard', $userId, 'rule_set', $setId, ['version' => (int) $d['version']]);
            return self::ok('Borrador descartado.');
        });
    }

    // --- Publicar, revertir, retirar ---------------------------------------------------------------------------------------------

    public static function publish(int $setId, int $userId, ?string $note = null): array
    {
        $r = Db::transaction(static function () use ($setId, $userId, $note): array {
            $set = Db::one('SELECT id, name, kind FROM rule_sets WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$setId]);
            if ($set === null) {
                return self::fail('El conjunto no existe.');
            }
            $d = Db::one('SELECT * FROM rule_versions WHERE set_id = ? AND draft_slot = 1 FOR UPDATE', [$setId]);
            if ($d === null) {
                return self::fail('No hay un borrador para publicar. Guarda un borrador primero.');
            }
            $rules = Db::decode($d['rules']);
            $v = Rules::normalize($rules, (string) $set['kind']);
            if (!$v['ok'] || !$rules) {
                return self::fail($rules ? 'El borrador tiene errores y no se puede publicar. Corrígelos y guarda de nuevo.' : 'El borrador no tiene reglas.');
            }
            $old = Db::one('SELECT id, version, rules FROM rule_versions WHERE set_id = ? AND pub_slot = 1 FOR UPDATE', [$setId]);
            if ($old !== null) {
                Db::run("UPDATE rule_versions SET status = 'archived', pub_slot = NULL WHERE id = ?", [(int) $old['id']]);
            }
            $n = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : ($d['note'] ?? null);
            Db::run("UPDATE rule_versions SET status = 'published', draft_slot = NULL, pub_slot = 1, published_at = ?, published_by = ?, note = ? WHERE id = ?", [Clock::now(), $userId, $n, (int) $d['id']]);
            $diff = self::diffLines($old !== null ? Db::decode($old['rules']) : [], $rules);
            ActivityLog::record('rules_publish', $userId, 'rule_set', $setId, ['set' => $set['name'], 'version' => (int) $d['version'], 'previous' => $old !== null ? (int) $old['version'] : null, 'diff' => $diff]);
            return self::ok('Versión ' . (int) $d['version'] . ' publicada: ya se aplica a los leads nuevos y a los que cambien.', ['version' => (int) $d['version']]);
        });
        self::clearCache();
        return $r;
    }

    public static function revert(int $setId, int $versionId, int $userId): array
    {
        $r = Db::transaction(static function () use ($setId, $versionId, $userId): array {
            $set = Db::one('SELECT id, name FROM rule_sets WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$setId]);
            if ($set === null) {
                return self::fail('El conjunto no existe.');
            }
            $t = Db::one('SELECT * FROM rule_versions WHERE id = ? AND set_id = ? FOR UPDATE', [$versionId, $setId]);
            if ($t === null || $t['status'] !== 'archived') {
                return self::fail('Solo se puede volver a una versión archivada.');
            }
            $old = Db::one('SELECT id, version, rules FROM rule_versions WHERE set_id = ? AND pub_slot = 1 FOR UPDATE', [$setId]);
            if ($old !== null) {
                Db::run("UPDATE rule_versions SET status = 'archived', pub_slot = NULL WHERE id = ?", [(int) $old['id']]);
            }
            $ver = (int) Db::value('SELECT COALESCE(MAX(version), 0) + 1 FROM rule_versions WHERE set_id = ?', [$setId]);
            $rules = Db::decode($t['rules']);
            $id = Db::insert('rule_versions', ['set_id' => $setId, 'version' => $ver, 'status' => 'published', 'rules' => $rules, 'rule_count' => count($rules), 'note' => 'Revertida a la versión ' . (int) $t['version'],
                'created_by' => $userId, 'created_at' => Clock::now(), 'published_by' => $userId, 'published_at' => Clock::now(), 'reverted_from' => (int) $t['version'], 'pub_slot' => 1]);
            ActivityLog::record('rules_revert', $userId, 'rule_set', $setId, ['set' => $set['name'], 'version' => $ver, 'from' => (int) $t['version'], 'previous' => $old !== null ? (int) $old['version'] : null,
                'diff' => self::diffLines($old !== null ? Db::decode($old['rules']) : [], $rules)]);
            return self::ok('Se volvió a las reglas de la versión ' . (int) $t['version'] . ' como versión nueva ' . $ver . '. La historia se conserva.', ['version' => $ver, 'version_id' => $id]);
        });
        self::clearCache();
        return $r;
    }

    public static function unpublish(int $setId, int $userId): array
    {
        $r = Db::transaction(static function () use ($setId, $userId): array {
            $set = Db::one('SELECT id, name FROM rule_sets WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$setId]);
            $old = Db::one('SELECT id, version FROM rule_versions WHERE set_id = ? AND pub_slot = 1 FOR UPDATE', [$setId]);
            if ($set === null || $old === null) {
                return self::fail('Este conjunto no tiene una versión publicada.');
            }
            Db::run("UPDATE rule_versions SET status = 'archived', pub_slot = NULL WHERE id = ?", [(int) $old['id']]);
            ActivityLog::record('rules_unpublish', $userId, 'rule_set', $setId, ['set' => $set['name'], 'version' => (int) $old['version']]);
            return self::ok('Conjunto retirado: ya no se aplica. La puntuación vuelve a la del documento.');
        });
        self::clearCache();
        return $r;
    }

    /** Retira TODAS las versiones publicadas: la puntuacion y las alertas vuelven a ser exactamente las del documento. @return array{ok:bool,msg:string,n:int} */
    public static function retireAll(int $userId): array
    {
        $n = Db::transaction(static function () use ($userId): int {
            $rows = Db::all('SELECT v.id, v.version, v.set_id FROM rule_versions v WHERE v.pub_slot = 1 FOR UPDATE');
            foreach ($rows as $v) {
                Db::run("UPDATE rule_versions SET status = 'archived', pub_slot = NULL WHERE id = ?", [(int) $v['id']]);
            }
            if ($rows) {
                ActivityLog::record('rules_reset_all', $userId, 'rule_set', 'all', ['retired' => array_map(static fn($v) => ['set' => (int) $v['set_id'], 'version' => (int) $v['version']], $rows)]);
            }
            return count($rows);
        });
        self::clearCache();
        return ['ok' => true, 'msg' => $n > 0 ? 'Se retiraron ' . $n . ' conjuntos publicados. La puntuación y las alertas vuelven a ser las del documento.' : 'No había reglas publicadas: ya se usa lo del documento.', 'n' => $n];
    }

    public static function deleteSet(int $setId, int $userId): array
    {
        $r = Db::transaction(static function () use ($setId, $userId): array {
            $set = Db::one('SELECT id, name FROM rule_sets WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$setId]);
            if ($set === null) {
                return self::fail('El conjunto no existe.');
            }
            Db::run("UPDATE rule_versions SET status = IF(status = 'published', 'archived', status), pub_slot = NULL, draft_slot = NULL WHERE set_id = ?", [$setId]);
            Db::update('rule_sets', $setId, ['deleted_at' => Clock::now()]);
            ActivityLog::record('rules_set_delete', $userId, 'rule_set', $setId, ['set' => $set['name']]);
            return self::ok('Conjunto borrado. Su historial se conserva en la base.');
        });
        self::clearCache();
        return $r;
    }

    // --- Lo que ve el motor ---------------------------------------------------------------------------------------------------

    /** @return list<array{set_id:int,version_id:int,name:string,kind:string,version:int,rules:list<array>}> versiones PUBLICADAS en orden de aplicacion */
    public static function engineSets(): array
    {
        if (self::$engineCache !== null) {
            return self::$engineCache;
        }
        $rows = Db::all(
            "SELECT s.id AS set_id, s.name, s.kind, v.id AS version_id, v.version, v.rules FROM rule_sets s JOIN rule_versions v ON v.set_id = s.id AND v.pub_slot = 1
             WHERE s.deleted_at IS NULL ORDER BY FIELD(s.kind, 'scoring', 'alerts', 'routing_tasks'), s.position, s.id"
        );
        return self::$engineCache = array_map(static fn($r) => ['set_id' => (int) $r['set_id'], 'version_id' => (int) $r['version_id'], 'name' => (string) $r['name'], 'kind' => (string) $r['kind'], 'version' => (int) $r['version'], 'rules' => Db::decode($r['rules'])], $rows);
    }

    // --- Diferencias -------------------------------------------------------------------------------------------------------------

    /**
     * Diferencia legible entre dos listas de reglas, por id de regla.
     * @return list<array{type:string,id:string,name:string,changes:list<array{field:string,from:string,to:string}>}>
     */
    public static function diff(array $old, array $new): array
    {
        $oi = [];
        foreach ($old as $i => $r) {
            $oi[(string) $r['id']] = ['i' => $i, 'r' => $r];
        }
        $ni = [];
        foreach ($new as $i => $r) {
            $ni[(string) $r['id']] = ['i' => $i, 'r' => $r];
        }
        $out = [];
        foreach ($new as $i => $r) {
            $id = (string) $r['id'];
            if (!isset($oi[$id])) {
                $out[] = ['type' => 'added', 'id' => $id, 'name' => (string) $r['name'], 'changes' => [['field' => 'Regla', 'from' => '', 'to' => RulePhrase::rule($r)]]];
                continue;
            }
            $o = $oi[$id]['r'];
            $ch = [];
            $cmp = static function (string $field, string $a, string $b) use (&$ch): void {
                if ($a !== $b) {
                    $ch[] = ['field' => $field, 'from' => $a, 'to' => $b];
                }
            };
            $cmp('Nombre', (string) $o['name'], (string) $r['name']);
            $cmp('Estado', !empty($o['enabled']) ? 'activada' : 'desactivada', !empty($r['enabled']) ? 'activada' : 'desactivada');
            $cmp('Detener al coincidir', !empty($o['stop']) ? 'sí' : 'no', !empty($r['stop']) ? 'sí' : 'no');
            $cmp('CUANDO', RulePhrase::block($o['when'] ?? null), RulePhrase::block($r['when'] ?? null));
            $cmp('EXCEPTO', RulePhrase::block($o['except'] ?? null), RulePhrase::block($r['except'] ?? null));
            $cmp('ENTONCES', implode(' y ', array_map([RulePhrase::class, 'action'], (array) $o['then'])), implode(' y ', array_map([RulePhrase::class, 'action'], (array) $r['then'])));
            if ($ch) {
                $out[] = ['type' => 'changed', 'id' => $id, 'name' => (string) $r['name'], 'changes' => $ch];
            } elseif ($oi[$id]['i'] !== $i) {
                $out[] = ['type' => 'moved', 'id' => $id, 'name' => (string) $r['name'], 'changes' => [['field' => 'Prioridad', 'from' => (string) ($oi[$id]['i'] + 1), 'to' => (string) ($i + 1)]]];
            }
        }
        foreach ($old as $r) {
            if (!isset($ni[(string) $r['id']])) {
                $out[] = ['type' => 'removed', 'id' => (string) $r['id'], 'name' => (string) $r['name'], 'changes' => [['field' => 'Regla', 'from' => RulePhrase::rule($r), 'to' => '']]];
            }
        }
        return $out;
    }

    /** La diferencia como lineas cortas para el registro de actividad (anterior / nuevo). @return list<string> */
    public static function diffLines(array $old, array $new): array
    {
        $lines = [];
        $label = ['added' => 'Añadida', 'removed' => 'Quitada', 'changed' => 'Cambió', 'moved' => 'Movida'];
        foreach (self::diff($old, $new) as $d) {
            $det = [];
            foreach ($d['changes'] as $c) {
                $det[] = $d['type'] === 'added' ? $c['to'] : ($d['type'] === 'removed' ? $c['from'] : $c['field'] . ': «' . $c['from'] . '» → «' . $c['to'] . '»');
            }
            $lines[] = mb_substr($label[$d['type']] . ' «' . $d['name'] . '»: ' . implode(' | ', $det), 0, 420);
        }
        return array_slice($lines, 0, 40);
    }
}
