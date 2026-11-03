<?php
/**
 * Utilidades de plantilla (no es un partial que imprima): funciones puras con prefijo ns_.
 * Uso desde páginas:  require_once NS_ROOT . '/templates/partials/kit.php';
 * Los partials la cargan solos con require_once.
 */
if (defined('NS_KIT_LOADED')) {
    return;
}
define('NS_KIT_LOADED', true);

/**
 * Texto con marcas: escapa con phtext() ({{ph:X}} => ph()) y convierte
 * {hl}…{/hl} => <span class="hl">…</span> y {br} => <br>. Nada de HTML crudo.
 */
function ns_rich(string $s): string
{
    return str_replace(['{hl}', '{/hl}', '{br}'], ['<span class="hl">', '</span>', '<br>'], phtext($s));
}

/** Estrella NorthStar (§5.5). Color por CSS (.star => currentColor; por defecto cian). */
function ns_star(int $size = 16, string $class = ''): string
{
    return '<svg class="star' . ($class !== '' ? ' ' . e($class) : '') . '" width="' . $size . '" height="' . $size
        . '" viewBox="0 0 249 249" aria-hidden="true" focusable="false"><path d="M127 0L98 100L0 123L99 149L122 249L153 148L249 125L150 98Z"/></svg>';
}

/** Iconos de trazo (24x24, currentColor). Nombres: arrow whatsapp phone chevron close menu play calc trend doc doc-down check mail truck pin plus book. */
function ns_icon(string $name, int $size = 18, string $class = ''): string
{
    static $paths = [
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'whatsapp' => '<path d="M3 21l1.7-4.6A8.5 8.5 0 1 1 8 19.6L3 21z"/>',
        'phone'    => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'chevron'  => '<path d="M6 9l6 6 6-6"/>',
        'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
        'menu'     => '<path d="M4 7h16M4 12h16M4 17h10"/>',
        'play'     => '<path d="M8 5.5v13l11-6.5z"/>',
        'calc'     => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 12h2M14 12h2M8 16h2M14 16h2"/>',
        'trend'    => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'doc'      => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'doc-down' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M12 11v6M9 14l3 3 3-3"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'truck'    => '<path d="M2 7h11v9H2zM13 10h4l3 3v3h-7"/><circle cx="6" cy="17.5" r="1.8"/><circle cx="16.5" cy="17.5" r="1.8"/>',
        'pin'      => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 21V5M8 7h7"/>',
    ];
    if ($name === 'star') {
        return ns_star($size, $class);
    }
    $inner = $paths[$name] ?? '';
    $cls = 'icon' . ($name === 'play' ? ' icon--fill' : '') . ($class !== '' ? ' ' . e($class) : '');
    return '<svg class="' . $cls . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}

/**
 * <picture> responsive sobre assets/img/{base}-{ancho}.webp + {base}.jpg.
 * $o: base (sin extensión), alt, sizes, class, eager(bool), widths(array, por defecto los que existan).
 */
function ns_img(array $o): string
{
    static $dims = [];
    $base = (string) ($o['base'] ?? '');
    if ($base === '') {
        return '';
    }
    $dir = NS_ROOT . '/assets/img/';
    $widths = $o['widths'] ?? [490, 640, 1024, 1600];
    $set = [];
    foreach ($widths as $w) {
        if (is_file($dir . "$base-$w.webp")) {
            $set[] = asset("img/$base-$w.webp") . " {$w}w";
        }
    }
    $jpg = is_file($dir . "$base.jpg") ? "img/$base.jpg" : null;
    if ($jpg !== null && !isset($dims[$base])) {
        $g = @getimagesize($dir . "$base.jpg");
        $dims[$base] = $g ? [$g[0], $g[1]] : [0, 0];
    }
    [$w, $h] = $dims[$base] ?? [0, 0];
    $eager = !empty($o['eager']);
    $out = '<picture>';
    if ($set) {
        $out .= '<source type="image/webp" srcset="' . e(implode(', ', $set)) . '" sizes="' . e($o['sizes'] ?? '100vw') . '">';
    }
    $src = $jpg !== null ? asset($jpg) : ($set ? preg_replace('/ \d+w$/', '', end($set)) : '');
    $out .= '<img src="' . e($src) . '" alt="' . e($o['alt'] ?? '') . '"'
        . ($w ? ' width="' . $w . '" height="' . $h . '"' : '')
        . ($eager ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async"'
        . (!empty($o['class']) ? ' class="' . e($o['class']) . '"' : '') . '>';
    return $out . '</picture>';
}

/** Lockup del logo (§5.6). $variant: 'dark' (sobre navy, por defecto). */
function ns_logo(string $class = 'logo'): string
{
    return '<a class="' . e($class) . '" href="' . e(url('/')) . '">'
        . '<img class="logo__mark" src="' . e(asset('img/isotype-white.svg')) . '" alt="" width="46" height="46">'
        . '<span class="logo__text"><span class="logo__name">North<span>star</span></span>'
        . '<span class="logo__sub">Freight Solutions</span><span class="sr-only"> home</span></span></a>';
}

/**
 * Datos de navegación compartidos por nav, megas, menú móvil y footer. Solo rutas live.
 * Cada ítem: [label, desc, path, icono|null, label corto (footer)|null].
 */
function ns_menu(): array
{
    $m = [
        'dispatch' => [
            ['Dispatch for owner-operators', 'Loads booked, brokers handled, you approve every load', '/dispatch-for-owner-operators/', 'star', null],
            ['Amazon Relay dispatch', 'Load board, trips, scorecard and disputes', '/amazon-relay-dispatch/', 'star', null],
            ['Dry van dispatch', 'Steady freight booked and followed by one desk', '/dry-van-dispatch/', 'star', null],
        ],
        'ops' => [
            ['Load tracking & check calls', 'Pickup to POD, every load logged', '/load-tracking/', 'star', 'Load tracking'],
            ['Trucking back office', 'Settlements, disputes, driver onboarding', '/trucking-back-office/', 'star', null],
        ],
        'tools' => [
            ['IFTA calculator', 'Quarterly fuel tax by state', '/resources/ifta-calculator/', 'calc', null],
            ['Rate per mile calculator', 'Know if a load pays before you book it', '/resources/rate-per-mile-calculator/', 'trend', null],
        ],
        'templates' => [
            ['Bill of lading template', 'Free BOL in PDF and Word', '/resources/bol-template/', 'doc-down', null],
            ['Dispatcher-carrier agreement', 'What a fair dispatch contract covers', '/resources/dispatcher-carrier-agreement/', 'doc', null],
        ],
        'learn' => [
            ['Trucking glossary', 'Dry van, lumper, detention and more', '/resources/glossary/', 'star', null],
            ['Blog', 'Dispatch costs, Amazon Relay, lumper fees', '/blog/', 'star', null],
        ],
        'equipment' => [
            ['Box truck', '', '/box-truck-dispatch/', null, null],
            ['Hotshot', '', '/hotshot-dispatch/', null, null],
            ['Power only', '', '/power-only-dispatch/', null, null],
            ['Reefer', '', '/reefer-dispatch/', null, null],
            ['Flatbed', '', '/flatbed-dispatch/', null, null],
        ],
        'main' => [
            ['How it works', '', '/how-it-works/', null, null],
            ['Our desk', '', '/our-desk/', null, null],
            ['Contact', '', '/contact/', null, null],
        ],
    ];
    foreach ($m as $k => $items) {
        $m[$k] = array_values(array_filter($items, static fn(array $i): bool => route_is_live($i[2])));
    }
    if (!cfg('show_equipment_chips')) {
        $m['equipment'] = [];
    }
    return $m;
}
