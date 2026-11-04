<?php
/**
 * /our-desk/ — maqueta Desk.html.
 * Orden: hero fotográfico (patrón A: la fachada del video es la foto; título, meta y capítulos en una tarjeta de vidrio) · The team (4 tarjetas, app/data/team.php) · Three rules (3 tarjetas altas) · How we work · What you can count on (índice con vista previa, partial index-list, W/01..W/04) · CTA final (layout).
 * Rules y filas reutilizan afirmaciones que ya están en el sitio; ninguna lleva foto.
 * La portada del video es una foto de marca: el botón de play es lo único que va encima del video; el texto va sobre el degradado del hero.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$team = data('team');
$chapters = [
    ['title' => 'How we coordinate', 'desc' => 'Loads, drivers and tracking, with real daily tasks', 't' => null],
    ['title' => 'How we respond', 'desc' => 'Documenting an incident, communicating and following up', 't' => null],
    ['title' => 'What being your team means', 'desc' => 'Closeness, responsibilities and continuity of service', 't' => null],
];
$values = [
    ['One team', 'Dispatch, tracking and back office presented as one team.'],
    ['Evidence first', 'Incidents handled with evidence, not phone calls.'],
    ['Clear communication', 'Updates you can follow, in one channel.'],
];

// Three rules: ideas ya afirmadas en el sitio (home, tracking, desk).
$rules = [
    ['Incidents are handled with evidence', 'When a broker questions a late arrival or detention, the record is already there: time, place and photos.'],
    ['Operations run as a team', 'Behind every load there are people coordinating information, decisions and follow-up.'],
    ['Communication you can follow', 'Updates you can follow, in one channel.'],
];
// Filas numeradas (W/01..W/04): título y frase de apoyo ya existentes; cada fila enlaza a la página donde se explica.
$reasons = [
    ['You approve every load', 'Who approves loads, how we report, and which tasks the desk owns from day one.', '/dispatch-for-owner-operators/', ['base' => 'nf-hero-owner-operators', 'pos' => '50% 50%']],
    ['One channel for every update', 'Updates you can follow, in one channel.', '/load-tracking/', ['base' => 'nf-check-tracking-mirror', 'pos' => '68% 50%']],
    ['A record of every load', 'Rate cons, BOLs, PODs and receipts filed per load.', '/trucking-back-office/', ['base' => 'nf-hero-back-office', 'pos' => '40% 50%']],
    ['A team, not a single dispatcher', 'Dispatch, tracking and back office presented as one team.', '/how-it-works/', ['base' => 'nf-hero-how-it-works', 'pos' => '30% 50%']],
];
?>
<?php
// Hero fotográfico (patrón A): la fachada del video ES la foto del hero (marco `hero__pic`, play centrado); el título del video, su meta y los 3 capítulos van en una tarjeta de vidrio abajo a la derecha.
ob_start();
partial('video', [
    'part'  => 'player',
    'id'    => 'desk',
    'image' => ['base' => 'nf-dispatch-room-maps', 'alt' => 'Dispatch room at night with monitors showing route maps and an empty chair'],
    'cls'   => 'hero__pic',
    'foot'  => false,
    'pos'   => '60% 50%',
    'sizes' => '(min-width:1025px) 1160px, (min-width:761px) 100vw, calc(100vw - 72px)',
]);
$bg = ob_get_clean();
ob_start(); ?>
<div class="hero__glass">
  <p class="head video__title"><?= ns_rich('Meet the desk {hl}· 3 chapters{/hl}') ?></p>
  <span class="video__meta">Video · 3 to 4 min</span>
  <?php partial('video', ['part' => 'chapters', 'id' => 'desk', 'chapters' => $chapters, 'chapters_label' => 'Jump to a chapter']); ?>
</div>
<?php $aside = ob_get_clean();

partial('hero', [
    'route' => $route,
    'cls'   => 'sb-dk-hero',
    'h1'    => 'Built by dispatchers. {hl}Run as a team.{/hl}',
    'lead'  => 'At NorthStar, behind every load there are people coordinating information, decisions and follow-up. We want you to know who we are and how we work with you.',
    'bg'    => $bg,
    'aside' => $aside,
]);
?>

<section class="section" aria-labelledby="dk-team">
  <div class="container stack sb-dk-stack">
    <?php partial('section-head', ['eyebrow' => 'The team', 'title' => 'The people on your loads', 'id' => 'dk-team']); ?>
    <ul class="g4">
      <?php foreach ($team as $p): ?>
      <li>
        <article class="sb-dk-person">
          <div class="sb-dk-person__photo px">
            <?php if (!empty($p['photo'])): ?>
            <?= ns_img(['base' => (string) $p['photo'], 'alt' => phplain((string) $p['name']), 'sizes' => '(min-width:1025px) 280px, (min-width:641px) 50vw, 100vw']) ?>
            <?php else: ?>
            <span class="t-meta"><?= ph('Team photo') ?></span>
            <?php endif; ?>
          </div>
          <div class="sb-dk-person__body">
            <h3 class="head t-h3 sb-dk-person__name"><?= phtext((string) $p['name']) ?></h3>
            <span class="sb-dk-person__role"><?= phtext((string) $p['role']) ?></span>
            <span class="sb-dk-person__bio"><?= phtext((string) $p['bio']) ?></span>
          </div>
        </article>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="section sb-dk-rules" aria-labelledby="dk-rules">
  <div class="container stack sb-dk-stack">
    <?php partial('section-head', ['eyebrow' => 'How the desk behaves', 'title' => 'Three rules we work by', 'id' => 'dk-rules', 'count' => count($rules)]); ?>
    <ol class="g3 sb-dk-rulelist">
      <?php foreach ($rules as $i => [$title, $text]): ?>
      <li class="sb-dk-rule<?= $i === 0 ? ' sb-dk-rule--on' : '' ?>">
        <span class="sb-dk-rule__n t-meta">Rule <?= sprintf('%02d', $i + 1) ?></span>
        <h3 class="head sb-dk-rule__title"><?= e($title) ?></h3>
        <p class="sb-dk-rule__text"><?= e($text) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section class="section section--surface" aria-labelledby="dk-how">
  <div class="container stack sb-gap-32">
    <?php partial('section-head', ['title' => 'How we work', 'id' => 'dk-how']); ?>
    <ul class="g3">
      <?php foreach ($values as $i => [$title, $text]): ?>
      <li class="card <?= $i === 2 ? 'card--dark' : 'card--white' ?> sb-dk-value">
        <?= ns_star(20) ?>
        <h3 class="head t-h3 card__title"><?= e($title) ?></h3>
        <p class="card__text t-card"><?= e($text) ?></p>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="section sb-dk-why pre-cta" aria-labelledby="dk-why">
  <div class="container stack sb-dk-stack">
    <?php partial('section-head', ['eyebrow' => 'Working with the desk', 'title' => 'What you can count on', 'id' => 'dk-why', 'count' => count($reasons)]); ?>
    <?php partial('index-list', ['label' => 'What you can count on', 'items' => array_map(static fn(array $r, int $i): array => ['n' => 'W/' . sprintf('%02d', $i + 1), 'title' => $r[0], 'text' => $r[1], 'href' => route_is_live($r[2]) ? url($r[2]) : '', 'image' => $r[3]], $reasons, array_keys($reasons))]); ?>
  </div>
</section>
