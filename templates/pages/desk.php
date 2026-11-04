<?php
/**
 * /our-desk/ — maqueta Desk.html.
 * Orden: hero (texto + capítulos | fachada de video) · The team (4 tarjetas, app/data/team.php) · Three rules (3 tarjetas altas) · How we work · What you can count on (filas W/01..W/04) · CTA final (layout).
 * Rules y filas reutilizan afirmaciones que ya están en el sitio; ninguna lleva foto.
 * La portada del video es foto stock: sin texto encima salvo el botón de play (el pie va debajo).
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
    ['You approve every load', 'Who approves loads, how we report, and which tasks the desk owns from day one.', '/dispatch-for-owner-operators/'],
    ['One channel for every update', 'Updates you can follow, in one channel.', '/load-tracking/'],
    ['A record of every load', 'Rate cons, BOLs, PODs and receipts filed per load.', '/trucking-back-office/'],
    ['A team, not a single dispatcher', 'Dispatch, tracking and back office presented as one team.', '/how-it-works/'],
];
?>
<section class="hero on-dark sb-dk-hero">
  <div class="container hero__in">
    <div class="hero__main">
      <?php partial('breadcrumbs', ['route' => $route]); ?>
      <h1 class="hero__title t-h1">Built by dispatchers. <span class="hl">Run as a team.</span></h1>
      <p class="hero__lead t-lead">At NorthStar, behind every load there are people coordinating information, decisions and follow-up. We want you to know who we are and how we work with you.</p>
      <?php partial('video', ['part' => 'chapters', 'id' => 'desk', 'chapters' => $chapters, 'chapters_label' => 'Jump to a chapter']); ?>
    </div>
    <div class="hero__aside">
      <?php partial('video', [
          'part'  => 'player',
          'id'    => 'desk',
          'image' => ['base' => 'dispatch-team-headsets-office', 'alt' => 'Dispatchers wearing headsets at their workstations'],
          'title' => 'Meet the desk {hl}· 3 chapters{/hl}',
          'meta'  => 'Video · 3 to 4 min',
      ]); ?>
    </div>
  </div>
</section>

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
    <?php partial('section-head', ['eyebrow' => 'How the desk behaves', 'title' => 'Three rules we work by', 'id' => 'dk-rules']); ?>
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
    <?php partial('section-head', ['eyebrow' => 'Working with the desk', 'title' => 'What you can count on', 'id' => 'dk-why']); ?>
    <ol class="sb-dk-rows">
      <?php foreach ($reasons as $i => [$title, $text, $path]): $live = route_is_live($path); ?>
      <li class="sb-dk-row">
        <<?= $live ? 'a href="' . e(url($path)) . '"' : 'div' ?> class="sb-dk-row__in">
          <span class="sb-dk-row__n t-meta">W/<?= sprintf('%02d', $i + 1) ?></span>
          <span class="head sb-dk-row__title"><?= e($title) ?></span>
          <span class="sb-dk-row__text"><?= e($text) ?></span>
          <span class="sb-dk-row__go" aria-hidden="true"><span class="arrow">→</span></span>
        </<?= $live ? 'a' : 'div' ?>>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
