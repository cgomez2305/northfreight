<?php
/**
 * /amazon-relay-dispatch/ — Relay.html (diferencial). Disponibles: $route, $cfg y helpers.
 * Orden: hero fotográfico (patrón A) · In short · What we manage (A a E: filas apiladas con foto, patrón C) · Why Relay needs a dedicated desk (foto ancha con tarjeta solapada, patrón B) ·
 * Guides for Relay carriers (2 enlaces a artículos) · FAQ (pre-CTA). El CTA final lo pone el layout.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$manage = [
    ['A · Load board', 'Block & load booking', 'Monitoring the Relay load board and booking work that fits your lanes and equipment.',
     'truck', ['base' => 'nf-trailer-dock-dusk', 'alt' => 'White dry van trailer backed into a warehouse loading dock at dusk', 'pos' => '60% 50%']],
    ['B · Trips', 'Trip & arrival records', 'Schedules, arrivals and departures recorded for every run.',
     'pin', ['base' => 'nf-dock-waiting-clock', 'alt' => 'Wall clock beside a loading dock door at night as a trailer waits', 'pos' => '50% 40%']],
    ['C · Performance', 'Scorecard follow-up', 'Performance tracked so problems are corrected before they cost you loads.',
     'trend', ['base' => 'nf-tablet-cab-night-yard', 'alt' => 'Tablet mounted in a truck cab at night with a yard full of trailers beyond the windshield', 'pos' => '60% 50%']],
    ['D · Disputes', 'Disputes with evidence', 'Every claim filed with timestamps, photos and documents attached.',
     'doc', ['base' => 'nf-clipboard-on-truck-hood', 'alt' => 'Checklist on a clipboard resting on a truck hood at a night dock', 'pos' => '70% 55%']],
    ['E · Admin', 'Settlements & onboarding', 'Payments reconciled and new drivers set up in the account.',
     'calc', ['base' => 'nf-logistics-desk-cyan', 'alt' => 'Desk at night with a laptop, a tablet showing a table, a stack of paperwork and a pen under a lamp', 'pos' => '55% 50%']],
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
    'image' => ['base' => 'nf-trailer-dock-night-mist', 'alt' => 'White semi truck with a dry van trailer parked at a misty warehouse dock at night', 'pos' => '70% 60%'],
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', [
    'text'   => 'Amazon Relay dispatch is hands-on management of your Relay account. If you are an Amazon Relay carrier, our desk watches the load board, books the work that fits your trucks and manages performance and disputes inside your account.',
    'points' => [
        'Block and load booking',
        'Trip and arrival records',
        'Scorecard follow-up and disputes filed with evidence',
    ],
]);
?>
<section class="section" aria-labelledby="relay-manage">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'relay-manage', 'eyebrow' => 'Inside your Relay account', 'title' => 'What we manage in your Amazon Relay account']); ?>
    <?php partial('photo-rows', ['label' => 'What we manage in your Amazon Relay account', 'items' => array_map(static fn(array $m): array => ['tag' => $m[0], 'title' => $m[1], 'text' => $m[2], 'icon' => $m[3], 'image' => $m[4]], $manage)]); ?>
  </div>
</section>

<?php partial('photo-card', [
    'id'    => 'relay-why',
    'flip'  => true,
    'eyebrow' => 'Why it matters',
    'title' => 'Why Relay needs a dedicated desk',
    'lead'  => 'Relay moves fast: good blocks go in minutes and a weak scorecard limits what you can book. A desk that watches the board and fixes issues early keeps your trucks earning.',
    'image' => ['base' => 'nf-hitching-trailer-rain', 'alt' => 'White semi truck backing under a dry van trailer in the rain at night', 'pos' => '30% 55%'],
]); ?>

<?php if ($guides): ?>
<section class="sa-guides" aria-labelledby="relay-guides">
  <div class="container sa-guides__in">
    <h2 class="t-eyebrow sa-guides__label" id="relay-guides">Guides for Relay carriers</h2>
    <?php foreach ($guides as $g): ?>
      <a class="sa-guides__link" href="<?= e(url($g[0])) ?>"><?= e($g[1]) ?> <span class="arrow" aria-hidden="true">→</span></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="section sa-faq-after pre-cta" aria-labelledby="faq-title">
  <div class="container">
    <?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Amazon Relay dispatch questions', 'section' => false]); ?>
  </div>
</section>
