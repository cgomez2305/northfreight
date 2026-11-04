<?php
/**
 * Banda de video: banda ancha (radio 32, 21:9 en >=761px) con un video en bucle y una tarjeta de texto encima (copy ya existente, sin H2).
 * Sin JS, en móvil (<1025px), con prefers-reduced-motion o saveData: solo el póster estático (primer fotograma del bucle, WebP 1920 con variantes 640 y 1280).
 * Con >=1025px main.js monta el video (muted, loop, playsinline, preload none) cuando la banda se acerca a la pantalla (tras load + idle) y lo pausa al salir; si falla queda el póster.
 * motion.js hace un parallax ligero de la capa .vband__layer (póster y video juntos) con scrub, sin tocar el bucle.
 * La tarjeta de texto va sobre un fondo sólido casi opaco (--glass-strong): contraste AA medido sobre el peor fotograma. En <761px va debajo de la imagen.
 * Params: video(string: assets/video/<video>.mp4 y <video>-poster-{640,1280,1920}.webp), text(string con marcas), alt(string del póster). Si falta el mp4 o el póster no pinta nada.
 */
require_once __DIR__ . '/kit.php';
$video = (string) ($video ?? '');
if (preg_match('/^[a-z0-9-]+$/', $video) !== 1 || !is_file(NS_ROOT . "/assets/video/$video.mp4") || !is_file(NS_ROOT . "/assets/video/$video-poster-1920.webp")) {
    return;
}
$g = @getimagesize(NS_ROOT . "/assets/video/$video-poster-1920.webp");
$set = [];
foreach ([640, 1280, 1920] as $pw) {
    if (is_file(NS_ROOT . "/assets/video/$video-poster-$pw.webp")) {
        $set[] = asset("video/$video-poster-$pw.webp") . " {$pw}w";
    }
}
// Ancho útil del póster: en escritorio la capa mide ~110 % del alto del marco 21:9 (cover por ancho, ~100vw); en móvil es 4:3 y manda el alto
?>
<div class="vband">
  <figure class="vband__media" data-vband data-mp4="<?= e(asset("video/$video.mp4")) ?>">
    <div class="vband__layer">
      <img class="vband__poster" src="<?= e(asset("video/$video-poster-1920.webp")) ?>" srcset="<?= e(implode(', ', $set)) ?>" sizes="(min-width:761px) 100vw, 320px" alt="<?= e($alt ?? '') ?>"<?= $g ? ' width="' . $g[0] . '" height="' . $g[1] . '"' : '' ?> loading="lazy" decoding="async">
      <video class="vband__video" muted loop playsinline preload="none" aria-hidden="true" tabindex="-1"></video>
    </div>
    <figcaption class="vband__card"><p class="vband__text"><?= ns_rich($text ?? '') ?></p></figcaption>
  </figure>
</div>
