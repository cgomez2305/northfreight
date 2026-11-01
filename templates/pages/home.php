<?php
/**
 * Home (/) — replica de la maqueta index.html. Disponibles: $route, $cfg y los helpers.
 * Orden: hero · ticker · 01 Services · 02 Amazon Relay · 03 Onboarding · 04 Team + video · 05 Evidence · 06 FAQ (+ CTA del layout).
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

// Icono de la tarjeta 03 (documento con check, como la maqueta; no existe en ns_icon).
$iconDocCheck = '<svg class="icon" width="26" height="26" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13l2 2 4-4"/></svg>';

$ticker = ['Load booking', 'Amazon Relay', 'Check calls', 'POD & BOL filing', 'Disputes with evidence', 'Settlements'];

$services = [
    ['icon' => 'truck', 'num' => '01', 'title' => 'Dispatch & load booking',
     'text' => 'We search, negotiate and book freight on Amazon Relay and dry van lanes, plan routes around hours of service and coordinate every pickup with your drivers.',
     'href' => url('/dispatch-for-owner-operators/'), 'cta' => 'Dispatch for owner-operators'],
    ['icon' => 'pin', 'num' => '02', 'title' => 'Load tracking & check calls',
     'text' => 'Every trip followed from pickup to POD: ETAs, arrivals and departures logged, incidents documented and brokers kept informed — without you on the phone.',
     'href' => url('/load-tracking/'), 'cta' => 'Load tracking'],
    ['icon_html' => $iconDocCheck, 'num' => '03', 'title' => 'Trucking back office',
     'text' => 'Paperwork organized, settlements prepared and disputes followed with evidence. We also help onboard new drivers so they are ready to roll on day one.',
     'href' => url('/trucking-back-office/'), 'cta' => 'Back office services'],
];

$relay = [
    ['A · Load board', 'Block & load booking', 'Monitoring the Relay load board and booking work that fits your lanes and equipment.'],
    ['B · Trips', 'Trip & arrival records', 'Schedules, arrivals and departures kept on record for every run.'],
    ['C · Performance', 'Scorecard follow-up', 'Performance tracked so issues are corrected before they cost you loads.'],
    ['D · Disputes', 'Disputes with evidence', 'Every claim filed with timestamps, photos and documents attached.'],
];

$steps = [
    ['01', 'We learn your operation', 'Trucks, equipment, lanes, drivers and the accounts you already run — including Amazon Relay.'],
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
?>
<!-- HERO -->
<section class="hero hero--home on-dark" id="top">
  <svg class="hero__deco" aria-hidden="true" focusable="false" viewBox="0 0 1440 800" preserveAspectRatio="none">
    <defs><pattern id="hero-grid" width="48" height="48" patternUnits="userSpaceOnUse"><path class="hero__gridline" d="M48 0H0V48"/></pattern></defs>
    <rect width="1440" height="800" fill="url(#hero-grid)"/>
    <polygon points="-40,800 820,140 860,140 120,800"/>
    <path class="lane-dash" d="M-40 800 L840 130"/>
  </svg>
  <div class="container hero__in">
    <div class="hero__main anim-rise">
      <div class="hero__eyebrow">
        <?= ns_star(16) ?>
        <h1 class="eyebrow home-kicker"><?= e($route['h1']) ?></h1>
      </div>
      <?php if ($variantA): ?>
      <p class="t-display home-hero__title home-hero__title--a"><?= e($headline) ?></p>
      <?php else: ?>
      <p class="t-display home-hero__title">Booked.<br>Tracked.<br><span class="hl">Documented.</span></p>
      <?php endif; ?>
      <?= ns_swoosh(220, 14) ?>
      <p class="hero__lead t-lead">NorthStar is your remote dispatch and back-office desk. We book Amazon Relay and dry van freight, follow every load to delivery, and keep the paperwork that protects your pay — so incidents are handled with evidence and operations run as a team.</p>
      <div class="hero__ctas">
        <a class="btn btn--primary btn--lg" href="<?= e($waHref) ?>" rel="noopener">Talk to a dispatcher <?= ns_icon('arrow', 18) ?></a>
        <a class="btn btn--ghost btn--lg" href="<?= e(url('/how-it-works/')) ?>">See how we onboard</a>
      </div>
      <ul class="tags eyebrow" aria-label="What we cover">
        <?php foreach ($tags as $t): ?><li class="tag"><?= e($t) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <div class="hero__aside">
      <div class="hero__media hero__media--stripe">
        <div class="hero__clip"><?= ns_img(['base' => 'white-semi-mountain-highway-sunrise', 'alt' => 'White semi truck with dry van trailer driving on a mountain highway at sunrise', 'eager' => true, 'sizes' => '(min-width:1025px) 540px, 100vw']) ?></div>
        <?= ns_star(64, 'hero__star twinkle') ?>
      </div>
      <aside class="data-card anim-rise home-log" aria-label="Example desk log">
        <div class="data-card__head">
          <span class="eyebrow data-card__label home-log__label">Desk log · Example</span>
          <span class="data-card__status"><span class="data-card__dot" aria-hidden="true"></span>IN TRANSIT · LOAD #<?= ph('ID') ?></span>
        </div>
        <ul class="log">
          <li class="log__row"><span class="log__time">06:12</span><span class="log__text">Rate confirmation received &amp; filed</span></li>
          <li class="log__row"><span class="log__time">09:40</span><span class="log__text">Driver checked in at shipper — photo attached</span></li>
          <li class="log__row log__row--on"><span class="log__time">11:55</span><span class="log__text">Delay logged with timestamps for detention claim</span></li>
        </ul>
      </aside>
    </div>
  </div>
</section>

<!-- TICKER -->
<div class="ticker home-ticker">
  <div class="ticker__track home-ticker__track">
    <ul class="home-ticker__set">
      <?php foreach ($ticker as $t): ?><li><span><?= e($t) ?></span><?= ns_star(13) ?></li><?php endforeach; ?>
    </ul>
    <ul class="home-ticker__set" aria-hidden="true">
      <?php foreach ($ticker as $t): ?><li><span><?= e($t) ?></span><?= ns_star(13) ?></li><?php endforeach; ?>
    </ul>
  </div>
</div>

<!-- 01 SERVICES -->
<section class="section home-services" id="services" aria-labelledby="services-title">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => '01 — What we manage', 'title' => 'Three fronts.{br}One dispatch desk.', 'id' => 'services-title', 'swoosh' => true, 'split' => true,
        'lead' => 'Most truck dispatch companies stop at the booking. We stay on the load until the money is in — the same team that books it tracks it and closes it out.']); ?>
    <div class="g3 gap-16">
      <?php foreach ($services as $s): partial('card', $s + ['size' => 'lg', 'hover' => true, 'bar' => true, 'min_title' => true]); endforeach; ?>
    </div>
    <p class="eyebrow home-more">
      <?php if (route_is_live('/dry-van-dispatch/')): ?><a href="<?= e(url('/dry-van-dispatch/')) ?>">Dry van dispatch <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
      <?php if (route_is_live('/amazon-relay-dispatch/')): ?><a href="<?= e(url('/amazon-relay-dispatch/')) ?>">Amazon Relay dispatch <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
    </p>
  </div>
</section>

<!-- 02 AMAZON RELAY -->
<section class="home-relay on-dark" aria-labelledby="relay-title">
  <?= ns_star(440, 'home-relay__star') ?>
  <div class="container home-relay__in">
    <div class="home-relay__main">
      <span class="t-eyebrow">02 — Specialty</span>
      <h2 class="t-h2" id="relay-title">Amazon Relay dispatch, <span class="hl">handled end to end.</span></h2>
      <p class="t-body home-relay__lead">Relay rewards carriers who move fast and keep clean records. Our desk watches the load board for you, books the blocks that fit your trucks and follows performance and disputes inside your account.</p>
      <a class="btn btn--primary home-relay__btn" href="<?= e(url('/amazon-relay-dispatch/')) ?>"><span>Amazon Relay dispatch <span class="arrow" aria-hidden="true">→</span></span></a>
    </div>
    <div class="g2 gap-12 home-relay__grid">
      <?php foreach ($relay as $i => [$tag, $title, $text]): partial('card', ['tag' => $tag, 'title' => $title, 'text' => $text, 'cls' => 'home-rcard' . ($i === 3 ? ' home-rcard--accent' : '')]); endforeach; ?>
    </div>
  </div>
</section>

<!-- 03 ONBOARDING -->
<section class="section home-onboarding" aria-labelledby="onboarding-title">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => '03 — Onboarding', 'title' => 'How we plug into your operation.', 'id' => 'onboarding-title', 'swoosh' => true]); ?>
    <ol class="g4 home-steps">
      <?php foreach ($steps as $i => [$n, $title, $text]): ?>
      <li class="home-step">
        <?php if ($i < 3): ?>
        <span class="head home-step__badge cut-s" aria-hidden="true"><?= e($n) ?></span>
        <?php else: ?>
        <span class="home-step__badge home-step__badge--star cut-s" aria-hidden="true"><?= ns_star(30, 'twinkle') ?></span>
        <?php endif; ?>
        <h3 class="head t-h3 c-navy"><span class="sr-only">Step <?= e($n) ?>: </span><?= e($title) ?></h3>
        <p class="t-card c-slate"><?= e($text) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- 04 TEAM + VIDEO -->
<section class="section section--surface home-team" aria-labelledby="team-title">
  <div class="container split">
    <div class="home-team__text">
      <span class="t-eyebrow">04 — The desk behind the load</span>
      <h2 class="t-h2 c-navy" id="team-title">Built by dispatchers.<br><span class="hl">Run as a team.</span></h2>
      <p class="t-body c-slate">Behind every trip there are people coordinating information, decisions and follow-up. Meet the team, hear how we work, and jump straight to the topic you care about.</p>
      <?php partial('video', ['part' => 'chapters', 'id' => 'desk', 'theme' => 'light', 'chapters' => $chapters, 'chapters_label' => 'Jump to a chapter']); ?>
    </div>
    <div class="home-team__video">
      <?php partial('video', ['part' => 'player', 'id' => 'desk', 'theme' => 'light',
          'image' => ['base' => 'truck-oncoming-dusk-mirror', 'alt' => 'Semi truck approaching at dusk, seen from a car’s side mirror'],
          'title' => 'A load doesn’t dispatch itself. {hl}There’s a team behind it.{/hl}', 'meta' => 'Video · 3–4 min']); ?>
    </div>
  </div>
</section>

<!-- 05 EVIDENCE -->
<section class="section" aria-labelledby="evidence-title">
  <div class="container split">
    <?php partial('section-head', ['eyebrow' => '05 — Proof, not promises', 'title' => 'Incidents are handled with evidence.', 'id' => 'evidence-title', 'cls' => 'home-evidence__head',
        'lead' => 'When a broker questions detention, a lumper fee or a late arrival, the answer is already on file. Here is what our desk keeps for every load.']); ?>
    <ul class="g3h gap-10 home-docs">
      <?php foreach ($docs as $i => $d): ?>
      <li class="home-docs__item"><?php partial('card', ['size' => 'tile', 'variant' => $i === 5 ? 'dark' : 'light', 'code' => 'DOC · ' . sprintf('%02d', $i + 1), 'title' => $d]); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- 06 FAQ -->
<?php partial('faq', ['items' => route_faq_items($route), 'eyebrow' => '06 — FAQ', 'title' => 'Questions carriers ask us.', 'surface' => 'light', 'pre_cta' => true]); ?>
