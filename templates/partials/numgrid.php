<?php
/**
 * Rejilla numerada con filete y círculo de flecha (sin fondo de tarjeta). Cada celda: filete superior fino, número mono, título condensado, texto y un círculo de flecha ≥44 px
 * que es un enlace extendido (toda la celda se puede pulsar; el nombre accesible lo da `label`). Con 5 elementos se añade la 6.ª celda `cta` para que ninguna fila quede huérfana.
 * CSS: components.css (.ngrid*). Es una `.g3`, así que conserva la entrada 3D de las rejillas (motion.js gridScene): 3 columnas en ≥1025px, 2 hasta 641px y 1 en móvil.
 * Params:
 *  items  array  [['title'=>…,'text'=>…,'href'=>url,'label'=>'Ask the desk about … (nombre accesible del enlace)','n'?=>'01'], …]  (sin href: la celda no lleva círculo)
 *  cta    array|null  ['title'=>'Talk to the desk','text'=>…,'href'=>…,'label'=>…] 6.ª celda destacada (círculo cian relleno)
 *  label  string  aria-label de la lista       cls  string  clase extra
 */
require_once __DIR__ . '/kit.php';
$items = $items ?? [];
$cta = is_array($cta ?? null) ? $cta : null;
if (!$items) {
    return;
}
?>
<ol class="ngrid g3<?= !empty($cls) ? ' ' . e($cls) : '' ?>"<?= !empty($label) ? ' aria-label="' . e($label) . '"' : '' ?>>
  <?php foreach ($items as $i => $it): ?>
  <li class="ngrid__item">
    <span class="ngrid__n" aria-hidden="true"><?= e((string) ($it['n'] ?? sprintf('%02d', $i + 1))) ?></span>
    <h3 class="head ngrid__title"><?= e((string) $it['title']) ?></h3>
    <?php if (!empty($it['text'])): ?><p class="ngrid__text"><?= e((string) $it['text']) ?></p><?php endif; ?>
    <?php if (!empty($it['href'])): ?><a class="ngrid__go" href="<?= e((string) $it['href']) ?>" rel="noopener" aria-label="<?= e((string) ($it['label'] ?? $it['title'])) ?>"><span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
  </li>
  <?php endforeach; ?>
  <?php if ($cta): ?>
  <li class="ngrid__item ngrid__item--cta">
    <span class="ngrid__n" aria-hidden="true">+</span>
    <h3 class="head ngrid__title"><?= e((string) $cta['title']) ?></h3>
    <?php if (!empty($cta['text'])): ?><p class="ngrid__text"><?= e((string) $cta['text']) ?></p><?php endif; ?>
    <a class="ngrid__go" href="<?= e((string) $cta['href']) ?>" rel="noopener" aria-label="<?= e((string) ($cta['label'] ?? $cta['title'])) ?>"><span class="arrow" aria-hidden="true">→</span></a>
  </li>
  <?php endif; ?>
</ol>
