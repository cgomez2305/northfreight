<?php
/**
 * Migas visibles con aria-label="Breadcrumb" (lista ordenada; la última lleva aria-current="page").
 * Params: route(array) — usa breadcrumbs($route) —  o crumbs(array de ['label','path']). Con <2 migas no pinta nada.
 * Se usa dentro de un fondo oscuro (hero). JSON-LD BreadcrumbList lo pone el layout.
 */
require_once __DIR__ . '/kit.php';
$crumbs = $crumbs ?? (isset($route) ? breadcrumbs($route) : []);
if (count($crumbs) < 2) {
    return;
}
$last = count($crumbs) - 1;
?>
<nav class="crumbs t-meta" aria-label="Breadcrumb">
  <ol>
    <?php foreach ($crumbs as $n => $c): ?>
    <li><?php if ($n === $last): ?><span aria-current="page"><?= e($c['label']) ?></span><?php else: ?><a href="<?= e(url($c['path'])) ?>"><?= e($c['label']) ?></a><span aria-hidden="true">/</span><?php endif; ?></li>
    <?php endforeach; ?>
  </ol>
</nav>
