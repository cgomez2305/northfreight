<?php
/**
 * Panel mega menú Resources (oculto por defecto; lo abre JS). Free tools · Templates · Learn · tarjeta cian de descarga del BOL.
 * Solo se pinta si SHOW_RESOURCES. Hook JS: #mega-resources[data-mega="resources"].
 */
require_once __DIR__ . '/kit.php';
if (!cfg('show_resources')) {
    return;
}
$m = ns_menu();
$cols = [['Free tools', $m['tools']], ['Templates', $m['templates']], ['Learn', $m['learn']]];
?>
<div id="mega-resources" class="mega" data-mega="resources" aria-label="Resources" hidden>
  <div class="mega__in mega__in--resources">
    <?php foreach ($cols as [$label, $items]): if (!$items) { continue; } ?>
    <div class="mega__col">
      <span class="mega__label t-meta"><?= e($label) ?></span>
      <?php foreach ($items as $i): ?>
      <a class="mega__link" href="<?= e(url($i[2])) ?>"><?= ns_icon($i[3], 16) ?><span class="mega__text"><strong class="mega__title"><?= e($i[0]) ?></strong><span class="mega__desc"><?= e($i[1]) ?></span></span></a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php if (route_is_live('/resources/bol-template/')): ?>
    <a class="mega__promo" href="<?= e(url('/resources/bol-template/')) ?>">
      <span class="t-meta">Free download</span>
      <span class="head mega__promo-title">BOL template ready to print</span>
      <span class="mega__promo-text">The same bill of lading our desk files on every load.</span>
      <span class="mega__promo-cta">Download free <span class="arrow" aria-hidden="true">→</span></span>
    </a>
    <?php endif; ?>
  </div>
</div>
