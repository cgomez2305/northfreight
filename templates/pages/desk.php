<?php
/**
 * /our-desk/ — maqueta Desk.html.
 * Orden: hero (texto + capítulos | fachada de video) · The team (4 tarjetas, app/data/team.php) · How we work · CTA final (layout).
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
          <div class="sb-dk-person__photo">
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
