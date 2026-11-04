<?php
/**
 * /how-it-works/ — maqueta How.html.
 * Orden: hero fotográfico (patrón A) · 4 pasos (filas apiladas con foto, patrón C; los títulos siguen siendo H2) · A load, stage by stage (partial stage-table) · What you can expect from the desk · CTA final (layout).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$steps = [
    ['We learn your operation', 'Trucks, equipment, lanes, drivers, home time and the accounts you already run, including Amazon Relay.', 'pin',
     ['base' => 'nf-hands-map-keys', 'alt' => 'Hands gathered around a folded route map with a truck key and a notebook beside it', 'pos' => '55% 50%']],
    ['We define scope & contacts', 'Who approves loads, how and when we report, and which tasks the desk owns from day one.', 'doc',
     ['base' => 'nf-signing-clipboard-night', 'alt' => 'Hand signing a form on a clipboard at night, with a trailer out of focus behind', 'pos' => '60% 45%']],
    ['We run the day-to-day', 'Booking, tracking and documenting every load. You get updates on WhatsApp, where you already work.', 'truck',
     ['base' => 'nf-white-truck-rainy-highway', 'alt' => 'White semi truck with a dry van trailer driving a wet highway at night', 'pos' => '65% 55%']],
    ['We review & improve', 'Regular reviews of lanes, rates and incidents to keep your trucks earning.', 'trend',
     ['base' => 'nf-trailer-rows-aerial', 'alt' => 'Aerial view of white trailers lined up in rows in a truck lot at night', 'pos' => '55% 50%']],
];
$expect = ['One channel for every update', 'A record of every load', 'A team, not a single dispatcher'];

partial('hero', [
    'route' => $route,
    'cls'   => 'sb-hw-hero',
    'eyebrow' => 'How we plug into your operation',
    'h1'    => 'How truck dispatching works {hl}with NorthStar{/hl}',
    'lead'  => 'Hiring a dispatch company should not mean losing control of your trucks. Here is exactly how we start and how we work with you every day.',
    'image' => ['base' => 'nf-route-map-night-trucks', 'alt' => 'Glowing route lines over a dark map with trucks on a night highway', 'pos' => '72% 50%', 'sizes' => '(min-width:1025px) 1260px, (min-width:761px) 100vw, 540px'],
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

<section class="section" aria-label="The four steps">
  <div class="container">
    <?php partial('photo-rows', ['tag' => 'h2', 'label' => 'How we start and work with you', 'items' => array_map(static fn(array $st, int $i): array => ['n' => sprintf('%02d', $i + 1), 'title' => $st[0], 'text' => $st[1], 'icon' => $st[2], 'image' => $st[3]], $steps, array_keys($steps))]); ?>
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
