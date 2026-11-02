<?php
/**
 * Botón flotante de WhatsApp (todas las pantallas). Esquina inferior derecha; ≤760px sube por encima de la barra móvil.
 * Se oculta con el pop-up del menú abierto (CSS body:has(.mobile-menu:not([hidden])) o body.menu-open).
 * El contenedor lleva la sombra (drop-shadow) y el enlace el chaflán: clip-path recortaría una sombra propia.
 * Params: wa(string|null) clave/mensaje de WhatsApp de la ruta.
 */
require_once __DIR__ . '/kit.php';
$wa = $wa ?? null;
?>
<div class="wa-float">
  <a class="wa-float__btn" href="<?= e(wa($wa)) ?>" rel="noopener" aria-label="Chat with a dispatcher on WhatsApp"><?= ns_icon('whatsapp', 26) ?><span class="wa-float__label">WhatsApp</span></a>
</div>
