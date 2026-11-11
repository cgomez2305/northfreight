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
 * {hl}…{/hl} => <span class="hl">…</span>, {br} => <br> y {pill:base} => pastilla de foto decorativa dentro del titular
 * (<span class="hpill" aria-hidden="true">, alt vacío: no cambia el título accesible; solo si assets/img/<base>-640.webp existe; usa <base>-pill-320.webp si existe: la pastilla mide ≤122 px y 320 px bastan incluso en retina). Nada de HTML crudo.
 */
function ns_rich(string $s): string
{
    $out = str_replace(['{hl}', '{/hl}', '{br}'], ['<span class="hl">', '</span>', '<br>'], phtext($s));
    if (strpos($out, '{pill:') !== false) {
        $out = preg_replace_callback('/\{pill:([a-z0-9-]+)\}/', static function (array $m): string {
            if (!is_file(NS_ROOT . '/assets/img/' . $m[1] . '-640.webp')) {
                return '';
            }
            return '<span class="hpill" aria-hidden="true">' . (is_file(NS_ROOT . '/assets/img/' . $m[1] . '-pill-320.webp')
                ? ns_img(['base' => $m[1] . '-pill', 'alt' => '', 'widths' => [320], 'sizes' => '160px'])
                : ns_img(['base' => $m[1], 'alt' => '', 'widths' => [640], 'sizes' => '160px'])) . '</span>';
        }, $out);
    }
    return $out;
}

/** Estrella NorthStar (§5.5). Color por CSS (.star => currentColor; por defecto cian). */
function ns_star(int $size = 16, string $class = ''): string
{
    return '<svg class="star' . ($class !== '' ? ' ' . e($class) : '') . '" width="' . $size . '" height="' . $size
        . '" viewBox="0 0 249 249" aria-hidden="true" focusable="false"><path d="M127 0L98 100L0 123L99 149L122 249L153 148L249 125L150 98Z"/></svg>';
}

/** Iconos de trazo (24x24, currentColor). Nombres: arrow whatsapp phone chevron close menu play calc trend doc doc-down check mail truck pin plus book route locate file-check form. */
function ns_icon(string $name, int $size = 18, string $class = ''): string
{
    static $paths = [
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
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
        'route'      => '<circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M8 19h6.5a3.5 3.5 0 0 0 0-7h-5a3.5 3.5 0 0 1 0-7H16"/>',
        'locate'     => '<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="8"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
        'file-check' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 14.5l2.2 2.2L15 12.5"/>',
        'form'       => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h4"/>',
    ];
    if ($name === 'star') {
        return ns_star($size, $class);
    }
    $inner = $paths[$name] ?? '';
    $cls = 'icon' . ($name === 'play' ? ' icon--fill' : '') . ($class !== '' ? ' ' . e($class) : '');
    return '<svg class="' . $cls . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}

/**
 * Añade a un atributo `sizes` la rama de pantallas retina (≥1,5 dppx): delante de cada entrada con condición de ancho y tamaño en px o vw se inserta la misma condición
 * `and (min-resolution:1.5dppx)` con el 65 % del tamaño. Motivo: el navegador elige la variante MÁS PEQUEÑA con ancho ≥ tamaño × DPR, y la regla del sitio permite x1,10 en 1x pero x1,6 en retina;
 * con una sola cifra no se pueden cumplir las dos (a 1440 con DPR 2, un marco de 1152 px pedía 2304 y bajaba la de 2560 cuando 1600 cubre x1,44). Con 65 %, 2 × tamaño × 0,65 = 1,3 × marco: la variante elegida
 * nunca amplía más de x1,54. Los navegadores sin soporte de `min-resolution` en `sizes` ignoran la entrada y siguen con la normal (sin riesgo). Las entradas sin condición (la última, p. ej. `65vw` en móvil,
 * que ya va al 65 %) y las de 0px no se tocan. El orden de las entradas se conserva, así que la semántica de ancho no cambia.
 */
function ns_sizes_retina(string $sizes): string
{
    $out = [];
    foreach (explode(',', $sizes) as $part) {
        $part = trim($part);
        if (preg_match('/^(\(min-width:\s*\d+px\))\s+(\d+(?:\.\d+)?)(px|vw)$/', $part, $m) === 1 && (float) $m[2] > 0) {
            $out[] = $m[1] . ' and (min-resolution:1.5dppx) ' . rtrim(rtrim(number_format((float) $m[2] * 0.65, 1, '.', ''), '0'), '.') . $m[3];
        }
        $out[] = $part;
    }
    return implode(', ', $out);
}

/**
 * <picture> responsive sobre {dir}/{base}-{ancho}.webp (+ {base}.jpg de respaldo si existe).
 * $o: base (sin extensión), alt, sizes, class, eager(bool), widths(array, por defecto los que existan), dir ('img' por defecto; 'video' para fotogramas en assets/video, hoy sin uso),
 *     art(array de ['media'=>…, 'base'=>…, 'sizes'=>…, 'widths'=>…?]: recortes alternativos que se emiten ANTES de la fuente normal; el primero cuya media coincida gana).
 * `sizes` debe describir el ANCHO que ocupa la imagen una vez recortada con object-fit: cover (marco, margen de .px y alto incluidos), no solo el ancho del marco: con marcos
 * más altos que la foto manda el alto, y un `sizes` corto hace elegir una variante demasiado pequeña (se ve pixelada). Regla: ancho útil = max(ancho marco, alto imagen × proporción foto).
 * Variantes (fase N): 640, 768, 1024, 1280, 1600, 2048, 2560 (y 3200 en heros y CTA; los recortes `-tall`/`-m` añaden 480). El navegador elige la MÁS PEQUEÑA con ancho ≥ tamaño × DPR, y la regla del sitio es x1,10 máx. en 1x y x1,6 en retina: en móvil (≤640 px) se declara `65vw` en marcos que mandan por el ancho (≈ 0,65 × el marco: a DPR 2 elige 640 y a DPR 3 elige 768, ampliación x1,1 a x1,5) y `calc(80vw - 32px)` en los marcos 4:3 con foto 3:2 (card, video, banda, blog), donde manda el alto y el ancho útil es ≈ 1,24 × el marco (a DPR 3 eligen 1024: con 768 pasarían de x1,6), en escritorio el ancho útil del marco (1x) y `ns_sizes_retina()` añade solo la rama retina con el 65 % (a 1440 y DPR 2 un marco de 1152 baja la de 1600, no la de 2560). Los heros del home llevan `widths` y `sizes` propios y no pasan por aquí.
 * Las variantes no se ampliaron nunca: solo existen anchos ≤ al de la foto de origen (el ancho nativo puede ser cualquiera; se detectan en disco).
 */
function ns_img(array $o): string
{
    static $dims = [];
    $base = (string) ($o['base'] ?? '');
    if ($base === '') {
        return '';
    }
    $dirName = ($o['dir'] ?? 'img') === 'video' ? 'video' : 'img';
    $dir = NS_ROOT . '/assets/' . $dirName . '/';
    // Sin `widths` se usan todas las variantes que existan en disco ({base}-{ancho}.webp), de menor a mayor
    $found = static function (string $b, string $d): array {
        $ws = [];
        foreach (glob($d . $b . '-[0-9]*.webp') ?: [] as $f) {
            if (preg_match('/-(\d+)\.webp$/', $f, $m)) {
                $ws[] = (int) $m[1];
            }
        }
        sort($ws);
        return $ws;
    };
    $widths = $o['widths'] ?? $found($base, $dir);
    $srcset = static function (string $b, array $ws, string $d, string $dn): array {
        $set = [];
        foreach ($ws as $w) {
            if (is_file($d . "$b-$w.webp")) {
                $set[] = asset("$dn/$b-$w.webp") . " {$w}w";
            }
        }
        return $set;
    };
    $set = $srcset($base, $widths, $dir, $dirName);
    // Variante más grande en disco: es el `src` de respaldo y da el width/height del <img> (sirve cualquier ancho). Sin variantes WebP cae al {base}.jpg.
    $maxW = 0;
    foreach ($widths as $cand) {
        if ((int) $cand > $maxW && is_file($dir . "$base-$cand.webp")) {
            $maxW = (int) $cand;
        }
    }
    $jpg = is_file($dir . "$base.jpg") ? "$dirName/$base.jpg" : null;
    $key = $dirName . '/' . $base;
    if (!isset($dims[$key])) {
        $ref = $maxW > 0 ? $dir . "$base-$maxW.webp" : ($jpg !== null ? $dir . "$base.jpg" : '');
        $g = $ref !== '' ? @getimagesize($ref) : false;
        $dims[$key] = $g ? [$g[0], $g[1]] : [0, 0];
    }
    [$w, $h] = $dims[$key];
    $eager = !empty($o['eager']);
    $out = '<picture>';
    foreach ((array) ($o['art'] ?? []) as $a) {
        $as = $srcset((string) $a['base'], $a['widths'] ?? $found((string) $a['base'], $dir), $dir, $dirName);
        if ($as) {
            $out .= '<source type="image/webp" media="' . e($a['media']) . '" srcset="' . e(implode(', ', $as)) . '" sizes="' . e(ns_sizes_retina((string) ($a['sizes'] ?? ($o['sizes'] ?? '100vw')))) . '">';
        }
    }
    if ($set) {
        $out .= '<source type="image/webp" srcset="' . e(implode(', ', $set)) . '" sizes="' . e(ns_sizes_retina((string) ($o['sizes'] ?? '100vw'))) . '">';
    }
    // `src` de respaldo (solo lo usan navegadores sin <picture>): la variante más grande ≤1600 px, no la de 3200 (el width/height sí vienen de la mayor: fija la proporción)
    $srcW = 0;
    foreach ($widths as $cand) {
        if ((int) $cand <= 1600 && (int) $cand > $srcW && is_file($dir . "$base-$cand.webp")) {
            $srcW = (int) $cand;
        }
    }
    $srcW = $srcW > 0 ? $srcW : $maxW;
    $src = $srcW > 0 ? asset("$dirName/$base-$srcW.webp") : ($jpg !== null ? asset($jpg) : '');
    $out .= '<img src="' . e($src) . '" alt="' . e($o['alt'] ?? '') . '"'
        . ($w ? ' width="' . $w . '" height="' . $h . '"' : '')
        . ($eager ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async"'
        . (!empty($o['class']) ? ' class="' . e($o['class']) . '"' : '') . '>';
    return $out . '</picture>';
}

/**
 * Slot de video de fondo del hero del home: devuelve ['mp4'=>url,'webm'=>url] solo con los archivos que existan en assets/video/{nombre}.{mp4,webm}
 * (vacío si no hay ninguno: queda la foto y no se emite <video> ni se pide nada). main.js lo monta tras `load` + idle, solo en >=1025px, sin prefers-reduced-motion ni saveData;
 * si falla, la foto sigue. El video (hero.mp4: muted, sin audio, 1920×1080, escena de una sola pasada SIN bucle) se reproduce una vez y se queda en el último fotograma.
 */
function ns_hero_video(string $name = 'hero'): array
{
    $out = [];
    if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
        return $out;
    }
    foreach (['webm', 'mp4'] as $ext) {
        if (is_file(NS_ROOT . "/assets/video/$name.$ext")) {
            $out[$ext] = asset("video/$name.$ext");
        }
    }
    return $out;
}

/** URL del video del equipo (DESK_VIDEO_URL de config.local.php) o ''. */
function ns_video_src(): string
{
    return defined('DESK_VIDEO_URL') ? trim((string) DESK_VIDEO_URL) : '';
}

/** true si la fachada del video puede mostrarse: hay video real, o estamos en staging (revisión de diseño). En producción sin video se omiten fachada, play, duración y capítulos. */
function ns_video_ready(): bool
{
    return ns_video_src() !== '' || !is_production();
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
            ['Dry van dispatch', 'Dry van freight booked and followed by one desk', '/dry-van-dispatch/', 'star', null],
            ['Power only dispatch', 'Power only loads booked, trailer details confirmed', '/power-only-dispatch/', 'star', null],
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
            ['Careers', '', '/careers/', null, null],
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


/**
 * Miniatura (foto 3:2 de Night Freight) de una ruta destino, para filas y tarjetas de recurso/artículo/servicio: [base, alt] o null.
 * Los artículos y recursos leen su foto de app/data/posts.php; las páginas de servicio, de su hero. Un mismo `$exclude` (bases ya usadas en la página) evita repetir foto dentro de una página:
 * si la foto de la ruta ya está usada, se devuelve la alternativa de `$alt` (['/ruta/' => ['base','alt']]) o null.
 */
function ns_thumb(string $path, array $exclude = [], array $alt = []): ?array
{
    static $svc = [
        '/amazon-relay-dispatch/'            => ['nf-hero-amazon-relay', 'Aerial view of a white semi truck with a dry van trailer driving toward a distribution warehouse with a row of loading docks at sunset'],
        '/dispatch-for-owner-operators/'     => ['nf-hero-owner-operators', 'Inside a truck cab at sunrise, with a phone mounted on the dashboard and an empty highway ahead'],
        '/trucking-back-office/'             => ['nf-hero-back-office', 'Wooden desk in warm window light with an open binder of papers, reading glasses, a pen, a mug and a closed laptop, and a hand resting on the page'],
        '/load-tracking/'                    => ['nf-check-tracking-mirror', 'Truck side mirror at sunset reflecting a white trailer and the highway behind it'],
        '/how-it-works/'                     => ['nf-hero-how-it-works', 'Aerial view of white semi trucks on a highway interchange with looping ramps at sunset'],
        '/dry-van-dispatch/'                 => ['nf-hero-dry-van', 'Open dry van trailer at a loading dock with pallets of boxed cargo, a pallet jack and a worker in a safety vest in golden light'],
        '/blog/'                             => ['nf-faq-dock-light-wide', 'White trailer backed into a dock door on a warehouse wall, with yellow bollards and painted parking lines'],
        '/resources/ifta-calculator/'        => ['nf-resource-ifta', 'An open road atlas with a receipt, a pen and a calculator on a pale desk in soft window light'],
        '/resources/dispatcher-carrier-agreement/' => ['nf-resource-agreement', 'A two-page document with a pen on a wooden desk beside a lamp, a plant and a mug, with a hand resting on the desk'],
    ];
    $found = null;
    foreach (data('posts') as $p) {
        if (($p['path'] ?? '') === $path && !empty($p['image']['base'])) {
            $found = [(string) $p['image']['base'], (string) ($p['image']['alt'] ?? '')];
            break;
        }
    }
    $found = $found ?? ($svc[$path] ?? null);
    if ($found !== null && in_array($found[0], $exclude, true)) {
        $found = $alt[$path] ?? null;
    }
    return $found;
}

/**
 * "Related reading": filas-tarjeta (partial row-card) con enlaces internos contextuales, sin H2 nuevo (la sección lleva aria-label). Solo imprime las rutas live.
 * $items: [['path' => '/blog/…/', 'title' => '…', 'text' => '…', 'chips' => ['Article']], …]. La foto sale de ns_thumb() sin repetirse dentro del bloque.
 */
function ns_read_next(array $items, string $label = 'Related reading'): void
{
    $items = array_values(array_filter($items, static fn(array $i): bool => route_is_live((string) $i['path'])));
    if (!$items) {
        return;
    }
    echo '<section class="section" aria-label="' . e($label) . '"><div class="container"><div class="rlist">';
    $used = [];
    foreach ($items as $i) {
        $t = ns_thumb((string) $i['path'], $used);
        if ($t) {
            $used[] = $t[0];
        }
        partial('row-card', [
            'href'  => url((string) $i['path']),
            'title' => (string) $i['title'],
            'text'  => (string) ($i['text'] ?? ''),
            'chips' => (array) ($i['chips'] ?? []),
            'dark'  => !empty($i['dark']),
            'image' => $t ? ['base' => $t[0], 'alt' => $t[1]] : null,
        ]);
    }
    echo '</div></div></section>';
}

/** Lista de comprobación con checks circulares cian (panel con foto, patrón B). $items: textos ya afirmados en el sitio. */
function ns_checks(array $items, string $label = ''): string
{
    $out = '<ul class="checks"' . ($label !== '' ? ' aria-label="' . e($label) . '"' : '') . '>';
    foreach ($items as $it) {
        $out .= '<li class="checks__item"><span class="checks__dot" aria-hidden="true">' . ns_icon('check', 14) . '</span><span>' . e((string) $it) . '</span></li>';
    }
    return $out . '</ul>';
}
