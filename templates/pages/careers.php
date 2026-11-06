<?php
/**
 * /careers/ — hub de captación de conductores (brief P). Disponibles: $route, $cfg y helpers.
 * Encuadre honesto: NorthStar es un servicio de dispatch, NO un empleador de owner-operators. Las vacantes de company drivers solo existen si hay entradas reales en app/data/jobs.php.
 * Orden: hero fotográfico (patrón A) · In short · Choose your path (2 tarjetas con foto) · What you get with the desk (rejilla numerada) · How it works (4 pasos) ·
 *        What you need to apply (solo ph(): desaparece en producción) · Seguir leyendo (3 filas-tarjeta) · FAQ (pre-CTA). El CTA final lo pone el layout.
 * Fotos (ninguna repetida en la página): hero nf-hero-dry-van · caminos nf-setup-owner-operator y nf-setup-small-fleet · filas de ns_thumb() con exclusión.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$jobs = jobs_active();
$apply = url('/careers/apply/') . '?type=owner-operator';

partial('hero', [
    'route'   => $route,
    'cls'     => 'cr-hero',
    'h1'      => 'Truck driver jobs & owner operator {hl}partnerships{/hl}',
    'tagline' => 'For owner-operators this is a dispatch partnership, not a job offer.',
    'lead'    => 'If you run your own truck, the NorthStar desk works as your dispatch desk and you approve every load. Looking for a company driving job? Real openings are listed here when they exist.',
    'ctas'    => [
        ['label' => 'Apply as an owner-operator', 'href' => $apply, 'arrow' => true],
        ['label' => 'How the partnership works', 'href' => url('/careers/owner-operators/'), 'variant' => 'ghost', 'arrow' => true],
    ],
    'image'   => ['base' => 'nf-hero-dry-van', 'alt' => 'White semi truck pulling a dry van trailer along a highway under an overcast dusk sky', 'pos' => '70% 50%'],
]);

// Resumen "In short" (SEO/GEO): solo información ya afirmada en el sitio; sin cifras, tarifas ni promesas nuevas.
partial('summary', [
    'text'   => 'Truck driver jobs and owner operator partnerships at NorthStar work like this: if you run your own truck, the dispatch desk finds and books your loads, deals with brokers, tracks every trip and keeps the paperwork, and you approve every load. It is a dispatch service, not employment. Company driver jobs are listed only when real openings exist.',
    'points' => [
        'You approve every load',
        'Updates on WhatsApp, where you already work',
        'Company driver openings listed only when real',
    ],
]);

$driverCard = $jobs
    ? ['tag' => 'Company drivers', 'title' => 'CDL-A company driver jobs', 'text' => 'See the open positions, what each job involves and how to apply.',
       'href' => url('/careers/cdl-a-drivers/'), 'cta' => 'See open positions', 'variant' => 'link']
    : ['tag' => 'Company drivers', 'title' => 'CDL-A company driver jobs', 'text' => 'No openings listed right now. We only publish real positions, so this page stays honest.',
       'variant' => 'light'];
?>
<section class="section" aria-labelledby="cr-paths">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'cr-paths', 'eyebrow' => 'Two ways to work with us', 'title' => 'Choose your path']); ?>
    <div class="g2 cr-paths">
      <?php partial('card', [
          'variant' => 'link', 'href' => url('/careers/owner-operators/'), 'tag' => 'Owner-operators', 'title' => 'Partner with a dispatcher',
          'text' => 'Keep running your own truck. The desk finds and books your loads, deals with brokers, tracks every trip and keeps the paperwork that gets you paid.',
          'cta' => 'Owner operator jobs, explained',
          'image' => ['base' => 'nf-setup-owner-operator', 'alt' => 'White semi truck parked alone in an empty lot at dusk, with bare trees behind', 'sizes' => '(min-width:1025px) 540px, (min-width:641px) 46vw, calc(100vw - 40px)'],
      ]); ?>
      <?php partial('card', $driverCard + [
          'image' => ['base' => 'nf-setup-small-fleet', 'alt' => 'Several white semi trucks parked in a row in a depot lot at dusk', 'sizes' => '(min-width:1025px) 540px, (min-width:641px) 46vw, calc(100vw - 40px)'],
          'cls' => $jobs ? '' : 'cr-card--note',
      ]); ?>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="cr-why">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'cr-why', 'eyebrow' => 'Why NorthStar', 'title' => 'What you get with the desk']); ?>
    <?php
    $why = [
        ['You approve every load', 'We tell you what we booked and why.'],
        ['Updates on WhatsApp', 'Where you already work, at every stage of the load.'],
        ['A team, not one dispatcher', 'A team behind every load, so nobody disappears on you.'],
        ['Evidence on file', 'Arrival timestamps and photos ready when detention or a dispute comes up.'],
        ['Settlements prepared', 'So you see exactly what each load paid.'],
    ];
    partial('numgrid', ['label' => 'What you get with the desk', 'items' => array_map(static fn(array $w): array => [
        'title' => $w[0], 'text' => $w[1],
        'href'  => wa("Hi, I'd like to know more about " . strtolower($w[0]) . ' with NorthStar.'),
        'label' => 'Ask the desk about ' . strtolower($w[0]) . ' on WhatsApp',
    ], $why), 'cta' => ['title' => 'Talk to the desk', 'text' => 'No forms first. Open WhatsApp with a ready message to send to a dispatcher.', 'href' => wa($route['wa']), 'label' => 'Talk to a dispatcher on WhatsApp']]); ?>
  </div>
</section>

<section class="section" aria-labelledby="cr-how">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'cr-how', 'eyebrow' => 'How it works', 'title' => 'From application to your first load', 'lead' => 'Send the short application or message us on WhatsApp. This is how we start and how we work with you every day.']); ?>
    <?php partial('steps', ['label' => 'How we start and work with you', 'items' => [
        ['title' => 'You apply', 'text' => 'Four short steps, or a WhatsApp message if you prefer.'],
        ['title' => 'We learn your operation', 'text' => 'Trucks, equipment, lanes, drivers, home time and the accounts you already run, including Amazon Relay.'],
        ['title' => 'We define scope & contacts', 'text' => 'Who approves loads, how and when we report, and which tasks the desk owns from day one.'],
        ['title' => 'We run the day-to-day', 'text' => 'Booking, tracking and documenting every load. You get updates on WhatsApp, where you already work.'],
    ]]); ?>
    <p class="cr-more"><a class="cr-link" href="<?= e(url('/how-it-works/')) ?>">See the full process <span class="arrow" aria-hidden="true">→</span></a></p>
  </div>
</section>

<?php
// Requisitos: SOLO datos pendientes del cliente. En producción ph() devuelve '' y la sección entera desaparece (nunca un hueco).
$reqs = array_values(array_filter([
    ph('Minimum driving experience'),
    ph('Authority and insurance needed'),
    ph('Equipment types accepted'),
    ph('Documents to have ready'),
], static fn(string $h): bool => $h !== ''));
if ($reqs): ?>
<section class="section" aria-labelledby="cr-reqs">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'cr-reqs', 'eyebrow' => 'Requirements', 'title' => 'What you need to apply']); ?>
    <ul class="cr-reqs">
      <?php foreach ($reqs as $r): ?><li><?= $r ?></li><?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<?php
// Seguir leyendo: tres rutas live, cada una con su foto y sin repetir ninguna de la página.
$used = ['nf-hero-dry-van', 'nf-setup-owner-operator', 'nf-setup-small-fleet'];
$more = [
    ['/dispatch-for-owner-operators/', 'Dispatch services for owner-operators', 'What the desk does for your truck, in detail.', ['Service']],
    ['/how-it-works/', 'How truck dispatching works', 'Four steps, from learning your operation to running it together.', ['Guide']],
    ['/resources/rate-per-mile-calculator/', 'Rate per mile calculator', 'Know if a load pays before you book it.', ['Free', 'Calculator']],
];
?>
<section class="section" aria-labelledby="cr-more">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'cr-more', 'eyebrow' => 'Keep reading', 'title' => 'Before you apply']); ?>
    <div class="cr-rows">
      <?php foreach ($more as $m): if (!route_is_live($m[0])) { continue; } $th = ns_thumb($m[0], $used); if ($th) { $used[] = $th[0]; }
          partial('row-card', ['href' => url($m[0]), 'title' => $m[1], 'text' => $m[2], 'chips' => $m[3], 'h' => 'p', 'image' => $th ? ['base' => $th[0], 'alt' => $th[1]] : null]);
      endforeach; ?>
    </div>
  </div>
</section>

<?php partial('faq', ['items' => route_faq_items($route), 'title' => 'Truck driver and owner operator questions', 'pre_cta' => true]); ?>
