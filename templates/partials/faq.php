<?php
/**
 * FAQ: <details>/<summary> con icono (estrella) que gira 45° al abrir. Imprime {{ph:X}} con ph(). Si no hay ítems no pinta nada.
 * El JSON-LD FAQPage lo pone el layout (page_schema) a partir de la ruta.
 * Params: items(array de ['q','a','link'=>['label','path']?] como devuelve route_faq_items()/faq_items()),
 *         title(string, defecto "Questions carriers ask us."), eyebrow(string, defecto "FAQ"),
 *         surface('white' defecto: sección blanca, tarjetas gris | 'light': sección gris, tarjetas blancas),
 *         open_first(bool, true), section(bool, true: envuelve en <section class="section"> con .pre-cta si pre_cta), pre_cta(bool), id.
 */
require_once __DIR__ . '/kit.php';
$items = $items ?? [];
if (!$items) {
    return;
}
$onLight = ($surface ?? 'white') === 'light';
$openFirst = $open_first ?? true;
$section = $section ?? true;
?>
<?php if ($section): ?><section class="section<?= $onLight ? ' section--surface' : '' ?><?= !empty($pre_cta) ? ' pre-cta' : '' ?>"<?= !empty($id) ? ' id="' . e($id) . '"' : '' ?> aria-labelledby="faq-title"><div class="container"><?php endif; ?>
<div class="faq<?= $onLight ? ' faq--on-surface' : '' ?>">
  <div class="faq__head">
    <span class="t-eyebrow"><?= e($eyebrow ?? 'FAQ') ?></span>
    <h2 class="t-h2 c-navy" id="faq-title"><?= ns_rich($title ?? 'Questions carriers ask us.') ?></h2>
  </div>
  <div class="faq__list">
    <?php foreach ($items as $n => $it): ?>
    <details class="faq__item"<?= $openFirst && $n === 0 ? ' open' : '' ?>>
      <summary class="faq__q"><span><?= phtext($it['q']) ?></span><span class="faq__icon" aria-hidden="true"><?= ns_star(18) ?></span></summary>
      <p class="faq__a t-body"><?= phtext($it['a']) ?><?php if (!empty($it['link']) && route_is_live($it['link'][1])): ?> <a href="<?= e(url($it['link'][1])) ?>"><?= e($it['link'][0]) ?> <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?></p>
    </details>
    <?php endforeach; ?>
  </div>
</div>
<?php if ($section): ?></div></section><?php endif; ?>
