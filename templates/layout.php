<?php
/**
 * STUB del layout — lo reemplaza el coder del layout (nav, footer, CSS, OG, fuentes…).
 * Contrato: recibe $route, $cfg y $content (HTML de la página ya renderizado). No contiene H1.
 */
?><!doctype html>
<html lang="en-US">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($route['title']) ?></title>
<meta name="description" content="<?= e($route['meta']) ?>">
<?php if (($route['status'] ?? '') !== 'error'): ?>
<link rel="canonical" href="<?= e(canonical($route)) ?>">
<?php endif; ?>
<?php if (noindex($route)): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?php schema_json(page_schema($route)); ?>
</head>
<body>
<main id="main">
<?= $content ?>
</main>
</body>
</html>
