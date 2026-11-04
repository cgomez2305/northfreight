<?php
/**
 * Pasos numerados en columnas (sin tarjeta): filete fino, número mono grande, título condensado y texto. <ol> real; 1 columna en móvil, 2 en tablet y hasta 4 en escritorio.
 * Los títulos son <h3> por defecto (`h`: 'h3'|'p'). Sin imágenes ni escenas propias: entra con la rejilla genérica de motion.js si el contenedor es .g2/.g3 (aquí no lo es: solo CSS).
 * CSS: pages/careers.css (.crs*).
 * Params:
 *  items  array  [['title'=>…,'text'=>…], …] (marcas de ns_rich permitidas)
 *  label  string aria-label de la lista       h string etiqueta del título       cls string clase extra
 */
require_once __DIR__ . '/kit.php';
$items = $items ?? [];
if (!$items) {
    return;
}
$h = in_array($h ?? 'h3', ['h3', 'p'], true) ? ($h ?? 'h3') : 'h3';
?>
<ol class="crs crs--n<?= count($items) ?><?= !empty($cls) ? ' ' . e($cls) : '' ?>"<?= !empty($label) ? ' aria-label="' . e($label) . '"' : '' ?>>
  <?php foreach ($items as $i => $it): ?>
  <li class="crs__item">
    <span class="crs__n" aria-hidden="true"><?= e(sprintf('%02d', $i + 1)) ?></span>
    <<?= $h ?> class="head crs__title"><?= ns_rich((string) $it['title']) ?></<?= $h ?>>
    <?php if (!empty($it['text'])): ?><p class="crs__text"><?= ns_rich((string) $it['text']) ?></p><?php endif; ?>
  </li>
  <?php endforeach; ?>
</ol>
