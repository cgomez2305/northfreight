<?php
/**
 * /trucking-back-office/ — maqueta BackOffice.html.
 * Orden: hero fotográfico (patrón A) · Included (rejilla numerada: 5 trabajos + celda "Talk to the desk") · One team (banda fotográfica, patrón D: Book → Track → Document → Get paid) ·
 * "From the desk" (artículo destacado + 2 filas, datos de posts.php) · CTA final (layout).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$jobs = [
    ['Settlement preparation', 'Every load reconciled against its rate confirmation.'],
    ['Dispute management', 'Claims followed with the evidence our desk collected on the road.'],
    ['Document organization', 'Rate cons, BOLs, PODs and receipts filed per load.'],
    ['Driver onboarding', 'New drivers set up and ready to roll on day one.'],
    ['Account performance', 'Follow-up on scorecards and account health on Amazon Relay.'],
];
$flow = ['Book', 'Track', 'Document', 'Get paid'];
// Cada círculo abre WhatsApp con un mensaje propio de ese trabajo (el contacto principal del sitio es WhatsApp, sin formulario previo).
$jobItems = array_map(static fn(array $j, int $i): array => [
    'n' => sprintf('%02d', $i + 1), 'title' => $j[0], 'text' => $j[1],
    'href' => wa("Hi, I'd like help with " . strtolower($j[0]) . ' in my trucking back office.'),
    'label' => 'Ask the desk about ' . strtolower($j[0]) . ' on WhatsApp',
], $jobs, array_keys($jobs));

partial('hero', [
    'route'   => $route,
    'cls'     => 'sb-bo-hero',
    'h1'      => 'Trucking back office {hl}services{/hl}',
    'tagline' => 'The work behind the load, done right.',
    'lead'    => 'Paperwork organized, settlements prepared and disputes followed until they close.',
    'ctas'    => [['label' => 'Talk to the desk', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true]],
    'image'   => ['base' => 'nf-hero-back-office', 'alt' => 'Desk with a lamp, a stamp, a stack of blank forms and a laptop beside a rain-covered window at night', 'pos' => '60% 50%'],
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', [
    'text'   => 'Trucking back office services cover the work behind the load: paperwork organized, settlements prepared and disputes followed until they close. The same NorthStar desk books and tracks the load, so it already has every document it needs.',
    'points' => [
        'Settlement preparation and dispute management',
        'Rate cons, BOLs, PODs and receipts filed per load',
        'Driver onboarding and Amazon Relay account follow-up',
    ],
]);
?>

<section class="section" aria-labelledby="bo-included">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'Included', 'title' => 'Five back office jobs off your desk', 'id' => 'bo-included', 'count' => count($jobItems)]); ?>
    <?php partial('numgrid', ['items' => $jobItems, 'label' => 'Back office jobs',
        'cta' => ['title' => 'Talk to the desk', 'text' => 'No forms first. Open WhatsApp with a ready message and a dispatcher replies.', 'href' => wa($route['wa']), 'label' => 'Talk to the desk about your back office on WhatsApp']]); ?>
  </div>
</section>

<?php partial('photo-band', [
    'id'      => 'bo-team',
    'eyebrow' => 'One team',
    'title'   => 'Dispatch and back office, one team',
    'lead'    => 'Because the same desk books and tracks the load, the back office already has every document it needs.',
    'image'   => ['base' => 'nf-resource-agreement', 'alt' => 'Open folder with two blank contract pages, a pen and a desk lamp, with a truck parked in the window behind', 'pos' => '70% 60%'],
    'chips'   => $flow,
    'chips_label' => 'From booking to payment',
    'chips_arrow' => true,
    'chips_end'   => true,
]); ?>

<?php partial('desk-block', [
    'id'      => 'bo-guides',
    'eyebrow' => 'From the desk',
    'title'   => 'Paperwork and pay, explained',
    'lead'    => 'Practical guides on getting paid for every hour at the dock, filling a bill of lading and what dispatch costs.',
    'btn'     => ['label' => 'All articles', 'href' => url('/blog/')],
    'paths'   => ['/blog/lumper-fees-detention-pay/', '/resources/bol-template/', '/blog/how-much-does-truck-dispatch-cost/'],
    'exclude' => ['nf-hero-back-office', 'nf-resource-agreement'],
]); ?>
