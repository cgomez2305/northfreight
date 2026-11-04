<?php
/**
 * Tarjeta redondeada (24px). Variantes: light (defecto, fondo panel), dark (destacada: panel-2 con borde cian fino), link (toda la tarjeta es <a>, con hover).
 * Params:
 *  variant 'light'|'dark'|'link'   size 'default'|'lg'|'tile'   (lg = padding fluido de home; tile = radio pequeño)
 *  tag     string  etiqueta eyebrow ("A · Load board")      code  string  etiqueta mono ("LOG · 01")
 *  num     string  número grande ("01")                     icon  string  nombre de ns_icon()  | icon_html string (SVG propio)
 *  title   string  H3 (marcas permitidas)                   text  string  (marcas permitidas)
 *  href    string  destino (variant link, o enlace inferior)   cta string  texto del enlace inferior ("Load tracking")
 *  image   array   ['base','alt','sizes'?] foto arriba (radio 24, 4:3) y el texto debajo, sin caja (tarjeta de foto)
 *  px      bool    false => la foto no entra en el parallax vertical genérico (.px); defecto true (el carril de la home la mueve en horizontal)
 *  hover   bool    elevación al pasar el ratón sin ser enlace
 *  h       string  etiqueta del título ('h3' defecto)    cls string clase extra   min_title bool (min-height 2 líneas)
 *  wrap    bool    envoltorio .card-wrap (defecto true)
 * Nota: dentro de variant link no hay enlaces anidados (el texto cta se pinta como span).
 */
require_once __DIR__ . '/kit.php';
$variant = $variant ?? 'light';
$size = $size ?? 'default';
$isLink = $variant === 'link' && !empty($href);
$image = !empty($image) && is_array($image) ? $image : null;
$el = $isLink ? 'a' : 'article';
$h = in_array($h ?? 'h3', ['h2', 'h3', 'h4', 'p'], true) ? ($h ?? 'h3') : 'h3';
$cls = 'card' . ($variant === 'dark' ? ' card--dark' : '') . ($size === 'lg' ? ' card--lg' : '') . ($size === 'tile' ? ' card--tile' : '') . ($image ? ' card--photo' : '')
    . (!empty($hover) ? ' card--hover' : '') . (!empty($cls) ? ' ' . $cls : '');
$wrap = $wrap ?? true;
$hasTop = !empty($icon) || !empty($icon_html) || !empty($num);
?>
<?php if ($wrap): ?><div class="card-wrap"><?php endif; ?>
<<?= $el ?> class="<?= e($cls) ?>"<?= $isLink ? ' href="' . e($href) . '"' : '' ?>>
  <?php if ($image): ?><figure class="card__photo<?= ($px ?? true) ? ' px' : '' ?>"><?= ns_img(['base' => $image['base'], 'alt' => $image['alt'] ?? '', 'sizes' => $image['sizes'] ?? '(min-width:1025px) 500px, (min-width:641px) 42vw, calc(100vw - 72px)']) ?></figure><?php endif; ?>
  <?php if ($hasTop && (!empty($icon) || !empty($icon_html))): ?>
  <div class="card__top">
    <span class="card__icon" aria-hidden="true"><?= !empty($icon_html) ? $icon_html : ns_icon($icon, 26) ?></span>
    <?php if (!empty($num)): ?><span class="card__num" aria-hidden="true"><?= e($num) ?></span><?php endif; ?>
  </div>
  <?php elseif (!empty($num)): ?><span class="card__num" aria-hidden="true"><?= e($num) ?></span><?php endif; ?>
  <?php if (!empty($tag) && !empty($code)): ?><div class="card__meta"><span class="card__tag t-meta"><?= ns_rich($tag) ?></span><span class="card__code"><?= ns_rich($code) ?></span></div>
  <?php elseif (!empty($tag)): ?><span class="card__tag t-meta"><?= ns_rich($tag) ?></span>
  <?php elseif (!empty($code)): ?><span class="card__code"><?= ns_rich($code) ?></span><?php endif; ?>
  <?php if (!empty($title)): ?><<?= $h ?> class="head t-h3 card__title<?= $size === 'tile' ? ' card__title--s' : '' ?><?= !empty($min_title) ? ' h2-min' : '' ?>"><?= ns_rich($title) ?></<?= $h ?>><?php endif; ?>
  <?php if (!empty($text)): ?><p class="card__text t-card"><?= ns_rich($text) ?></p><?php endif; ?>
  <?php if (!empty($cta)): ?>
    <?php if ($isLink): ?><span class="card__link">
    <?php else: ?><a class="card__link" href="<?= e($href ?? '#') ?>"><?php endif; ?>
    <?= e($cta) ?> <span class="arrow" aria-hidden="true">→</span><?= $isLink ? '</span>' : '</a>' ?>
  <?php endif; ?>
</<?= $el ?>>
<?php if ($wrap): ?></div><?php endif; ?>
