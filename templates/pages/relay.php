<?php
/**
 * /amazon-relay-dispatch/ — Relay.html (diferencial). Disponibles: $route, $cfg y helpers.
 * Orden: hero fotográfico (patrón A) · In short · What we manage (A a E: filas apiladas con foto, patrón C) · Why Relay needs a dedicated desk (foto ancha con tarjeta solapada, patrón B) ·
 * Guides for Relay carriers (bloque "From the desk": destacado + 2 filas, sustituye a la tira de 2 enlaces) · FAQ (pre-CTA). El CTA final lo pone el layout.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$manage = [
    ['Load board', 'Block & load booking', 'Monitoring the Relay load board and booking work that fits your lanes and equipment.',
     'truck', ['base' => 'nf-service-dispatch-booking', 'alt' => 'A worker in a safety vest walking toward the loading docks of a warehouse, with a white trailer parked alongside in warm evening light', 'pos' => '60% 50%']],
    ['Trips', 'Trip & arrival records', 'Schedules, arrivals and departures recorded for every run.',
     'pin', ['base' => 'nf-blog-lumper-detention', 'alt' => 'Stretch-wrapped pallets in a warehouse loading bay in late-afternoon sun, with a wall clock above an open dock door and a worker far in the background', 'pos' => '50% 40%']],
    ['Performance', 'Scorecard follow-up', 'Performance tracked so problems are corrected before they cost you loads.',
     'trend', ['base' => 'nf-blog-relay-load-board', 'alt' => 'An open laptop on the hood of a white truck in a freight yard at sunset, with a coiled blue air line in the foreground and rows of trailers behind', 'pos' => '60% 50%']],
    ['Disputes', 'Disputes with evidence', 'Every claim filed with timestamps, photos and documents attached.',
     'doc', ['base' => 'nf-blog-relay-requirements', 'alt' => 'A white hard hat and a folded high-visibility vest beside an open folder of blank pages and a clipboard on a steel table at a loading dock', 'pos' => '70% 55%']],
    ['E · Admin', 'Settlements & onboarding', 'Payments reconciled and new drivers set up in the account.',
     'calc', ['base' => 'nf-service-back-office', 'alt' => 'Desk by a window with a stack of printed forms, a hand holding a pen, a keyboard and a monitor', 'pos' => '55% 50%']],
];
$guides = array_filter([
    ['/blog/amazon-relay-requirements/', 'Amazon Relay requirements for carriers'],
    ['/blog/amazon-relay-load-board/', 'How the Amazon Relay load board works'],
], static fn(array $g): bool => route_is_live($g[0]));

partial('hero', [
    'route' => $route,
    'cls'   => 'sa-hero--relay',
    'h1'    => 'Amazon Relay dispatch, {hl}handled end to end{/hl}',
    'lead'  => 'Relay rewards carriers who move fast and keep clean records. Our desk watches the load board, books the work that fits your trucks and manages performance and disputes inside your account.',
    'ctas'  => [
        ['label' => 'Ask about Relay dispatch', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true],
    ],
    'image' => ['base' => 'nf-hero-amazon-relay', 'alt' => 'Aerial view of a white semi truck with a dry van trailer driving toward a distribution warehouse with a row of loading docks at sunset', 'pos' => '70% 30%'],
    'video' => 'relay-dock',   // fase X: video de Carlos (patio de muelles aéreo, 8 s, una pasada); póster de escritorio nf-hero-amazon-relay-vid
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', summary_for($route));
?>
<section class="section" aria-labelledby="relay-manage">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'relay-manage', 'eyebrow' => 'Inside your Relay account', 'title' => 'What we manage in your Amazon Relay account']); ?>
    <?php partial('photo-rows', ['label' => 'What we manage in your Amazon Relay account', 'items' => array_map(static fn(array $m): array => ['tag' => $m[0], 'title' => $m[1], 'text' => $m[2], 'icon' => $m[3], 'image' => $m[4]], $manage)]); ?>
  </div>
</section>

<?php partial('photo-card', [
    'id'    => 'relay-why',
    'eyebrow' => 'Why it matters',
    'title' => 'Why Relay needs a dedicated desk',
    'lead'  => 'Relay moves fast: good blocks can go quickly and a weak scorecard limits what you can book. A desk that watches the board and fixes issues early keeps your trucks earning.',
    'image' => ['base' => 'nf-setup-amazon-relay', 'alt' => 'White semi truck with a dry van trailer driving past a long distribution warehouse with lit loading docks at dusk', 'pos' => '30% 55%'],
]); ?>

<?php if ($guides): ?>
<?php partial('desk-block', [
    'id'      => 'relay-guides',
    'eyebrow' => 'From the desk',
    'title'   => 'Guides for Relay carriers',
    'lead'    => 'How the Relay load board works, what Relay requires from carriers and what dispatch costs, in plain language.',
    'btn'     => ['label' => 'All articles', 'href' => url('/blog/')],
    'paths'   => ['/blog/amazon-relay-load-board/', '/blog/amazon-relay-requirements/', '/blog/how-much-does-truck-dispatch-cost/'],
    // las fotos propias de esos artículos ya están en las filas de arriba: aquí llevan otra (sin repetir foto en la página)
    'exclude' => array_merge(array_map(static fn(array $m): string => $m[4]['base'], $manage), ['nf-hero-amazon-relay', 'nf-setup-amazon-relay']),
    'alt'     => [
        '/blog/amazon-relay-load-board/'   => ['nf-check-owner-approve', 'Hands holding a smartphone beside a white truck at a fuel station at sunset'],
        '/blog/amazon-relay-requirements/' => ['nf-resource-bol-template', 'A clipboard with a blank multi-part shipping form and a pen on a steel dock plate, with a pallet and a warm sunset behind'],
    ],
]); ?>
<?php endif; ?>

<section class="section sa-faq-after pre-cta" aria-labelledby="faq-title">
  <div class="container">
    <?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Amazon Relay dispatch questions', 'section' => false]); ?>
  </div>
</section>
