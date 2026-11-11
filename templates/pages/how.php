<?php
/**
 * /how-it-works/ — maqueta How.html.
 * Orden: hero fotográfico (patrón A) · In short · triada Booked / Tracked / Documented (iconos sin tarjeta) · 4 pasos (filas apiladas con foto, patrón C; los títulos siguen siendo H2) · A load, stage by stage (partial stage-table) · What you can expect from the desk · CTA final (layout).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$rowSizes = '(min-width:1025px) 1000px, (min-width:761px) 100vw, (min-width:641px) calc(100vw - 40px), 65vw';   // la fila mide ~1089 px: a 1x basta la variante de 1024 (x1,06)
$steps = [
    ['We learn your operation', 'Trucks, equipment, lanes, drivers, home time and the accounts you already run, including Amazon Relay.', 'pin',
     ['base' => 'nf-onboarding-learn', 'alt' => 'Diner booth table by a window with two coffee mugs, a notebook with a pen and a phone, and a truck stop outside at sunrise', 'pos' => '55% 50%', 'sizes' => $rowSizes]],
    ['We define scope & contacts', 'Who approves loads, how and when we report, and which tasks the desk owns from day one.', 'doc',
     ['base' => 'nf-onboarding-scope', 'alt' => 'A hand tracing a route in blue ink on an open road atlas, with a tablet showing a map, a coffee mug and a notebook on a wooden table', 'pos' => '60% 45%', 'sizes' => $rowSizes]],
    ['We run the day-to-day', 'Booking, tracking and documenting every load. You get updates on WhatsApp, where you already work.', 'truck',
     ['base' => 'nf-onboarding-run', 'alt' => 'White semi truck with a sleeper cab at the gate of a logistics facility at sunrise', 'pos' => '65% 55%', 'sizes' => $rowSizes]],
    ['We review & improve', 'Regular reviews of lanes, rates and incidents to keep your trucks earning.', 'trend',
     ['base' => 'nf-onboarding-review', 'alt' => 'A hand marking a printed spreadsheet at a desk, with a laptop showing a bar chart, a coffee mug and truck keys', 'pos' => '55% 50%', 'sizes' => $rowSizes]],
];
$expect = ['One channel for every update', 'A record of every load', 'A team, not a single dispatcher'];

partial('hero', [
    'route' => $route,
    'cls'   => 'sb-hw-hero',
    'eyebrow' => 'How we plug into your operation',
    'h1'    => 'How truck dispatching works {hl}with NorthStar{/hl}',
    'lead'  => 'Hiring a dispatch company should not mean losing control of your trucks. Here is exactly how we start and how we work with you every day.',
    'image' => ['base' => 'nf-hero-how-it-works', 'alt' => 'Aerial view of white semi trucks on a highway interchange with looping ramps at sunset', 'pos' => '72% 50%'],
    'video' => 'how-interchange',   // fase X: video de Carlos (intercambiador aéreo, 8 s, una pasada); póster de escritorio nf-hero-how-it-works-vid
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', summary_for($route));

// Triada bajo el hero: qué hace el desk en cada carga, con el copy que ya describe cada una (servicios del home y del resumen). Sin H2 nuevos: los títulos son <p>.
partial('trio', ['label' => 'What the desk does on every load', 'items' => [
    ['icon' => 'route',      'title' => 'We book the freight',     'text' => 'We search, negotiate and book freight on Amazon Relay and dry van lanes.'],
    ['icon' => 'locate',     'title' => 'We follow every trip',    'text' => 'Every trip followed from pickup to POD, with ETAs, arrivals and departures logged.'],
    ['icon' => 'file-check', 'title' => 'We keep the paperwork', 'text' => 'Paperwork organized and kept on file, so settlements and disputes start with evidence.'],
]]);
?>

<section class="section" aria-label="The four steps">
  <div class="container">
    <?php partial('photo-rows', ['tag' => 'h2', 'rail' => true, 'label' => 'How we start and work with you', 'items' => array_map(static fn(array $st, int $i): array => ['title' => $st[0], 'text' => $st[1], 'icon' => $st[2], 'image' => $st[3]], $steps, array_keys($steps))]); ?>
  </div>
</section>

<?php partial('stage-table', [ 'title' => 'A load, stage by stage', 'id' => 'hw-stages']); ?>

<!-- CORTINA: foto grande fijada que el panel "What you can expect" (curtain-next) cubre al subir (≥1025px con JS); sin JS o en móvil, foto normal -->
<?php partial('curtain', ['image' => ['base' => 'nf-check-tracking-mirror', 'alt' => 'Truck side mirror at sunset reflecting a white trailer and the highway behind it', 'pos' => '70% 50%'], 'short' => true]); ?>

<section class="section section--surface curtain-next" aria-labelledby="hw-expect">
  <div class="container stack sb-gap-32">
    <?php partial('section-head', ['title' => 'What you can expect from the desk', 'id' => 'hw-expect']); ?>
    <ul class="g3">
      <?php foreach ($expect as $i => $t): ?>
      <li class="card <?= $i === 2 ? 'card--dark' : 'card--white' ?> sb-hw-item"><?= ns_star(18) ?><span class="head sb-hw-item__text"><?= e($t) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if (route_is_live('/careers/owner-operators/')): ?><p><a class="card__link" href="<?= e(url('/careers/owner-operators/')) ?>">Partner with the desk <span class="arrow" aria-hidden="true">→</span></a></p><?php endif; ?>
  </div>
</section>
