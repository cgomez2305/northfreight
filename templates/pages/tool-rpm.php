<?php
/**
 * Calculadora de rate per mile (ToolRpm.html de la maqueta): hero con calculadora → 3 pasos → FAQ → recursos relacionados.
 * La lógica vive en assets/js/calc-rpm.js (se carga desde el layout). Sin JS se ven los campos y un aviso <noscript>.
 */

// [clave, etiqueta, valor de ejemplo, prefijo $, ayuda]
$fields = [
    ['pay',      'Load pay',          '2400', true,  'Line haul + fuel surcharge'],
    ['loaded',   'Loaded miles',      '900',  false, ''],
    ['deadhead', 'Deadhead miles',    '80',   false, 'Empty miles to the pickup'],
    ['fuel',     'Fuel price / gal',  '3.85', true,  ''],
    ['mpg',      'Truck MPG',         '6.5',  false, ''],
    ['extras',   'Tolls, lumper & other', '60', true, ''],
];

$steps = [
    'Enter the total the broker pays for the load, including fuel surcharge if it is listed separately.',
    'Add loaded and deadhead miles. Deadhead is the empty run to the shipper, and it costs fuel and time too.',
    'Add your fuel price and MPG. The result updates as you type; compare the net per mile with your cost per mile.',
];

$related = [
    ['code' => 'TOOL',    'title' => 'IFTA calculator',                'text' => 'Net taxable gallons by state for your quarterly return.', 'path' => '/resources/ifta-calculator/', 'dark' => false],
    ['code' => 'ARTICLE', 'title' => 'How much does truck dispatch cost?', 'text' => 'Percentage vs flat fee, and what should be included.',  'path' => '/blog/how-much-does-truck-dispatch-cost/', 'dark' => false],
    ['code' => 'SERVICE', 'title' => 'Dispatch for owner-operators',   'text' => 'Let the desk find, check and book the loads that pay.',   'path' => '/dispatch-for-owner-operators/', 'dark' => true],
];
$related = array_values(array_filter($related, static fn(array $c): bool => route_is_live($c['path'])));

// Calculadora (columna derecha del hero).
ob_start();
?>
<div class="tools-calc cut" data-calc="rpm">
  <form class="tools-calc__form g2" aria-label="Load details" autocomplete="off" novalidate>
    <p class="tools-calc__label t-meta">Load details · example values</p>
    <?php foreach ($fields as [$key, $label, $value, $money, $hint]): $id = 'rpm-' . $key; ?>
    <div class="field tools-field">
      <label for="<?= e($id) ?>"><?= e($label) ?></label>
      <span class="tools-unit">
        <?php if ($money): ?><span class="tools-unit__sym" aria-hidden="true">$</span><?php endif; ?>
        <input class="input<?= $money ? ' input--unit' : '' ?>" id="<?= e($id) ?>" name="<?= e($key) ?>" type="text" inputmode="decimal" autocomplete="off" value="<?= e($value) ?>" data-field="<?= e($key) ?>" aria-describedby="<?= e($id) ?>-msg<?= $hint !== '' ? ' ' . e($id) . '-hint' : '' ?>">
      </span>
      <?php if ($hint !== ''): ?><span class="tools-hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></span><?php endif; ?>
      <span class="tools-msg" id="<?= e($id) ?>-msg" data-msg hidden></span>
    </div>
    <?php endforeach; ?>
  </form>
  <div class="tools-calc__result">
    <p class="tools-calc__label tools-calc__label--cyan t-meta">Result</p>
    <div class="tools-calc__out" aria-live="polite" aria-atomic="true">
    <p class="tools-calc__big-label mono">Rate per loaded mile</p>
    <p class="tools-calc__big" data-out="rpmLoaded">—</p>
    <dl class="tools-res">
      <div class="tools-res__row"><dt>All-in rate per mile</dt><dd class="mono" data-out="rpmAll">—</dd></div>
      <div class="tools-res__row"><dt>Fuel for the trip</dt><dd class="mono" data-out="fuelCost">—</dd></div>
      <div class="tools-res__row"><dt>Left after fuel &amp; extras</dt><dd class="mono" data-out="net">—</dd></div>
      <div class="tools-res__row"><dt>Net per total mile</dt><dd class="mono tools-res__accent" data-out="netPerMile">—</dd></div>
    </dl>
    </div>
    <noscript><p class="tools-noscript">This calculator needs JavaScript to work out the numbers. Turn it on, or message the desk and we will run the math with you.</p></noscript>
    <div class="tools-calc__notes">
      <p class="tools-calc__note">Compare the net per mile with your own cost per mile: truck payment, insurance, maintenance and your pay.</p>
      <p class="tools-calc__note">Estimate only. Results depend on the numbers you enter.</p>
    </div>
  </div>
</div>
<?php
$aside = ob_get_clean();

partial('hero', [
    'route'   => $route,
    'h1'      => 'Rate per mile {hl}calculator{/hl}',
    'eyebrow' => 'Free tool',
    'lead'    => 'Put in the load pay, the miles and your fuel. See the real rate per mile, counting deadhead, and what is left before you book.',
    'aside'   => $aside,
    'cls'     => 'tools-hero tools-hero--rpm',
]);
?>

<section class="section" aria-labelledby="rpm-steps-title">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'How to use it', 'title' => 'How to calculate rate per mile in three steps', 'id' => 'rpm-steps-title']); ?>
    <ol class="g3 tools-steps">
      <?php foreach ($steps as $n => $text): ?>
      <li class="tools-step cut-s"><span class="tools-step__n mono">STEP <?= sprintf('%02d', $n + 1) ?></span><span class="tools-step__t"><?= e($text) ?></span></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Rate per mile calculator questions', 'surface' => 'light', 'open_first' => false]); ?>

<?php if ($related): ?>
<section class="section tools-related" aria-labelledby="rpm-related-title">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'Keep going', 'title' => 'Related resources', 'id' => 'rpm-related-title']); ?>
    <div class="g3">
      <?php foreach ($related as $c):
        partial('card', [
            'variant' => 'link',
            'cls'     => $c['dark'] ? 'card--dark' : '',
            'code'    => $c['code'],
            'title'   => $c['title'],
            'text'    => $c['text'],
            'href'    => url($c['path']),
            'cta'     => 'Open',
        ]);
      endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
