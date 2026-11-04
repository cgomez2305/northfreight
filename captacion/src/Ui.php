<?php
/** Piezas de interfaz reutilizables (HTML ya escapado) que usan las vistas: iconos, chips, avatares, pastillas y paginacion. */

declare(strict_types=1);

namespace Captacion;

use Captacion\Services\Catalog;
use Captacion\Services\Fmt;

final class Ui
{
    public static function e(mixed $v): string
    {
        return View::e($v);
    }

    public static function icon(string $name, string $class = ''): string
    {
        return '<svg class="ic' . ($class !== '' ? ' ' . self::e($class) : '') . '" aria-hidden="true" focusable="false"><use href="#i-' . self::e($name) . '"/></svg>';
    }

    public static function temp(string $t, bool $short = false): string
    {
        $cls = $t === 'disqualified' ? 'dq' : $t;
        return '<span class="chip chip--' . self::e($cls) . '"><i class="dot"></i>' . self::e(Catalog::label(Catalog::TEMPS, $t)) . '</span>';
    }

    public static function stage(string $stage, ?string $pipeline = null): string
    {
        $label = $pipeline !== null ? Catalog::stageLabel($pipeline, $stage) : Catalog::stageAny($stage);
        return '<span class="chip chip--stage st-' . self::e($stage) . '"><i class="dot"></i>' . self::e($label) . '</span>';
    }

    public static function avatar(string $name, string $size = '', ?int $variant = null): string
    {
        return '<span class="av av-' . ($variant ?? Fmt::avatar($name)) . ($size !== '' ? ' av--' . self::e($size) : '') . '" aria-hidden="true">' . self::e(Fmt::initials($name)) . '</span>';
    }

    public static function score(int $score, string $temp): string
    {
        $cls = $temp === 'disqualified' ? 'dq' : $temp;
        return '<span class="score score--' . self::e($cls) . '" title="Puntuación ' . $score . ' de 100">' . $score . '</span>';
    }

    /** @param array{text:string,dir:string,good:?bool} $d */
    public static function delta(array $d, string $extra = ''): string
    {
        $cls = $d['good'] === null ? 'flat' : ($d['good'] ? 'good' : 'bad');
        $ic = $d['dir'] === 'up' ? 'arrow-up-right' : ($d['dir'] === 'down' ? 'arrow-down-right' : 'minus');
        return '<span class="delta delta--' . $cls . '"' . ($extra !== '' ? ' ' . $extra : '') . '>' . self::icon($ic) . self::e($d['text']) . '</span>';
    }

    /** Query string a partir de los parametros no vacios. */
    public static function qs(array $params): string
    {
        $p = array_filter($params, static fn($v) => $v !== null && $v !== '' && $v !== []);
        return $p ? '?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986) : '';
    }

    /** Paginacion numerada con anterior/siguiente. $base = parametros actuales; $key = nombre del parametro de pagina; $path = ruta. */
    public static function pager(string $path, array $base, string $key, int $page, int $pages, string $anchor = ''): string
    {
        if ($pages <= 1) {
            return '';
        }
        $link = static fn(int $p): string => self::e($path . self::qs([$key => $p > 1 ? $p : null] + $base) . $anchor);
        $items = [];
        $win = array_unique(array_filter([1, 2, $page - 1, $page, $page + 1, $pages - 1, $pages], static fn($p) => $p >= 1 && $p <= $pages));
        sort($win);
        $prev = 0;
        foreach ($win as $p) {
            if ($prev && $p - $prev > 1) {
                $items[] = '<li class="pg__gap" aria-hidden="true">…</li>';
            }
            $items[] = '<li>' . ($p === $page ? '<span class="pg__n is-on" aria-current="page">' . $p . '</span>' : '<a class="pg__n" href="' . $link($p) . '" aria-label="Página ' . $p . '">' . $p . '</a>') . '</li>';
            $prev = $p;
        }
        return '<nav class="pg" aria-label="Paginación"><ul>'
            . '<li>' . ($page > 1 ? '<a class="pg__n" href="' . $link($page - 1) . '" aria-label="Página anterior">' . self::icon('chevron-left') . '</a>' : '<span class="pg__n is-off" aria-hidden="true">' . self::icon('chevron-left') . '</span>') . '</li>'
            . implode('', $items)
            . '<li>' . ($page < $pages ? '<a class="pg__n" href="' . $link($page + 1) . '" aria-label="Página siguiente">' . self::icon('chevron-right') . '</a>' : '<span class="pg__n is-off" aria-hidden="true">' . self::icon('chevron-right') . '</span>') . '</li>'
            . '</ul></nav>';
    }

    /** Equipo como texto corto: "Dry van, Reefer +1". */
    public static function equipment(array $types, int $max = 2): string
    {
        $labels = array_map(static fn($t) => Catalog::label(Catalog::EQUIPMENT, (string) $t), $types);
        if (!$labels) {
            return '—';
        }
        $shown = array_slice($labels, 0, $max);
        return implode(', ', $shown) . (count($labels) > $max ? ' +' . (count($labels) - $max) : '');
    }
}
