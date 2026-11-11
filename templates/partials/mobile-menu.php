<?php
/**
 * Pop-up de navegación móvil (≤1024px). role=dialog aria-modal=true, hidden por defecto.
 * Params: wa(string|null) mensaje de WhatsApp.
 * Hooks JS: #mobile-menu (quitar/poner `hidden`), [data-menu-close] (backdrop y botón cerrar),
 *           enlaces .mm-link/.mm-main cierran al clic. El panel es .mobile-menu__panel; foco inicial: [data-menu-close].
 * Mientras no esté `hidden`, la mbar se oculta por CSS (body:has(.mobile-menu:not([hidden])) o body.menu-open).
 * Sin JS este panel no se abre: en ≤1024px aparece un enlace al footer (#footer-nav) que contiene todos los enlaces.
 */
require_once __DIR__ . '/kit.php';
$wa = $wa ?? null;
$route = $route ?? null;
$m = ns_menu();
$services = array_merge($m['dispatch'], $m['ops']);
$resources = array_merge($m['tools'], $m['templates'], $m['learn']);
$showRes = cfg('show_resources') && $resources;
?>
<div id="mobile-menu" class="mobile-menu" role="dialog" aria-modal="true" aria-label="Site menu" hidden>
  <button type="button" class="mobile-menu__backdrop" data-menu-close aria-label="Close menu"></button>
  <div class="mobile-menu__panel">
    <div class="mobile-menu__head">
      <?= ns_logo() ?>
      <button type="button" class="btn mobile-menu__close" data-menu-close aria-label="Close menu"><?= ns_icon('close', 20) ?></button>
    </div>
    <nav class="mobile-menu__nav" aria-label="Mobile">
      <?php if ($services): ?>
      <details class="acc" open>
        <summary class="mm-head">Services <span class="acc__plus" aria-hidden="true">+</span></summary>
        <div class="acc__list">
          <?php foreach ($services as $i): ?><a class="mm-link" href="<?= e(url($i[2])) ?>"><?= e($i[0]) ?></a><?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
      <?php if ($showRes): ?>
      <details class="acc">
        <summary class="mm-head">Resources <span class="acc__plus" aria-hidden="true">+</span></summary>
        <div class="acc__list">
          <?php foreach ($resources as $i): ?><a class="mm-link" href="<?= e(url($i[2])) ?>"><?= e($i[0]) ?></a><?php endforeach; ?>
          <?php if (route_is_live('/resources/')): ?><a class="mm-link mm-link--all" href="<?= e(url('/resources/')) ?>">All resources <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
        </div>
      </details>
      <?php endif; ?>
      <?php foreach ($m['main'] as $i): ?><a class="mm-main" href="<?= e(url($i[2])) ?>"><?= e($i[0]) ?></a><?php endforeach; ?>
    </nav>
    <div class="mobile-menu__foot">
      <?php partial('lang-switch', ['route' => $route ?? null, 'variant' => 'menu']); ?>
      <span class="t-meta mobile-menu__label">Talk to the desk</span>
      <a class="btn btn--primary btn--between" href="<?= e(wa($wa)) ?>" rel="noopener"><span class="btn__t">WhatsApp a dispatcher</span></a>
      <a class="btn btn--ghost btn--card btn--between" href="<?= e(tel()) ?>">Call <?= e(cfg('phone')) ?></a>
      <a class="mobile-menu__mail" href="mailto:<?= e((string) cfg('email')) ?>"><?= e((string) cfg('email')) ?></a>
    </div>
  </div>
</div>
