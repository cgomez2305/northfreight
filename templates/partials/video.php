<?php
/**
 * Video con fachada: imagen + botón play centrado (único control sobre imagen permitido), pie DEBAJO y capítulos.
 * No hay iframe hasta el clic (lo hace video.js).
 * Params:
 *  image   array   ['base'=>'nf-dispatch-room-wide','alt'=>'…']  (portada)
 *  title   string  pie: "Meet the desk {hl}· 3 chapters{/hl}"           meta string  "Video · 3–4 min"
 *  chapters array  [['title','desc'?,'t'=>'0:00'|null,'href'?], …]  t=null => ph('0:00') (pendiente); con href es <a>, sin href <button>
 *  chapters_label string ("Jump to a chapter")
 *  src     string  URL del video real (data-video-src); por defecto DESK_VIDEO_URL. En ENV=production sin URL el partial imprime solo la foto (sin play, pie ni capítulos)
 *  theme   'dark' (defecto, sobre navy) | 'light'
 *  part    'all' (defecto: fachada + pie + capítulos) | 'player' (solo fachada+pie) | 'chapters' (solo la lista, p. ej. en otra columna)
 *  id      string  id del contenedor (defecto "video"); enlaza capítulos y reproductor aunque estén en columnas distintas
 *  cls     string  clase extra en el contenedor .video (patrones: `pcard__photo` = foto ancha del patrón B, `hero__pic` = marco del hero fotográfico)
 *  foot    bool    false => sin pie (título + meta) debajo de la fachada (en el hero fotográfico el título va en la tarjeta de vidrio)
 *  pos     string  object-position de la portada ("70% 50%")  ·  sizes string  atributo sizes de la portada
 * Hooks JS: [data-video] (contenedor; con part=chapters lleva data-video-for="<id>") · button.video__play[data-video-play]
 *           · botones .chapter[data-t="m:ss"][aria-pressed] dentro de .video__chapters · estado: .is-active + aria-pressed="true".
 *           El reproductor se monta dentro de .video__frame.
 */
require_once __DIR__ . '/kit.php';
$part = $part ?? 'all';
$theme = ($theme ?? 'dark') === 'light' ? ' video--light' : '';
$id = $id ?? 'video';
$chapters = $chapters ?? [];
$chapters_label = $chapters_label ?? 'Jump to a chapter';
$image = $image ?? null;
$src = $src ?? ns_video_src();
$ready = ns_video_ready();   // producción sin video real: foto sola, sin play, sin pie (título y duración) y sin capítulos
$showPlayer = in_array($part, ['all', 'player'], true) && $image;
$showChapters = in_array($part, ['all', 'chapters'], true) && $chapters && $ready;
$chaptersHtml = static function () use ($chapters, $chapters_label, $id): void { ?>
  <div class="video__chapters" role="group" aria-label="<?= e($chapters_label) ?>">
    <span class="video__chapters-label t-meta"><?= e($chapters_label) ?></span>
    <?php foreach ($chapters as $n => $c):
      $time = $c['t'] ?? null;
      $tag = !empty($c['href']) ? 'a' : 'button'; ?>
    <<?= $tag ?> class="chapter"<?= $tag === 'a' ? ' href="' . e($c['href']) . '"' : ' type="button" data-t="' . e((string) $time) . '" aria-pressed="false"' ?>>
      <span class="chapter__time"><?= $time !== null && $time !== '' ? e($time) : ph('0:00') ?></span>
      <span class="chapter__body"><strong class="chapter__title"><?= e($c['title']) ?></strong><?php if (!empty($c['desc'])): ?><span class="chapter__desc"><?= e($c['desc']) ?></span><?php endif; ?></span>
    </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
<?php };
?>
<div class="video<?= $theme ?><?= !empty($cls) ? ' ' . e($cls) : '' ?>"<?= !empty($pos) ? ' style="--pos:' . e($pos) . '"' : '' ?> data-video id="<?= e($part === 'chapters' ? $id . '-chapters' : $id) ?>"<?= $src !== '' ? ' data-video-src="' . e($src) . '"' : '' ?><?= $part === 'chapters' ? ' data-video-for="' . e($id) . '"' : '' ?>>
  <?php if ($showChapters && $part === 'chapters') { $chaptersHtml(); } ?>
  <?php if ($showPlayer): ?>
  <div class="video__frame px">
    <?= ns_img(['base' => $image['base'], 'alt' => $image['alt'] ?? '', 'sizes' => $sizes ?? '(min-width:1025px) 700px, (min-width:641px) calc(100vw - 72px), calc(80vw - 32px)']) ?>
    <?php if ($ready): ?><button type="button" class="video__play" data-video-play aria-label="Play the video"><?= ns_icon('play', 28) ?></button><?php endif; ?>
  </div>
  <?php if (($foot ?? true) !== false && $ready): ?>
  <div class="video__foot">
    <p class="head video__title"><?= ns_rich($title ?? '') ?></p>
    <?php if (!empty($meta)): ?><span class="video__meta"><?= e($meta) ?></span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <?php if ($showChapters && $part === 'all'): ?><div class="video__after"><?php $chaptersHtml(); ?></div><?php endif; ?>
</div>
