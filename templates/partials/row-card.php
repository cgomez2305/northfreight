<?php
/**
 * Fila-tarjeta horizontal con miniatura 3:2, chips mono, título, una línea de texto y círculo de flecha. Un solo <a> por fila (toda la fila es el enlace).
 * Variante horizontal del partial card: ≥761px miniatura | cuerpo | flecha; en ≤760px la miniatura va arriba a todo el ancho (como la tarjeta con foto) y la flecha abajo a la derecha.
 * CSS: components.css (.rcard*).
 * Params:
 *  href    string  destino (url ya resuelta)
 *  title   string  título (h3 por defecto; `h`: 'h3'|'h4'|'p')       text  string  una línea
 *  chips   array   etiquetas mono ["Free", "Calculator"]
 *  image   array|null  ['base','alt','sizes'?] miniatura 3:2 (de ns_thumb())    dark bool  variante destacada (borde cian)
 *  size    string  'default' | 'sm' (fila compacta del bloque "From the desk")
 */
require_once __DIR__ . '/kit.php';
$image = !empty($image['base']) ? $image : null;
$chips = is_array($chips ?? null) ? $chips : [];
$h = in_array($h ?? 'h3', ['h3', 'h4', 'p'], true) ? ($h ?? 'h3') : 'h3';
$sm = ($size ?? 'default') === 'sm';
?>
<a class="rcard<?= !empty($dark) ? ' rcard--dark' : '' ?><?= $sm ? ' rcard--sm' : '' ?><?= $image ? '' : ' rcard--nophoto' ?>" href="<?= e((string) $href) ?>">
  <?php if ($image): ?><figure class="rcard__photo"><?= ns_img(['base' => (string) $image['base'], 'alt' => (string) ($image['alt'] ?? ''), 'sizes' => $image['sizes'] ?? ($sm ? '(min-width:761px) 160px, calc(100vw - 72px)' : '(min-width:1025px) 300px, (min-width:761px) 260px, calc(100vw - 72px)')]) ?></figure><?php endif; ?>
  <span class="rcard__body">
    <?php if ($chips): ?><span class="rcard__chips"><?php foreach ($chips as $c): ?><span class="rcard__chip"><?= e((string) $c) ?></span><?php endforeach; ?></span><?php endif; ?>
    <<?= $h ?> class="head rcard__title"><?= e((string) $title) ?></<?= $h ?>>
    <?php if (!empty($text)): ?><span class="rcard__text"><?= e((string) $text) ?></span><?php endif; ?>
  </span>
  <span class="rcard__go" aria-hidden="true"><span class="arrow">→</span></span>
</a>
