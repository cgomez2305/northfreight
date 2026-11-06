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
        return '<span class="av' . ($size !== '' ? ' av--' . self::e($size) : '') . '" aria-hidden="true">' . self::e(Fmt::initials($name)) . '</span>';
    }

    public static function score(int $score, string $temp): string
    {
        $cls = $temp === 'disqualified' ? 'dq' : $temp;
        return '<span class="score' . ($cls === 'dq' ? ' score--dq' : '') . '" title="Puntuación ' . $score . ' de 100">' . $score . '</span>';
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

    /** Barra de puntuacion de 4 segmentos (25 puntos cada uno), con el color de la temperatura. */
    public static function scoreBar(int $score, string $temp): string
    {
        $on = max(0, min(4, (int) ceil($score / 25)));
        $cls = $temp === 'disqualified' ? 'dq' : $temp;
        $segs = '';
        for ($i = 1; $i <= 4; $i++) {
            $segs .= '<i' . ($i <= $on ? ' class="on"' : '') . '></i>';
        }
        return '<span class="sbar sbar--' . self::e($cls) . '" role="img" aria-label="Puntuación ' . $score . ' de 100">' . $segs . '</span>';
    }

    /** Etiquetas discretas del lead: perfil y equipo (hasta $max), cada una con su tono. */
    public static function tags(array $lead, int $max = 2): string
    {
        $profile = (string) ($lead['profile'] ?? '');
        $out = '<span class="tag tag--p-' . self::e($profile) . '"><i></i>' . self::e(Catalog::label(Catalog::PROFILES_SHORT, $profile)) . '</span>';
        $eq = is_array($lead['equipment_types'] ?? null) ? $lead['equipment_types'] : [];
        foreach (array_slice($eq, 0, $max) as $t) {
            $out .= '<span class="tag tag--e-' . (crc32((string) $t) % 5) . '"><i></i>' . self::e(Catalog::label(Catalog::EQUIPMENT, (string) $t)) . '</span>';
        }
        return $out;
    }

    /** Icono de la etapa (encabezado de columna del tablero, lista de etapas). */
    public static function stageIcon(string $stage): string
    {
        return self::icon(['new' => 'inbox', 'contacted' => 'phone', 'qualified' => 'check-circle', 'prequalified' => 'check-circle', 'call_booked' => 'calendar', 'presented' => 'send', 'signed' => 'shield', 'hired' => 'shield', 'lost' => 'x'][$stage] ?? 'layers', 'ic--st st-' . $stage);
    }

    /** Medidor de rayas (Q6): $on de $total rayas llenas. El valor va en el texto accesible; las rayas son decorativas. */
    public static function ticks(int $on, int $total, string $label, string $cls = ''): string
    {
        $on = max(0, min($total, $on));
        $h = '';
        for ($i = 1; $i <= $total; $i++) {
            $h .= '<i' . ($i <= $on ? ' class="on"' : '') . '></i>';
        }
        return '<span class="tk' . ($cls !== '' ? ' ' . self::e($cls) : '') . '" role="img" aria-label="' . self::e($label) . '">' . $h . '</span>';
    }

    /**
     * Numero protagonista (Q6): la parte entera grande y los decimales y la unidad atenuados (`79<small>.0%</small>`). El texto accesible final va en un `.sr` y lo pintado en un span aria-hidden,
     * asi el conteo animado nunca cambia lo que lee un lector de pantalla. $count = ['v'=>valor,'dec'=>decimales,'suf'=>sufijo,'pre'=>prefijo opcional, p. ej. «$»] activa el conteo (data-*); sin el, el numero es estatico.
     * @param string $text texto final: «1,234», «79.0%», «13 min», «4 h 52 min»
     */
    public static function big(string $text, string $cls = 'kpi__v', ?array $count = null): string
    {
        $head = $text;
        $rest = '';
        if (preg_match('/^(\d[\d,]*)(.*)$/su', $text, $m) === 1) {
            [$head, $rest] = [$m[1], $m[2]];
        }
        $attr = $count !== null ? ' data-count="' . self::e(rtrim(rtrim(number_format((float) $count['v'], 4, '.', ''), '0'), '.') ?: '0') . '" data-dec="' . (int) $count['dec'] . '" data-suf="' . self::e((string) $count['suf']) . '"' . (($count['pre'] ?? '') !== '' ? ' data-pre="' . self::e((string) $count['pre']) . '"' : '') : '';
        return '<p class="' . self::e($cls) . '"' . $attr . '><span class="sr">' . self::e($text) . '</span><span class="kpi__n" aria-hidden="true">' . self::e($head) . ($rest !== '' ? '<small>' . self::e($rest) . '</small>' : '') . '</span></p>';
    }

    /** Boton «i» con la explicacion de lo que mide una tarjeta (tooltip por hover y foco). */
    public static function tip(string $t): string
    {
        return '<button class="info" type="button" aria-label="Qué mide: ' . self::e($t) . '" data-tip="' . self::e($t) . '">' . self::icon('info') . '</button>';
    }
}
