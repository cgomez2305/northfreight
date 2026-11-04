<?php
/**
 * Triada de iconos sin tarjeta: tres columnas con icono de trazo en un cuadrado --r-tile, título condensado y dos líneas. Sin fondo ni borde de caja.
 * CSS: pages/services-b.css (.trio*). 1 columna en ≤640px. Con `href` la columna entera es un enlace (contacto); sin él es texto (how it works).
 * Los títulos son <p> y no encabezados: la triada va justo bajo el H1/resumen y un H3 sin H2 rompería el orden de encabezados; tampoco añade H2 nuevos a la página.
 * Params:
 *  items  array  [['icon'=>'route' (ns_icon),'title'=>…,'text'=>…,'href'?=>url,'cta'?=>'Open WhatsApp'], …]
 *  label  string aria-label de la sección     cls string clase extra
 */
require_once __DIR__ . '/kit.php';
$items = $items ?? [];
if (!$items) {
    return;
}
?>
<section class="section trio-sec<?= !empty($cls) ? ' ' . e($cls) : '' ?>"<?= !empty($label) ? ' aria-label="' . e($label) . '"' : '' ?>>
  <div class="container">
    <ul class="trio">
      <?php foreach ($items as $it): $href = !empty($it['href']) ? (string) $it['href'] : ''; $el = $href !== '' ? 'a' : 'div'; ?>
      <li class="trio__item">
        <<?= $el ?> class="trio__in"<?= $href !== '' ? ' href="' . e($href) . '"' . (str_starts_with($href, 'http') ? ' rel="noopener"' : '') : '' ?>>
          <span class="trio__icon" aria-hidden="true"><?= ns_icon((string) $it['icon'], 28) ?></span>
          <span class="head trio__title"><?= e((string) $it['title']) ?></span>
          <span class="trio__text"><?= e((string) $it['text']) ?></span>
          <?php if ($href !== '' && !empty($it['cta'])): ?><span class="trio__cta"><?= e((string) $it['cta']) ?> <span class="arrow" aria-hidden="true">→</span></span><?php endif; ?>
        </<?= $el ?>>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
