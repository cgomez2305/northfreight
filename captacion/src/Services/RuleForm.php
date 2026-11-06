<?php
/**
 * Formulario del editor de reglas (Q5b): lee lo que llega (POST clasico o la cadena urlencoded que manda el JS), conserva EN EL ORDEN ENVIADO las reglas y sus bloques con claves saneadas
 * y aplica las operaciones estructurales del editor sin JavaScript (añadir, quitar, duplicar, subir, bajar). Los valores NO se validan aqui: eso lo hace Services\Rules::normalize();
 * aqui solo se conserva lo escrito (aunque sea invalido) para volver a pintarlo con sus errores.
 *
 * Nombres de campo: rules[<clave>][name|enabled|stop|example|id], rules[<clave>][when|except][mode|items][<n>][field|op|value] (un grupo lleva su propio items),
 * rules[<clave>][then][<n>][type|points|cap|level|urgency|text|title|due_hours|priority|tag]. Las claves son [A-Za-z0-9_]{1,24}; el orden de las reglas es el del envio (el JS reordena el DOM).
 */

declare(strict_types=1);

namespace Captacion\Services;

final class RuleForm
{
    private const KEY = '/^[A-Za-z0-9_]{1,24}$/';

    /** Reglas tal cual llegaron, con claves saneadas, en el orden recibido y con topes de tamano (el limite real lo pone Rules::normalize). */
    public static function raw(mixed $in): array
    {
        if (!is_array($in)) {
            return [];
        }
        $out = [];
        foreach (array_slice($in, 0, Rules::MAX_RULES + 1, true) as $k => $r) {
            $k = (string) $k;
            if (preg_match(self::KEY, $k) !== 1 || !is_array($r)) {
                continue;
            }
            $out[$k] = self::clean($r, 0);
        }
        return $out;
    }

    private static function clean(array $a, int $depth): array
    {
        $o = [];
        foreach (array_slice($a, 0, 60, true) as $k => $v) {
            $k = (string) $k;
            if (preg_match(self::KEY, $k) !== 1) {
                continue;
            }
            if (is_array($v)) {
                $o[$k] = $depth < 6 ? self::clean($v, $depth + 1) : [];
            } elseif (is_scalar($v) || $v === null) {
                $o[$k] = is_string($v) ? mb_substr($v, 0, 400) : $v;
            }
        }
        return $o;
    }

    /** Lee la cadena urlencoded que arma el JS (new URLSearchParams(new FormData(form))). */
    public static function fromQuery(mixed $qs): array
    {
        if (!is_string($qs) || $qs === '' || strlen($qs) > 60000) {
            return [];
        }
        $parsed = [];
        parse_str($qs, $parsed);
        return $parsed;
    }

    /** Clave nueva para una regla, condicion o accion. */
    public static function newKey(array $siblings, string $prefix = 'n'): string
    {
        $i = count($siblings) + 1;
        while (isset($siblings[$prefix . $i])) {
            $i++;
        }
        return $prefix . $i;
    }

    /** ¿Prefijo de nombre de campo valido? (lo usa el endpoint que devuelve filas sueltas: nunca se imprime algo que no cumpla). */
    public static function validPrefix(string $np): bool
    {
        return preg_match('/^rules\[[A-Za-z0-9_]{1,24}\](\[(when|except|then|items|[A-Za-z0-9_]{1,24})\]){0,4}$/', $np) === 1;
    }

    /** «rules[r1][when][items][0]» -> «rules.r1.when.items.0» */
    public static function dotted(string $np): string
    {
        return str_replace(['][', '[', ']'], ['.', '.', ''], $np);
    }

    /** Una condicion vacia (el campo se elige de la lista). */
    public static function blankCond(string $field = 'profile'): array
    {
        $type = Rules::fieldType($field) ?? 'enum';
        return ['field' => $field, 'op' => Rules::OPS_BY_TYPE[$type][0], 'value' => ''];
    }

    public static function blankAction(string $kind, string $type = ''): array
    {
        $type = in_array($type, Rules::KIND_ACTIONS[$kind] ?? [], true) ? $type : (Rules::KIND_ACTIONS[$kind][0] ?? 'add_points');
        return match ($type) {
            'add_points'      => ['type' => $type, 'points' => '5', 'cap' => ''],
            'min_temperature' => ['type' => $type, 'level' => 'hot'],
            'telegram_alert'  => ['type' => $type, 'urgency' => 'normal', 'text' => ''],
            'create_task'     => ['type' => $type, 'title' => '', 'due_hours' => '2', 'priority' => '2'],
            'tag'             => ['type' => $type, 'tag' => ''],
            default           => ['type' => 'review_queue'],
        };
    }

    public static function blankRule(string $kind): array
    {
        $b = Rules::blank($kind);
        $b['when'] = ['mode' => 'all', 'items' => ['c1' => self::blankCond()]];
        $b['then'] = ['a1' => self::blankAction($kind)];
        return $b;
    }

    /** Reglas normalizadas (listas) a la forma del formulario: claves por id y bloques con claves. */
    public static function fromRules(array $rules): array
    {
        $out = [];
        $n = 0;
        foreach ($rules as $r) {
            $key = preg_match(self::KEY, (string) ($r['id'] ?? '')) === 1 ? (string) $r['id'] : 'x' . (++$n);
            $block = static function (?array $b): ?array {
                if ($b === null) {
                    return null;
                }
                $items = [];
                foreach ((array) ($b['items'] ?? []) as $j => $it) {
                    if (isset($it['items'])) {
                        $g = ['mode' => $it['mode'] ?? 'all', 'items' => []];
                        foreach ((array) $it['items'] as $k => $c) {
                            $g['items']['c' . $k] = $c;
                        }
                        $items['g' . $j] = $g;
                    } else {
                        $items['c' . $j] = $it;
                    }
                }
                return ['mode' => $b['mode'] ?? 'all', 'items' => $items];
            };
            $r['when'] = $block($r['when'] ?? null);
            $r['except'] = $block($r['except'] ?? null);
            $then = [];
            foreach ((array) ($r['then'] ?? []) as $j => $a) {
                $then['a' . $j] = $a;
            }
            $r['then'] = $then;
            $out[$key] = $r;
        }
        return $out;
    }

    // --- Operaciones estructurales (sin JavaScript) ---------------------------------------------------------------------------------

    /**
     * @param array $raw reglas en forma de formulario
     * @return array las reglas despues de la operacion (o iguales si la operacion no es valida)
     */
    public static function apply(array $raw, string $kind, string $op): array
    {
        $parts = explode(':', $op, 2);
        $name = $parts[0];
        $arg = $parts[1] ?? '';
        $path = $arg === '' ? [] : explode('.', $arg);
        foreach ($path as $p) {
            if (preg_match(self::KEY, $p) !== 1) {
                return $raw;
            }
        }
        $key = $path[0] ?? '';
        switch ($name) {
            case 'add_rule':
                if (count($raw) < Rules::MAX_RULES) {
                    $raw[self::newKey($raw, 'n')] = self::blankRule($kind);
                }
                return $raw;
            case 'del_rule':
                unset($raw[$key]);
                return $raw;
            case 'dup_rule':
                if (!isset($raw[$key]) || count($raw) >= Rules::MAX_RULES) {
                    return $raw;
                }
                $copy = $raw[$key];
                $copy['id'] = '';
                $copy['example'] = '0';
                $copy['name'] = mb_substr((string) ($copy['name'] ?? '') . ' (copia)', 0, 80);
                $new = [];
                foreach ($raw as $k => $r) {
                    $new[$k] = $r;
                    if ($k === $key) {
                        $new[self::newKey($raw, 'n')] = $copy;
                    }
                }
                return $new;
            case 'up':
            case 'down':
                $keys = array_keys($raw);
                $i = array_search($key, $keys, true);
                if ($i === false) {
                    return $raw;
                }
                $j = $name === 'up' ? $i - 1 : $i + 1;
                if ($j < 0 || $j >= count($keys)) {
                    return $raw;
                }
                [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
                return array_replace(array_flip($keys), $raw);
            case 'add_cond':
            case 'add_group':   // arg: rules.KEY.when | rules.KEY.except | rules.KEY.when.items.GKEY (dentro de un grupo)
                $ref = &self::ref($raw, $path);
                if (is_array($ref)) {
                    $ref['items'] = (array) ($ref['items'] ?? []);
                    $isGroup = count($path) > 3;
                    if (count($ref['items']) < ($isGroup ? Rules::MAX_GROUP_ITEMS : Rules::MAX_ITEMS)) {
                        if ($name === 'add_group' && !$isGroup) {
                            $ref['items'][self::newKey($ref['items'], 'g')] = ['mode' => 'any', 'items' => ['c1' => self::blankCond()]];
                        } elseif ($name === 'add_cond') {
                            $ref['items'][self::newKey($ref['items'], 'c')] = self::blankCond();
                        }
                    }
                }
                return $raw;
            case 'del_item':   // arg: rules.KEY.when.items.CKEY (una condicion o un grupo) o dentro de un grupo
                if (count($path) >= 5) {
                    $last = array_pop($path);
                    $ref = &self::ref($raw, $path);
                    if (is_array($ref)) {
                        unset($ref[$last]);
                    }
                }
                return $raw;
            case 'add_except':
                if (isset($raw[$key])) {
                    $raw[$key]['except'] = ['mode' => 'all', 'items' => ['c1' => self::blankCond()]];
                }
                return $raw;
            case 'del_except':
                if (isset($raw[$key])) {
                    $raw[$key]['except'] = null;
                }
                return $raw;
            case 'add_act':
                if (isset($raw[$key]) && count((array) ($raw[$key]['then'] ?? [])) < Rules::MAX_ACTIONS) {
                    $have = array_column((array) ($raw[$key]['then'] ?? []), 'type');
                    $type = '';
                    foreach (Rules::KIND_ACTIONS[$kind] ?? [] as $t) {
                        if (!in_array($t, $have, true) || $t === 'tag') {
                            $type = $t;
                            break;
                        }
                    }
                    $then = (array) ($raw[$key]['then'] ?? []);
                    $then[self::newKey($then, 'a')] = self::blankAction($kind, $type);
                    $raw[$key]['then'] = $then;
                }
                return $raw;
            case 'del_act':   // arg: KEY.AKEY
                if (isset($path[1], $raw[$key]['then'][$path[1]])) {
                    unset($raw[$key]['then'][$path[1]]);
                }
                return $raw;
            default:   // save, refresh: sin cambios
                return $raw;
        }
    }

    /** Referencia al nodo $path dentro de $raw (ruta que empieza en «rules»). @param list<string> $path */
    private static function &ref(array &$raw, array $path): mixed
    {
        $null = null;
        if (($path[0] ?? '') !== 'rules') {
            return $null;
        }
        array_shift($path);
        $node = &$raw;
        foreach ($path as $p) {
            if (!is_array($node) || !array_key_exists($p, $node)) {
                return $null;
            }
            $node = &$node[$p];
        }
        return $node;
    }
}
