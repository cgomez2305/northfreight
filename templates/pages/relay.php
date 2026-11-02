<?php
/**
 * /amazon-relay-dispatch/ — Relay.html (diferencial). Disponibles: $route, $cfg y helpers.
 * Orden: hero apilado con línea de carril · What we manage (A–E, g5) · Why Relay needs a dedicated desk (texto + foto) ·
 * Guides for Relay carriers (2 enlaces a artículos) · FAQ (pre-CTA). El CTA final lo pone el layout.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$manage = [
    ['A · Load board', 'Block & load booking', 'Monitoring the Relay load board and booking work that fits your lanes and equipment.'],
    ['B · Trips', 'Trip & arrival records', 'Schedules, arrivals and departures recorded for every run.'],
    ['C · Performance', 'Scorecard follow-up', 'Performance tracked so problems are corrected before they cost you loads.'],
    ['D · Disputes', 'Disputes with evidence', 'Every claim filed with timestamps, photos and documents attached.'],
    ['E · Admin', 'Settlements & onboarding', 'Payments reconciled and new drivers set up in the account.'],
];
$guides = array_filter([
    ['/blog/amazon-relay-requirements/', 'Amazon Relay requirements for carriers'],
    ['/blog/amazon-relay-load-board/', 'How the Amazon Relay load board works'],
], static fn(array $g): bool => route_is_live($g[0]));

partial('hero', [
    'route' => $route,
    'cls'   => 'sa-hero--relay',
    'deco'  => 'lane',
    'stack' => true,
    'h1'    => 'Amazon Relay dispatch, {hl}handled end to end{/hl}',
    'lead'  => 'Relay rewards carriers who move fast and keep clean records. Our desk watches the load board, books the work that fits your trucks and manages performance and disputes inside your account.',
    'ctas'  => [
        ['label' => 'Ask about Relay dispatch', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true],
    ],
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
    <div class="g5">
      <?php foreach ($manage as $i => $m): ?>
        <?php partial('card', ['variant' => $i === 4 ? 'dark' : 'light', 'tag' => $m[0], 'title' => $m[1], 'text' => $m[2]]); ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section--surface" aria-labelledby="relay-why">
  <div class="container sa-split">
    <div class="sa-split__text sa-split__text--wide">
      <?php partial('section-head', [
          'id' => 'relay-why',
          'eyebrow' => 'Why it matters',
          'title' => 'Why Relay needs a dedicated desk',
          'lead' => 'Relay moves fast: good blocks go in minutes and a weak scorecard limits what you can book. A desk that watches the board and fixes issues early keeps your trucks earning.',
      ]); ?>
    </div>
    <div class="sa-media">
      <?= ns_img(['base' => 'dry-van-trailers-loading-docks', 'alt' => 'Dry van trailers backed into warehouse loading docks', 'sizes' => '(min-width:1025px) 600px, 100vw']) ?>
    </div>
  </div>
</section>

<?php if ($guides): ?>
<section class="sa-guides" aria-labelledby="relay-guides">
  <div class="container sa-guides__in">
    <h2 class="t-eyebrow sa-guides__label" id="relay-guides">Guides for Relay carriers</h2>
    <?php foreach ($guides as $g): ?>
      <a class="cut-s sa-guides__link" href="<?= e(url($g[0])) ?>"><?= e($g[1]) ?> <span class="arrow" aria-hidden="true">→</span></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="section sa-faq-after pre-cta" aria-labelledby="faq-title">
  <div class="container">
    <?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Amazon Relay dispatch questions', 'section' => false]); ?>
  </div>
</section>
