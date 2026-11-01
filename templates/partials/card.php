<?php
/**
 * Tarjeta con chaflán. Variantes: light (defecto, fondo surface), dark (navy), link (toda la tarjeta es <a>, con barra y hover).
 * Params:
 *  variant 'light'|'dark'|'link'   size 'default'|'lg'|'tile'   (lg = padding fluido de home; tile = chaflán pequeño)
 *  tag     string  etiqueta eyebrow ("A · Load board")      code  string  etiqueta mono ("LOG · 01")
 *  num     string  número grande ("01")                     icon  string  nombre de ns_icon()  | icon_html string (SVG propio)
 *  title   string  H3 (marcas permitidas)                   text  string  (marcas permitidas)
 *  href    string  destino (variant link, o enlace inferior)   cta string  texto del enlace inferior ("Load tracking")
 *  bar     bool    barra superior animada (defecto: true en link, false en el resto)
 *  hover   bool    elevación al pasar el ratón sin ser enlace
 *  h       string  etiqueta del título ('h3' defecto)    cls string clase extra   min_title bool (min-height 2 líneas)
 * Nota: dentro de variant link no hay enlaces anidados (el texto cta se pinta como span).
 */
require_once __DIR__ . '/kit.php';
$variant = $variant ?? 'light';
$size = $size ?? 'default';
$isLink = $variant === 'link' && !empty($href);
$bar = $bar ?? $isLink;
$el = $isLink ? 'a' : 'article';
$h = in_array($h ?? 'h3', ['h2', 'h3', 'h4', 'p'], true) ? ($h ?? 'h3') : 'h3';
$cls = 'card' . ($variant === 'dark' ? ' card--dark' : '') . ($size === 'lg' ? ' card--lg' : '') . ($size === 'tile' ? ' card--tile' : '')
    . (!empty($hover) ? ' card--hover' : '') . (!empty($cls) ? ' ' . $cls : '');
$hasTop = !empty($icon) || !empty($icon_html) || !empty($num);
?>
<<?= $el ?> class="<?= e($cls) ?>"<?= $isLink ? ' href="' . e($href) . '"' : '' ?>>
  <?php if ($bar): ?><span class="card__bar" aria-hidden="true"></span><?php endif; ?>
  <?php if ($hasTop && (!empty($icon) || !empty($icon_html))): ?>
  <div class="card__top">
    <span class="card__icon" aria-hidden="true"><?= !empty($icon_html) ? $icon_html : ns_icon($icon, 26) ?></span>
    <?php if (!empty($num)): ?><span class="card__num" aria-hidden="true"><?= e($num) ?></span><?php endif; ?>
  </div>
  <?php elseif (!empty($num)): ?><span class="card__num" aria-hidden="true"><?= e($num) ?></span><?php endif; ?>
  <?php if (!empty($tag)): ?><span class="card__tag t-meta"><?= ns_rich($tag) ?></span><?php endif; ?>
  <?php if (!empty($code)): ?><span class="card__code"><?= ns_rich($code) ?></span><?php endif; ?>
  <?php if (!empty($title)): ?><<?= $h ?> class="head t-h3 card__title<?= $size === 'tile' ? ' card__title--s' : '' ?><?= !empty($min_title) ? ' h2-min' : '' ?>"><?= ns_rich($title) ?></<?= $h ?>><?php endif; ?>
  <?php if (!empty($text)): ?><p class="card__text t-card"><?= ns_rich($text) ?></p><?php endif; ?>
  <?php if (!empty($cta)): ?>
    <?php if ($isLink): ?><span class="card__link">
    <?php else: ?><a class="card__link" href="<?= e($href ?? '#') ?>"><?php endif; ?>
    <?= e($cta) ?> <span class="arrow" aria-hidden="true">→</span><?= $isLink ? '</span>' : '</a>' ?>
  <?php endif; ?>
</<?= $el ?>>
