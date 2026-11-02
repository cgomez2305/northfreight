<?php
/**
 * Layout del sitio. Contrato: recibe $route, $cfg y $content (HTML de la página ya renderizado). No imprime H1
 * (lo pone cada página, normalmente con partial('hero')).
 *
 * Claves de ruta que lee: title, meta, path, template, status, schema, wa, og, motion, css (grupos de CSS; anula el mapa plantilla => grupo), cta (false => sin bloque CTA final).
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

// JS (solo si el archivo existe). main.js en todo; módulos por plantilla; motion solo con $route['motion'].
$scripts = [];
$jsByTemplate = ['tool-rpm' => 'calc-rpm.js', 'tool-ifta' => 'calc-ifta.js', 'blog' => 'blog-filter.js', 'home' => 'video.js', 'desk' => 'video.js'];
$jsFile = static fn(string $f): bool => is_file(NS_ROOT . '/assets/js/' . $f);
$gsap = !empty($route['motion']) && $jsFile('motion.js');
if ($gsap && is_file(NS_ROOT . '/assets/css/motion.css')) {
    $css[] = 'css/motion.css'; // reglas que necesitan las animaciones (solo bajo html.js-motion)
}
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
<link rel="preload" href="/assets/fonts/saira-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/dm-sans-var.woff2" as="font" type="font/woff2" crossorigin>
<?php foreach ($css as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
<?php /*XHARNESS*/ $X = explode(',', $_GET['x'] ?? ''); foreach ($X as $x) { if (preg_match('/^c-[a-z0-9]+$/', $x) && is_file($f = '/tmp/claude-0/-home-user-northfreight/c07eb0c9-b69d-57c0-985f-fd1d15f47e46/scratchpad/perf/x-' . $x . '.css')) { echo '<style>' . file_get_contents($f) . '</style>' . "\n"; } } /*/XHARNESS*/ ?>
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
<?php if (in_array('nojs', $X)) { $gsap = false; $jsFile = static fn(string $f): bool => false; $jsByTemplate = []; } /*XH*/ ?>
<?php if ($jsFile('main.js')): ?>
<script src="<?= e(asset('js/main.js')) ?>" defer></script>
<?php endif; ?>
<?php if (isset($jsByTemplate[$template]) && $jsFile($jsByTemplate[$template])): ?>
<script src="<?= e(asset('js/' . $jsByTemplate[$template])) ?>" defer></script>
<?php endif; ?>
<?php if ($gsap && in_array('lazy', $X)): ?>
<script>
(function(){var L=["https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js","https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js","<?= e(asset('js/split.js')) ?>","<?= e(asset('js/motion.js')) ?>"];
function go(){L.forEach(function(u){var s=document.createElement('script');s.src=u;s.async=false;document.body.appendChild(s);});}
function idle(){(window.requestIdleCallback||function(f){setTimeout(f,200)})(go,{timeout:1500});}
if(document.readyState==='complete')idle();else addEventListener('load',idle);})();
</script>
<?php elseif ($gsap): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js" defer></script>
<?php if ($jsFile('split.js')): ?>
<script src="<?= e(asset('js/split.js')) ?>" defer></script>
<?php endif; ?>
<script src="<?= e(asset('js/motion.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
