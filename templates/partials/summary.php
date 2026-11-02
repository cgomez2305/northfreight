<?php
/**
 * Resumen "In short": bloque corto, directo y citable (answer-first) justo tras el hero de las páginas de servicio.
 * Pensado para lectores y para buscadores/IA que extraen respuestas: 1–2 frases definicionales (+ opcional 3 viñetas).
 * Usa SOLO información que ya está en el copy de la página: nada de cifras, tarifas, cobertura ni promesas nuevas.
 * Params: text(string, requerido), label(string, defecto 'In short'), points(array de hasta 3 strings, opcional), id(string, defecto 'in-short').
 * Marcas de texto: las de ns_rich() ({hl}, {br}, {{ph:X}}).
 */
require_once __DIR__ . '/kit.php';
$text = trim((string) ($text ?? ''));
if ($text === '') {
    return;
}
$label = (string) ($label ?? 'In short');
$id = preg_match('/^[a-z0-9-]+$/', (string) ($id ?? 'in-short')) === 1 ? (string) ($id ?? 'in-short') : 'in-short';
$points = array_slice(array_values(array_filter(array_map('strval', (array) ($points ?? [])), static fn(string $p): bool => trim($p) !== '')), 0, 3);
?>
<section class="summary" aria-labelledby="<?= e($id) ?>">
  <div class="container">
    <div class="summary__wrap">
      <div class="summary__box cut">
        <p class="t-eyebrow summary__label" id="<?= e($id) ?>"><?= e($label) ?></p>
        <p class="summary__text"><?= ns_rich($text) ?></p>
        <?php if ($points): ?>
        <ul class="summary__points">
          <?php foreach ($points as $pt): ?><li><?= ns_star(14) ?><span><?= ns_rich($pt) ?></span></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
