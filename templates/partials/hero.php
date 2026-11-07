<?php
/**
 * Hero interno: panel redondeado (migas → eyebrow → H1 → tagline → lead → CTAs → columna derecha opcional).
 * Imprime el ÚNICO <h1> de la página. Textos con marcas: {hl}palabra clave{/hl} (cian) y {br}; {{ph:X}} => ph().
 *
 * Params (todos opcionales salvo h1/route):
 *  route   array   ruta actual (migas automáticas y h1 por defecto = $route['h1'])
 *  crumbs  array   [['label','path'],…] si no se pasa route
 *  h1      string  "Dispatch services for {hl}owner-operators{/hl}"
 *  h1_tag  string  'h1' (defecto) | 'p' => imprime el titular como <p> con la misma clase (para páginas cuyo H1 va en otro sitio)
 *  size    string  'h1' (defecto) | 'h1-long' (artículos y legales)
 *  eyebrow string  etiqueta sobre el H1 (con estrella)
 *  tagline string  frase corta bajo el titular
 *  lead    string  entrada
 *  ctas    array   [['label','href','variant'=>'primary|ghost','icon'=>'arrow|whatsapp|null','arrow'=>bool (→ de texto, solo en ghost: los primarios ya llevan la celda de flecha ↗ por CSS)],…]
 *  image   array   ['base'=>'nf-hero-owner-operators','alt'=>'…','pos'=>'70% 50%' (object-position), 'sizes'=>'…' (ancho útil del cover: max(ancho del marco, alto de la foto ≈ 704 px × proporción); las fotos 16:9 o más anchas necesitan sizes mayor y, en móvil, el marco 4:3 manda por el alto)] PATRÓN A (hero fotográfico): la foto es el marco del hero (ancho del contenedor, radio 32), anclada a la derecha
 *                  del panel con `.px`; el texto va abajo a la izquierda sobre degradado (--photo-fade-*) y las migas arriba como chip. En <1025px la foto va arriba y el texto debajo, sin superponer.
 *  image.pos_d  string  (opcional) object-position solo de >=1025 px (foto y video de escritorio); sin él vale `pos`
 *  video   string  (opcional, con image) nombre de assets/video/<video>.mp4: video de fondo de una sola pasada, sin bucle, solo >=1025 px (main.js lo monta tras load + idle, con fundido sobre la foto; ver [data-bgvideo]).
 *                  El póster de escritorio es `<image.base>-vid` (primer fotograma EXACTO del video, 640 a 1920); <1025 px y sin video se ve la foto de siempre. Sin el mp4 no se emite <video>.
 *  aside   string  HTML ya renderizado. Con `image` es una tarjeta de vidrio abajo a la derecha (solo copy ya afirmado: ficha de equipo, puntos del resumen); sin `image`, columna derecha como siempre (Desk log, vista previa de documento)
 *  bg      string  HTML ya renderizado que sustituye a la foto (la fachada del video en Our desk: el marco ya lleva su <picture> y el botón de play)
 *  stack   bool    true => sin columna derecha, H1 hasta 980px
 *  cls     string  clase extra en <section>
 */
require_once __DIR__ . '/kit.php';
$h1 = $h1 ?? (string) ($route['h1'] ?? '');
$h1html = ns_rich($h1);
$size = ($size ?? 'h1') === 'h1-long' ? 't-h1-long' : 't-h1';
$h1tag = ($h1_tag ?? 'h1') === 'p' ? 'p' : 'h1';
$image = $image ?? null;
$aside = $aside ?? '';
$bg = (string) ($bg ?? '');
$photo = !empty($image['base']) || $bg !== '';
$stack = !$photo && (!empty($stack) || $aside === '');
$ctas = $ctas ?? [];
$video = (string) ($video ?? '');
$hasVideo = $photo && !empty($image['base']) && preg_match('/^[a-z0-9-]+$/', $video) === 1 && is_file(NS_ROOT . "/assets/video/$video.mp4");
$vidPoster = $hasVideo && is_file(NS_ROOT . '/assets/img/' . $image['base'] . '-vid-640.webp') ? (string) $image['base'] . '-vid' : '';
?>
<section class="hero hero--int on-dark<?= $photo ? ' hero--photo' : '' ?><?= $stack ? ' hero--stack' : '' ?><?= !empty($cls) ? ' ' . e($cls) : '' ?>">
  <?php if ($bg !== ''): ?><?= $bg ?>
  <?php elseif ($photo): ?><div class="hero__pic px"<?php $st = (!empty($image['pos']) ? '--pos:' . $image['pos'] . ';' : '') . (!empty($image['pos_d']) ? '--pos-d:' . $image['pos_d'] . ';' : ''); ?><?= $st !== '' ? ' style="' . e($st) . '"' : '' ?><?= $hasVideo ? ' data-bgvideo data-mp4="' . e(asset("video/$video.mp4")) . '"' : '' ?>><?= ns_img(['base' => $image['base'], 'alt' => $image['alt'] ?? '', 'eager' => true, 'sizes' => $image['sizes'] ?? '(min-width:1025px) 1160px, (min-width:761px) 100vw, (min-width:641px) calc(100vw - 72px), 65vw',
      'art' => $vidPoster !== '' ? [['media' => '(min-width:1025px)', 'base' => $vidPoster, 'sizes' => '1252px']] : []]) ?><?php if ($hasVideo): ?><video class="hero__video" muted playsinline preload="none" aria-hidden="true" tabindex="-1"></video><?php endif; ?></div><?php endif; ?>
  <div class="container hero__in">
    <?php if ($photo): ?><div class="hero__crumbs"><?php if (isset($crumbs)): partial('breadcrumbs', ['crumbs' => $crumbs]); elseif (isset($route)): partial('breadcrumbs', ['route' => $route]); endif; ?></div><?php endif; ?>
    <div class="hero__main">
      <?php if (!$photo): if (isset($crumbs)): partial('breadcrumbs', ['crumbs' => $crumbs]); elseif (isset($route)): partial('breadcrumbs', ['route' => $route]); endif; endif; ?>
      <?php if (!empty($eyebrow)): ?><p class="hero__eyebrow t-eyebrow"><span><?= ns_rich($eyebrow) ?></span></p><?php endif; ?>
      <<?= $h1tag ?> class="hero__title <?= $size ?>"><?= $h1html ?></<?= $h1tag ?>>
      <?php if (!empty($tagline)): ?><p class="t-tagline"><?= ns_rich($tagline) ?></p><?php endif; ?>
      <?php if (!empty($lead)): ?><p class="hero__lead t-lead"><?= ns_rich($lead) ?></p><?php endif; ?>
      <?php if ($ctas): ?>
      <div class="hero__ctas">
        <?php foreach ($ctas as $c): $v = $c['variant'] ?? 'primary'; ?>
        <a class="btn btn--<?= e($v) ?>" href="<?= e($c['href']) ?>"<?= !empty($c['wa']) ? ' rel="noopener"' : '' ?>><?php if (!empty($c['arrow']) && $v !== 'primary'): ?><span><?= e($c['label']) ?> <span class="arrow" aria-hidden="true">→</span></span><?php else: ?><?= e($c['label']) ?><?php endif; ?><?php if (!empty($c['icon'])) { echo ns_icon($c['icon']); } ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($aside !== ''): ?>
    <div class="hero__aside"><?= $aside ?></div>
    <?php endif; ?>
  </div>
</section>
