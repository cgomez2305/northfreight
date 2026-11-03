<?php
/**
 * Home (/) — replica de la maqueta index.html. Disponibles: $route, $cfg y los helpers.
 * Orden: hero · 01 Services · 02 Amazon Relay · 03 Onboarding · 04 Team + video · 05 Evidence · 06 FAQ (+ CTA del layout).
 * El <h1> es el eyebrow pequeño (como la maqueta); el titular grande es un <p>. Estilos en assets/css/pages/home.css.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$variantA = ($cfg['hero_variant'] ?? 'B') === 'A';
$headline = (string) ($cfg['hero_headline'] ?? 'Booked. Tracked. Documented.');
$tags = $variantA
    ? ['Coordinated loads', 'Organized information', 'Constant follow-up']
    : ['Amazon Relay', 'Dry Van', 'Load Tracking', 'Back Office'];

// Hero: mensaje de WhatsApp de la página.
$waHref = wa($route['wa'] ?? null);

// Tamaño de las fotos de las tarjetas de servicio (carril ≥1025px: ancho de tarjeta clamp(320px,40vw,600px) + 18 % de parallax)
$railSizes = '(min-width:1025px) 700px, (min-width:641px) 33vw, 100vw';

$services = [
    ['num' => '01', 'title' => 'Dispatch & load booking',
     'text' => 'We search, negotiate and book freight on Amazon Relay and dry van lanes, plan routes around hours of service and coordinate every pickup with your drivers.',
     'href' => url('/dispatch-for-owner-operators/'), 'cta' => 'Dispatch for owner-operators',
     'image' => ['base' => 'red-semi-dry-van-open-road', 'alt' => 'Red semi truck pulling a dry van on an open road', 'sizes' => $railSizes]],
    ['num' => '02', 'title' => 'Load tracking & check calls',
     'text' => 'Every trip followed from pickup to POD: ETAs, arrivals and departures logged, incidents documented and brokers kept informed, so you are not on the phone.',
     'href' => url('/load-tracking/'), 'cta' => 'Load tracking',
     'image' => ['base' => 'two-semis-hillside-highway', 'alt' => 'Two semi trucks on a highway through the hills', 'sizes' => $railSizes]],
    ['num' => '03', 'title' => 'Trucking back office',
     'text' => 'Paperwork organized, settlements prepared and disputes followed with evidence. We also help onboard new drivers so they are ready to roll on day one.',
     'href' => url('/trucking-back-office/'), 'cta' => 'Back office services',
     'image' => ['base' => 'dry-van-trailers-loading-docks', 'alt' => 'Dry van trailers backed into warehouse loading docks', 'sizes' => $railSizes]],
];

$relay = [
    ['A · Load board', 'Block & load booking', 'Monitoring the Relay load board and booking work that fits your lanes and equipment.'],
    ['B · Trips', 'Trip & arrival records', 'Schedules, arrivals and departures kept on record for every run.'],
    ['C · Performance', 'Scorecard follow-up', 'Performance tracked so issues are corrected before they cost you loads.'],
    ['D · Disputes', 'Disputes with evidence', 'Every claim filed with timestamps, photos and documents attached.'],
];

$steps = [
    ['01', 'We learn your operation', 'Trucks, equipment, lanes, drivers and the accounts you already run, including Amazon Relay.'],
    ['02', 'We define scope & contacts', 'Who approves loads, how we report, and which tasks the desk owns from day one.'],
    ['03', 'We run the day-to-day', 'Booking, tracking and documenting every load, with updates where you already work: WhatsApp.'],
    ['04', 'We review & improve', 'Regular reviews of lanes, rates and incidents to keep your trucks earning.'],
];

$chapters = [
    ['title' => 'How we coordinate loads and drivers', 't' => null, 'href' => url('/our-desk/')],
    ['title' => 'How we document an incident', 't' => null, 'href' => url('/our-desk/')],
    ['title' => 'What being your team really means', 't' => null, 'href' => url('/our-desk/')],
];

$docs = ['Rate confirmation', 'Check-call log', 'Arrival timestamps', 'BOL & POD', 'Incident photos', 'Settlement summary'];

// Desk log de ejemplo (evidencia). El ID de carga es un placeholder: en producción ph() devuelve '' y solo se imprime "POD FILED".
$loadId = ph('ID');
$log = [
    ['06:12', 'Rate confirmation received & filed', false],
    ['09:40', 'Driver checked in at shipper, photo attached', false],
    ['11:55', 'Delay logged with timestamps for detention claim', true],
];

// Cifras ya afirmadas en el copy del sitio (no añadir otras).
$figures = [
    ['4', 'steps to get started'],
    ['1', 'desk that books, tracks and documents'],
    ['0', 'forms before you talk to us'],
];
?>
<!-- HERO: foto protagonista (degradado solo desde la izquierda y abajo) + titular -->
<section class="hero hero--home" id="top">
  <div class="hero__bg"><?= ns_img(['base' => 'truck-oncoming-dusk-mirror', 'alt' => 'Semi truck approaching at dusk, seen from a car’s side mirror', 'eager' => true, 'sizes' => '100vw']) ?></div>
  <div class="container hero__in">
    <div class="hero__main">
      <h1 class="eyebrow home-kicker"><?= e($route['h1']) ?></h1>
      <?php if ($variantA): ?>
      <p class="t-display home-hero__title home-hero__title--a"><?= e($headline) ?></p>
      <?php else: ?>
      <p class="t-display home-hero__title"><span class="home-hero__l1">Booked. Tracked.</span> <span class="home-hero__l2 hl">Documented.</span></p>
      <?php endif; ?>
      <p class="hero__lead t-lead">NorthStar is your remote dispatch and back-office desk. We book Amazon Relay and dry van freight, follow every load to delivery, and keep the paperwork that protects your pay. That is how incidents get handled with evidence and operations run as a team.</p>
      <div class="hero__ctas">
        <a class="btn btn--primary btn--lg" href="<?= e($waHref) ?>" rel="noopener">Talk to a dispatcher <?= ns_icon('arrow', 18) ?></a>
        <a class="btn btn--ghost btn--lg" href="<?= e(url('/how-it-works/')) ?>">See how we onboard</a>
      </div>
    </div>
    <ul class="tags eyebrow" aria-label="What we cover">
      <?php foreach ($tags as $t): ?><li class="tag"><?= e($t) ?></li><?php endforeach; ?>
    </ul>
  </div>
</section>

<?php partial('summary', [
    'variant' => 'lead',
    'text'    => 'NorthStar is a remote truck dispatch company for owner-operators and small fleets. Our trucking dispatch services cover load booking on Amazon Relay and dry van, load tracking from pickup to POD and back-office work, all from one dispatch desk.',
    'points'  => [
        'Amazon Relay and dry van booking',
        'Load tracking and check calls',
        'Settlements, disputes and paperwork',
    ],
]); ?>

<!-- 01 SERVICES: rejilla de 3 tarjetas con foto; con html.js-motion y ≥1025px motion.js la convierte en un carril horizontal fijado (.home-services__pin) -->
<section class="section home-services" id="services" aria-labelledby="services-title">
  <div class="home-services__pin">
    <div class="container">
      <?php partial('section-head', ['eyebrow' => '01 · What we manage', 'title' => 'Three fronts.{br}One dispatch desk.', 'id' => 'services-title', 'split' => true,
          'lead' => 'Most truck dispatch companies stop at the booking. We stay on the load until the money is in. The same team that books it tracks it and closes it out.']); ?>
    </div>
    <div class="container home-rail">
      <div class="g3 home-cards">
        <?php foreach ($services as $s): partial('card', $s + ['size' => 'lg', 'hover' => true]); endforeach; ?>
      </div>
    </div>
  </div>
  <div class="container">
    <p class="eyebrow home-more">
      <?php if (route_is_live('/dry-van-dispatch/')): ?><a href="<?= e(url('/dry-van-dispatch/')) ?>">Dry van dispatch <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
      <?php if (route_is_live('/amazon-relay-dispatch/')): ?><a href="<?= e(url('/amazon-relay-dispatch/')) ?>">Amazon Relay dispatch <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
    </p>
  </div>
</section>

<!-- 02 AMAZON RELAY -->
<section class="home-relay" aria-labelledby="relay-title">
  <div class="container home-relay__in">
    <div class="home-relay__main">
      <span class="t-eyebrow">02 · Specialty</span>
      <h2 class="t-h2" id="relay-title">Amazon Relay dispatch for <span class="hl">carriers who move fast.</span></h2>
      <p class="t-body home-relay__lead">Relay rewards carriers who move fast and keep clean records. Our desk watches the load board for you, books the blocks that fit your trucks and follows performance and disputes inside your account.</p>
      <a class="btn btn--primary home-relay__btn" href="<?= e(url('/amazon-relay-dispatch/')) ?>"><span>Amazon Relay dispatch <span class="arrow" aria-hidden="true">→</span></span></a>
    </div>
    <div class="g2 home-relay__grid">
      <?php foreach ($relay as $i => [$tag, $title, $text]): partial('card', ['tag' => $tag, 'title' => $title, 'text' => $text, 'cls' => 'home-rcard' . ($i === 3 ? ' home-rcard--accent' : '')]); endforeach; ?>
    </div>
  </div>
</section>

<!-- 03 ONBOARDING -->
<section class="section home-onboarding" aria-labelledby="onboarding-title">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => '03 · Onboarding', 'title' => 'How we plug into your operation.', 'id' => 'onboarding-title']); ?>
    <ol class="home-steps">
      <?php foreach ($steps as [$n, $title, $text]): ?>
      <li class="home-step">
        <span class="home-step__n" aria-hidden="true"><?= e($n) ?></span>
        <h3 class="head t-h3"><span class="sr-only">Step <?= e($n) ?>: </span><?= e($title) ?></h3>
        <p class="t-card"><?= e($text) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
    <ul class="home-figures">
      <?php foreach ($figures as [$n, $label]): ?>
      <li class="home-figure"><span class="home-figure__n"><?= e($n) ?></span><span class="home-figure__t"><?= e($label) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- 04 TEAM + VIDEO -->
<section class="section section--surface home-team" aria-labelledby="team-title">
  <div class="container split">
    <div class="home-team__text">
      <span class="t-eyebrow">04 · The desk behind the load</span>
      <h2 class="t-h2" id="team-title">Built by dispatchers.<br><span class="hl">Run as a team.</span></h2>
      <p class="t-body c-slate">Behind every trip there are people coordinating information, decisions and follow-up. Meet the team, hear how we work, and jump straight to the topic you care about.</p>
      <?php partial('video', ['part' => 'chapters', 'id' => 'desk', 'chapters' => $chapters, 'chapters_label' => 'Jump to a chapter']); ?>
    </div>
    <div class="home-team__video">
      <?php partial('video', ['part' => 'player', 'id' => 'desk',
          'image' => ['base' => 'dispatch-team-headsets-office', 'alt' => 'Dispatchers wearing headsets at their workstations'],
          'title' => 'A load doesn’t dispatch itself. {hl}There’s a team behind it.{/hl}', 'meta' => 'Video · 3 to 4 min']); ?>
    </div>
  </div>
</section>

<!-- 05 EVIDENCE -->
<section class="section" aria-labelledby="evidence-title">
  <div class="container split home-evidence">
    <div class="home-evidence__text">
      <?php partial('section-head', ['eyebrow' => '05 · Proof, not promises', 'title' => 'Incidents are handled with evidence.', 'id' => 'evidence-title',
          'lead' => 'When a broker questions detention, a lumper fee or a late arrival, the answer is already on file. Here is what our desk keeps for every load.']); ?>
      <ul class="home-docs" aria-label="Documents the desk keeps for every load">
        <?php foreach ($docs as $i => $d): ?>
        <li class="home-doc<?= $i === 5 ? ' home-doc--on' : '' ?>"><?= e($d) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <figure class="data-card home-log" aria-label="Desk log, example">
      <div class="data-card__head">
        <span class="eyebrow home-log__title">Desk log</span>
        <span class="data-card__label t-meta">Example</span>
      </div>
      <ul class="log">
        <?php foreach ($log as [$time, $text, $on]): ?>
        <li class="log__row<?= $on ? ' log__row--on' : '' ?>"><span class="log__time"><?= e($time) ?></span><span class="log__text"><?= e($text) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <p class="data-card__status"><span class="data-card__dot" aria-hidden="true"></span>POD FILED<?= $loadId !== '' ? ' · LOAD #' . $loadId : '' ?></p>
    </figure>
  </div>
</section>

<!-- 06 FAQ -->
<?php partial('faq', ['items' => route_faq_items($route), 'eyebrow' => '06 · FAQ', 'title' => 'Truck dispatch questions carriers ask us.', 'pre_cta' => true]); ?>
