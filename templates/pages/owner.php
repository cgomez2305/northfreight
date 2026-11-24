<?php
/**
 * /dispatch-for-owner-operators/ — Owner.html. Disponibles: $route, $cfg y helpers.
 * Orden: hero fotográfico (patrón A) · What you get (rejilla numerada con filete y círculo de flecha, 5 + celda CTA) · Why owner-operators work with us (foto ancha con tarjeta solapada y lista de comprobación, patrón B) ·
 * Pestañas "Not running a single truck?" (Small fleet / Amazon Relay carrier) · FAQ (pre-CTA). El CTA final lo pone el layout.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$gets = [
    ['01', 'Loads booked on your lanes', 'Amazon Relay and dry van, planned around your hours of service and home time.'],
    ['02', 'Rates & broker setup', 'Packets, rate confirmations and BOLs handled for you.'],
    ['03', 'Check calls & ETAs', 'Brokers updated without you picking up the phone.'],
    ['04', 'Evidence on file', 'Arrival timestamps and photos ready when detention or a dispute comes up.'],
    ['05', 'Settlements prepared', 'So you see exactly what each load paid.'],
];
// Cada círculo abre WhatsApp con un mensaje propio de esa línea (el contacto principal del sitio es WhatsApp, sin formulario previo).
$getItems = array_map(static fn(array $g): array => [
    'title' => $g[1], 'text' => $g[2],
    'href' => wa("Hi, I'm an owner-operator and I'd like to talk about " . strtolower($g[1]) . ' with NorthStar.'),
    'label' => 'Ask the desk about ' . strtolower($g[1]) . ' on WhatsApp',
], $gets);
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
    'lead'    => 'NorthStar works as your dispatch desk: we find and book the loads, deal with brokers, track every trip and keep the paperwork that gets you paid.',
    'ctas'    => [
        ['label' => 'Talk to a dispatcher', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true],
        ['label' => 'How we onboard', 'href' => url('/how-it-works/'), 'variant' => 'ghost'],
    ],
    'image'   => ['base' => 'nf-hero-owner-operators', 'alt' => 'Inside a truck cab at sunset, with a hand on the steering wheel and an empty highway ahead', 'pos' => '62% 100%', 'pos_d' => '0% 100%'],
    'video'   => 'owner-cab',   // fase W: video de Carlos (cabina al atardecer, 8 s, una pasada); póster de escritorio nf-hero-owner-operators-vid
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', summary_for($route));
?>
<section class="section" aria-labelledby="owner-gets">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'owner-gets', 'title' => 'One dispatch desk behind your truck']); ?>
    <?php partial('numgrid', ['items' => $getItems, 'label' => 'What you get',
        'cta' => ['title' => 'Talk to the desk', 'text' => 'No forms first. Open WhatsApp with a ready message to send to a dispatcher.', 'href' => wa($route['wa']), 'label' => 'Talk to a dispatcher on WhatsApp']]); ?>
  </div>
</section>

<?php partial('photo-card', [
    'id'      => 'owner-control',
    'title'   => 'You stay in control.',
    'lead'    => 'You approve every load. We tell you what we booked and why, and you can message the desk on WhatsApp.',
    'image'   => ['base' => 'nf-check-owner-approve', 'alt' => 'Hands holding a smartphone beside a white truck at a fuel station at sunset', 'pos' => '60% 100%'],
    'extra'   => ns_checks($control, 'What staying in control means'),
]); ?>

<?php partial('tabs', [
    'id'      => 'owner-setups',
    'title'   => 'Running more than one truck?',
    'label'   => 'Choose your setup',
    'items'   => [
        ['label' => 'Small fleet', 'title' => 'Small fleet', 'wa' => "Hi, I run a small fleet and I'd like to talk about dispatch with NorthStar.",
         'points' => ['A team, not a single dispatcher', 'New drivers set up in your accounts', 'Who approves loads, how we report and which tasks the desk owns'],
         'image' => ['base' => 'nf-setup-small-fleet', 'alt' => 'Several white semi trucks and a white pickup parked in a row in a depot lot at sunset', 'pos' => '50% 70%'],
         'video' => 'small-fleet-yard',
         'link' => ['label' => 'See how we onboard', 'href' => url('/how-it-works/')]],
        ['label' => 'Amazon Relay carrier', 'title' => 'Amazon Relay carrier', 'wa' => 'relay',
         'points' => ['Block and load booking', 'Trip and arrival records', 'Scorecard follow-up and disputes filed with evidence'],
         'image' => ['base' => 'nf-setup-amazon-relay', 'alt' => 'White semi truck with a dry van trailer driving past a long distribution warehouse with lit loading docks at dusk', 'pos' => '50% 55%'],
         'video' => 'relay-yard-dusk',
         'link' => ['label' => 'Amazon Relay dispatch', 'href' => url('/amazon-relay-dispatch/')]],
    ],
]); ?>

<?php if (route_is_live('/careers/owner-operators/')): ?>
<section class="section" aria-label="Careers">
  <div class="container">
    <?php partial('row-card', ['href' => url('/careers/owner-operators/'), 'title' => 'Looking for owner operator jobs?', 'text' => 'It is a dispatch partnership, not a job offer. See how partnering with the desk works and apply.', 'chips' => ['Careers', 'Apply'], 'h' => 'p',
        'image' => ['base' => 'nf-setup-owner-operator', 'alt' => 'White long-hood semi truck with a dry van trailer parked in a rest area at sunrise, with a person standing beside the cab']]); ?>
  </div>
</section>
<?php endif; ?>

<?php ns_read_next([
    ['path' => '/blog/how-much-does-truck-dispatch-cost/', 'title' => 'How much does truck dispatch service cost?', 'text' => 'The ways dispatchers charge and the red flags to check before you sign.', 'chips' => ['Article']],
    ['path' => '/blog/lumper-fees-detention-pay/', 'title' => 'Lumper fees and detention pay', 'text' => 'How to document both and actually get paid.', 'chips' => ['Article']],
]); ?>

<?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Owner-operator dispatch questions', 'pre_cta' => true]); ?>
