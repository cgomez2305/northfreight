<?php
/**
 * Banda de imagen con slot de video: banda ancha (radio 32, 21:9 en >=761px) con una foto y una tarjeta de texto encima (copy ya existente, sin H2).
 * Hoy es una foto fija (Night Freight). El slot de video sigue listo: si se pasa `video` y existen assets/video/<video>.mp4 (el póster es la foto `image`),
 * con >=1025px main.js monta el video (muted, loop, playsinline, preload none) cuando la banda se acerca a la pantalla (tras load + idle) y lo pausa al salir;
 * sin JS, en móvil, con prefers-reduced-motion o saveData (o si el video falla) queda la foto. Sin el mp4 no se emite <video> ni data-vband: cero peticiones extra.
 * motion.js hace un parallax ligero de la capa .vband__layer con scrub (no toca el bucle del video).
 * La tarjeta de texto va sobre un fondo sólido casi opaco (--glass-strong): contraste AA medido. En <761px va debajo de la imagen.
 * Params: image(array ['base','alt']: foto 3:2, variantes en assets/img/<base>-{ancho}.webp), text(string con marcas), video(string opcional: assets/video/<video>.mp4).
 */
require_once __DIR__ . '/kit.php';
$image = is_array($image ?? null) && !empty($image['base']) ? $image : null;
if ($image === null) {
    return;
}
$video = (string) ($video ?? '');
$hasVideo = preg_match('/^[a-z0-9-]+$/', $video) === 1 && is_file(NS_ROOT . "/assets/video/$video.mp4");
?>
<div class="vband">
  <figure class="vband__media"<?= $hasVideo ? ' data-vband data-mp4="' . e(asset("video/$video.mp4")) . '"' : '' ?>>
    <div class="vband__layer">
      <?= ns_img(['base' => (string) $image['base'], 'alt' => (string) ($image['alt'] ?? ''), 'class' => 'vband__poster', 'sizes' => '(min-width:761px) 100vw, calc(100vw - 72px)']) ?>
      <?php if ($hasVideo): ?><video class="vband__video" muted loop playsinline preload="none" aria-hidden="true" tabindex="-1"></video><?php endif; ?>
    </div>
    <figcaption class="vband__card"><p class="vband__text"><?= ns_rich($text ?? '') ?></p></figcaption>
  </figure>
</div>
