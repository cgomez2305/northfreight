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

// Hero: mensaje de WhatsApp de la página. $loadId: marca de placeholder (vacío en producción: entonces no se imprime "LOAD #").
$loadId = ph('ID');
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
<section class="hero hero--home on-dark" id="top" data-motion="hero">
  <div class="container hero__in">
    <div class="hero__main anim-rise">
      <div class="hero__eyebrow">
        <?= ns_star(16) ?>
        <h1 class="eyebrow home-kicker"><?= e($route['h1']) ?></h1>
      </div>
      <?php if ($variantA): ?>
      <p class="t-display home-hero__title home-hero__title--a"><?= e($headline) ?></p>
      <?php else: ?>
      <p class="t-display home-hero__title"><span class="hw hw--1" data-w="Booked.">Booked.</span><br><span class="hw hw--2" data-w="Tracked.">Tracked.</span><br><span class="hw hw--3 hl" data-w="Documented.">Documented.</span></p>
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
      <figure class="bp" id="bp" aria-labelledby="bp-lbl">
        <div class="bp__head">
          <div class="bp__id">
            <span class="eyebrow bp__label" id="bp-lbl" aria-hidden="true">Example lane</span>
            <span class="bp__status"><span class="data-card__dot" aria-hidden="true"></span><span class="bp__st"><span class="bp__s bp__s--a" aria-hidden="true">IN TRANSIT</span><span class="bp__s bp__s--b">POD FILED<?= $loadId !== '' ? ' · LOAD #' . $loadId : '' ?></span></span></span>
          </div>
          <button type="button" class="bp__replay" data-bp-replay>Replay lane <span class="arrow" aria-hidden="true">→</span></button>
        </div>
        <svg class="bp__svg" viewBox="0 0 520 300" width="520" height="300" aria-hidden="true" focusable="false">
          <defs><pattern id="bp-g" width="20" height="20" patternUnits="userSpaceOnUse"><path d="M20 0H0V20"/></pattern></defs>
          <rect class="bp__g" x="8" y="8" width="504" height="284" fill="url(#bp-g)"/>
          <path class="bp__gm" d="M100 8V292M200 8V292M300 8V292M400 8V292M8 100H512M8 200H512"/>
          <rect class="bp__fr" x="8" y="8" width="504" height="284"/>
          <path class="bp__cn" d="M8 22V8H22M498 8H512V22M512 278V292H498M22 292H8V278"/>
          <g class="bp__ref"><path d="M60 8v6M160 8v6M260 8v6M360 8v6M460 8v6M8 80h6M8 160h6M8 240h6"/><text x="60" y="28">A</text><text x="160" y="28">B</text><text x="260" y="28">C</text><text x="360" y="28">D</text><text x="460" y="28">E</text><text x="26" y="84">1</text><text x="26" y="164">2</text><text x="26" y="244">3</text></g>
          <path class="bp__plan" pathLength="1" d="M56 236H176L236 176H316L376 116H468"/>
          <path class="bp__trail" pathLength="1" d="M56 236H176L236 176H316L376 116H468"/>
          <g class="bp__cota"><path d="M56 272H176M320 272H440M56 266v12M440 266v12"/><path class="bp__arw" d="M56 272l8-3v6zM440 272l-8-3v6z"/><text x="248" y="275.5">SHIPPER TO RECEIVER</text></g>
          <g class="bp__origin"><polygon class="bp__node" points="52,229 63,229 63,240 60,243 49,243 49,232"/><text class="bp__o1" x="40" y="203">ORIGIN · SHIPPER</text><text class="bp__o2 bp__hi" x="40" y="218">09:40</text></g>
          <g class="bp__cp"><circle class="bp__ping" cx="276" cy="176" r="4"/><circle class="bp__dot" cx="276" cy="176" r="4"/><text class="bp__ck" x="276" y="200" text-anchor="middle">CHECK CALL</text></g>
          <g class="bp__dest"><text class="bp__d1" x="500" y="80" text-anchor="end">DESTINATION · RECEIVER</text><g class="bp__ds"><g transform="translate(473 99) scale(.1365)"><path class="bp__star" d="M127 0L98 100L0 123L99 149L122 249L153 148L249 125L150 98Z"/></g></g><text class="bp__d2 bp__hi" x="500" y="152" text-anchor="end">11:55</text></g>
          <g class="bp__truck"><g class="bp__rig">
            <rect x="-22" y="-14" width="28" height="11"/>
            <path class="bp__rib" d="M-15 -12.5V-4.5M-8 -12.5V-4.5M-1 -12.5V-4.5"/>
            <path d="M7 -3V-12H12L17 -8V-3z"/>
            <path class="bp__win" d="M9 -10.2H11.6L14.4 -8H9z"/>
            <path class="bp__rib" d="M6 -5H7"/>
            <circle cx="-18" cy="-2.2" r="2.2"/><circle cx="-13" cy="-2.2" r="2.2"/><circle cx="12" cy="-2.2" r="2.2"/>
          </g></g>
          <g transform="translate(92 92) rotate(-6)"><g class="bp__stamp bp__stamp--a"><polygon class="bp__sp" points="8,0 104,0 104,32 96,40 0,40 0,8"/><polygon class="bp__sp2" points="10,3 101,3 101,31 94,37 3,37 3,10"/><text class="bp__sh" x="52" y="20" text-anchor="middle">RATE CON</text><text class="bp__ss" x="52" y="31" text-anchor="middle">CONFIRMED</text></g></g>
          <g transform="translate(372 186) rotate(4)"><g class="bp__stamp bp__stamp--b"><polygon class="bp__sp" points="8,0 96,0 96,32 88,40 0,40 0,8"/><polygon class="bp__sp2" points="10,3 93,3 93,31 86,37 3,37 3,10"/><text class="bp__sh bp__pod" x="48" y="23" text-anchor="middle">POD</text><text class="bp__ss" x="48" y="33" text-anchor="middle">SIGNED</text></g></g>
        </svg>
        <figcaption class="bp__cap">
          <p class="sr-only">Example lane: rate confirmation filed, truck tracked with check calls, POD signed and filed on arrival.</p>
          <ul class="log">
            <li class="log__row"><span class="log__time">06:12</span><span class="log__text">Rate confirmation received &amp; filed</span></li>
            <li class="log__row"><span class="log__time">09:40</span><span class="log__text">Driver checked in at shipper — photo attached</span></li>
            <li class="log__row log__row--on"><span class="log__time">11:55</span><span class="log__text">Delay logged with timestamps for detention claim</span></li>
          </ul>
        </figcaption>
      </figure>
      <script>
      (function(){var h=document.querySelector('.hero--home'),p=document.getElementById('bp'),b=p&&p.querySelector('[data-bp-replay]');if(!b)return;
      if(p.getBoundingClientRect().top>innerHeight*.85&&window.IntersectionObserver){p.classList.add('bp--wait');var o=new IntersectionObserver(function(e){if(e[0].isIntersecting){p.classList.remove('bp--wait');o.disconnect()}},{threshold:.35});o.observe(p)}
      b.addEventListener('click',function(){p.classList.remove('bp--wait');h.classList.add('bp-off');void h.offsetWidth;h.classList.remove('bp-off')})})();
      </script>
    </div>
  </div>
</section>

<!-- TICKER -->
<div class="ticker home-ticker" data-motion="ticker">
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
<section class="home-relay on-dark" data-motion="band" aria-labelledby="relay-title">
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
    <ol class="g4 home-steps" data-motion="steps">
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
