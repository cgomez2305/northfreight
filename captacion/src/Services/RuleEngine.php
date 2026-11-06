<?php
/**
 * Motor de reglas (Q5b): evaluacion PURA y determinista. No lee la base, no usa la red ni la hora (el reloj y los datos del lead llegan ya resueltos en $facts), no escribe nada y no
 * envia nada: devuelve QUE habria que hacer y QUIEN lo pidio. Por eso sirve igual para el motor real, la vista previa y la simulacion.
 *
 * Orden: primero los conjuntos de puntuacion (cada uno en su prioridad), luego alertas y luego tareas/etiquetas, que ya ven la puntuacion y la temperatura finales.
 * Dentro de un conjunto las reglas se evaluan de arriba abajo, una sola pasada; «detener al coincidir» corta el resto de ESE conjunto.
 * Datos ausentes: toda comparacion es falsa salvo las negaciones (no es, no esta en, no contiene, vacio), que son verdaderas.
 * Puntos: cada regla suma entre -30 y 30 (con tope opcional); el ajuste TOTAL de todas las reglas se limita a ±40 y la puntuacion final a 0..100. Un lead descartado (CDL no domiciliada,
 * sin ingles) no cambia de puntuacion ni de temperatura por reglas.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class RuleEngine
{
    private const KIND_ORDER = ['scoring' => 0, 'alerts' => 1, 'routing_tasks' => 2];
    private const RANK = ['cold' => 0, 'warm' => 1, 'hot' => 2];

    /** @return list<string> */
    private static function arr(mixed $x): array
    {
        return is_array($x) ? array_values(array_map('strval', $x)) : [];
    }

    public static function cond(array $c, array $f): bool
    {
        $field = (string) ($c['field'] ?? '');
        $op = (string) ($c['op'] ?? '');
        $type = Rules::fieldType($field);
        if ($type === null) {
            return false;
        }
        $x = $f[$field] ?? null;
        $v = $c['value'] ?? null;
        switch ($type) {
            case 'bool':
                return (bool) $x === (bool) $v;
            case 'multi':
                $have = self::arr($x);
                $want = self::arr($v);
                return match ($op) {
                    'in'         => (bool) array_intersect($have, $want),
                    'not_in'     => !array_intersect($have, $want),
                    'all'        => $want !== [] && !array_diff($want, $have),
                    'exists'     => $have !== [],
                    'not_exists' => $have === [],
                    default      => false,
                };
            case 'number':
                if ($op === 'not_exists') {
                    return $x === null;
                }
                if ($op === 'exists') {
                    return $x !== null;
                }
                if ($x === null) {
                    return $op === 'is_not';
                }
                $n = (float) $x;
                return match ($op) {
                    'is'      => abs($n - (float) $v) < 0.0001,
                    'is_not'  => abs($n - (float) $v) >= 0.0001,
                    'gt'      => $n > (float) $v,
                    'gte'     => $n >= (float) $v,
                    'lt'      => $n < (float) $v,
                    'lte'     => $n <= (float) $v,
                    'between' => is_array($v) && isset($v[0], $v[1]) && $n >= (float) $v[0] && $n <= (float) $v[1],
                    default   => false,
                };
            case 'text':
                $s = $x === null ? '' : mb_strtolower((string) $x);
                return match ($op) {
                    'exists'       => $s !== '',
                    'not_exists'   => $s === '',
                    'is'           => $s !== '' && $s === (string) $v,
                    'is_not'       => $s !== (string) $v,
                    'in'           => $s !== '' && in_array($s, self::arr($v), true),
                    'contains'     => $s !== '' && str_contains($s, (string) $v),
                    'not_contains' => $s === '' || !str_contains($s, (string) $v),
                    default        => false,
                };
            default:   // enum
                $s = $x === null ? '' : (string) $x;
                return match ($op) {
                    'exists'     => $s !== '',
                    'not_exists' => $s === '',
                    'is'         => $s !== '' && $s === (string) $v,
                    'is_not'     => $s !== (string) $v,
                    'in'         => $s !== '' && in_array($s, self::arr($v), true),
                    'not_in'     => !in_array($s, self::arr($v), true),
                    default      => false,
                };
        }
    }

    /** Un bloque CUANDO o EXCEPTO: condiciones y UN nivel de grupos, unidos con Y (all) u O (any). Un bloque vacio no coincide. */
    public static function block(?array $b, array $f): bool
    {
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        if (!$items) {
            return false;
        }
        $any = ($b['mode'] ?? 'all') === 'any';
        foreach ($items as $it) {
            $r = isset($it['items']) ? self::block($it, $f) : self::cond((array) $it, $f);
            if ($any && $r) {
                return true;
            }
            if (!$any && !$r) {
                return false;
            }
        }
        return !$any;
    }

    /** @return 'match'|'no_match'|'excepted' */
    public static function matches(array $rule, array $f): string
    {
        if (!self::block($rule['when'] ?? null, $f)) {
            return 'no_match';
        }
        if (!empty($rule['except']) && self::block($rule['except'], $f)) {
            return 'excepted';
        }
        return 'match';
    }

    /** ¿Alguna regla de estos conjuntos mira el consentimiento? (para no consultarlo si nadie lo usa). */
    public static function usesField(array $sets, string $field): bool
    {
        $scan = static function (?array $b) use (&$scan, $field): bool {
            foreach ((array) ($b['items'] ?? []) as $it) {
                if (isset($it['items']) ? $scan($it) : (($it['field'] ?? '') === $field)) {
                    return true;
                }
            }
            return false;
        };
        foreach ($sets as $s) {
            foreach ((array) $s['rules'] as $r) {
                if (!empty($r['enabled']) && ($scan($r['when'] ?? null) || $scan($r['except'] ?? null))) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param list<array{set_id:int,version_id:int,name:string,kind:string,rules:list<array>}> $sets
     * @param array $facts datos del lead (ver Rules::FIELDS); score y temperature NO van aqui: son el estado en marcha
     * @param array{score:int,temperature:string} $base resultado de Scoring::score
     * @param array{hot_min:int,warm_min:int} $thr umbrales de temperatura
     * @return array{score:int,temperature:string,adjust:int,applied:list<array>,alerts:list<array>,tasks:list<array>,tags:list<string>,review:bool,trace:list<array>}
     */
    public static function run(array $sets, array $facts, array $base, array $thr): array
    {
        usort($sets, static fn($a, $b) => (self::KIND_ORDER[$a['kind']] ?? 9) <=> (self::KIND_ORDER[$b['kind']] ?? 9));   // estable desde PHP 8: conserva el orden de posicion dentro de cada tipo
        $dq = ($base['temperature'] ?? '') === 'disqualified';
        $score = (int) $base['score'];
        $adjust = 0;
        $floor = -1;
        $hot = (int) ($thr['hot_min'] ?? 70);
        $warm = (int) ($thr['warm_min'] ?? 40);
        $tempOf = static function (int $s) use ($hot, $warm, &$floor, $dq): string {
            if ($dq) {
                return 'disqualified';
            }
            $t = $s >= $hot ? 'hot' : ($s >= $warm ? 'warm' : 'cold');
            return $floor > self::RANK[$t] ? array_search($floor, self::RANK, true) : $t;
        };
        $out = ['score' => $score, 'temperature' => (string) $base['temperature'], 'adjust' => 0, 'applied' => [], 'alerts' => [], 'tasks' => [], 'tags' => [], 'review' => false, 'trace' => []];

        foreach ($sets as $set) {
            $stopped = false;
            foreach ((array) $set['rules'] as $rule) {
                $rid = (string) $rule['id'];
                $t = ['set_id' => $set['set_id'], 'rule_id' => $rid, 'result' => ''];
                if ($stopped) {
                    $out['trace'][] = ['result' => 'stopped'] + $t;
                    continue;
                }
                if (empty($rule['enabled'])) {
                    $out['trace'][] = ['result' => 'disabled'] + $t;
                    continue;
                }
                $facts['score'] = $score;
                $facts['temperature'] = $tempOf($score);
                $m = self::matches($rule, $facts);
                if ($m !== 'match') {
                    $out['trace'][] = ['result' => $m] + $t;
                    continue;
                }
                $out['trace'][] = ['result' => 'applied'] + $t;
                $effects = [];
                foreach ((array) $rule['then'] as $a) {
                    switch ($a['type']) {
                        case 'add_points':
                            if ($dq) {
                                break;
                            }
                            $asked = (int) $a['points'];
                            $cap = $a['cap'] ?? null;
                            $want = $score + $asked;
                            if ($cap !== null) {
                                $want = $asked > 0 ? min($want, max($score, (int) $cap)) : max($want, min($score, (int) $cap));
                            }
                            $delta = $want - $score;
                            $delta = max(-Rules::MAX_ADJUST, min(Rules::MAX_ADJUST, $adjust + $delta)) - $adjust;
                            $delta = max(0, min(100, $score + $delta)) - $score;
                            $adjust += $delta;
                            $score += $delta;
                            $effects[] = ['type' => 'add_points', 'points' => $delta, 'asked' => $asked, 'capped' => $delta !== $asked];
                            break;
                        case 'min_temperature':
                            if ($dq) {
                                break;
                            }
                            $lv = self::RANK[(string) $a['level']] ?? 1;
                            $before = $tempOf($score);
                            $floor = max($floor, $lv);
                            $effects[] = ['type' => 'min_temperature', 'level' => (string) $a['level'], 'raised' => $tempOf($score) !== $before];
                            break;
                        case 'telegram_alert':
                            $effects[] = ['type' => 'telegram_alert', 'urgency' => (string) $a['urgency'], 'text' => (string) $a['text']];
                            $out['alerts'][] = ['set_id' => $set['set_id'], 'version_id' => $set['version_id'], 'rule_id' => $rid, 'name' => (string) $rule['name'], 'urgency' => (string) $a['urgency'], 'text' => (string) $a['text']];
                            break;
                        case 'create_task':
                            $effects[] = ['type' => 'create_task', 'title' => (string) $a['title'], 'due_hours' => (int) $a['due_hours']];
                            $out['tasks'][] = ['set_id' => $set['set_id'], 'version_id' => $set['version_id'], 'rule_id' => $rid, 'name' => (string) $rule['name'], 'title' => (string) $a['title'], 'due_hours' => (int) $a['due_hours'], 'priority' => (int) ($a['priority'] ?? 2)];
                            break;
                        case 'tag':
                            $effects[] = ['type' => 'tag', 'tag' => (string) $a['tag']];
                            $out['tags'][] = (string) $a['tag'];
                            break;
                        case 'review_queue':
                            $effects[] = ['type' => 'review_queue'];
                            $out['review'] = true;
                            break;
                    }
                }
                $out['applied'][] = ['set_id' => $set['set_id'], 'version_id' => $set['version_id'], 'set' => (string) $set['name'], 'rule_id' => $rid, 'name' => (string) $rule['name'], 'kind' => (string) $set['kind'], 'effects' => $effects];
                if (!empty($rule['stop'])) {
                    $stopped = true;
                }
            }
        }
        $out['score'] = $score;
        $out['adjust'] = $adjust;
        $out['temperature'] = $dq ? 'disqualified' : $tempOf($score);
        $out['tags'] = array_values(array_unique($out['tags']));
        return $out;
    }
}
