<?php
/**
 * Layout del sitio. Contrato: recibe $route, $cfg y $content (HTML de la página ya renderizado). No imprime H1
 * (lo pone cada página, normalmente con partial('hero')).
 *
 * Claves de ruta que lee: title, meta, path, template, status, schema, wa, og, motion (GSAP + ScrollTrigger + motion.js/css), css (grupos de CSS; anula el mapa plantilla => grupo), cta (false => sin bloque CTA final).
 * Orden: head (SEO, OG, fuentes, CSS, JSON-LD) → skip link → opsbar + header (nav, megas, menú móvil) →
 *        <main id="main"> → cta → footer → mbar → scripts.
 */
require_once __DIR__ . '/partials/kit.php';

$ctaOn = ($route['cta'] ?? true) !== false;
$wa = $route['wa'] ?? null;
$isError = ($route['status'] ?? '') === 'error';
$template = (string) ($route['template'] ?? '');
$title = (string) ($route['title'] ?? cfg('brand'));
$desc = (string) ($route['meta'] ?? '');

// Imagen OG: la de la ruta/plantilla si existe; si no, assets/img/og-default.jpg
$ogOwn = isset($route['og']) || is_file(NS_ROOT . '/assets/img/og/' . $template . '.jpg');
$ogImage = $ogOwn ? og_image($route) : 'https://' . cfg('domain') . asset('img/og-default.jpg');

// CSS: núcleo común (fuentes, tokens, base, components) + el grupo de página de la plantilla (assets/css/pages/<grupo>.css).
// Mapa plantilla => grupo en un solo sitio; la ruta puede anularlo con 'css' => ['grupo', …] (nombres sin .css; [] = sin grupo).
$cssByTemplate = [
    'home' => 'home',
    'owner' => 'services-a', 'relay' => 'services-a', 'dryvan' => 'services-a', 'equipment' => 'services-a',
    'tracking' => 'services-b', 'backoffice' => 'services-b', 'how' => 'services-b', 'desk' => 'services-b', 'contact' => 'services-b',
    'legal' => 'company', '404' => 'company', 'resources' => 'company', 'doc-bol' => 'company', 'doc-agreement' => 'company',
    'glossary' => 'tools', 'tool-rpm' => 'tools', 'tool-ifta' => 'tools',
    'blog' => 'blog', 'post' => 'blog',
];
$cssGroups = isset($route['css']) ? (array) $route['css'] : (isset($cssByTemplate[$template]) ? [$cssByTemplate[$template]] : []);
$css = ['css/fonts.css', 'css/tokens.css', 'css/base.css', 'css/components.css'];
foreach ($cssGroups as $g) {
    $g = basename((string) $g, '.css');
    if (preg_match('/^[a-z0-9-]+$/', $g) === 1 && is_file(NS_ROOT . '/assets/css/pages/' . $g . '.css')) {
        $css[] = 'css/pages/' . $g . '.css';
    }
}
if (!empty($route['motion']) && is_file(NS_ROOT . '/assets/css/motion.css')) {
    $css[] = 'css/motion.css';   // reglas que necesitan las escenas (todas bajo html.js-motion)
}

// Movimiento: solo rutas con 'motion' => true cargan GSAP 3.12.5 + ScrollTrigger (cdnjs, versión fija con SRI) y motion.js/motion.css.
// Todo lo que necesita html.js-motion lo añade motion.js solo si GSAP existe y no hay prefers-reduced-motion.
$jsFile = static fn(string $f): bool => is_file(NS_ROOT . '/assets/js/' . $f);
$gsap = !empty($route['motion']) && $jsFile('motion.js');
// JS (solo si el archivo existe). main.js en todo; módulos por plantilla.
$scripts = [];
$jsByTemplate = ['tool-rpm' => 'calc-rpm.js', 'tool-ifta' => 'calc-ifta.js', 'blog' => 'blog-filter.js', 'home' => 'video.js', 'desk' => 'video.js'];
?><!doctype html>
<html lang="en-US">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if (!$isError): ?>
<link rel="canonical" href="<?= e(canonical($route)) ?>">
<?php endif; ?>
<?php if (noindex($route)): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta property="og:type" content="<?= $template === 'post' ? 'article' : 'website' ?>">
<meta property="og:site_name" content="<?= e(cfg('brand')) ?>">
<meta property="og:locale" content="en_US">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<?php if (!$isError): ?>
<meta property="og:url" content="<?= e(canonical($route)) ?>">
<?php endif; ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" type="image/png" sizes="180x180">
<link rel="apple-touch-icon" href="<?= e(asset('img/favicon.png')) ?>">
<link rel="preload" href="/assets/fonts/barlow-condensed-900.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/barlow-400.woff2" as="font" type="font/woff2" crossorigin>
<?php if ($gsap): ?>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<?php endif; ?>
<?php foreach ($css as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
<?php schema_json(page_schema($route)); ?>
</head>
<body<?= $ctaOn ? '' : ' class="no-cta"' ?>>
<a class="skip-link" href="#main">Skip to content</a>
<div class="site-top on-dark">
<?php partial('opsbar'); ?>
<header class="header" id="site-header">
<?php partial('nav', ['route' => $route, 'wa' => $wa]); ?>
<?php partial('mega-services'); ?>
<?php partial('mega-resources'); ?>
<?php partial('mobile-menu', ['wa' => $wa]); ?>
</header>
</div>
<main id="main"<?= $ctaOn ? ' class="has-cta"' : '' ?>>
<?= $content ?>
</main>
<?php if ($ctaOn): partial('cta', ['wa' => $wa]); endif; ?>
<?php partial('footer', ['wa' => $wa]); ?>
<?php partial('mbar', ['wa' => $wa]); ?>
<?php partial('wa-float', ['wa' => $wa]); ?>
<?php if ($jsFile('main.js')): ?>
<script src="<?= e(asset('js/main.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($gsap): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" integrity="sha512-7eHRwcbYkK4d9g/6tD/mhkf++eoTHwpNM9woBxtPUBWm67zeAfFC+HrdoE2GanKeocly/VxeLvIqwvCdk7qScg==" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js" integrity="sha512-onMTRKJBKz8M1TnqqDuGBlowlH0ohFzMXYRNebz+yOcc5TQr/zAKsthzhuv0hiyUKEiQEQXEynnXCvNTOk50dg==" crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
<script src="<?= e(asset('js/motion.js')) ?>" defer></script>
<?php endif; ?>
<?php if (isset($jsByTemplate[$template]) && $jsFile($jsByTemplate[$template])): ?>
<script src="<?= e(asset('js/' . $jsByTemplate[$template])) ?>" defer></script>
<?php endif; ?>
</body>
</html>
