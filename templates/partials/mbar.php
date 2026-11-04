<?php
/**
 * Barra fija móvil (≤760px): "Talk to a dispatcher" (cian, flex 1) + llamada (cuadrado 56px). Botones en píldora.
 * Se oculta cuando el pop-up está abierto: CSS body:has(.mobile-menu:not([hidden])) o la clase body.menu-open (opcional, JS).
 * Params: wa(string|null).
 */
require_once __DIR__ . '/kit.php';
$wa = $wa ?? null;
?>
<div class="mbar" id="mbar">
  <a class="btn btn--primary mbar__main" href="<?= e(wa($wa)) ?>" rel="noopener">Talk to a dispatcher</a>
  <a class="btn mbar__call" href="<?= e(tel()) ?>" aria-label="Call <?= e(cfg('brand')) ?>"><?= ns_icon('phone') ?></a>
</div>
