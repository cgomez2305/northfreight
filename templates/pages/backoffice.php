<?php
/**
 * /trucking-back-office/ — maqueta BackOffice.html.
 * Orden: hero (con estrella) · Included (5 tarjetas) · One team (Book → Track → Document → Get paid) · CTA final (layout).
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
    <?php partial('section-head', ['eyebrow' => 'Included', 'title' => 'Five jobs off your desk', 'id' => 'bo-included']); ?>
    <ul class="g5 sb-cells">
      <?php foreach ($jobs as $i => [$title, $text]): ?>
      <li><?php partial('card', ['variant' => $i === 4 ? 'dark' : 'light', 'num' => sprintf('%02d', $i + 1), 'title' => $title, 'text' => $text, 'cls' => 'sb-bo-job']); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="section section--surface" aria-labelledby="bo-team">
  <div class="container sb-split">
    <div class="sb-split__text">
      <?php partial('section-head', ['eyebrow' => 'One team', 'title' => 'Dispatch and back office, one team', 'id' => 'bo-team']); ?>
      <p class="t-body c-slate">Because the same desk books and tracks the load, the back office already has every document it needs.</p>
    </div>
    <ol class="sb-bo-flow" aria-label="From booking to payment">
      <?php foreach ($flow as $i => $step): ?>
      <li class="sb-bo-flow__item">
        <?php if ($i > 0): ?><span class="sb-bo-flow__arrow arrow" aria-hidden="true">→</span><?php endif; ?>
        <span class="sb-bo-flow__step<?= $i === count($flow) - 1 ? ' sb-bo-flow__step--end' : '' ?>"><?= e($step) ?></span>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
