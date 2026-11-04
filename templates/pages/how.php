<?php
/**
 * /how-it-works/ — maqueta How.html.
 * Orden: hero fotográfico (patrón A) · In short · triada Booked / Tracked / Documented (iconos sin tarjeta) · 4 pasos (filas apiladas con foto, patrón C; los títulos siguen siendo H2) · A load, stage by stage (partial stage-table) · What you can expect from the desk · CTA final (layout).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$rowSizes = '(min-width:1025px) 1000px, (min-width:761px) 100vw, (min-width:641px) calc(100vw - 40px), 65vw';   // la fila mide ~1089 px: a 1x basta la variante de 1024 (x1,06)
$steps = [
    ['We learn your operation', 'Trucks, equipment, lanes, drivers, home time and the accounts you already run, including Amazon Relay.', 'pin',
     ['base' => 'nf-onboarding-learn', 'alt' => 'Hands over a paper road map on a worn table, with a pen, truck keys and a notebook', 'pos' => '55% 50%', 'sizes' => $rowSizes]],
    ['We define scope & contacts', 'Who approves loads, how and when we report, and which tasks the desk owns from day one.', 'doc',
     ['base' => 'nf-onboarding-scope', 'alt' => 'Hand signing a form on a clipboard, with a trailer parked out of focus behind', 'pos' => '60% 45%', 'sizes' => $rowSizes]],
    ['We run the day-to-day', 'Booking, tracking and documenting every load. You get updates on WhatsApp, where you already work.', 'truck',
     ['base' => 'nf-onboarding-run', 'alt' => 'White semi truck with a dry van trailer driving past at dusk, with the background blurred by motion', 'pos' => '65% 55%', 'sizes' => $rowSizes]],
    ['We review & improve', 'Regular reviews of lanes, rates and incidents to keep your trucks earning.', 'trend',
     ['base' => 'nf-onboarding-review', 'alt' => 'Rows of white trailers parked in a truck yard beside a warehouse at dusk, with a tractor pulling through', 'pos' => '55% 50%', 'sizes' => $rowSizes]],
];
$expect = ['One channel for every update', 'A record of every load', 'A team, not a single dispatcher'];

partial('hero', [
    'route' => $route,
    'cls'   => 'sb-hw-hero',
    'eyebrow' => 'How we plug into your operation',
    'h1'    => 'How truck dispatching works {hl}with NorthStar{/hl}',
    'lead'  => 'Hiring a dispatch company should not mean losing control of your trucks. Here is exactly how we start and how we work with you every day.',
    'image' => ['base' => 'nf-hero-how-it-works', 'alt' => 'White semi truck crossing a highway overpass at night, with light trails on the ramps below', 'pos' => '72% 50%'],
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

// Triada bajo el hero: las tres palabras del titular del home con el copy que ya describe cada una (servicios del home y del resumen). Sin H2 nuevos: los títulos son <p>.
partial('trio', ['label' => 'Booked, tracked, documented', 'items' => [
    ['icon' => 'route',      'title' => 'Booked',     'text' => 'We search, negotiate and book freight on Amazon Relay and dry van lanes.'],
    ['icon' => 'locate',     'title' => 'Tracked',    'text' => 'Every trip followed from pickup to POD, with ETAs, arrivals and departures logged.'],
    ['icon' => 'file-check', 'title' => 'Documented', 'text' => 'Paperwork organized and kept on file, so settlements and disputes start with evidence.'],
]]);
?>

<section class="section" aria-label="The four steps">
  <div class="container">
    <?php partial('photo-rows', ['tag' => 'h2', 'rail' => true, 'label' => 'How we start and work with you', 'items' => array_map(static fn(array $st, int $i): array => ['n' => sprintf('%02d', $i + 1), 'title' => $st[0], 'text' => $st[1], 'icon' => $st[2], 'image' => $st[3]], $steps, array_keys($steps))]); ?>
  </div>
</section>

<?php partial('stage-table', ['eyebrow' => 'From booking to settlement', 'title' => 'A load, stage by stage', 'id' => 'hw-stages', 'count' => count(data('stages'))]); ?>

<!-- CORTINA: foto grande fijada que el panel "What you can expect" (curtain-next) cubre al subir (≥1025px con JS); sin JS o en móvil, foto normal -->
<?php partial('curtain', ['image' => ['base' => 'nf-check-tracking-mirror', 'alt' => 'Rain-streaked truck side mirror reflecting a white trailer on a wet highway at dusk', 'pos' => '70% 50%'], 'short' => true]); ?>

<section class="section section--surface curtain-next" aria-labelledby="hw-expect">
  <div class="container stack sb-gap-32">
    <?php partial('section-head', ['title' => 'What you can expect from the desk', 'id' => 'hw-expect']); ?>
    <ul class="g3">
      <?php foreach ($expect as $i => $t): ?>
      <li class="card <?= $i === 2 ? 'card--dark' : 'card--white' ?> sb-hw-item"><?= ns_star(18) ?><span class="head sb-hw-item__text"><?= e($t) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
