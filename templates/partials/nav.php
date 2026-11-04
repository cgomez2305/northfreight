<?php
/**
 * Barra de navegación principal (dentro de <header class="header">; el layout añade megas y menú móvil).
 * Params: route(array|null) para aria-current; wa(string|null) clave/mensaje de WhatsApp.
 * Hooks JS: button[data-mega-trigger="services|resources"][aria-controls][aria-expanded],
 *           button[data-menu-open] (aria-controls="mobile-menu"). Sin JS: enlace #footer-nav (solo ≤1024px, scripting:none).
 */
require_once __DIR__ . '/kit.php';
$route = $route ?? null;
$cur = (string) ($route['path'] ?? '');
$wa = $wa ?? ($route['wa'] ?? null);
$m = ns_menu();
$hasServices = $m['dispatch'] || $m['ops'];
$hasResources = cfg('show_resources') && ($m['tools'] || $m['templates'] || $m['learn']);
?>
<nav class="nav" aria-label="Main">
  <?= ns_logo() ?>
  <div class="nav__links">
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
  <div class="nav__actions">
    <a class="btn btn--primary btn--sm hide-m" href="<?= e(wa($wa)) ?>" rel="noopener">WhatsApp us</a>
    <button type="button" class="nav__burger" data-menu-open aria-label="Open menu" aria-expanded="false" aria-controls="mobile-menu"><?= ns_icon('menu', 20, 'icon--open') ?><?= ns_icon('close', 20, 'icon--close') ?></button>
    <a class="nav__burger nav__burger--link" href="#footer-nav" aria-label="Go to the site menu"><?= ns_icon('menu', 20) ?></a>
  </div>
</nav>
