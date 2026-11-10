<?php
/**
 * Calculadora IFTA (ToolIfta.html de la maqueta): hero → resumen + tabla por estado → 3 pasos → FAQ → recursos relacionados.
 * La lógica vive en assets/js/calc-ifta.js (se carga desde el layout). Sin JS se ven los campos y un aviso <noscript>.
 * Filas de ejemplo = las de la maqueta; las tasas NO se precargan (cambian cada trimestre).
 */

// [estado, millas, galones comprados, tasa]
$exampleRows = [
    ['TX', '1250', '210', ''],
    ['OK', '640',  '0',   ''],
    ['AR', '410',  '95',  ''],
    ['TN', '300',  '80',  ''],
];

$steps = [
    'Add one row per state you drove in this quarter, with the miles from your ELD or trip sheets.',
    'Enter the gallons you bought in each state from your fuel receipts. States with no purchases stay at 0.',
    'Optional: add each state\'s current rate to see tax due. Positive net gallons are owed; negative are credits.',
];

$related = [
    ['code' => 'TOOL',    'title' => 'Rate per mile calculator', 'text' => 'Loaded and all-in rate per mile before you book.',     'path' => '/resources/rate-per-mile-calculator/', 'dark' => false],
    ['code' => 'GUIDE',   'title' => 'Trucking glossary',        'text' => 'IFTA, deadhead, TONU and the rest of the vocabulary.',   'path' => '/resources/glossary/', 'dark' => false],
    ['code' => 'SERVICE', 'title' => 'Trucking back office',     'text' => 'Receipts, settlements and documents organized per load.', 'path' => '/trucking-back-office/', 'dark' => true],
];
$related = array_values(array_filter($related, static fn(array $c): bool => route_is_live($c['path'])));

/** Fila de la tabla. $n = sufijo único para los ids (el JS reemplaza {n} en la plantilla de filas nuevas). */
$iftaRow = static function (array $r, string $n): string {
    [$st, $mi, $gal, $rate] = $r;
    $cell = static fn(string $key, string $label, string $val, string $extra): string =>
        '<td><label class="sr-only" for="ifta-' . $key . '-' . $n . '">' . e($label) . '</label>'
        . '<input class="input' . ($key === 'st' ? ' mono tools-state' : '') . '" id="ifta-' . $key . '-' . $n . '" type="text" inputmode="'
        . ($key === 'st' ? 'text' : 'decimal') . '" autocomplete="off" value="' . e($val) . '" data-f="' . $key . '"' . $extra . '></td>';
    return '<tr data-row>'
        . $cell('st', 'State', $st, ' maxlength="2" autocapitalize="characters" spellcheck="false"')
        . $cell('mi', 'Miles', $mi, '')
        . $cell('gal', 'Gallons bought', $gal, '')
        . $cell('rate', 'Tax rate per gallon', $rate, ' placeholder="0.000"')
        . '<td class="mono tools-num" data-o="taxable">—</td>'
        . '<td class="mono tools-num tools-num--b" data-o="net">—</td>'
        . '<td class="mono tools-num tools-num--b" data-o="due">—</td>'
        . '<td class="tools-rmcell"><button type="button" class="tools-rm" data-remove aria-label="Remove state row">'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></td>'
        . '</tr>';
};

partial('hero', [
    'route'   => $route,
    'h1'      => 'IFTA {hl}calculator{/hl}',
    'eyebrow' => 'Free tool',
    'lead'    => 'Add the miles you drove and the fuel you bought in each state. The calculator works out your fleet MPG and the net taxable gallons for every jurisdiction on your quarterly return.',
    'cls'     => 'tools-hero tools-hero--short',
]);
?>
<section class="tools-band tools-band--calc" aria-label="IFTA calculator" data-calc="ifta">
  <div class="container tools-band__in">
    <div class="tools-tiles" aria-live="polite" aria-atomic="true">
      <div class="tools-tile"><span class="tools-tile__label mono">Total miles</span><span class="tools-tile__val" data-out="totalMiles">—</span></div>
      <div class="tools-tile"><span class="tools-tile__label mono">Total gallons</span><span class="tools-tile__val" data-out="totalGal">—</span></div>
      <div class="tools-tile"><span class="tools-tile__label mono">Fleet MPG</span><span class="tools-tile__val" data-out="mpg">—</span></div>
      <div class="tools-tile tools-tile--accent"><span class="tools-tile__label mono">Net tax due</span><span class="tools-tile__val" data-out="totalDue">—</span></div>
    </div>

    <div class="tools-sheet">
      <div class="tools-sheet__head">
        <p class="tools-calc__label t-meta">Miles &amp; fuel by state · example values</p>
        <button type="button" class="btn btn--navy btn--sm tools-add" data-add>+ Add state</button>
      </div>
      <noscript><p class="tools-noscript">This calculator needs JavaScript to total your rows and add or remove states. Turn it on to see your fleet MPG and net taxable gallons.</p></noscript>
      <div class="tools-scroll" role="region" aria-label="Miles and fuel by state" tabindex="0">
        <table class="tools-tbl">
          <thead>
            <tr>
              <th scope="col">State</th><th scope="col">Miles</th><th scope="col">Gallons bought</th><th scope="col">Rate $/gal</th>
              <th scope="col">Taxable gal</th><th scope="col">Net gal</th><th scope="col">Tax due</th><th scope="col"><span class="sr-only">Remove</span></th>
            </tr>
          </thead>
          <tbody data-rows>
            <?php foreach ($exampleRows as $i => $r): echo $iftaRow($r, (string) ($i + 1)); endforeach; ?>
          </tbody>
        </table>
      </div>
      <template data-row-template><?= $iftaRow(['', '', '', ''], '{n}') ?></template>
      <p class="tools-sheet__note">Rates change every quarter, so copy each state&#39;s current rate from the IFTA tax matrix. Positive net gallons = tax you owe that state; negative = a credit.</p>
      <p class="tools-sheet__note">Estimate only. Check the figures against your own records and your base jurisdiction&#39;s rules before you file.</p>
      <p class="sr-only" role="status" data-status></p>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="ifta-steps-title">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'How to use it', 'title' => 'How to use the IFTA calculator in three steps', 'id' => 'ifta-steps-title']); ?>
    <ol class="tools-steps">
      <?php foreach ($steps as $n => $text): ?>
      <li class="tools-step"><?= e($text) ?></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<?php partial('faq', ['items' => route_faq_items($route), 'title' => 'IFTA calculator questions', 'surface' => 'light', 'open_first' => false]); ?>

<?php if ($related): ?>
<section class="section tools-related" aria-labelledby="ifta-related-title">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'Keep going', 'title' => 'Related resources', 'id' => 'ifta-related-title']); ?>
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
