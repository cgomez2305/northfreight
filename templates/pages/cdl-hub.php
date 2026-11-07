<?php
/**
 * /careers/cdl-a-drivers/ — vacantes REALES de company drivers (app/data/jobs.php). Con jobs.php vacío la ruta es draft (404 en producción, visible con ?preview=TOKEN) y esta plantilla
 * muestra el aviso honesto "No openings listed right now". Disponibles: $route, $cfg y helpers.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$jobs = jobs_active();

partial('hero', [
    'route'   => $route,
    'cls'     => 'cr-hero',
    'eyebrow' => 'Company drivers',
    'h1'      => 'CDL-A company driver {hl}jobs{/hl}',
    'lead'    => $jobs ? 'These are the open company driver positions at NorthStar Freight Solutions. Each page says what the job involves and how to apply.' : 'No openings listed right now. We only publish real positions.',
    'ctas'    => $jobs ? [] : [['label' => 'Owner-operator partnership', 'href' => url('/careers/owner-operators/'), 'variant' => 'ghost', 'arrow' => true]],
    'image'   => ['base' => 'nf-hero-how-it-works', 'alt' => 'Aerial view of white semi trucks on a highway interchange with looping ramps at sunset', 'pos' => '72% 50%'],
]);
?>
<section class="section pre-cta" aria-labelledby="cd-open">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'cd-open', 'eyebrow' => 'Open positions', 'title' => $jobs ? 'Open positions' : 'No openings right now', 'count' => $jobs ? count($jobs) : 0]); ?>
    <?php if ($jobs): ?>
    <div class="cr-rows">
      <?php foreach ($jobs as $slug => $j):
          $loc = is_array($j['location'] ?? null) ? $j['location'] : [];
          $where = trim(($loc['city'] ?? '') . (isset($loc['city'], $loc['region']) ? ', ' : '') . ($loc['region'] ?? ''));
          partial('row-card', ['href' => url('/careers/cdl-a-drivers/' . $slug . '/'), 'title' => (string) $j['title'], 'text' => (string) ($j['summary'] ?? ''),
              'chips' => array_values(array_filter([$where, (string) ($j['equipment'] ?? '')])), 'h' => 'h3']);
      endforeach; ?>
    </div>
    <?php else: ?>
    <p class="t-body c-slate cr-empty">There are no company driver openings listed. If that changes, they will appear on this page. If you run your own truck, the owner-operator partnership may be what you are looking for.</p>
    <?php endif; ?>
  </div>
</section>
