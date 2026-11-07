<?php
/**
 * /dry-van-dispatch/ — DryVan.html. Disponibles: $route, $cfg y helpers.
 * Orden: hero con foto · Every step of the dry van load (ol g3, 6 pasos) · One desk behind every dry van load (foto ancha con tarjeta solapada y lista de comprobación, patrón B) · FAQ sobre gris (pre-CTA). El CTA final lo pone el layout.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$steps = [
    'Load search & rate negotiation',
    'Route planning by HOS',
    'Broker packets & rate cons',
    'Check calls & ETAs',
    'BOL & POD collection',
    'Detention documentation',
];

partial('hero', [
    'route' => $route,
    'cls'   => 'sa-hero--dryvan sa-hero--lead560',
    'h1'    => 'Dry van {hl}dispatch service{/hl}',
    'lead'  => 'Dry van freight, booked and followed by one desk. We search the boards, negotiate the rate, coordinate pickup and delivery and keep every document on file.',
    'ctas'  => [
        ['label' => 'Talk to a dispatcher', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true],
    ],
    'image' => ['base' => 'nf-hero-dry-van', 'alt' => 'Open dry van trailer at a loading dock with pallets of boxed cargo, a pallet jack and a worker in a safety vest in golden light', 'pos' => '70% 50%'],
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', summary_for($route));
?>
<section class="section" aria-labelledby="dv-steps">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'dv-steps', 'eyebrow' => 'Included', 'title' => 'Every step of the dry van load']); ?>
    <ol class="g3 sa-ol">
      <?php foreach ($steps as $i => $s): ?>
        <li><?php partial('card', ['variant' => $i === 5 ? 'dark' : 'light', 'cls' => 'sa-step', 'num' => sprintf('%02d', $i + 1), 'title' => $s]); ?></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<?php partial('photo-card', [
    'id'      => 'dv-desk',
    'flip'    => true,
    'eyebrow' => 'How we work',
    'title'   => 'One desk behind every dry van load',
    'lead'    => 'The same desk books, tracks and documents every load, as one team.',
    'image'   => ['base' => 'nf-check-dry-van-interior', 'alt' => 'Inside an empty dry van trailer, looking out through the open rear doors toward a warehouse with loading docks', 'pos' => '50% 55%'],
    'extra'   => ns_checks(['You approve every load', 'Updates on WhatsApp, where you already work', 'A record of every load', 'A team, not a single dispatcher'], 'What working with the desk means'),
]); ?>

<?php ns_read_next([
    ['path' => '/how-it-works/', 'title' => 'How truck dispatching works with NorthStar', 'text' => 'Scope, contacts and the day-to-day, step by step.', 'chips' => ['How it works']],
    ['path' => '/blog/how-much-does-truck-dispatch-cost/', 'title' => 'How much does truck dispatch service cost?', 'text' => 'The ways dispatchers charge and the red flags to check before you sign.', 'chips' => ['Article']],
]); ?>

<?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Dry van dispatch questions', 'surface' => 'light', 'pre_cta' => true]); ?>
