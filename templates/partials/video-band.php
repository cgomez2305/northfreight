<?php
/**
 * Banda de video: banda ancha (radio 32, 21:9 en >=761px) con un video en bucle y una tarjeta de texto encima (copy ya existente, sin H2).
 * Sin JS, en móvil (<1025px), con prefers-reduced-motion o saveData: solo el póster estático (WebP 1280 del primer fotograma).
 * Con >=1025px main.js monta el video (muted, loop, playsinline, preload none) cuando la banda se acerca a la pantalla (tras load + idle) y lo pausa al salir; si falla queda el póster.
 * motion.js hace un parallax ligero de la capa .vband__layer (póster y video juntos) con scrub, sin tocar el bucle.
 * La tarjeta de texto va sobre un fondo sólido casi opaco (--glass-strong): contraste AA medido sobre el peor fotograma. En <761px va debajo de la imagen.
 * Params: video(string: assets/video/<video>.mp4 y <video>-poster.webp), text(string con marcas), alt(string del póster). Si falta el mp4 o el póster no pinta nada.
 */
require_once __DIR__ . '/kit.php';
$video = (string) ($video ?? '');
if (preg_match('/^[a-z0-9-]+$/', $video) !== 1 || !is_file(NS_ROOT . "/assets/video/$video.mp4") || !is_file(NS_ROOT . "/assets/video/$video-poster.webp")) {
    return;
}
$poster = NS_ROOT . "/assets/video/$video-poster.webp";
$g = @getimagesize($poster);
?>
<div class="vband">
  <figure class="vband__media" data-vband data-mp4="<?= e(asset("video/$video.mp4")) ?>">
    <div class="vband__layer">
      <img class="vband__poster" src="<?= e(asset("video/$video-poster.webp")) ?>" alt="<?= e($alt ?? '') ?>"<?= $g ? ' width="' . $g[0] . '" height="' . $g[1] . '"' : '' ?> loading="lazy" decoding="async">
      <video class="vband__video" muted loop playsinline preload="none" aria-hidden="true" tabindex="-1"></video>
    </div>
    <figcaption class="vband__card"><p class="vband__text"><?= ns_rich($text ?? '') ?></p></figcaption>
  </figure>
</div>
