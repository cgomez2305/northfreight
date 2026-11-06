<?php
/**
 * /amazon-relay-dispatch/ — Relay.html (diferencial). Disponibles: $route, $cfg y helpers.
 * Orden: hero fotográfico (patrón A) · In short · What we manage (A a E: filas apiladas con foto, patrón C) · Why Relay needs a dedicated desk (foto ancha con tarjeta solapada, patrón B) ·
 * Guides for Relay carriers (bloque "From the desk": destacado + 2 filas, sustituye a la tira de 2 enlaces) · FAQ (pre-CTA). El CTA final lo pone el layout.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$manage = [
    ['A · Load board', 'Block & load booking', 'Monitoring the Relay load board and booking work that fits your lanes and equipment.',
     'truck', ['base' => 'nf-service-dispatch-booking', 'alt' => 'White dry van trailer backed into a warehouse loading dock at dusk', 'pos' => '60% 50%']],
    ['B · Trips', 'Trip & arrival records', 'Schedules, arrivals and departures recorded for every run.',
     'pin', ['base' => 'nf-blog-lumper-detention', 'alt' => 'Loaded trailer at a loading dock at night, with a wall clock above the dock door and a clipboard on a ledge', 'pos' => '50% 40%']],
    ['C · Performance', 'Scorecard follow-up', 'Performance tracked so problems are corrected before they cost you loads.',
     'trend', ['base' => 'nf-blog-relay-load-board', 'alt' => 'Rugged tablet mounted on a truck dashboard with a clipboard below and a trailer yard beyond the windshield', 'pos' => '60% 50%']],
    ['D · Disputes', 'Disputes with evidence', 'Every claim filed with timestamps, photos and documents attached.',
     'doc', ['base' => 'nf-blog-relay-requirements', 'alt' => 'Clipboard with a blank checklist resting on a truck hood, with a trailer at a dock behind', 'pos' => '70% 55%']],
    ['E · Admin', 'Settlements & onboarding', 'Payments reconciled and new drivers set up in the account.',
     'calc', ['base' => 'nf-service-back-office', 'alt' => 'Desk with a stack of paper forms, a pen, a binder clip, a closed laptop and a tablet', 'pos' => '55% 50%']],
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
    'image' => ['base' => 'nf-hero-amazon-relay', 'alt' => 'White semi truck with a dry van trailer parked beside a warehouse dock on a wet night yard', 'pos' => '70% 60%'],
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', summary_for($route));
?>
<section class="section" aria-labelledby="relay-manage">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'relay-manage', 'eyebrow' => 'Inside your Relay account', 'title' => 'What we manage in your Amazon Relay account', 'count' => count($manage)]); ?>
    <?php partial('photo-rows', ['label' => 'What we manage in your Amazon Relay account', 'items' => array_map(static fn(array $m): array => ['tag' => $m[0], 'title' => $m[1], 'text' => $m[2], 'icon' => $m[3], 'image' => $m[4]], $manage)]); ?>
  </div>
</section>

<?php partial('photo-card', [
    'id'    => 'relay-why',
    'flip'  => true,
    'eyebrow' => 'Why it matters',
    'title' => 'Why Relay needs a dedicated desk',
    'lead'  => 'Relay moves fast: good blocks can go quickly and a weak scorecard limits what you can book. A desk that watches the board and fixes issues early keeps your trucks earning.',
    'image' => ['base' => 'nf-setup-amazon-relay', 'alt' => 'White semi truck at a warehouse dock wall at night under tall floodlights', 'pos' => '30% 55%'],
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
        '/blog/amazon-relay-load-board/'   => ['nf-check-owner-approve', 'A hand holding a phone with a check mark on the screen inside a truck cab at dusk'],
        '/blog/amazon-relay-requirements/' => ['nf-resource-bol-template', 'Clipboard with a blank multi-part shipping form and a pen'],
    ],
]); ?>
<?php endif; ?>

<section class="section sa-faq-after pre-cta" aria-labelledby="faq-title">
  <div class="container">
    <?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Amazon Relay dispatch questions', 'section' => false]); ?>
  </div>
</section>
