<?php
/**
 * /how-it-works/ — maqueta How.html.
 * Orden: hero · 4 pasos (lista ordenada con línea discontinua) · A load, stage by stage (partial stage-table) · What you can expect from the desk · CTA final (layout).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$steps = [
    ['We learn your operation', 'Trucks, equipment, lanes, drivers, home time and the accounts you already run, including Amazon Relay.'],
    ['We define scope & contacts', 'Who approves loads, how and when we report, and which tasks the desk owns from day one.'],
    ['We run the day-to-day', 'Booking, tracking and documenting every load. You get updates on WhatsApp, where you already work.'],
    ['We review & improve', 'Regular reviews of lanes, rates and incidents to keep your trucks earning.'],
];
$expect = ['One channel for every update', 'A record of every load', 'A team, not a single dispatcher'];

partial('hero', [
    'route' => $route,
    'cls'   => 'sb-hw-hero',
    'eyebrow' => 'How we plug into your operation',
    'h1'    => 'How truck dispatching works {hl}with NorthStar{/hl}',
    'lead'  => 'Hiring a dispatch company should not mean losing control of your trucks. Here is exactly how we start and how we work with you every day.',
    'image' => ['base' => 'two-semis-hillside-highway', 'alt' => 'Two semi trucks on a highway through the hills'],
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', [
    'text'   => 'Truck dispatching with NorthStar starts with four steps: we learn your operation, define scope and contacts, run the day-to-day and review and improve together. You keep control of your trucks.',
    'points' => [
        'One channel for every update',
        'A record of every load',
        'A team, not a single dispatcher',
    ],
]);
?>

<section class="section">
  <div class="container sb-hw-wrap">
    <ol class="sb-hw-steps">
      <?php foreach ($steps as $i => [$title, $text]): $last = $i === count($steps) - 1; ?>
      <li class="sb-hw-step">
        <?php if ($last): ?><span class="sb-hw-step__num sb-hw-step__num--end" aria-hidden="true"><?= ns_star(30) ?></span>
        <?php else: ?><span class="sb-hw-step__num" aria-hidden="true"><?= sprintf('%02d', $i + 1) ?></span><?php endif; ?>
        <div class="sb-hw-step__body">
          <h2 class="head sb-hw-step__title"><?= e($title) ?></h2>
          <p class="t-body c-slate"><?= e($text) ?></p>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<?php partial('stage-table', ['eyebrow' => 'From booking to settlement', 'title' => 'A load, stage by stage', 'id' => 'hw-stages']); ?>

<section class="section section--surface" aria-labelledby="hw-expect">
  <div class="container stack sb-gap-32">
    <?php partial('section-head', ['title' => 'What you can expect from the desk', 'id' => 'hw-expect']); ?>
    <ul class="g3">
      <?php foreach ($expect as $i => $t): ?>
      <li class="card <?= $i === 2 ? 'card--dark' : 'card--white' ?> sb-hw-item"><?= ns_star(18) ?><span class="head sb-hw-item__text"><?= e($t) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
