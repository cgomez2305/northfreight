<?php
/**
 * Resumen "In short": bloque corto, directo y citable (answer-first) justo tras el hero de las páginas de servicio.
 * Pensado para lectores y para buscadores/IA que extraen respuestas: 1–2 frases definicionales (+ opcional 3 viñetas).
 * Usa SOLO información que ya está en el copy de la página: nada de cifras, tarifas, cobertura ni promesas nuevas.
 * Params: text(string, requerido), label(string, defecto 'In short'), points(array de hasta 3 strings, opcional; se pintan como chips), id(string, defecto 'in-short'),
 *         variant('default': texto 22-28px con borde izquierdo fino | 'lead': frase-declaración en condensada 32-58px, sin borde; la usa el home).
 *         textfill(bool, defecto false): cada palabra del texto va en <span class="w"> y motion.js la rellena de opacidad .5 a 1 con el scroll (solo >=1025px, una sola vez en el sitio: home).
 * Marcas de texto: las de ns_rich() ({hl}, {br}, {{ph:X}}).
 */
require_once __DIR__ . '/kit.php';
$text = trim((string) ($text ?? ''));
if ($text === '') {
    return;
}
$label = (string) ($label ?? 'In short');
$id = preg_match('/^[a-z0-9-]+$/', (string) ($id ?? 'in-short')) === 1 ? (string) ($id ?? 'in-short') : 'in-short';
$lead = ($variant ?? 'default') === 'lead';
$fill = !empty($textfill);
$points = array_slice(array_values(array_filter(array_map('strval', (array) ($points ?? [])), static fn(string $p): bool => trim($p) !== '')), 0, 3);
?>
<section class="summary<?= $lead ? ' summary--lead' : '' ?>" aria-labelledby="<?= e($id) ?>">
  <div class="container">
    <div class="summary__box">
      <p class="t-eyebrow summary__label" id="<?= e($id) ?>"><?= e($label) ?></p>
      <p class="summary__text"<?= $fill ? ' data-textfill' : '' ?>><?= $fill ? ns_words($text) : ns_rich($text) ?></p>
      <?php if ($points): ?>
      <ul class="summary__points">
        <?php foreach ($points as $pt): ?><li><?= ns_rich($pt) ?></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</section>
