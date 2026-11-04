<?php
/**
 * Patrón D · banda fotográfica (separadores de sección): foto ancha con el titular y la frase ya afirmada abajo a la izquierda sobre degradado y, a la derecha, chips (lista corta ya afirmada) o un botón pill de vidrio.
 * Misma construcción que el hero fotográfico (foto anclada a la derecha con máscara). <1025px: foto arriba y texto debajo, sin superponer. Sin `.px`: la banda no necesita parallax y así no se amplía la foto.
 * Params:
 *  id, eyebrow, title, lead   cabecera de sección (partial section-head)
 *  image  array  ['base','alt','pos'?,'sizes'?]
 *  chips  array  textos de los chips (lista corta ya afirmada en la página); `chips_end` (bool) pinta el último chip en cian; `chips_arrow` (bool) añade → entre chips (flujo)
 *  link   array|null ['label','href'] botón pill de vidrio
 *  cls    string clase extra en <section>
 */
require_once __DIR__ . '/kit.php';
$image = is_array($image ?? null) ? $image : [];
$chips = is_array($chips ?? null) ? $chips : [];
$link = is_array($link ?? null) && !empty($link['href']) ? $link : null;
?>
<section class="section pband-sec<?= !empty($cls) ? ' ' . e($cls) : '' ?>"<?= !empty($id) ? ' aria-labelledby="' . e($id) . '"' : '' ?>>
  <div class="container">
    <div class="pband">
      <figure class="pband__photo"<?= !empty($image['pos']) ? ' style="--pos:' . e($image['pos']) . '"' : '' ?>><?= ns_img(['base' => (string) $image['base'], 'alt' => (string) ($image['alt'] ?? ''), 'sizes' => $image['sizes'] ?? '(min-width:1025px) 1152px, (min-width:761px) 100vw, calc(100vw - 40px)']) ?></figure>
      <div class="pband__body">
        <?php partial('section-head', ['id' => $id ?? '', 'eyebrow' => $eyebrow ?? '', 'title' => $title ?? '', 'lead' => $lead ?? '']); ?>
        <?php if ($chips || $link): ?>
        <div class="pband__side">
          <?php if ($chips): ?>
          <ol class="pband__chips<?= !empty($chips_arrow) ? ' pband__chips--flow' : '' ?>" <?= !empty($chips_label) ? 'aria-label="' . e($chips_label) . '"' : '' ?>>
            <?php foreach ($chips as $n => $c): $end = !empty($chips_end) && $n === count($chips) - 1; ?>
            <li class="pband__chip<?= $end ? ' pband__chip--end' : '' ?>"><?php if (!empty($chips_arrow) && $n > 0): ?><span class="arrow" aria-hidden="true">→</span><?php endif; ?><?= ($end || !empty($chips_arrow)) ? '' : ns_star(14) ?><?= e((string) $c) ?></li>
            <?php endforeach; ?>
          </ol>
          <?php endif; ?>
          <?php if ($link): ?><a class="pcard__pill pband__pill" href="<?= e($link['href']) ?>"><?= e($link['label']) ?> <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
