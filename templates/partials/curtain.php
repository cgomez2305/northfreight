<?php
/**
 * Cortina fotográfica: una foto grande (ancho del contenedor, radio 32) que se queda fijada mientras la sección SIGUIENTE (un panel opaco con la clase `curtain-next`) sube y la tapa.
 * Sin texto sobre la foto. La imagen interior se desplaza con scrub (motion.js, escena `curtainScene`, 1 ScrollTrigger).
 * Solo con html.js-motion, >=1025px y >=700px de alto el wrapper es alto y el hijo queda `sticky`; en móvil, sin JS o con movimiento reducido es una foto grande normal (2:1, 4:3 en móvil) y la sección siguiente va debajo sin solaparla.
 * Poner `curtain-next` en la <section> que sigue (debe ser un panel con fondo propio, p. ej. .home-relay o .section--surface); motion.js la excluye de la entrada de bandas para que el solape sea limpio.
 * Params: image(array ['base','alt','pos'?,'sizes'?], requerido; foto v2 que no se repita en la página); short(bool, defecto false: foto fijada de hasta 520 px en vez de toda la ventana, para paneles siguientes cortos; motion.css `.curtain--short`).
 */
require_once __DIR__ . '/kit.php';
$image = is_array($image ?? null) ? $image : [];
if (empty($image['base'])) {
    return;
}
?>
<div class="curtain<?= !empty($short) ? ' curtain--short' : '' ?>" data-curtain>
  <div class="container curtain__pin">
    <figure class="curtain__frame"<?= !empty($image['pos']) ? ' style="--pos:' . e((string) $image['pos']) . '"' : '' ?>><?= ns_img(['base' => (string) $image['base'], 'alt' => (string) ($image['alt'] ?? ''),
        'sizes' => $image['sizes'] ?? '(min-width:1281px) 1280px, (min-width:761px) calc(100vw - 96px), calc(100vw - 40px)']) ?></figure>
  </div>
</div>
