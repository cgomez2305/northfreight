<?php
/**
 * Home (/) — replica de la maqueta index.html. Disponibles: $route, $cfg y los helpers.
 * Orden: hero (video de fondo + widget "Start in WhatsApp") · 01 Services · 02 Amazon Relay · 03 Why a desk (sticky con crossfade) · 04 Onboarding (fotos y líneas de progreso) · 05 Team + video · 06 Evidence · banda de video · 07 FAQ (+ CTA del layout).
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

// Widget "Start in WhatsApp" (hero): pestañas = quién eres, chips = en qué ayudamos (los servicios que ya existen). main.js recompone el enlace wa.me
// con el mensaje; sin JS el botón apunta al enlace por defecto de la ruta. No se guarda ni se envía ningún dato.
$waTabs = [
    ['owner', 'Owner-operator', 'I run an owner-operator business'],
    ['fleet', 'Small fleet', 'I run a small fleet'],
    ['relay', 'Amazon Relay', 'I haul Amazon Relay freight'],
];
$waChips = ['Load booking', 'Load tracking', 'Back office'];

// Video de fondo del hero (assets/video/hero.mp4, opcional hero.webm). main.js lo activa tras load + idle solo en >=1025px; sin él queda la foto. Ver ns_hero_video() en kit.php.
$heroVideo = ns_hero_video('hero');

// Ancho útil de las fotos de las tarjetas de servicio. Carril ≥1025px: la foto manda por el ALTO de la tarjeta (≤540 px, cover) → ~540 × 1,5 = 810 px de foto, no el ancho de la tarjeta.
// Tarjetas apiladas (marco 4:3 + .px 110 %): ancho útil ≈ 1,24 × el ancho del marco.
$railSizes = '(min-width:1025px) 820px, (min-width:641px) 42vw, 124vw';

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

// 03 Why a desk: tres razones ya afirmadas en el sitio (How it works, Our desk, Evidence). Cada una enlaza a la página donde se explica (son los elementos enfocables del sticky).
$why = [
    ['01', 'One channel for every update', 'Updates you can follow, in one channel: WhatsApp, where you already work.',
     'headset-agent-office', '/load-tracking/', 'Load tracking'],
    ['02', 'A record of every load', 'Arrival timestamps and photos kept on file, with the BOL and POD filed per load.',
     'archive-shelves-documents', '/trucking-back-office/', 'Trucking back office'],
    ['03', 'A team behind every load', 'The same desk books, tracks and documents every load, as one team.',
     'dispatch-desk-headset-monitors', '/how-it-works/', 'How it works'],
];

// 04 Onboarding: foto decorativa por paso (alt descriptivo corto)
$steps = [
    ['01', 'We learn your operation', 'Trucks, equipment, lanes, drivers and the accounts you already run, including Amazon Relay.',
     ['base' => 'team-reviewing-documents', 'alt' => 'Two people going over a printed document at a desk']],
    ['02', 'We define scope & contacts', 'Who approves loads, how we report, and which tasks the desk owns from day one.',
     ['base' => 'clipboard-signing-report', 'alt' => 'Hand filling in a report on a clipboard']],
    ['03', 'We run the day-to-day', 'Booking, tracking and documenting every load, with updates where you already work: WhatsApp.',
     ['base' => 'truck-night-mountain-road', 'alt' => 'Semi truck driving a mountain road at night']],
    ['04', 'We review & improve', 'Regular reviews of lanes, rates and incidents to keep your trucks earning.',
     ['base' => 'aerial-trucks-parking-lot', 'alt' => 'Aerial view of semi trailers lined up in a truck lot']],
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
<!-- HERO: foto protagonista (degradado solo desde la izquierda y abajo) + titular + widget de WhatsApp. El <video> va vacío y sin descarga (preload none, sin src): main.js le pone la fuente tras load + idle en >=1025px y entra con un fundido sobre la foto (que sigue siendo el LCP) -->
<section class="hero hero--home" id="top">
  <div class="hero__bg"<?= $heroVideo ? ' data-hero-video data-mp4="' . e($heroVideo['mp4'] ?? '') . '" data-webm="' . e($heroVideo['webm'] ?? '') . '" data-poster="' . e($heroVideo['poster'] ?? '') . '"' : '' ?>><?= ns_img(['dir' => 'video', 'base' => 'hero-poster', 'widths' => [1280, 1920], 'alt' => 'Aerial view of trucks and cars on a busy highway lined with trees at sunset', 'eager' => true, 'sizes' => '100vw',
        'art' => [['media' => '(max-width:1024px)', 'base' => 'hero-poster-sq', 'widths' => [640, 1080], 'sizes' => '100vw']]]) ?><?php if ($heroVideo): ?><video class="hero__video" muted loop playsinline preload="none" aria-hidden="true" tabindex="-1"></video><?php endif; ?></div>
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
        <a class="btn btn--primary btn--lg" href="<?= e($waHref) ?>" rel="noopener">Talk to a dispatcher</a>
        <a class="btn btn--ghost btn--lg" href="<?= e(url('/how-it-works/')) ?>">See how we onboard</a>
      </div>
      <ul class="tags eyebrow" aria-label="What we cover">
        <?php foreach ($tags as $t): ?><li class="tag"><?= e($t) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <div class="hero-wa" data-wa-widget data-wa-base="<?= e(cfg('wa_base')) ?>">
      <p class="hero-wa__title t-meta">Start in WhatsApp</p>
      <p class="hero-wa__note" data-wa-note>Open WhatsApp with a ready message and a dispatcher replies.</p>
      <div class="hero-wa__ui" data-wa-ui hidden>
        <div class="hero-wa__tabs" role="tablist" aria-label="I am">
          <?php foreach ($waTabs as $i => [$key, $label, $who]): ?>
          <button type="button" class="hero-wa__tab" role="tab" id="wa-tab-<?= e($key) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="wa-panel"<?= $i === 0 ? '' : ' tabindex="-1"' ?> data-who="<?= e($who) ?>"><?= e($label) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="hero-wa__panel" role="tabpanel" id="wa-panel" aria-labelledby="wa-tab-owner">
          <div class="hero-wa__chips" role="group" aria-label="I want help with">
            <?php foreach ($waChips as $c): ?>
            <button type="button" class="hero-wa__chip" aria-pressed="false" data-svc="<?= e(strtolower($c)) ?>"><?= e($c) ?></button>
            <?php endforeach; ?>
          </div>
          <p class="hero-wa__msg" data-wa-msg></p>
        </div>
      </div>
      <a class="btn btn--primary btn--card hero-wa__go" href="<?= e($waHref) ?>" rel="noopener" data-wa-open>Open WhatsApp</a>
    </div>
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
      <?php partial('section-head', ['eyebrow' => '01 · What we manage', 'title' => 'Three fronts.{pill:semi-truck-snowy-mountains}{br}One dispatch desk.{pill:white-semi-truck-front}', 'id' => 'services-title', 'split' => true,
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
      <a class="btn btn--primary home-relay__btn" href="<?= e(url('/amazon-relay-dispatch/')) ?>">Amazon Relay dispatch</a>
    </div>
    <div class="g2 home-relay__grid">
      <?php foreach ($relay as $i => [$tag, $title, $text]): partial('card', ['tag' => $tag, 'title' => $title, 'text' => $text, 'cls' => 'home-rcard' . ($i === 3 ? ' home-rcard--accent' : '')]); endforeach; ?>
    </div>
  </div>
</section>

<!-- 03 WHY A DESK: con html.js-motion y >=1025px (alto >=700px) motion.js fija la sección y cambia el numeral, la foto (crossfade) y el ítem activo según el scroll.
     Sin JS, en móvil o con movimiento reducido: tres tarjetas apiladas, foto arriba y texto debajo. Cada foto vive una sola vez en el DOM, dentro de su ítem. -->
<section class="section home-why" aria-labelledby="why-title">
  <div class="why__pin">
    <div class="container">
      <?php partial('section-head', ['eyebrow' => '03 · Why a desk', 'title' => 'A team, not a single dispatcher', 'id' => 'why-title']); ?>
    </div>
    <div class="container why">
      <div class="why__nums" aria-hidden="true">
        <?php foreach ($why as [$n]): ?><span><?= e($n) ?></span><?php endforeach; ?>
      </div>
      <ol class="why__list">
        <?php foreach ($why as $i => [$n, $title, $text, $photo, $path, $linkLabel]): ?>
        <li class="why__item<?= $i === 0 ? ' is-on' : '' ?>">
          <figure class="why__photo"><?= ns_img(['base' => $photo, 'alt' => '', 'sizes' => '(min-width:641px) 33vw, 100vw',
            'art' => [['media' => '(min-width:1025px)', 'base' => $photo . '-tall', 'sizes' => '440px']]]) ?></figure>
          <div class="why__body">
            <span class="why__n t-meta" aria-hidden="true"><?= e($n) ?></span>
            <h3 class="head t-h3"><?= e($title) ?></h3>
            <p class="t-card"><?= e($text) ?></p>
            <?php if (route_is_live($path)): ?><a class="why__link" href="<?= e(url($path)) ?>"><?= e($linkLabel) ?> <span class="arrow" aria-hidden="true">→</span></a><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>
</section>

<!-- 04 ONBOARDING: columnas con foto, línea fina arriba y línea cian que se rellena con el scroll (motion.js); desfase vertical escalonado en escritorio -->
<section class="section home-onboarding" aria-labelledby="onboarding-title">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => '04 · Onboarding', 'title' => 'How we plug into your operation.', 'id' => 'onboarding-title']); ?>
    <ol class="home-steps">
      <?php foreach ($steps as [$n, $title, $text, $photo]): ?>
      <li class="home-step">
        <span class="home-step__line" aria-hidden="true"><i></i></span>
        <figure class="home-step__photo px"><?= ns_img($photo + ['sizes' => '(min-width:641px) 50vw, 100vw',
            'art' => [['media' => '(min-width:1025px)', 'base' => $photo['base'] . '-tall', 'sizes' => '360px']]]) ?></figure>
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
      <span class="t-eyebrow">05 · The desk behind the load</span>
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
      <?php partial('section-head', ['eyebrow' => '06 · Proof, not promises', 'title' => 'Incidents are handled with evidence.', 'id' => 'evidence-title',
          'lead' => 'When a broker questions detention, a lumper fee or a late arrival, the answer is already on file. Here is what our desk keeps for every load.']); ?>
      <ul class="home-docs" aria-label="Documents the desk keeps for every load">
        <?php foreach ($docs as $i => $d): ?>
        <li class="home-doc<?= $i === 5 ? ' home-doc--on' : '' ?>"><?= e($d) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <figure class="dlog" aria-label="Desk log, example">
      <div class="dlog__bar">
        <span class="dlog__dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <span class="dlog__title t-meta">Load · Example</span>
        <span class="dlog__pill t-meta"><span class="dlog__pdot" aria-hidden="true"></span>POD filed</span>
      </div>
      <dl class="dlog__rows">
        <?php if ($loadId !== ''): ?><div class="dlog__row"><dt>Load</dt><dd>#<?= $loadId ?></dd></div><?php endif; ?>
        <div class="dlog__row"><dt>Handled by</dt><dd>Dispatch desk</dd></div>
        <div class="dlog__row"><dt>Updates</dt><dd>WhatsApp</dd></div>
      </dl>
      <ol class="dlog__line">
        <?php foreach ($log as [$time, $text, $on]): ?>
        <li class="dlog__step<?= $on ? ' dlog__step--on' : '' ?>"><span class="dlog__time"><?= e($time) ?></span><span class="dlog__text"><?= e($text) ?></span></li>
        <?php endforeach; ?>
      </ol>
      <ul class="dlog__docs" aria-label="Documents on file">
        <?php foreach (['BOL', 'POD', 'Rate con'] as $d): ?><li><?= ns_icon('check', 13) ?><?= e($d) ?></li><?php endforeach; ?>
      </ul>
    </figure>
  </div>
</section>

<!-- Banda de video: patio de carga (solo >=1025px; en móvil, movimiento reducido o saveData queda el póster). Sin H2 nuevo: el texto es un <p> con copy ya existente (evidencia) -->
<?php partial('video-band', [
    'video'  => 'freight-yard',
    'text'   => 'Every load leaves a record: rate confirmation, check-call log, arrival timestamps, BOL and POD.',
    'alt'    => 'Aerial view of a freight yard with semi trailers',
]); ?>

<!-- 07 FAQ -->
<?php partial('faq', ['items' => route_faq_items($route), 'eyebrow' => '07 · FAQ', 'title' => 'Truck dispatch questions carriers ask us.', 'pre_cta' => true,
    'image' => ['base' => 'warehouse-sunlight-dock-tall', 'alt' => 'Sunlight streaming into a warehouse loading bay', 'sizes' => '(min-width:1025px) 560px, 0px']]); ?>
