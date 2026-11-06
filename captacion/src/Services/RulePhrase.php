<?php
/**
 * Frases legibles de las reglas (Q5b): «Cuando el perfil es Owner-operator y el estado está en TX, TN, FL». Se usan en la lista, el editor, el historial con diferencias, las muestras de la vista previa
 * y el registro de actividad. Recibe reglas ya normalizadas (Services\Rules::normalize); todo texto sale SIN escapar: quien lo imprime lo escapa.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class RulePhrase
{
    private const SHOW = 6;

    private static function valueLabel(string $field, mixed $v): string
    {
        $opts = Rules::options($field);
        $one = static fn($x): string => $field === 'has_usdot' ? ($x ? 'Sí' : 'No') : ($opts[(string) $x] ?? (string) $x);
        if (is_array($v)) {
            $labels = array_map(static fn($x) => $field === 'state' ? (string) $x : $one($x), $v);
            $extra = count($labels) - self::SHOW;
            return implode(', ', array_slice($labels, 0, self::SHOW)) . ($extra > 0 ? ' y ' . $extra . ' más' : '');
        }
        return $one($v);
    }

    private static function num(mixed $n, string $field): string
    {
        $unit = Rules::NUM[$field][2] ?? '';
        $s = is_float($n) ? rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') : (string) $n;
        return $s . ($unit !== '' && $unit !== 'puntos' && $unit !== 'camiones' ? ' ' . $unit : '');
    }

    public static function cond(array $c): string
    {
        $field = (string) ($c['field'] ?? '');
        $op = (string) ($c['op'] ?? 'is');
        if (!isset(Rules::FIELDS[$field])) {
            return 'una condición sin completar';
        }
        $subject = Rules::FIELDS[$field][0];
        $type = Rules::FIELDS[$field][1];
        $v = $c['value'] ?? null;
        if ($field === 'has_usdot') {
            return $v === false ? 'no tiene USDOT' : 'tiene USDOT';
        }
        if ($op === 'exists') {
            return $subject . ' tiene dato';
        }
        if ($op === 'not_exists') {
            return $subject . ' está vacío';
        }
        $label = Rules::opLabel($field, $op);
        if ($type === 'number') {
            if ($op === 'between' && is_array($v)) {
                return $subject . ' ' . $label . ' ' . self::num($v[0] ?? 0, $field) . ' y ' . self::num($v[1] ?? 0, $field);
            }
            return $subject . ' ' . $label . ' ' . self::num($v ?? 0, $field);
        }
        if ($type === 'text') {
            return $subject . ' ' . $label . ' ' . (is_array($v) ? implode(', ', array_map(static fn($x) => '«' . $x . '»', $v)) : '«' . (string) $v . '»');
        }
        return $subject . ' ' . $label . ' ' . self::valueLabel($field, $v);
    }

    /** «a y b», «a o b» o con un grupo entre paréntesis. @param array{mode?:string,items?:list<array>}|null $b */
    public static function block(?array $b): string
    {
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        $join = ($b['mode'] ?? 'all') === 'any' ? ' o ' : ' y ';
        $parts = [];
        foreach ($items as $it) {
            if (isset($it['items'])) {
                $inner = implode(($it['mode'] ?? 'all') === 'any' ? ' o ' : ' y ', array_map([self::class, 'cond'], (array) $it['items']));
                $parts[] = count($items) > 1 ? '(' . $inner . ')' : $inner;
            } else {
                $parts[] = self::cond($it);
            }
        }
        return implode($join, $parts);
    }

    public static function when(array $rule): string
    {
        $t = self::block($rule['when'] ?? null);
        return $t === '' ? 'Cuando (sin condiciones)' : 'Cuando ' . $t;
    }

    public static function except(array $rule): string
    {
        $t = self::block($rule['except'] ?? null);
        return $t === '' ? '' : 'Excepto cuando ' . $t;
    }

    public static function action(array $a): string
    {
        switch ($a['type'] ?? '') {
            case 'add_points':
                $p = (int) ($a['points'] ?? 0);
                $cap = $a['cap'] ?? null;
                $txt = ($p >= 0 ? 'sumar ' : 'restar ') . abs($p) . (abs($p) === 1 ? ' punto' : ' puntos');
                if ($cap !== null && $cap !== '') {
                    $txt .= $p >= 0 ? ' (sin pasar de ' . (int) $cap . ')' : ' (sin bajar de ' . (int) $cap . ')';
                }
                return $txt;
            case 'min_temperature':
                return 'dejar la temperatura al menos en ' . (($a['level'] ?? '') === 'hot' ? 'Caliente' : 'Tibio');
            case 'telegram_alert':
                return 'avisar por Telegram' . (($a['urgency'] ?? '') === 'urgent' ? ' (urgente)' : '') . ': «' . (string) ($a['text'] ?? '') . '»';
            case 'create_task':
                return 'crear la tarea «' . (string) ($a['title'] ?? '') . '» para dentro de ' . (int) ($a['due_hours'] ?? 0) . ' h';
            case 'tag':
                return 'poner la etiqueta «' . (string) ($a['tag'] ?? '') . '»';
            case 'review_queue':
                return 'enviar el lead a la cola de revisión';
            default:
                return 'una acción sin completar';
        }
    }

    public static function then(array $rule): string
    {
        $parts = array_map([self::class, 'action'], (array) ($rule['then'] ?? []));
        return $parts ? 'Entonces ' . implode(' y ', $parts) : 'Entonces (sin acciones)';
    }

    /** La regla completa en una sola cadena (registro de actividad y diferencias). */
    public static function rule(array $rule): string
    {
        return trim(self::when($rule) . '. ' . self::then($rule) . '.' . (self::except($rule) !== '' ? ' ' . self::except($rule) . '.' : ''));
    }
}
