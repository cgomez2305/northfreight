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
 *  image   array   ['base'=>'red-semi-dry-van-open-road','alt'=>'…','ratio'=>(sin uso),'small'=>bool (máx. 490px de ancho: fotos de 490px),'sizes'=>'…'] foto a la derecha (radio 24; en móvil debajo del texto)
 *  aside   string  HTML ya renderizado (tarjeta de datos / vista previa de documento); va tras la imagen si hay ambas
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
$stack = !empty($stack) || (!$image && $aside === '');
$ctas = $ctas ?? [];
?>
<section class="hero hero--int on-dark<?= $stack ? ' hero--stack' : '' ?><?= !empty($cls) ? ' ' . e($cls) : '' ?>">
  <div class="container hero__in">
    <div class="hero__main">
      <?php if (isset($crumbs)): partial('breadcrumbs', ['crumbs' => $crumbs]); elseif (isset($route)): partial('breadcrumbs', ['route' => $route]); endif; ?>
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
    <?php if ($image || $aside !== ''): ?>
    <div class="hero__aside">
      <?php if ($image): ?>
      <figure class="hero__photo px<?= !empty($image['small']) ? ' hero__photo--s' : '' ?>"><?= ns_img(['base' => $image['base'], 'alt' => $image['alt'] ?? '', 'eager' => true, 'sizes' => $image['sizes'] ?? '(min-width:1025px) 700px, calc(124vw - 100px)']) ?></figure>
      <?php endif; ?>
      <?= $aside ?>
    </div>
    <?php endif; ?>
  </div>
</section>
