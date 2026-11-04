<?php
/**
 * "Choose your setup": pestañas (role=tablist, flechas/Home/End) con un panel por setup. Cada panel es la foto grande 16:9 (radio 32, sin parallax) con una tarjeta --panel solapada
 * (patrón B) que lleva el nombre del setup, 3 puntos ya afirmados, el enlace a la página de servicio y el botón de WhatsApp con el mensaje de esa variante.
 * Los paneles están SIEMPRE en el HTML: sin JS se apilan (los botones de pestaña van ocultos). Un script inline (antes del marcado, para no producir salto de maquetación) pone `js-tabs` en <html>
 * y cablea las pestañas; con esa clase solo se ve el panel activo. A partir de 1025px la tarjeta es absoluta y la altura del panel no cambia al cambiar de pestaña.
 * CSS: components.css (.tabs*, .tabp*).
 * Params:
 *  id, eyebrow, title, lead   cabecera de sección (H2 con id; único en la página)
 *  items  array  [['key'=>'owner','label'=>'Owner-operator','title'=>'Owner-operator' (H3 del panel),'points'=>[3 textos],'image'=>['base','alt','pos'?],'link'=>['label','href']|null,'wa'=>'owner' (clave o mensaje de wa())], …]
 *  label  string aria-label de la lista de pestañas      cls string clase extra en <section>
 */
require_once __DIR__ . '/kit.php';
$items = $items ?? [];
if (count($items) < 2) {
    return;
}
$uid = 'tabs-' . preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($id ?? 'x')));
?>
<section class="section tabs-sec<?= !empty($cls) ? ' ' . e($cls) : '' ?>"<?= !empty($id) ? ' aria-labelledby="' . e($id) . '"' : '' ?>>
  <div class="container stack">
    <?php partial('section-head', ['id' => $id ?? '', 'eyebrow' => $eyebrow ?? '', 'title' => $title ?? '', 'lead' => $lead ?? '']); ?>
    <script>(function(d){d.documentElement.classList.add('js-tabs');d.addEventListener('DOMContentLoaded',function(){var r=d.getElementById('<?= e($uid) ?>');if(!r)return;var t=[].slice.call(r.querySelectorAll('[role="tab"]')),p=[].slice.call(r.querySelectorAll('[role="tabpanel"]'));function pick(i,f){t.forEach(function(b,k){var on=k===i;b.setAttribute('aria-selected',on?'true':'false');b.tabIndex=on?0:-1;p[k].classList.toggle('is-on',on);});if(f)t[i].focus();}t.forEach(function(b,i){b.addEventListener('click',function(){pick(i,false);});b.addEventListener('keydown',function(e){var n=null;if(e.key==='ArrowRight')n=(i+1)%t.length;else if(e.key==='ArrowLeft')n=(i-1+t.length)%t.length;else if(e.key==='Home')n=0;else if(e.key==='End')n=t.length-1;if(n===null)return;e.preventDefault();pick(n,true);});});});})(document);</script>
    <div class="tabs" id="<?= e($uid) ?>">
      <div class="tabs__list" role="tablist"<?= !empty($label) ? ' aria-label="' . e($label) . '"' : '' ?>>
        <?php foreach ($items as $i => $it): ?>
        <button type="button" class="tabs__tab" role="tab" id="<?= e($uid . '-t' . $i) ?>" aria-controls="<?= e($uid . '-p' . $i) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?>><?= e((string) $it['label']) ?></button>
        <?php endforeach; ?>
      </div>
      <?php foreach ($items as $i => $it): $img = $it['image'] ?? []; $link = is_array($it['link'] ?? null) && !empty($it['link']['href']) ? $it['link'] : null; ?>
      <div class="tabs__panel tabp<?= $i === 0 ? ' is-on' : '' ?>" role="tabpanel" id="<?= e($uid . '-p' . $i) ?>" aria-labelledby="<?= e($uid . '-t' . $i) ?>">
        <figure class="tabp__photo"<?= !empty($img['pos']) ? ' style="--pos:' . e($img['pos']) . '"' : '' ?>><?= ns_img(['base' => (string) $img['base'], 'alt' => (string) ($img['alt'] ?? ''), 'sizes' => '(min-width:1025px) 1152px, (min-width:761px) 100vw, (min-width:641px) calc(100vw - 72px), 65vw']) ?></figure>
        <div class="tabp__card">
          <h3 class="head t-h3 tabp__title"><?= e((string) ($it['title'] ?? $it['label'])) ?></h3>
          <?= ns_checks((array) $it['points']) ?>
          <div class="tabp__ctas">
            <a class="btn btn--primary btn--card" href="<?= e(wa($it['wa'] ?? null)) ?>" rel="noopener"><span class="btn__t">Talk to a dispatcher</span></a>
            <?php if ($link): ?><a class="btn btn--ghost btn--card" href="<?= e((string) $link['href']) ?>"><?= e((string) $link['label']) ?> <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
