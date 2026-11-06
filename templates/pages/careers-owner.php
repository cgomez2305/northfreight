<?php
/**
 * /careers/owner-operators/ — "owner operator jobs" y "partner with a dispatcher" (brief P). NO apunta a "dispatch services for owner-operators" (keyword de /dispatch-for-owner-operators/, a la que enlaza).
 * Encuadre honesto: servicio de dispatch, no un empleo. Disponibles: $route, $cfg y helpers.
 * Orden: hero fotográfico (patrón A) · In short · What is included (rejilla numerada) · Your truck, your decision (patrón B con checks) · Equipment we dispatch (solo rutas live) ·
 *        On your own vs with the desk (tabla) · How dispatch fees work (+ calculadora) · Start your application (paso 1 por GET, sin datos personales) · FAQ (pre-CTA).
 * Fotos (ninguna repetida): hero nf-setup-owner-operator · patrón B nf-check-owner-approve · filas de ns_thumb() con exclusión.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$applyUrl = url('/careers/apply/');
$included = [
    ['Loads booked on your lanes', 'Amazon Relay and dry van, planned around your hours of service and home time.'],
    ['Rates & broker setup', 'Packets, rate confirmations and BOLs handled for you.'],
    ['Check calls & ETAs', 'Brokers updated without you picking up the phone.'],
    ['Evidence on file', 'Arrival timestamps and photos ready when detention or a dispute comes up.'],
    ['Settlements prepared', 'So you see exactly what each load paid.'],
];

partial('hero', [
    'route'   => $route,
    'cls'     => 'cr-hero',
    'h1'      => 'Owner operator jobs: partner with a {hl}dispatcher{/hl}',
    'tagline' => 'A dispatch partnership, not a job offer.',
    'lead'    => 'NorthStar is not an employer. You keep running your own truck and you approve every load, while the desk works as your dispatch desk: we find and book the loads, deal with brokers, track every trip and keep the paperwork that gets you paid.',
    'ctas'    => [
        ['label' => 'Start your application', 'href' => $applyUrl . '?type=owner-operator', 'arrow' => true],
        ['label' => 'Talk to a dispatcher', 'href' => wa($route['wa']), 'variant' => 'ghost', 'wa' => true, 'arrow' => true],
    ],
    'image'   => ['base' => 'nf-setup-owner-operator', 'alt' => 'White semi truck parked alone in an empty lot at dusk, with bare trees behind', 'pos' => '66% 50%'],
]);

partial('summary', summary_for($route));
?>
<section class="section" aria-labelledby="co-included">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'co-included', 'eyebrow' => 'What is included', 'title' => 'What the desk handles for your truck']); ?>
    <?php partial('numgrid', ['label' => 'What the desk handles', 'items' => array_map(static fn(array $g): array => [
        'title' => $g[0], 'text' => $g[1],
        'href'  => wa("Hi, I'm an owner-operator and I'd like to talk about " . strtolower($g[0]) . ' with NorthStar.'),
        'label' => 'Ask the desk about ' . strtolower($g[0]) . ' on WhatsApp',
    ], $included), 'cta' => ['title' => 'Talk to the desk', 'text' => 'No forms first. Open WhatsApp with a ready message to send to a dispatcher.', 'href' => wa($route['wa']), 'label' => 'Talk to a dispatcher on WhatsApp']]); ?>
  </div>
</section>

<?php partial('photo-card', [
    'id'      => 'co-control',
    'eyebrow' => 'Who decides',
    'title'   => 'Your truck, your decision.',
    'lead'    => 'You approve every load. We tell you what we booked and why, and you can message the desk on WhatsApp.',
    'image'   => ['base' => 'nf-check-owner-approve', 'alt' => 'A hand holding a phone with a check mark on the screen inside a truck cab at dusk', 'pos' => '60% 100%'],
    'extra'   => ns_checks(['Every load approved by you', 'Updates on WhatsApp, where you already work', 'A team, not one dispatcher who disappears'], 'What staying in control means'),
]); ?>

<?php
$used = ['nf-setup-owner-operator', 'nf-check-owner-approve'];
$equip = [
    ['/amazon-relay-dispatch/', 'Amazon Relay dispatch', 'Load board, trips, scorecard and disputes, handled end to end.', ['Amazon Relay']],
    ['/dry-van-dispatch/', 'Dry van dispatch', 'Dry van freight booked and followed by one desk.', ['Dry van']],
];
?>
<section class="section" aria-labelledby="co-equip">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'co-equip', 'eyebrow' => 'Equipment', 'title' => 'Equipment we dispatch', 'lead' => 'We book Amazon Relay, power only and dry van freight.']); ?>
    <div class="cr-rows">
      <?php foreach ($equip as $q): if (!route_is_live($q[0])) { continue; } $th = ns_thumb($q[0], $used); if ($th) { $used[] = $th[0]; }
          partial('row-card', ['href' => url($q[0]), 'title' => $q[1], 'text' => $q[2], 'chips' => $q[3], 'h' => 'p', 'image' => $th ? ['base' => $th[0], 'alt' => $th[1]] : null]);
      endforeach; ?>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="co-compare">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'co-compare', 'eyebrow' => 'Side by side', 'title' => 'On your own or with a dispatch desk']); ?>
    <?php partial('compare', ['caption' => 'On your own versus with the NorthStar dispatch desk', 'rows' => [
        ['Finding loads', 'You search load boards and call around between drives.', 'We find and book the loads on your lanes.'],
        ['Brokers and rates', 'You set up with each broker and handle every rate confirmation.', 'Packets, rate confirmations and BOLs handled for you.'],
        ['Check calls', 'You answer every status call from the cab.', 'Brokers updated without you picking up the phone.'],
        ['Paperwork', 'Timestamps, photos and receipts are on you.', 'Arrival timestamps and photos on file, settlements prepared.'],
        ['The final say', 'Every decision is yours.', 'Every decision is still yours: you approve every load.'],
    ]]); ?>
  </div>
</section>

<section class="section" aria-labelledby="co-fee">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'co-fee', 'eyebrow' => 'Fees', 'title' => 'How dispatch fees work']); ?>
    <div class="cr-fee">
      <div class="cr-fee__text">
        <p class="t-body c-slate">Scope is agreed before we start, so you always know what the desk handles and what it costs.</p>
        <p class="t-body c-slate">NorthStar quotes each operation individually, based on the volume of active trucks and the scope of service. <a href="<?= e(wa($route['wa'])) ?>" rel="noopener">Message the desk on WhatsApp</a> to get a quote for your operation.</p>
      </div>
      <div class="cr-rows">
        <?php
        $feeRows = [
            ['/blog/how-much-does-truck-dispatch-cost/', 'How much does truck dispatch cost?', 'The three fee models, what should be included and what to watch for.', ['Guide']],
            ['/resources/rate-per-mile-calculator/', 'Rate per mile calculator', 'Know if a load pays before you book it.', ['Free', 'Calculator']],
        ];
        foreach ($feeRows as $q): if (!route_is_live($q[0])) { continue; } $th = ns_thumb($q[0], $used); if ($th) { $used[] = $th[0]; }
            partial('row-card', ['href' => url($q[0]), 'title' => $q[1], 'text' => $q[2], 'chips' => $q[3], 'h' => 'p', 'size' => 'sm', 'image' => $th ? ['base' => $th[0], 'alt' => $th[1]] : null]);
        endforeach; ?>
      </div>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="co-start">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'co-start', 'eyebrow' => 'Start here', 'title' => 'Start your application', 'lead' => 'Tell us what you run. We take you to the short application with it filled in. No personal details yet.']); ?>
    <form class="cr-quick" method="get" action="<?= e($applyUrl) ?>">
      <input type="hidden" name="type" value="owner-operator">
      <label class="field" for="cq-equipment">What do you run?
        <select class="input" id="cq-equipment" name="equipment">
          <option value="">Choose one</option>
          <?php foreach (data('lead-options')['equipment'] as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="field" for="cq-trucks">Number of trucks
        <input class="input" type="number" id="cq-trucks" name="trucks" min="1" max="999" inputmode="numeric" autocomplete="off">
      </label>
      <button type="submit" class="btn btn--navy btn--block-m cr-quick__go">Continue to the application</button>
    </form>
  </div>
</section>

<?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Owner operator job questions', 'pre_cta' => true]); ?>
