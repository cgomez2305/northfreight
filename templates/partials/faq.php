<?php
/**
 * FAQ: <details>/<summary> en filas con línea fina y marcador (+) a la izquierda que gira 45° al abrir. Imprime {{ph:X}} con ph(). Si no hay ítems no pinta nada.
 * El JSON-LD FAQPage lo pone el layout (page_schema) a partir de la ruta.
 * Params: items(array de ['q','a','link'=>['label','path']?] como devuelve route_faq_items()/faq_items(); el enlace también
 *         se acepta como [label, path]),
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
// Enlace opcional de una pregunta: ['label'=>…,'path'=>…] o [label, path] => [label, path] | null.
$faqLink = static function (array $it): ?array {
    $l = $it['link'] ?? null;
    if (!is_array($l)) {
        return null;
    }
    $label = $l['label'] ?? $l[0] ?? null;
    $path = $l['path'] ?? $l[1] ?? null;
    return is_string($label) && is_string($path) && $path !== '' ? [$label, $path] : null;
};
?>
<?php if ($section): ?><section class="section<?= $onLight ? ' section--surface' : '' ?><?= !empty($pre_cta) ? ' pre-cta' : '' ?>"<?= !empty($id) ? ' id="' . e($id) . '"' : '' ?> aria-labelledby="faq-title"><div class="container"><?php endif; ?>
<div class="faq<?= $onLight ? ' faq--on-surface' : '' ?>">
  <div class="faq__head">
    <span class="t-eyebrow"><?= e($eyebrow ?? 'FAQ') ?></span>
    <h2 class="t-h2 c-navy" id="faq-title"><?= ns_rich($title ?? 'Questions carriers ask us.') ?></h2>
  </div>
  <div class="faq__list">
    <?php foreach ($items as $n => $it): $link = $faqLink($it); ?>
    <details class="faq__item"<?= $openFirst && $n === 0 ? ' open' : '' ?>>
      <summary class="faq__q"><span><?= phtext($it['q']) ?></span><span class="faq__icon" aria-hidden="true"><?= ns_icon('plus', 16) ?></span></summary>
      <p class="faq__a t-body"><?= phtext($it['a']) ?><?php if ($link !== null && route_is_live($link[1])): ?> <a href="<?= e(url($link[1])) ?>"><?= e($link[0]) ?> <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?></p>
    </details>
    <?php endforeach; ?>
  </div>
</div>
<?php if ($section): ?></div></section><?php endif; ?>
