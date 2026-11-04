<?php
/**
 * Patrón C · filas de servicio apiladas con sticky. Lista de 3 o más elementos con foto: cada fila es una tarjeta-foto grande (foto de fondo anclada a la derecha, cuadrado de vidrio con icono arriba a la izquierda,
 * número mono, título condensado y texto abajo a la izquierda sobre degradado, círculo de flecha abajo a la derecha si la fila enlaza).
 * CSS: components.css (.prows/.prow__*). ≥1025px y ≥700px de alto con html.js-motion las filas se apilan (position: sticky, --i = índice) y motion.js escala y atenúa la que cubre la siguiente;
 * en móvil, tablet y sin JS es una lista normal (foto arriba y texto debajo). Usar SOLO con contenido real para 3 o más filas.
 * Params:
 *  items  array  [['n'=>'01','title'=>…,'text'=>…,'icon'=>'pin' (nombre de ns_icon),'image'=>['base','alt','pos'?,'sizes'?],'href'?=>url (la fila entera es un enlace),'tag'?=>'A · Load board' (sustituye al número)], …]
 *  tag    string 'h3' (defecto) | 'h2': nivel del título de cada fila
 *  label  string aria-label de la lista
 */
require_once __DIR__ . '/kit.php';
$items = $items ?? [];
$tag = ($tag ?? 'h3') === 'h2' ? 'h2' : 'h3';
if (!$items) {
    return;
}
?>
<ol class="prows"<?= !empty($label) ? ' aria-label="' . e($label) . '"' : '' ?>>
  <?php foreach ($items as $i => $it): $href = !empty($it['href']) ? (string) $it['href'] : ''; $img = $it['image'] ?? []; $el = $href !== '' ? 'a' : 'div'; ?>
  <li class="prow" style="--i:<?= (int) $i ?>">
    <<?= $el ?> class="prow__in"<?= $href !== '' ? ' href="' . e($href) . '"' : '' ?>>
      <figure class="prow__photo"<?= !empty($img['pos']) ? ' style="--pos:' . e($img['pos']) . '"' : '' ?>><?= ns_img(['base' => (string) $img['base'], 'alt' => (string) ($img['alt'] ?? ''), 'sizes' => $img['sizes'] ?? '(min-width:1025px) 1152px, (min-width:761px) 100vw, calc(100vw - 40px)']) ?></figure>
      <?php if (!empty($it['icon'])): ?><span class="prow__icon" aria-hidden="true"><?= ns_icon((string) $it['icon'], 26) ?></span><?php endif; ?>
      <div class="prow__body">
        <span class="prow__n t-meta"><?= e((string) ($it['tag'] ?? ($it['n'] ?? ''))) ?></span>
        <<?= $tag ?> class="prow__title head"><?= e((string) $it['title']) ?></<?= $tag ?>>
        <?php if (!empty($it['text'])): ?><p class="prow__text"><?= e((string) $it['text']) ?></p><?php endif; ?>
      </div>
      <?php if ($href !== ''): ?><span class="prow__go" aria-hidden="true"><span class="arrow">→</span></span><?php endif; ?>
    </<?= $el ?>>
  </li>
  <?php endforeach; ?>
</ol>
