<?php
/**
 * Tarjeta de recurso de la maqueta (hub /resources/ y "relacionados" de las plantillas de documento).
 * Se incluye dentro de un bucle con $rc = ['path','code','title','text','dark'?,'icon'? (nombre de ns_icon()) | 'icon_svg'? (SVG propio)].
 * Solo se pinta si la ruta está live (route_is_live()). Foto 3:2 arriba (Night Freight) según la ruta destino ($resPhotos); sin entrada en el mapa, la tarjeta va sin foto.
 */
$resPhotos = [
    '/resources/rate-per-mile-calculator/'      => ['nf-calculator-map-dashboard', 'Calculator and route map on a truck dashboard at dusk'],
    '/resources/ifta-calculator/'               => ['nf-fuel-nozzle-truck-stop', 'Fuel nozzle at a truck stop at night with a semi truck parked behind'],
    '/resources/bol-template/'                  => ['nf-shipping-form-clipboard', 'Clipboard with a blank shipping form under a cool light'],
    '/resources/dispatcher-carrier-agreement/'  => ['nf-contracts-amber-cyan', 'Open binder with a pen and paperwork under an amber lamp'],
    '/resources/glossary/'                      => ['nf-cards-fan-key-fob', 'Fan of blank cards beside a notebook and a truck key fob'],
    '/blog/amazon-relay-requirements/'          => ['nf-clipboard-on-truck-hood', 'Checklist on a clipboard resting on a truck hood at a night dock'],
    '/blog/amazon-relay-load-board/'            => ['nf-tablet-cab-night-yard', 'Tablet mounted in a truck cab at night looking over a yard of trailers'],
    '/blog/'                                    => ['nf-loading-dock-blue-hour', 'Open loading dock door glowing cyan under a blue-hour sky'],
    '/blog/lumper-fees-detention-pay/'          => ['nf-dock-waiting-clock', 'Wall clock beside a loading dock door at night as a trailer waits'],
    '/blog/how-much-does-truck-dispatch-cost/'  => ['nf-vintage-calculator-night-road', 'Vintage desk calculator beside a notepad and a clock, with a truck on a night road behind'],
    '/load-tracking/'                           => ['nf-highway-side-mirror-night', 'Night highway with trucks seen in a truck’s side mirror'],
    '/how-it-works/'                            => ['nf-route-map-night-trucks', 'Glowing route lines over a dark map with trucks on a night highway'],
];
if (!route_is_live($rc['path'])) {
    return;
}
$rcDark = !empty($rc['dark']);
$rcImg = $resPhotos[$rc['path']] ?? null;
?>
<a class="card co-res<?= $rcImg ? ' co-res--photo' : '' ?><?= $rcDark ? ' card--dark' : '' ?>" href="<?= e(url($rc['path'])) ?>">
  <?php if ($rcImg): ?><figure class="co-res__photo"><?= ns_img(['base' => $rcImg[0], 'alt' => $rcImg[1], 'sizes' => $rcSizes ?? '(min-width:1025px) 560px, (min-width:641px) 50vw, calc(100vw - 72px)']) ?></figure><?php endif; ?>
  <span class="co-res__top"><?php if (!empty($rc['icon_svg'])): ?><?= $rc['icon_svg'] ?><?php elseif (!empty($rc['icon'])): ?><?= ns_icon($rc['icon'], 28) ?><?php endif; ?><span class="card__code"><?= e($rc['code']) ?></span></span>
  <h3 class="head t-h3 card__title"><?= e($rc['title']) ?></h3>
  <p class="card__text t-card"><?= e($rc['text']) ?></p>
  <span class="card__link">Open <span class="arrow" aria-hidden="true">→</span></span>
</a>
