<?php
/**
 * /dispatch-for-owner-operators/ — Owner.html. Disponibles: $route, $cfg y helpers.
 * Orden: hero con foto · What you get (g5) · Why owner-operators work with us · FAQ (pre-CTA). El CTA final lo pone el layout.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$gets = [
    ['01', 'Loads booked on your lanes', 'Amazon Relay and dry van, planned around your hours of service and home time.'],
    ['02', 'Rates & broker setup', 'Packets, rate confirmations and BOLs handled for you.'],
    ['03', 'Check calls & ETAs', 'Brokers updated without you picking up the phone.'],
    ['04', 'Evidence on file', 'Arrival timestamps and photos ready when detention or a dispute comes up.'],
    ['05', 'Settlements prepared', 'So you see exactly what each load paid.'],
];
$control = [
    'Every load approved by you',
    'Updates on WhatsApp, where you already work',
    'A team, not one dispatcher who disappears',
];

partial('hero', [
    'route'   => $route,
    'cls'     => 'sa-hero--lead560',
    'h1'      => 'Dispatch services for {hl}owner-operators{/hl}',
    'tagline' => 'You drive. We run the desk.',
    'lead'    => 'NorthStar works as your independent truck dispatcher: we find and book the loads, deal with brokers, track every trip and keep the paperwork that gets you paid.',
    'ctas'    => [
        ['label' => 'Talk to a dispatcher', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true],
        ['label' => 'How we onboard', 'href' => url('/how-it-works/'), 'variant' => 'ghost'],
    ],
    'image'   => ['base' => 'red-semi-dry-van-open-road', 'alt' => 'Red owner-operator semi truck pulling a dry van on an open road'],
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', [
    'text'   => 'Dispatch for owner-operators means an independent dispatch desk finds and books your loads, deals with brokers, tracks every trip and keeps the paperwork that gets you paid. NorthStar runs that desk for you.',
    'points' => [
        'You approve every load',
        'Updates on WhatsApp, where you already work',
        'Arrival timestamps and photos kept on file',
    ],
]);
?>
<section class="section" aria-labelledby="owner-gets">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'owner-gets', 'eyebrow' => 'What you get', 'title' => 'One dispatch desk behind your truck']); ?>
    <div class="g5">
      <?php foreach ($gets as $i => $g): ?>
        <?php partial('card', ['variant' => $i === 4 ? 'dark' : 'light', 'num' => $g[0], 'title' => $g[1], 'text' => $g[2]]); ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section--surface" aria-labelledby="owner-control">
  <div class="container sa-split">
    <div class="sa-split__text">
      <?php partial('section-head', [
          'id' => 'owner-control',
          'eyebrow' => 'Why owner-operators work with us',
          'title' => 'You stay in control.',
          'lead' => 'You approve every load. We tell you what we booked and why, and you can reach the desk on WhatsApp at any time.',
      ]); ?>
    </div>
    <ul class="check-list sa-split__list">
      <?php foreach ($control as $c): ?>
        <li><?= ns_star(16) ?><?= e($c) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Owner-operator questions', 'pre_cta' => true]); ?>
