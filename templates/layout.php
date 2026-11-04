<?php
/**
 * Layout del sitio. Contrato: recibe $route, $cfg y $content (HTML de la página ya renderizado). No imprime H1
 * (lo pone cada página, normalmente con partial('hero')).
 *
 * Claves de ruta que lee: title, meta, path, template, status, schema, wa, og, motion (GSAP + ScrollTrigger + motion.js/css), css (grupos de CSS; anula el mapa plantilla => grupo), cta (false => sin bloque CTA final), cta_image ('base' de assets/img | '' | false: foto de fondo del CTA; por defecto CTA_IMAGE).
 * Orden: head (SEO, OG, fuentes, CSS, JSON-LD) → skip link → header (tres islas: logo · nav · acciones; megas y menú móvil) →
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

// CSS: núcleo común (fuentes, tokens, base, components) + el grupo de página de la plantilla (assets/css/pages/<grupo>.css) + motion.css si la ruta carga GSAP.
// La lista (y el mapa plantilla => grupo, anulable por ruta con 'css' => ['grupo', …]) vive en app/assets.php. Si el build (bin/build-assets.php) dejó un
// archivo dist vigente para esa lista, se enlaza UNO solo; si no coincide con las fuentes, se enlazan los archivos sueltos como siempre.
$css = ns_css_sources($route);
$cssDist = ns_dist_css($css);

// Movimiento: solo rutas con 'motion' => true cargan GSAP 3.12.5 + ScrollTrigger (cdnjs, versión fija con SRI) y motion.js/motion.css.
// Todo lo que necesita html.js-motion lo añade motion.js solo si GSAP existe y no hay prefers-reduced-motion.
$jsFile = static fn(string $f): bool => is_file(NS_ROOT . '/assets/js/' . $f);
// URL de un JS propio: la versión minificada del build (assets/js/dist/*.min.js) si el manifiesto está al día con la fuente; si no, el archivo original.
$jsUrl = static fn(string $f): string => ns_dist_js($f) ?? asset('js/' . $f);
$gsap = !empty($route['motion']) && $jsFile('motion.js');
// JS (solo si el archivo existe). main.js en todo; módulos por plantilla.
$scripts = [];
$jsByTemplate = ['tool-rpm' => 'calc-rpm.js', 'tool-ifta' => 'calc-ifta.js', 'blog' => 'blog-filter.js', 'home' => 'video.js', 'desk' => 'video.js'];
// La ruta puede anular el mapa con 'js' => ['calc-rpm.js', …] (módulos que solo necesita esa página, p. ej. la mini calculadora de /resources/ y de dos artículos).
$jsList = isset($route['js']) ? (array) $route['js'] : (isset($jsByTemplate[$template]) ? [$jsByTemplate[$template]] : []);
$jsList = array_values(array_filter($jsList, static fn($j): bool => is_string($j) && preg_match('/^[a-z0-9-]+\.js$/', $j) === 1 && $jsFile($j)));
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
<meta name="robots" content="<?= is_production() && ($route['status'] ?? 'live') === 'live' && ($route['gate'] ?? '') === '' ? 'noindex, follow' : 'noindex, nofollow' ?>">
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
<?php if ($cssDist !== null): ?>
<link rel="stylesheet" href="<?= e($cssDist['url']) ?>">
<?php else: foreach ($css as $c): ?>
<link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; endif; ?>
<?php schema_json(page_schema($route)); ?>
</head>
<body<?= $ctaOn ? '' : ' class="no-cta"' ?>>
<a class="skip-link" href="#main">Skip to content</a>
<div class="site-top on-dark">
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
<?php if ($ctaOn): $ctaBase = array_key_exists('cta_image', $route) ? $route['cta_image'] : CTA_IMAGE; partial('cta', ['wa' => $wa, 'image' => $ctaBase ? ['base' => (string) $ctaBase, 'alt' => ''] : null]); endif; ?>
<?php partial('footer', ['wa' => $wa]); ?>
<?php partial('mbar', ['wa' => $wa]); ?>
<?php partial('wa-float', ['wa' => $wa]); ?>
<?php if ($jsFile('main.js')): ?>
<script src="<?= e($jsUrl('main.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($gsap): ?>
<script src="<?= e($jsUrl('motion.js')) ?>" data-gsap="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" data-gsap-sri="sha512-7eHRwcbYkK4d9g/6tD/mhkf++eoTHwpNM9woBxtPUBWm67zeAfFC+HrdoE2GanKeocly/VxeLvIqwvCdk7qScg==" data-st="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js" data-st-sri="sha512-onMTRKJBKz8M1TnqqDuGBlowlH0ohFzMXYRNebz+yOcc5TQr/zAKsthzhuv0hiyUKEiQEQXEynnXCvNTOk50dg==" defer></script>
<?php endif; ?>
<?php foreach ($jsList as $j): ?>
<script src="<?= e($jsUrl($j)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
