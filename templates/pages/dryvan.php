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
    'lead'  => 'Steady dry van freight, booked and followed by one desk. We search the boards, negotiate the rate, coordinate pickup and delivery and keep every document on file.',
    'ctas'  => [
        ['label' => 'Talk to a dispatcher', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true],
    ],
    'image' => ['base' => 'nf-hero-dry-van', 'alt' => 'White semi truck pulling a dry van trailer along a highway under an overcast dusk sky', 'pos' => '70% 50%', 'sizes' => '(min-width:1025px) 1350px, (min-width:761px) 100vw, 580px'],
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', [
    'text'   => 'Dry van dispatch service means a desk that finds and books dry van freight, negotiates the rate, coordinates pickup and delivery and keeps every document on file. NorthStar handles it from one dispatch desk.',
    'points' => [
        'Load search and rate negotiation',
        'Check calls and ETAs while the load moves',
        'BOL and POD collected, detention documented',
    ],
]);
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
    'image'   => ['base' => 'nf-check-dry-van-interior', 'alt' => 'Inside an empty dry van trailer, with cargo rails along the walls and a worn wooden floor', 'pos' => '50% 55%'],
    'extra'   => ns_checks(['You approve every load', 'Updates on WhatsApp, where you already work', 'A record of every load', 'A team, not a single dispatcher'], 'What working with the desk means'),
]); ?>

<?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Dry van dispatch questions', 'surface' => 'light', 'pre_cta' => true]); ?>
