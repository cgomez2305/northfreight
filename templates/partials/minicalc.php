<?php
/**
 * Mini calculadora de rate per mile incrustada (hub /resources/ y artículos de costes y de lumper/detention): 3 campos (load pay, loaded miles, deadhead miles) y el resultado
 * (RPM cargado y all-in) en una región aria-live. Valores de ejemplo marcados como "example values", aviso de estimación y botón a la calculadora completa.
 * La lógica es la de assets/js/calc-rpm.js (mismo [data-calc="rpm"]; los campos que faltan cuentan como 0). Cargar calc-rpm.js solo donde se use (clave 'js' de la ruta). Sin JS: noscript.
 * CSS: assets/css/pages/company.css y blog.css (.mcalc*, mismo bloque en ambos). No se anima (inputs y resultados aria-live quedan fuera de las escenas de motion.js).
 * Params: id (prefijo de los ids de los campos, único en la página), title (opcional: encabezado del bloque), tag ('h3' defecto | 'h2' | 'p'), cls.
 */
require_once __DIR__ . '/kit.php';
$id = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($id ?? 'mrpm'))) ?: 'mrpm';
$tag = in_array($tag ?? 'h3', ['h2', 'h3', 'p'], true) ? ($tag ?? 'h3') : 'h3';
// [clave, etiqueta, valor de ejemplo]
$fields = [
    ['pay',      'Load pay ($)',     '2400'],
    ['loaded',   'Loaded miles',     '900'],
    ['deadhead', 'Deadhead miles',   '80'],
];
?>
<div class="mcalc<?= !empty($cls) ? ' ' . e($cls) : '' ?>" data-calc="rpm">
  <div class="mcalc__main">
    <p class="mcalc__label t-meta">Try it · example values</p>
    <?php if (!empty($title)): ?><<?= $tag ?> class="head mcalc__title"><?= e((string) $title) ?></<?= $tag ?>><?php endif; ?>
    <form class="mcalc__form" aria-label="Load details" autocomplete="off" novalidate>
      <?php foreach ($fields as [$key, $label, $value]): $fid = $id . '-' . $key; ?>
      <div class="field mcalc__field">
        <label for="<?= e($fid) ?>"><?= e($label) ?></label>
        <input class="input" id="<?= e($fid) ?>" name="<?= e($key) ?>" type="text" inputmode="decimal" autocomplete="off" value="<?= e($value) ?>" data-field="<?= e($key) ?>" aria-describedby="<?= e($fid) ?>-msg">
        <span class="mcalc__msg" id="<?= e($fid) ?>-msg" data-msg hidden></span>
      </div>
      <?php endforeach; ?>
    </form>
    <p class="mcalc__note">Estimate only. Results depend on the numbers you enter.</p>
  </div>
  <div class="mcalc__result">
    <p class="mcalc__label t-meta mcalc__label--cyan">Result</p>
    <div class="mcalc__out" aria-live="polite" aria-atomic="true">
      <p class="mcalc__big-label mono">Rate per loaded mile</p>
      <p class="mcalc__big" data-out="rpmLoaded">—</p>
      <p class="mcalc__row"><span>All-in rate per mile</span><span class="mono" data-out="rpmAll">—</span></p>
    </div>
    <noscript><p class="mcalc__note">This calculator needs JavaScript to work out the numbers.</p></noscript>
    <a class="btn btn--primary btn--card" href="<?= e(url('/resources/rate-per-mile-calculator/')) ?>"><span class="btn__t">Open the full calculator</span></a>
  </div>
</div>
