<?php
/**
 * Cabecera en "tres islas" (dentro de <header class="header">; el layout añade megas y menú móvil):
 *   1. isla del logo · 2. isla de navegación (<nav>, solo ≥1025px, con la pastilla que se desliza entre ítems) · 3. isla de acciones
 *      (chip "On shift · ET hh:mm" si SHOW_ON_SHIFT, botón WhatsApp, menú móvil). ≤1024px quedan dos islas: logo y menú.
 * Params: route(array|null) para aria-current; wa(string|null) clave/mensaje de WhatsApp.
 * Hooks JS: .nav__links (+ span.nav__pill) y sus button[data-mega-trigger="services|resources"][aria-controls][aria-expanded];
 *           button[data-menu-open] (aria-controls="mobile-menu"); span[data-t="et"] (reloj, lo rellena main.js).
 *           main.js pone .is-scrolled en el <header> pasados ~80px de scroll. Sin JS: cabecera completa y enlace #footer-nav (solo ≤1024px, scripting:none).
 */
require_once __DIR__ . '/kit.php';
$route = $route ?? null;
$cur = (string) ($route['path'] ?? '');
$wa = $wa ?? ($route['wa'] ?? null);
$m = ns_menu();
$hasServices = $m['dispatch'] || $m['ops'];
$hasResources = cfg('show_resources') && ($m['tools'] || $m['templates'] || $m['learn']);
?>
<div class="hdr">
  <div class="hdr__island hdr__brand"><?= ns_logo() ?></div>
  <nav class="nav hdr__island" aria-label="Main">
    <div class="nav__links">
      <span class="nav__pill" aria-hidden="true"></span>
      <?php if ($hasServices): ?>
      <button type="button" class="nav__trigger" data-mega-trigger="services" aria-expanded="false" aria-controls="mega-services">Services <?= ns_icon('chevron', 12) ?></button>
      <?php endif; ?>
      <?php if ($hasResources): ?>
      <button type="button" class="nav__trigger" data-mega-trigger="resources" aria-expanded="false" aria-controls="mega-resources">Resources <?= ns_icon('chevron', 12) ?></button>
      <?php endif; ?>
      <?php foreach ($m['main'] as $i): ?>
      <a class="nav__link" href="<?= e(url($i[2])) ?>"<?= $cur === $i[2] ? ' aria-current="page"' : '' ?>><?= e($i[0]) ?></a>
      <?php endforeach; ?>
    </div>
  </nav>
  <div class="hdr__island hdr__act">
    <?php if (cfg('show_on_shift')): ?>
    <p class="hdr__desk"><span class="hdr__dot" aria-hidden="true"></span><span class="hdr__on"><span class="sr-only">Dispatch desk </span>On shift</span><span class="hdr__clk">ET <strong data-t="et">--:--</strong></span></p>
    <?php endif; ?>
    <a class="btn btn--primary btn--sm hide-m" href="<?= e(wa($wa)) ?>" rel="noopener"><span class="btn__t">WhatsApp us</span></a>
    <button type="button" class="nav__burger" data-menu-open aria-label="Open menu" aria-expanded="false" aria-controls="mobile-menu"><?= ns_icon('menu', 20, 'icon--open') ?><?= ns_icon('close', 20, 'icon--close') ?></button>
    <a class="nav__burger nav__burger--link" href="#footer-nav" aria-label="Go to the site menu"><?= ns_icon('menu', 20) ?></a>
  </div>
</div>
