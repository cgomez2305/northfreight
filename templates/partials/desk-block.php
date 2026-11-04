<?php
/**
 * Bloque "From the desk": a la izquierda etiqueta + H2 + texto + botón; a la derecha UN artículo destacado (foto arriba, texto debajo: nada encima de la foto) y DOS filas compactas
 * (partial row-card, size sm). Datos de app/data/posts.php (título, extracto, categoría, minutos). No muestra fechas mientras `published` esté vacío.
 * CSS: components.css (.dblock*, .dfeat*). Solo se pinta si hay al menos el destacado live.
 * Params:
 *  id, eyebrow, title, lead  cabecera (H2 con el id para aria-labelledby; el H2 debe ser único en la página y distinto en cada página)
 *  paths  array   rutas de los artículos: [destacado, fila 1, fila 2] (rutas live de app/data/posts.php)
 *  btn    array   ['label','href'] botón a la izquierda
 *  exclude array  bases de foto ya usadas en la página (no se repiten)    alt array  ['/ruta/' => ['base','alt']] foto alternativa si la de la ruta ya está usada
 *  cls    string  clase extra en <section>
 */
require_once __DIR__ . '/kit.php';
$paths = array_values(array_filter($paths ?? [], 'route_is_live'));
$posts = [];
foreach (data('posts') as $p) {
    $posts[(string) ($p['path'] ?? '')] = $p;
}
$exclude = $exclude ?? [];
$alt = $alt ?? [];
$feat = $paths ? ($posts[$paths[0]] ?? null) : null;
if (!$feat) {
    return;
}
$meta = static function (array $p): array {
    $c = [];
    if (!empty($p['category'])) {
        $c[] = (string) $p['category'];
    }
    if (!empty($p['minutes'])) {
        $c[] = (int) $p['minutes'] . ' min read';
    }
    $pub = post_dates($p)['published'];
    if ($pub !== '') {
        $c[] = fmt_date($pub);
    }
    return $c;
};
$thumbs = [];
foreach ($paths as $path) {
    $t = ns_thumb($path, $exclude, $alt);
    if ($t) {
        $exclude[] = $t[0];
    }
    $thumbs[$path] = $t;
}
?>
<section class="section dblock<?= !empty($cls) ? ' ' . e($cls) : '' ?>"<?= !empty($id) ? ' aria-labelledby="' . e($id) . '"' : '' ?>>
  <div class="container dblock__in">
    <div class="dblock__head">
      <?php partial('section-head', ['id' => $id ?? '', 'eyebrow' => $eyebrow ?? '', 'title' => $title ?? '', 'lead' => $lead ?? '']); ?>
      <?php if (!empty($btn['href'])): ?><a class="btn btn--primary btn--card" href="<?= e((string) $btn['href']) ?>"><span class="btn__t"><?= e((string) $btn['label']) ?></span></a><?php endif; ?>
    </div>
    <div class="dblock__list">
      <a class="dfeat" href="<?= e(url($feat['path'])) ?>">
        <?php if (!empty($thumbs[$paths[0]])): $t = $thumbs[$paths[0]]; ?><figure class="dfeat__photo"><?= ns_img(['base' => $t[0], 'alt' => $t[1], 'sizes' => '(min-width:1025px) 480px, (min-width:641px) 50vw, 65vw']) ?></figure><?php endif; ?>
        <span class="dfeat__body">
          <span class="rcard__chips"><?php foreach ($meta($feat) as $c): ?><span class="rcard__chip"><?= e($c) ?></span><?php endforeach; ?></span>
          <h3 class="head dfeat__title"><?= e((string) $feat['title']) ?></h3>
          <span class="dfeat__text"><?= e((string) ($feat['excerpt'] ?? '')) ?></span>
          <span class="dfeat__link">Read the article <span class="arrow" aria-hidden="true">→</span></span>
        </span>
      </a>
      <div class="dblock__rows">
        <?php foreach (array_slice($paths, 1, 2) as $path): $p = $posts[$path] ?? null; if (!$p) { continue; }
            partial('row-card', ['href' => url($p['path']), 'title' => $p['title'], 'chips' => $meta($p), 'image' => $thumbs[$path] ? ['base' => $thumbs[$path][0], 'alt' => $thumbs[$path][1]] : null, 'size' => 'sm']);
        endforeach; ?>
      </div>
    </div>
  </div>
</section>
