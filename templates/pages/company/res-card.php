<?php
/**
 * Tarjeta de recurso de la maqueta (hub /resources/ y "relacionados" de las plantillas de documento).
 * Se incluye dentro de un bucle con $rc = ['path','code','title','text','dark'?,'icon'? (nombre de ns_icon()) | 'icon_svg'? (SVG propio)].
 * Solo se pinta si la ruta está live (route_is_live()). Foto 3:2 arriba (Night Freight) según la ruta destino (ns_thumb() de kit.php); sin entrada en el mapa, la tarjeta va sin foto.
 */
if (!route_is_live($rc['path'])) {
    return;
}
$rcDark = !empty($rc['dark']);
require_once NS_ROOT . '/templates/partials/kit.php';
$rcImg = ns_thumb($rc['path']);
?>
<a class="card co-res<?= $rcImg ? ' co-res--photo' : '' ?><?= $rcDark ? ' card--dark' : '' ?>" href="<?= e(url($rc['path'])) ?>">
  <?php if ($rcImg): ?><figure class="co-res__photo"><?= ns_img(['base' => $rcImg[0], 'alt' => $rcImg[1], 'sizes' => $rcSizes ?? '(min-width:1025px) 560px, (min-width:641px) 50vw, calc(100vw - 72px)']) ?></figure><?php endif; ?>
  <span class="co-res__top"><?php if (!empty($rc['icon_svg'])): ?><?= $rc['icon_svg'] ?><?php elseif (!empty($rc['icon'])): ?><?= ns_icon($rc['icon'], 28) ?><?php endif; ?><span class="card__code"><?= e($rc['code']) ?></span></span>
  <h3 class="head t-h3 card__title"><?= e($rc['title']) ?></h3>
  <p class="card__text t-card"><?= e($rc['text']) ?></p>
  <span class="card__link">Open <span class="arrow" aria-hidden="true">→</span></span>
</a>
