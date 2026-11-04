<?php
/**
 * Patrón B · foto ancha con tarjeta solapada (sustituye a "texto | foto pequeña" en una caja). Foto 16:9 (radio 32, `.px`) y, solapándola por abajo, una tarjeta --panel con eyebrow, H2 y texto.
 * CSS: components.css (.pcard__*). motion.js: la tarjeta sube con desfase respecto a la foto (scrub, solo transform). <1025px: foto y tarjeta apiladas con un solape pequeño.
 * Params:
 *  id, eyebrow, title, lead   cabecera de sección (partial section-head; el H2 lleva el id para aria-labelledby)
 *  image   array  ['base','alt','sizes'?,'pos'?] foto (3:2, 4:3 o 16:9; se recorta con cover). `sizes` = ancho útil del marco (por defecto 1152px en escritorio)
 *  link    array|null  ['label','href'] botón pill de vidrio dentro de la foto (solo si la sección ya tiene ese enlace)
 *  flip    bool   tarjeta a la derecha (cuando el sujeto de la foto está a la izquierda)
 *  video   array|null  ['id','title','meta'] la foto es la fachada de un video: se imprime con el partial video (botón de play) y el pie (título + meta) queda como tarjeta de vidrio dentro de la foto
 *  extra   string HTML ya renderizado que va al final de la tarjeta  ·  after string HTML ya renderizado que va DEBAJO de la composición (p. ej. los capítulos del video, en 3 columnas)
 *  cls     string clase extra en <section>
 */
require_once __DIR__ . '/kit.php';
$image = is_array($image ?? null) ? $image : [];
$link = is_array($link ?? null) && !empty($link['href']) ? $link : null;
$video = is_array($video ?? null) ? $video : null;
$sizes = $image['sizes'] ?? '(min-width:1025px) 1152px, (min-width:761px) 100vw, 100vw';
?>
<section class="section pcard<?= !empty($cls) ? ' ' . e($cls) : '' ?>"<?= !empty($id) ? ' aria-labelledby="' . e($id) . '"' : '' ?>>
  <div class="container">
    <div class="pcard__in<?= !empty($flip) ? ' pcard--flip' : '' ?>">
      <?php if ($video): partial('video', ['part' => 'player', 'id' => $video['id'], 'image' => ['base' => $image['base'], 'alt' => $image['alt'] ?? ''], 'title' => $video['title'], 'meta' => $video['meta'] ?? '', 'cls' => 'pcard__photo', 'sizes' => $sizes, 'pos' => $image['pos'] ?? '']);
      else: ?>
      <figure class="pcard__photo px"<?= !empty($image['pos']) ? ' style="--pos:' . e($image['pos']) . '"' : '' ?>>
        <?= ns_img(['base' => (string) $image['base'], 'alt' => (string) ($image['alt'] ?? ''), 'sizes' => $sizes]) ?>
        <?php if ($link): ?><a class="pcard__pill" href="<?= e($link['href']) ?>"><?= e($link['label']) ?> <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
      </figure>
      <?php endif; ?>
      <div class="pcard__card">
        <?php partial('section-head', ['id' => $id ?? '', 'eyebrow' => $eyebrow ?? '', 'title' => $title ?? '', 'lead' => $lead ?? '']); ?>
        <?= $extra ?? '' ?>
      </div>
    </div>
    <?php if (!empty($after)): ?><div class="pcard__after"><?= $after ?></div><?php endif; ?>
  </div>
</section>
