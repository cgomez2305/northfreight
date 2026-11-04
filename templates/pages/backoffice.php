<?php
/**
 * /trucking-back-office/ — maqueta BackOffice.html.
 * Orden: hero fotográfico (patrón A) · Included (5 tarjetas) · One team (banda fotográfica, patrón D: Book → Track → Document → Get paid) · CTA final (layout).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$jobs = [
    ['Settlement preparation', 'Every load reconciled against its rate confirmation.'],
    ['Dispute management', 'Claims followed with the evidence our desk collected on the road.'],
    ['Document organization', 'Rate cons, BOLs, PODs and receipts filed per load.'],
    ['Driver onboarding', 'New drivers set up and ready to roll on day one.'],
    ['Account performance', 'Follow-up on scorecards and account health on Amazon Relay.'],
];
$flow = ['Book', 'Track', 'Document', 'Get paid'];

partial('hero', [
    'route'   => $route,
    'cls'     => 'sb-bo-hero',
    'h1'      => 'Trucking back office {hl}services{/hl}',
    'tagline' => 'The work behind the load, done right.',
    'lead'    => 'Paperwork organized, settlements prepared and disputes followed until they close.',
    'ctas'    => [['label' => 'Talk to the desk', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true]],
    'image'   => ['base' => 'nf-desk-rain-window-night', 'alt' => 'Desk at night with a lamp, a stack of paper and a laptop beside a rain-covered window', 'pos' => '60% 50%'],
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', [
    'text'   => 'Trucking back office services cover the work behind the load: paperwork organized, settlements prepared and disputes followed until they close. The same NorthStar desk books and tracks the load, so it already has every document it needs.',
    'points' => [
        'Settlement preparation and dispute management',
        'Rate cons, BOLs, PODs and receipts filed per load',
        'Driver onboarding and Amazon Relay account follow-up',
    ],
]);
?>

<section class="section" aria-labelledby="bo-included">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'Included', 'title' => 'Five back office jobs off your desk', 'id' => 'bo-included']); ?>
    <ul class="g5 sb-cells">
      <?php foreach ($jobs as $i => [$title, $text]): ?>
      <li><?php partial('card', ['variant' => $i === 4 ? 'dark' : 'light', 'num' => sprintf('%02d', $i + 1), 'title' => $title, 'text' => $text, 'cls' => 'sb-bo-job']); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<?php partial('photo-band', [
    'id'      => 'bo-team',
    'eyebrow' => 'One team',
    'title'   => 'Dispatch and back office, one team',
    'lead'    => 'Because the same desk books and tracks the load, the back office already has every document it needs.',
    'image'   => ['base' => 'nf-contracts-amber-cyan', 'alt' => 'Open binder with a pen and paperwork under an amber lamp', 'pos' => '70% 60%'],
    'chips'   => $flow,
    'chips_label' => 'From booking to payment',
    'chips_arrow' => true,
    'chips_end'   => true,
]); ?>
