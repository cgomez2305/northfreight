<?php
/**
 * Índice con vista previa: lista de enlaces reales a ancho completo (número mono, título condensado, frase, flecha) y, en >=1025px, una foto grande (radio 24) a la derecha que cambia por fundido
 * de opacidad (250 ms, sin mover el layout) al pasar el cursor por una fila o enfocarla con el teclado. main.js (`[data-idx]`) marca la fila activa y atenúa las demás (.is-live); sin JS la primera fila y su foto
 * quedan activas y nada se atenúa. En <1025px solo la lista (la vista previa no se pinta ni se descarga). Las fotos son decorativas (aria-hidden): el enlace ya dice a dónde va.
 * Params: items(array de ['n'=>string,'title','text','href'(string|null: sin enlace la fila es un div),'image'=>['base','pos'?]]), label(string, aria-label de la lista), sizes(string).
 */
require_once __DIR__ . '/kit.php';
$items = array_values(array_filter((array) ($items ?? []), static fn($it): bool => is_array($it) && !empty($it['title'])));
if (!$items) {
    return;
}
$sizes = (string) ($sizes ?? '(min-width:1025px) 880px, 0px');
?>
<div class="idx" data-idx>
  <ol class="idx__list"<?= !empty($label) ? ' aria-label="' . e((string) $label) . '"' : '' ?>>
    <?php foreach ($items as $i => $it): $href = (string) ($it['href'] ?? ''); $tag = $href !== '' ? 'a href="' . e($href) . '"' : 'div'; ?>
    <li class="idx__item<?= $i === 0 ? ' is-on' : '' ?>">
      <<?= $tag ?> class="idx__row">
        <span class="idx__n t-meta"><?= e((string) ($it['n'] ?? sprintf('%02d', $i + 1))) ?></span>
        <span class="head idx__title"><?= e((string) $it['title']) ?></span>
        <span class="idx__text"><?= e((string) ($it['text'] ?? '')) ?></span>
        <span class="idx__go" aria-hidden="true"><span class="arrow">→</span></span>
      </<?= $href !== '' ? 'a' : 'div' ?>>
    </li>
    <?php endforeach; ?>
  </ol>
  <div class="idx__stage" aria-hidden="true">
    <?php foreach ($items as $i => $it): if (empty($it['image']['base'])) { continue; } ?>
    <figure class="idx__pv<?= $i === 0 ? ' is-on' : '' ?>"<?= !empty($it['image']['pos']) ? ' style="--pos:' . e((string) $it['image']['pos']) . '"' : '' ?>><?= ns_img(['base' => (string) $it['image']['base'], 'alt' => '', 'sizes' => $sizes]) ?></figure>
    <?php endforeach; ?>
  </div>
</div>
