<?php
/**
 * Vista previa de impacto y simulacion de reglas (Q5b). NO modifica nada del negocio: ni leads, ni eventos, ni tareas, ni consentimientos, ni encola alertas. Solo lee leads y escribe en sus
 * propias tablas (rule_simulations y rule_simulation_rows). Usa el motor PURO (RuleEngine) con la hora inyectada, asi que un mismo conjunto de datos da siempre el mismo resultado.
 *
 * «Antes» = lo que dan HOY las reglas publicadas (sin el conjunto que se edita si no esta publicado, o con su version publicada); «despues» = lo mismo con el borrador en su lugar. Asi el impacto mide
 * solo el efecto de LA EDICION. Alcance: ultimos N leads, ultimos N dias o un rango de fechas ET (maximo 400 dias).
 *
 * Vista previa: hasta PREVIEW_ROWS leads y PREVIEW_SECONDS segundos (si no alcanza, lo dice: partial). Simulacion: hasta SIM_MAX leads; si es grande corre por la cola en lotes de BATCH.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class RuleSim
{
    public const PREVIEW_ROWS = 1500;
    public const PREVIEW_SECONDS = 3.0;
    public const SIM_MAX = 20000;
    public const BATCH = 250;
    public const INLINE_MAX = 400;
    public const SAMPLES = 10;
    public const ROWS_MAX = 5000;
    public const LAST_CHOICES = [100, 500, 1000, 2000, 5000, 10000, 20000];
    public const DAY_CHOICES = [7, 30, 90, 180, 365];

    // --- Alcance -------------------------------------------------------------------------------------------------------------

    /**
     * @return array{ok:bool,scope:array,label:string,error:string}
     */
    public static function scope(mixed $in, bool $forSim = false): array
    {
        $in = is_array($in) ? $in : [];
        $max = $forSim ? self::SIM_MAX : self::PREVIEW_ROWS;
        $mode = (string) ($in['mode'] ?? 'last');
        if ($mode === 'days') {
            $d = (int) ($in['days'] ?? 30);
            $d = in_array($d, self::DAY_CHOICES, true) ? $d : 30;
            return ['ok' => true, 'scope' => ['mode' => 'days', 'days' => $d], 'label' => 'Últimos ' . $d . ' días', 'error' => ''];
        }
        if ($mode === 'range') {
            $from = is_string($in['from'] ?? null) ? trim($in['from']) : '';
            $to = is_string($in['to'] ?? null) ? trim($in['to']) : '';
            $f = Fmt::fromEtInput($from);
            $t = Fmt::fromEtInput($to, true);
            if ($f === null || $t === null) {
                return ['ok' => false, 'scope' => [], 'label' => '', 'error' => 'Escribe las dos fechas del rango (AAAA-MM-DD).'];
            }
            if (Clock::parse($f) > Clock::parse($t)) {
                return ['ok' => false, 'scope' => [], 'label' => '', 'error' => 'La fecha inicial no puede ser posterior a la final.'];
            }
            if ((Clock::parse($t) - Clock::parse($f)) > 400 * 86400) {
                return ['ok' => false, 'scope' => [], 'label' => '', 'error' => 'El rango puede ser de 400 días como máximo.'];
            }
            return ['ok' => true, 'scope' => ['mode' => 'range', 'from' => $from, 'to' => $to], 'label' => 'Del ' . $from . ' al ' . $to, 'error' => ''];
        }
        $n = (int) ($in['n'] ?? 500);
        $n = max(10, min($max, $n));
        return ['ok' => true, 'scope' => ['mode' => 'last', 'n' => $n], 'label' => 'Últimos ' . $n . ' leads', 'error' => ''];
    }

    /** Condicion SQL del alcance (sin el limite de «ultimos N»). @return array{0:string,1:list<mixed>} */
    private static function where(array $scope): array
    {
        $w = 'deleted_at IS NULL';
        $p = [];
        if ($scope['mode'] === 'days') {
            $w .= ' AND created_at >= ?';
            $p[] = Clock::at(Clock::time() - (int) $scope['days'] * 86400);
        } elseif ($scope['mode'] === 'range') {
            $w .= ' AND created_at >= ? AND created_at <= ?';
            $p[] = Fmt::fromEtInput((string) $scope['from']);
            $p[] = Fmt::fromEtInput((string) $scope['to'], true);
        }
        return [$w, $p];
    }

    /** Id minimo del alcance «ultimos N» (el N-esimo mas reciente). */
    private static function minId(int $n): int
    {
        return (int) Db::value('SELECT id FROM leads WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1 OFFSET ' . max(0, $n - 1)) ?: 0;
    }

    public static function count(array $scope): int
    {
        [$w, $p] = self::where($scope);
        if ($scope['mode'] === 'last') {
            return min((int) $scope['n'], (int) Db::value('SELECT COUNT(*) FROM leads WHERE ' . $w));
        }
        return (int) Db::value('SELECT COUNT(*) FROM leads WHERE ' . $w, $p);
    }

    // --- Conjuntos antes / despues -----------------------------------------------------------------------------------------------

    /**
     * @param array{set_id:int,name:string,kind:string,rules:list<array>} $target el conjunto editado con las reglas del borrador
     * @return array{0:list<array>,1:list<array>} [publicados tal cual (sin el borrador), publicados con el borrador en lugar de la version del conjunto]
     */
    public static function sets(array $target): array
    {
        $before = [];
        $after = [];
        $placed = false;
        foreach (RuleSets::engineSets() as $s) {
            $before[] = $s;
            if ($s['set_id'] === $target['set_id']) {
                $after[] = ['rules' => $target['rules'], 'name' => $target['name'], 'kind' => $target['kind'], 'version_id' => 0] + $s;
                $placed = true;
            } else {
                $after[] = $s;
            }
        }
        if (!$placed) {
            $after[] = ['set_id' => $target['set_id'], 'version_id' => 0, 'name' => $target['name'], 'kind' => $target['kind'], 'version' => 0, 'rules' => $target['rules']];
        }
        return [$before, $after];
    }

    /** @return array{before:array,after:array,mine:list<string>,fx:list<string>,changed:bool,matched:bool,alerts:int,tasks:int,tags:int,review:int} */
    private static function evalLead(array $lead, array $facts, array $before, array $after, array $settings, int $targetId): array
    {
        $base = Scoring::score($lead, $settings);
        $b = RuleRunner::combine($lead, $base, $before, $facts, $settings);
        $a = RuleRunner::combine($lead, $base, $after, $facts, $settings);
        $mine = [];
        $fx = ['alerts' => 0, 'tasks' => 0, 'tags' => 0, 'review' => 0];
        foreach (($a['effects']['applied'] ?? []) as $ap) {
            if ($ap['set_id'] !== $targetId) {
                continue;
            }
            $mine[] = (string) $ap['name'];
            foreach ($ap['effects'] as $e) {
                $fx['alerts'] += $e['type'] === 'telegram_alert' ? 1 : 0;
                $fx['tasks'] += $e['type'] === 'create_task' ? 1 : 0;
                $fx['tags'] += $e['type'] === 'tag' ? 1 : 0;
                $fx['review'] += $e['type'] === 'review_queue' ? 1 : 0;
            }
        }
        return [
            'before' => ['score' => (int) $b['score'], 'temperature' => (string) $b['temperature']], 'after' => ['score' => (int) $a['score'], 'temperature' => (string) $a['temperature']],
            'mine' => $mine, 'changed' => $b['score'] !== $a['score'] || $b['temperature'] !== $a['temperature'], 'matched' => $mine !== [],
        ] + $fx;
    }

    private static function blank(): array
    {
        $z = ['hot' => 0, 'warm' => 0, 'cold' => 0, 'disqualified' => 0];
        return ['scanned' => 0, 'before' => $z, 'after' => $z, 'changed' => 0, 'to_hot' => 0, 'from_hot' => 0, 'matched' => 0, 'alerts' => 0, 'tasks' => 0, 'tags' => 0, 'review' => 0, 'score_delta' => 0];
    }

    private static function add(array &$sum, array $r): void
    {
        $sum['scanned']++;
        $sum['before'][$r['before']['temperature']] = ($sum['before'][$r['before']['temperature']] ?? 0) + 1;
        $sum['after'][$r['after']['temperature']] = ($sum['after'][$r['after']['temperature']] ?? 0) + 1;
        $sum['changed'] += $r['changed'] ? 1 : 0;
        $sum['to_hot'] += $r['after']['temperature'] === 'hot' && $r['before']['temperature'] !== 'hot' ? 1 : 0;
        $sum['from_hot'] += $r['before']['temperature'] === 'hot' && $r['after']['temperature'] !== 'hot' ? 1 : 0;
        $sum['matched'] += $r['matched'] ? 1 : 0;
        $sum['alerts'] += $r['alerts'] > 0 ? 1 : 0;
        $sum['tasks'] += $r['tasks'] > 0 ? 1 : 0;
        $sum['tags'] += $r['tags'] > 0 ? 1 : 0;
        $sum['review'] += $r['review'] > 0 ? 1 : 0;
        $sum['score_delta'] += $r['after']['score'] - $r['before']['score'];
    }

    private static function sample(array $lead, array $r): array
    {
        $fx = [];
        foreach (['alerts' => 'alerta', 'tasks' => 'tarea', 'tags' => 'etiqueta', 'review' => 'cola de revisión'] as $k => $l) {
            if ($r[$k] > 0) {
                $fx[] = $l;
            }
        }
        return ['id' => (int) $lead['id'], 'code' => Fmt::code((int) $lead['id']), 'name' => Fmt::name($lead), 'profile' => (string) $lead['profile'], 'state' => (string) ($lead['state'] ?? ''),
            'before' => $r['before'], 'after' => $r['after'], 'rules' => array_slice($r['mine'], 0, 4), 'fx' => $fx];
    }

    // --- Vista previa -------------------------------------------------------------------------------------------------------------

    /**
     * Impacto del conjunto editado sobre los leads del alcance, sin guardar nada.
     * @param array{set_id:int,name:string,kind:string,rules:list<array>} $target
     * @return array{summary:array,samples:list<array>,partial:bool,seconds:float,scope:string}
     */
    public static function preview(array $target, array $scope, ?float $maxSeconds = null): array
    {
        $t0 = microtime(true);
        $maxSeconds ??= self::PREVIEW_SECONDS;
        [$before, $after] = self::sets($target);
        $settings = Scoring::settings();
        $needConsent = RuleEngine::usesField($before, 'consent') || RuleEngine::usesField($after, 'consent');
        $now = Clock::time();
        [$w, $p] = self::where($scope);
        $sum = self::blank();
        $samples = [];
        $extra = [];
        $partial = false;
        $limit = $scope['mode'] === 'last' ? min((int) $scope['n'], self::PREVIEW_ROWS) : self::PREVIEW_ROWS;
        $cursor = PHP_INT_MAX;
        while ($sum['scanned'] < $limit) {
            $rows = Db::all('SELECT * FROM leads WHERE ' . $w . ' AND id < ? ORDER BY id DESC LIMIT ' . min(500, $limit - $sum['scanned']), [...$p, $cursor]);
            if (!$rows) {
                break;
            }
            $leads = array_map([Lead::class, 'hydrate'], $rows);
            $cons = $needConsent ? RuleRunner::consentMap($leads) : [];
            foreach ($leads as $lead) {
                $cursor = (int) $lead['id'];
                $facts = RuleRunner::facts($lead, $now, $cons[$cursor] ?? []);
                $r = self::evalLead($lead, $facts, $before, $after, $settings, $target['set_id']);
                self::add($sum, $r);
                if (($r['changed'] || $r['matched'])) {
                    $s = self::sample($lead, $r);
                    if ($r['changed'] && count($samples) < self::SAMPLES) {
                        $samples[] = $s;
                    } elseif (count($extra) < self::SAMPLES) {
                        $extra[] = $s;
                    }
                }
            }
            if (microtime(true) - $t0 > $maxSeconds) {
                $partial = $sum['scanned'] < $limit;
                break;
            }
        }
        $samples = array_slice([...$samples, ...$extra], 0, self::SAMPLES);
        return ['summary' => $sum, 'samples' => $samples, 'partial' => $partial, 'seconds' => round(microtime(true) - $t0, 3), 'scope' => ''];
    }

    // --- Simulacion ----------------------------------------------------------------------------------------------------------------

    /** @return array{ok:bool,msg:string,id?:int} */
    public static function start(int $setId, ?int $versionId, array $rules, array $scope, int $userId): array
    {
        $set = RuleSets::find($setId);
        if ($set === null) {
            return ['ok' => false, 'msg' => 'El conjunto no existe.'];
        }
        $running = (int) Db::value("SELECT COUNT(*) FROM rule_simulations WHERE set_id = ? AND status = 'running' AND created_at > ?", [$setId, Clock::at(Clock::time() - 1800)]);
        if ($running > 0) {
            return ['ok' => false, 'msg' => 'Ya hay una simulación en marcha para este conjunto. Espera a que termine.'];
        }
        $total = self::count($scope);
        if ($total < 1) {
            return ['ok' => false, 'msg' => 'No hay leads en ese alcance para simular.'];
        }
        $id = Db::insert('rule_simulations', ['set_id' => $setId, 'version_id' => $versionId, 'user_id' => $userId, 'params' => $scope, 'rules' => $rules, 'status' => 'running', 'total' => $total,
            'summary' => self::blank(), 'created_at' => Clock::now()]);
        ActivityLog::record('rules_sim_start', $userId, 'rule_set', $setId, ['simulation' => $id, 'scope' => $scope, 'total' => $total]);
        if ($total <= self::INLINE_MAX) {
            self::step($id, 20.0);
        } else {
            Queue::push('rule_simulate', ['sim' => $id], Clock::now(), 'rule_sim:' . $id . ':0');
        }
        return ['ok' => true, 'msg' => $total <= self::INLINE_MAX ? 'Simulación terminada.' : 'Simulación en marcha: ' . number_format($total, 0, ',', '.') . ' leads por la cola, ' . self::BATCH . ' por lote.', 'id' => $id];
    }

    /** Procesa lotes hasta terminar o agotar $maxSeconds. @return array|null estado de la simulacion */
    public static function step(int $simId, float $maxSeconds = 40.0): ?array
    {
        $t0 = microtime(true);
        if (!Db::lock('rule_sim_' . $simId, 0)) {
            return self::status($simId);
        }
        try {
            $sim = Db::one('SELECT * FROM rule_simulations WHERE id = ?', [$simId]);
            if ($sim === null || $sim['status'] !== 'running') {
                return self::status($simId);
            }
            $set = Db::one('SELECT id, name, kind FROM rule_sets WHERE id = ?', [(int) $sim['set_id']]);
            if ($set === null) {
                Db::update('rule_simulations', $simId, ['status' => 'failed', 'error' => 'El conjunto ya no existe.', 'finished_at' => Clock::now()]);
                return self::status($simId);
            }
            $scope = Db::decode($sim['params']);
            $target = ['set_id' => (int) $set['id'], 'name' => (string) $set['name'], 'kind' => (string) $set['kind'], 'rules' => Db::decode($sim['rules'])];
            [$before, $after] = self::sets($target);
            $settings = Scoring::settings();
            $needConsent = RuleEngine::usesField($before, 'consent') || RuleEngine::usesField($after, 'consent');
            $now = Clock::time();
            [$w, $p] = self::where($scope);
            if ($scope['mode'] === 'last') {
                $w .= ' AND id >= ?';
                $p[] = self::minId((int) $scope['n']);
            }
            $sum = array_replace(self::blank(), Db::decode($sim['summary']));
            $last = (int) $sim['last_id'];
            $stored = (int) ($sum['rows'] ?? 0);
            $done = false;
            while (true) {
                $rows = Db::all('SELECT * FROM leads WHERE ' . $w . ' AND id > ? ORDER BY id LIMIT ' . self::BATCH, [...$p, $last]);
                if (!$rows) {
                    $done = true;
                    break;
                }
                $leads = array_map([Lead::class, 'hydrate'], $rows);
                $cons = $needConsent ? RuleRunner::consentMap($leads) : [];
                $ins = [];
                foreach ($leads as $lead) {
                    $last = (int) $lead['id'];
                    $r = self::evalLead($lead, RuleRunner::facts($lead, $now, $cons[$last] ?? []), $before, $after, $settings, $target['set_id']);
                    self::add($sum, $r);
                    if (($r['changed'] || $r['matched']) && $stored < self::ROWS_MAX) {
                        $ins[] = [$simId, $last, $r['before']['score'], $r['before']['temperature'], $r['after']['score'], $r['after']['temperature'], json_encode($r['mine'], JSON_UNESCAPED_UNICODE), min(255, $r['alerts']), min(255, $r['tasks']), min(255, $r['tags']), min(255, $r['review'])];
                        $stored++;
                    } elseif (($r['changed'] || $r['matched'])) {
                        $sum['rows_truncated'] = true;
                    }
                }
                foreach (array_chunk($ins, 200) as $chunk) {
                    Db::run('INSERT IGNORE INTO rule_simulation_rows (sim_id, lead_id, before_score, before_temp, after_score, after_temp, rules, alerts, tasks, tags, review) VALUES '
                        . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?)')), array_merge(...$chunk));
                }
                $sum['rows'] = $stored;
                Db::update('rule_simulations', $simId, ['processed' => $sum['scanned'], 'last_id' => $last, 'summary' => $sum]);
                if (count($rows) < self::BATCH) {
                    $done = true;
                    break;
                }
                if (microtime(true) - $t0 >= $maxSeconds) {
                    break;
                }
            }
            if ($done) {
                $sum['seconds'] = round(microtime(true) - $t0, 2);
                Db::update('rule_simulations', $simId, ['status' => 'done', 'processed' => $sum['scanned'], 'summary' => $sum, 'finished_at' => Clock::now()]);
            }
        } catch (\Throwable $t) {
            Db::update('rule_simulations', $simId, ['status' => 'failed', 'error' => mb_substr(Storage::redact($t->getMessage()), 0, 190), 'finished_at' => Clock::now()]);
            Storage::error('rule simulation failed', '#' . $simId . ' ' . get_class($t) . ': ' . $t->getMessage());
        } finally {
            Db::unlock('rule_sim_' . $simId);
        }
        return self::status($simId);
    }

    /** Handler del job rule_simulate: sigue donde se quedo y, si falta, vuelve a encolarse. */
    public static function jobStep(array $p): void
    {
        $id = (int) ($p['sim'] ?? 0);
        $st = self::step($id, 40.0);
        if ($st !== null && $st['status'] === 'running') {
            Queue::push('rule_simulate', ['sim' => $id], Clock::now(), 'rule_sim:' . $id . ':' . $st['last_id']);
        }
    }

    /** @return array{id:int,set_id:int,version_id:?int,status:string,total:int,processed:int,percent:int,last_id:int,summary:array,samples:list<array>,error:string,created_at:string,finished_at:?string,scope:array}|null */
    public static function status(int $simId, bool $withSamples = true): ?array
    {
        $s = Db::one('SELECT id, set_id, version_id, status, total, processed, last_id, summary, error, created_at, finished_at, params FROM rule_simulations WHERE id = ?', [$simId]);
        if ($s === null) {
            return null;
        }
        $samples = [];
        if ($withSamples && $s['status'] === 'done') {
            $rows = Db::all(
                'SELECT r.*, l.first_name, l.last_name, l.profile, l.state FROM rule_simulation_rows r JOIN leads l ON l.id = r.lead_id WHERE r.sim_id = ?
                 ORDER BY (r.after_score <> r.before_score OR r.after_temp <> r.before_temp) DESC, r.lead_id DESC LIMIT ' . self::SAMPLES,
                [$simId]
            );
            foreach ($rows as $r) {
                $fx = [];
                foreach (['alerts' => 'alerta', 'tasks' => 'tarea', 'tags' => 'etiqueta', 'review' => 'cola de revisión'] as $k => $l) {
                    if ((int) $r[$k] > 0) {
                        $fx[] = $l;
                    }
                }
                $samples[] = ['id' => (int) $r['lead_id'], 'code' => Fmt::code((int) $r['lead_id']), 'name' => Fmt::name($r), 'profile' => (string) $r['profile'], 'state' => (string) ($r['state'] ?? ''),
                    'before' => ['score' => (int) $r['before_score'], 'temperature' => (string) $r['before_temp']], 'after' => ['score' => (int) $r['after_score'], 'temperature' => (string) $r['after_temp']],
                    'rules' => array_slice(Db::decode($r['rules']), 0, 4), 'fx' => $fx];
            }
        }
        $total = (int) $s['total'];
        return ['id' => (int) $s['id'], 'set_id' => (int) $s['set_id'], 'version_id' => $s['version_id'] !== null ? (int) $s['version_id'] : null, 'status' => (string) $s['status'], 'total' => $total,
            'processed' => (int) $s['processed'], 'percent' => $s['status'] === 'done' ? 100 : ($total > 0 ? (int) min(99, floor((int) $s['processed'] / $total * 100)) : 0), 'last_id' => (int) $s['last_id'],
            'summary' => array_replace(self::blank(), Db::decode($s['summary'])), 'samples' => $samples, 'error' => (string) ($s['error'] ?? ''), 'created_at' => (string) $s['created_at'],
            'finished_at' => $s['finished_at'] !== null ? (string) $s['finished_at'] : null, 'scope' => Db::decode($s['params'])];
    }

    /** Ultima simulacion de un conjunto (la mas reciente) o null. */
    public static function latest(int $setId): ?array
    {
        $id = Db::value('SELECT id FROM rule_simulations WHERE set_id = ? ORDER BY id DESC LIMIT 1', [$setId]);
        return $id === null ? null : self::status((int) $id);
    }

    /** CSV de una simulacion terminada: sin nombre, telefono ni correo (solo codigo corto y datos de segmentacion), con proteccion contra formulas. */
    public static function csv(int $simId): ?string
    {
        $s = Db::one("SELECT id FROM rule_simulations WHERE id = ? AND status = 'done'", [$simId]);
        if ($s === null) {
            return null;
        }
        $out = "\xEF\xBB\xBF" . Csv::line(['Código', 'Perfil', 'Estado', 'Fuente', 'Puntuación antes', 'Temperatura antes', 'Puntuación después', 'Temperatura después', 'Reglas que actuaron', 'Alertas', 'Tareas', 'Etiquetas', 'Cola de revisión']);
        $last = 0;
        while (true) {
            $rows = Db::all('SELECT r.*, l.profile, l.state, l.source FROM rule_simulation_rows r JOIN leads l ON l.id = r.lead_id WHERE r.sim_id = ? AND r.lead_id > ? ORDER BY r.lead_id LIMIT 1000', [$simId, $last]);
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $last = (int) $r['lead_id'];
                $out .= Csv::line([Fmt::code($last), Catalog::label(Catalog::PROFILES, (string) $r['profile']), $r['state'], Catalog::source((string) $r['source']), $r['before_score'], Catalog::label(Catalog::TEMPS, (string) $r['before_temp']),
                    $r['after_score'], Catalog::label(Catalog::TEMPS, (string) $r['after_temp']), Db::decode($r['rules']), $r['alerts'], $r['tasks'], $r['tags'], $r['review']]);
            }
        }
        return $out;
    }
}
