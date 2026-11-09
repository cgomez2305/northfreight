<?php
/**
 * Home (/) — replica de la maqueta index.html. Disponibles: $route, $cfg y los helpers.
 * Orden: hero (video de fondo + widget "Start in WhatsApp") · 01 Services · 02 Choose your setup (pestañas) · 03 Amazon Relay · 04 Why a desk (sticky con crossfade) · 05 Onboarding (fotos y líneas de progreso) · 06 Team + video · 07 Evidence · banda de video · 08 From the desk (blog) · 09 FAQ (+ CTA del layout).
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

// Video del hero: assets/video/hero.mp4 (escena de una sola pasada, 1080p, sin audio, SIN bucle: se queda en el último fotograma). main.js lo monta tras load + idle solo en >=1025px sobre la foto (que es su primer fotograma). Ver ns_hero_video() en kit.php.
$heroVideo = ns_hero_video('hero');

// Ancho útil de las fotos de las tarjetas de servicio. Carril ≥1025px: la foto manda por el ALTO de la tarjeta (≤540 px, cover) → ~540 × 1,5 = 810 px de foto, no el ancho de la tarjeta.
// Tarjetas apiladas (marco 4:3 + .px 110 %): ancho útil real ≈ 1,24 × el ancho del marco; en móvil se declara a propósito 65vw (ver ns_img()): a DPR 2 elige la variante de 640 (x1,34) y a DPR 3 la de 768 (x1,4), dentro del límite x1,6.
$railSizes = '(min-width:1025px) 700px, (min-width:641px) 42vw, calc(80vw - 32px)';   // escritorio: la tarjeta mide ~806 px, así que a 1x basta la variante de 768 (x1,05); en retina ns_sizes_retina() pide 455 px y elige 1024 (x1,57)

$services = [
    ['num' => '01', 'title' => 'Dispatch & load booking',
     'text' => 'We search, negotiate and book freight on Amazon Relay and dry van lanes, plan routes around hours of service and coordinate every pickup with your drivers.',
     'href' => url('/dispatch-for-owner-operators/'), 'cta' => 'Dispatch for owner-operators',
     'image' => ['base' => 'nf-service-dispatch-booking', 'alt' => 'A worker in a safety vest walking toward the loading docks of a warehouse, with a white trailer parked alongside in warm evening light', 'sizes' => $railSizes]],
    ['num' => '02', 'title' => 'Load tracking & check calls',
     'text' => 'Every trip followed from pickup to POD: ETAs, arrivals and departures logged, incidents documented and brokers kept informed, so you are not on the phone.',
     'href' => url('/load-tracking/'), 'cta' => 'Load tracking',
     'image' => ['base' => 'nf-service-load-tracking', 'alt' => 'View from a truck cab at sunset, with a hand on a phone mounted on the dashboard and a trailer ahead on the highway', 'sizes' => $railSizes]],
    ['num' => '03', 'title' => 'Trucking back office',
     'text' => 'Paperwork organized, settlements prepared and disputes followed with evidence. We also help onboard new drivers so they are set up in your accounts.',
     'href' => url('/trucking-back-office/'), 'cta' => 'Back office services',
     'image' => ['base' => 'nf-service-back-office', 'alt' => 'Desk by a window with a stack of printed forms, a hand holding a pen, a keyboard and a monitor', 'sizes' => $railSizes]],
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
     'nf-why-one-channel', '/load-tracking/', 'Load tracking'],
    ['02', 'A record of every load', 'Arrival timestamps and photos kept on file, with the BOL and POD filed per load.',
     'nf-why-record-every-load', '/trucking-back-office/', 'Trucking back office'],
    ['03', 'A team behind every load', 'The same desk books, tracks and documents every load, as one team.',
     'nf-dispatch-room-maps', '/how-it-works/', 'How it works'],
];

// 04 Onboarding: foto decorativa por paso (alt descriptivo corto)
$steps = [
    ['01', 'We learn your operation', 'Trucks, equipment, lanes, drivers and the accounts you already run, including Amazon Relay.',
     ['base' => 'nf-onboarding-learn', 'alt' => 'Diner booth table by a window with two coffee mugs, a notebook with a pen and a phone, and a truck stop outside at sunrise', 'video' => 'onboarding-diner', 'vpos' => '100% 50%']],   // fase AC: video de Carlos (C01, 8 s, una pasada); recorte 4:5 desde x 1056 (derecha)
    ['02', 'We define scope & contacts', 'Who approves loads, how we report, and which tasks the desk owns from day one.',
     ['base' => 'nf-onboarding-scope', 'alt' => 'A hand tracing a route in blue ink on an open road atlas, with a tablet showing a map, a coffee mug and a notebook on a wooden table']],
    ['03', 'We run the day-to-day', 'Booking, tracking and documenting every load, with updates where you already work: WhatsApp.',
     ['base' => 'nf-onboarding-run', 'alt' => 'White semi truck with a sleeper cab at the gate of a logistics facility at sunrise', 'video' => 'onboarding-gate']],   // fase Z: video de Carlos (V09, el camión sale por la puerta al amanecer, 8 s, una pasada); póster de escritorio nf-onboarding-run-tall-vid (4:5, primer fotograma)
    ['04', 'We review & improve', 'Regular reviews of lanes, rates and incidents to keep your trucks earning.',
     ['base' => 'nf-onboarding-review', 'alt' => 'A hand marking a printed spreadsheet at a desk, with a laptop showing a bar chart, a coffee mug and truck keys']],
];

$chapters = [
    ['title' => 'How we coordinate loads and drivers', 't' => null, 'href' => url('/our-desk/')],
    ['title' => 'How we document an incident', 't' => null, 'href' => url('/our-desk/')],
    ['title' => 'What being your team really means', 't' => null, 'href' => url('/our-desk/')],
];

$docs = ['Rate confirmation', 'Check-call log', 'Arrival timestamps', 'BOL & POD', 'Incident photos', 'Settlement summary'];
// Pila de documentos que se archiva (07 Evidence): tres hojas maquetadas (sin texto legible ni cifras), rotuladas con documentos ya afirmados (chips de arriba y "Rate con" del Desk log); la bandeja usa la etiqueta "Kept on file" de /how-it-works/.
$filed = ['BOL', 'POD', 'Rate confirmation'];

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
<!-- HERO: foto protagonista (v3: nf-hero-highway-poster = primer fotograma de hero.mp4 a 1920×1080, sin master mayor porque el video de origen es 1280×720; degradado solo desde la izquierda y abajo) + titular + widget de WhatsApp. Recortes reales: -m (4:5, ≤760 px) y -sq (cuadrado, 761 a 1024 px); en escritorio la foto 16:9 completa con srcset 640 a 1920.
     Slot de video: si existe assets/video/hero.mp4 (o .webm) $heroVideo lo activa: el <video> va vacío (preload none, sin src) y main.js le pone la fuente tras load + idle en >=1025px, con fundido sobre la foto (que sigue siendo el LCP). Sin archivos no se emite nada y no hay peticiones. -->
<script>(function(w,d){var r=d.documentElement;r.classList.add('js-wa');if(w.matchMedia&&w.matchMedia('(min-width:1025px)').matches&&!w.matchMedia('(prefers-reduced-motion: reduce)').matches){r.style.setProperty('--pin-h',w.innerHeight+'px');r.classList.add('hero-pre');w.addEventListener('load',function(){setTimeout(function(){if(!r.classList.contains('js-motion'))r.classList.remove('hero-pre')},7000)})}})(window,document)</script>
<section class="hero hero--home" id="top">
  <div class="hero__bg"<?= $heroVideo ? ' data-hero-video data-mp4="' . e($heroVideo['mp4'] ?? '') . '" data-webm="' . e($heroVideo['webm'] ?? '') . '"' : '' ?>><?= ns_img(['base' => 'nf-hero-highway-poster', 'widths' => [640, 1024, 1280, 1600, 1920], 'alt' => 'White semi truck driving away along an empty highway at golden hour, with the sun low over the plains', 'eager' => true, 'sizes' => '100vw',
        'art' => [
            ['media' => '(max-width:760px)', 'base' => 'nf-hero-highway-poster-m', 'sizes' => '100vw'],   // móvil: recorte 4:5 real (864×1080) del primer fotograma del video, camión entero
            ['media' => '(max-width:1024px)', 'base' => 'nf-hero-highway-poster-sq', 'sizes' => '100vw']]]) ?><?php if ($heroVideo): ?><video class="hero__video" muted playsinline preload="none" aria-hidden="true" tabindex="-1"></video><?php endif; ?></div>
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
        <a class="btn btn--primary btn--lg" href="<?= e($waHref) ?>" rel="noopener"><span class="btn__t">Talk to a dispatcher</span></a>
        <a class="btn btn--ghost btn--lg" href="<?= e(url('/how-it-works/')) ?>">See how we onboard</a>
      </div>
      <ul class="tags eyebrow" aria-label="What we cover">
        <?php foreach ($tags as $t): ?><li class="tag"><?= e($t) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <div class="hero-wa" data-wa-widget data-wa-base="<?= e(cfg('wa_base')) ?>">
      <p class="hero-wa__title t-meta">Start in WhatsApp</p>
      <p class="hero-wa__note" data-wa-note>Open WhatsApp with a ready message to send to a dispatcher.</p>
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
          <p class="hero-wa__msg" data-wa-msg><?= e('Hi, ' . $waTabs[0][2] . ' and I\'d like to talk about dispatch with NorthStar.') ?></p>
        </div>
      </div>
      <a class="btn btn--primary btn--card hero-wa__go" href="<?= e($waHref) ?>" rel="noopener" data-wa-open><span class="btn__t">Open WhatsApp</span></a>
    </div>
  </div>
</section>

<?php partial('summary', summary_for($route) + ['variant' => 'lead', 'textfill' => true]   /* relleno de texto con el scroll (motion.js textScene): usado una sola vez; quitar 'textfill' lo desactiva */); ?>

<!-- 01 SERVICES: rejilla de 3 tarjetas con foto; con html.js-motion y ≥1025px motion.js la convierte en un carril horizontal fijado (.home-services__pin) -->
<section class="section home-services" id="services" aria-labelledby="services-title">
  <div class="home-services__pin">
    <div class="container">
      <?php partial('section-head', ['eyebrow' => '01 · What we manage', 'title' => 'Three fronts.{pill:nf-hero-amazon-relay}{br}One dispatch desk.{pill:nf-hero-dry-van}', 'id' => 'services-title', 'split' => true,
          'lead' => 'We stay on the load until it is documented and settled. The same team that books it tracks it and closes it out.']); ?>
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

<!-- 02 CHOOSE YOUR SETUP: pestañas (Owner-operator · Small fleet · Amazon Relay carrier). Cada panel = foto 16:9 con tarjeta solapada, 3 puntos ya afirmados en la página de destino, enlace y WhatsApp con el mensaje de esa variante. -->
<?php partial('tabs', [
    'id'      => 'setup-title',
    'eyebrow' => '02 · Your setup',
    'title'   => 'Choose your setup',
    'lead'    => 'Pick the one that looks like your operation. Each opens WhatsApp with a ready message.',
    'label'   => 'Choose your setup',
    'items'   => [
        ['label' => 'Owner-operator', 'title' => 'Owner-operator', 'wa' => 'owner',
         'points' => ['You approve every load', 'Updates on WhatsApp, where you already work', 'Arrival timestamps and photos kept on file'],
         'image' => ['base' => 'nf-setup-owner-operator', 'alt' => 'White long-hood semi truck with a dry van trailer parked in a rest area at sunrise, with a person standing beside the cab', 'pos' => '60% 100%'],
         'video' => 'owner-rest-area',
         'link' => ['label' => 'Dispatch for owner-operators', 'href' => url('/dispatch-for-owner-operators/')]],
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

<!-- CORTINA: foto grande fijada que el panel de Amazon Relay (curtain-next) cubre al subir (≥1025px con JS); sin JS o en móvil, foto normal -->
<?php partial('curtain', ['image' => ['base' => 'nf-blog-relay-load-board', 'alt' => 'An open laptop on the hood of a white truck in a freight yard at sunset, with a coiled blue air line in the foreground and rows of trailers behind', 'pos' => '50% 55%']]); ?>

<!-- 03 AMAZON RELAY -->
<section class="home-relay curtain-next" aria-labelledby="relay-title">
  <div class="container home-relay__in">
    <div class="home-relay__main">
      <span class="t-eyebrow">03 · Specialty</span>
      <h2 class="t-h2" id="relay-title">Amazon Relay dispatch for <span class="hl">carriers who move fast.</span></h2>
      <p class="t-body home-relay__lead">Relay rewards carriers who move fast and keep clean records. Our desk watches the load board for you, books the blocks that fit your trucks and follows performance and disputes inside your account.</p>
      <a class="btn btn--primary home-relay__btn" href="<?= e(url('/amazon-relay-dispatch/')) ?>"><span class="btn__t">Amazon Relay dispatch</span></a>
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
      <?php partial('section-head', ['eyebrow' => '04 · Why a desk', 'title' => 'A team, not a single dispatcher', 'id' => 'why-title']); ?>
    </div>
    <div class="container why">
      <div class="why__nums" aria-hidden="true">
        <?php foreach ($why as [$n]): ?><span><?= e($n) ?></span><?php endforeach; ?>
      </div>
      <ol class="why__list">
        <?php foreach ($why as $i => [$n, $title, $text, $photo, $path, $linkLabel]): ?>
        <li class="why__item<?= $i === 0 ? ' is-on' : '' ?>">
          <figure class="why__photo"><?= ns_img(['base' => $photo, 'alt' => '', 'sizes' => '(min-width:901px) 33vw, (min-width:641px) 100vw, 65vw',
            'art' => [['media' => '(min-width:1025px)', 'base' => $photo . '-tall', 'sizes' => '(min-width:1025px) 440px']]]) ?></figure>
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
    <?php partial('section-head', ['eyebrow' => '05 · Onboarding', 'title' => 'How we plug into your operation.', 'id' => 'onboarding-title']); ?>
    <ol class="home-steps">
      <?php foreach ($steps as [$n, $title, $text, $photo]):
          $sv = (string) ($photo['video'] ?? '');
          $hasSv = preg_match('/^[a-z0-9-]+$/', $sv) === 1 && is_file(NS_ROOT . "/assets/video/$sv.mp4") && is_file(NS_ROOT . '/assets/img/' . $photo['base'] . '-tall-vid-480.webp'); ?>
      <li class="home-step">
        <span class="home-step__line" aria-hidden="true"><i></i></span>
        <figure class="home-step__photo px"<?= $hasSv ? ' data-bgvideo data-mp4="' . e(asset("video/$sv.mp4")) . '"' : '' ?>><?= ns_img($photo + ['sizes' => '(min-width:641px) 50vw, 65vw',
            'art' => [['media' => '(min-width:1025px)', 'base' => $photo['base'] . ($hasSv ? '-tall-vid' : '-tall'), 'sizes' => '(min-width:1025px) 360px']]]) ?><?php if ($hasSv): ?><video class="home-step__video"<?= !empty($photo['vpos']) ?' style="object-position:' . e((string) $photo['vpos']) . '"' : '' ?> muted playsinline preload="none" aria-hidden="true" tabindex="-1"></video><?php endif; ?></figure>
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

<!-- 05 TEAM + VIDEO: patrón B (foto ancha con tarjeta solapada). Con video (o en staging) la fachada del video es la foto; el título y la meta del video van en una tarjeta de vidrio dentro de la foto y los 3 capítulos en 3 columnas debajo.
     Producción sin video real (DESK_VIDEO_URL vacío): foto sola con un enlace a /our-desk/, sin play, duración ni capítulos, y un texto que no promete video. -->
<?php
$videoReady = ns_video_ready();
$teamCard = [
    'id'      => 'team-title',
    'cls'     => 'home-team',
    'eyebrow' => '06 · The desk behind the load',
    'title'   => 'Built by dispatchers.{br}{hl}Run as a team.{/hl}',
    'image'   => ['base' => 'nf-dispatch-room-wide', 'alt' => 'A quiet dispatch desk by a large window at dusk, with two monitors, a headset, a notebook and a lamp, overlooking a freight yard with trailers', 'pos' => '50% 50%'],
];
if ($videoReady) {
    ob_start();
    partial('video', ['part' => 'chapters', 'id' => 'desk', 'chapters' => $chapters, 'chapters_label' => 'Jump to a chapter']);
    $chaptersHtml = ob_get_clean();
    $teamCard += [
        'lead'  => 'Behind every trip there are people coordinating information, decisions and follow-up. Meet the team, hear how we work, and jump straight to the topic you care about.',
        'video' => ['id' => 'desk', 'title' => 'A load doesn’t dispatch itself. {hl}There’s a team behind it.{/hl}', 'meta' => 'Video · 3 to 4 min'],
        'after' => $chaptersHtml,
    ];
} else {
    $teamCard += [
        'lead' => 'Behind every trip there are people coordinating information, decisions and follow-up. See how the desk works with you.',
        'link' => route_is_live('/our-desk/') ? ['label' => 'How the desk works', 'href' => url('/our-desk/')] : null,
    ];
}
partial('photo-card', $teamCard); ?>

<!-- 05 EVIDENCE -->
<section class="section" aria-labelledby="evidence-title">
  <div class="container split home-evidence">
    <div class="home-evidence__text">
      <?php partial('section-head', ['eyebrow' => '07 · Proof, not promises', 'title' => 'Incidents are handled with evidence.', 'id' => 'evidence-title',
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
  <!-- PILA QUE SE ARCHIVA: con html.js-motion y >=1025px las hojas bajan una a una a la bandeja con el scroll (motion.js fileScene); sin JS, en móvil o con movimiento reducido ya están archivadas (estado final) -->
  <div class="container home-file">
    <figure class="fshow" role="img" aria-label="Three documents filed in a tray: BOL, POD and Rate confirmation. Example.">
      <div class="fshow__sheets" aria-hidden="true">
        <?php foreach ($filed as $i => $name): ?>
        <div class="fsheet fsheet--<?= $i + 1 ?>" style="--i:<?= $i ?>">
          <span class="fsheet__label"><?= e($name) ?></span>
          <span class="fsheet__rule"></span>
          <span class="fsheet__cols"><i></i><i></i></span>
          <span class="fsheet__lines"><i></i><i></i><i></i><i></i></span>
          <span class="fsheet__sign"><i></i></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="fshow__tray" aria-hidden="true"><span class="fshow__t t-meta">Kept on file</span><span class="fshow__ex t-meta">Example</span></div>
    </figure>
  </div>
</section>

<!-- Banda de video: el póster es el primer fotograma gradado de assets/video/freight-dock.mp4 (nf-freight-dock), así que el paso foto a video es invisible; el video (una sola pasada, sin bucle, se queda en el último fotograma) se monta solo >=1025px, tras load + idle, y empieza al verse la banda. Sin H2 nuevo: el texto es un <p> con copy ya existente (evidencia) -->
<?php partial('video-band', [
    'image'  => ['base' => 'nf-freight-dock', 'alt' => 'White semi truck parked at a lit warehouse dock on a wet night yard'],
    'video'  => 'freight-dock',
    'text'   => 'Every load leaves a record: rate confirmation, check-call log, arrival timestamps, BOL and POD.',
]); ?>

<!-- 08 FROM THE DESK: artículo destacado + 2 filas (datos de app/data/posts.php; sin fechas mientras `published` esté vacío) -->
<?php partial('desk-block', [
    'id'      => 'desk-guides',
    'eyebrow' => '08 · From the desk',
    'title'   => 'Guides from the desk',
    'lead'    => 'Practical guides on what dispatch costs, how Amazon Relay works and how to get paid for every hour at the dock.',
    'btn'     => ['label' => 'All articles', 'href' => url('/blog/')],
    'paths'   => ['/blog/how-much-does-truck-dispatch-cost/', '/blog/amazon-relay-requirements/', '/blog/lumper-fees-detention-pay/'],
]); ?>

<!-- 09 FAQ -->
<?php partial('faq', ['items' => route_faq_items($route), 'eyebrow' => '09 · FAQ', 'title' => 'Truck dispatch questions carriers ask us.', 'pre_cta' => true,
    // Foto del FAQ por tema (cada pregunta de app/data/faq.php lleva 'topic'): 0 costo, arranque y contrato · 1 Amazon Relay · 2 alcance · 3 con quién trabajamos. El tema 0 es la foto de siempre (4:5 nativo).
    'image' => ['base' => 'nf-faq-dock-light-4x5', 'alt' => 'White trailer backed into a dock door on a warehouse wall, with yellow bollards and painted parking lines', 'sizes' => '(min-width:1025px) 500px, 0px', 'topics' => [
        ['base' => 'nf-faq-dock-light-4x5', 'alt' => 'White trailer backed into a dock door on a warehouse wall, with yellow bollards and painted parking lines'],
        ['base' => 'nf-check-tracking-mirror-tall', 'alt' => 'Truck side mirror at sunset reflecting a white trailer and the highway behind it'],
        ['base' => 'nf-hero-back-office-tall', 'alt' => 'Wooden desk in warm window light with an open binder of papers, reading glasses, a pen, a mug and a closed laptop, and a hand resting on the page'],
        ['base' => 'nf-check-owner-approve-tall', 'alt' => 'Hands holding a smartphone beside a white truck at a fuel station at sunset'],
    ]]]); ?>
