<?php
/**
 * Plantilla de las 5 páginas por equipo (EquipBox/Hotshot/Flatbed/PowerOnly/Reefer.html). Contenido: app/data/equipment.php
 * según $route['equip']. Disponibles: $route, $cfg y helpers. Rutas en draft hasta que el cliente confirme equipos (§7.4).
 * Orden: hero con ficha de equipo · What the desk handles (g4) · Before we book (oscuro) · FAQ plana (pre-CTA).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$all = data('equipment');
$eq = $all[$route['equip'] ?? ''] ?? null;
if ($eq === null) {
    return;
}

// Chips de la ficha: orden de la maqueta. Solo se enlazan páginas live (y todas mientras esta se previsualiza como draft).
$chipOrder = ['box', 'hotshot', 'power-only', 'reefer', 'flatbed'];
$previewing = ($route['status'] ?? 'live') !== 'live';
$previewQs = $previewing && !empty($_GET['preview']) ? '?preview=' . rawurlencode((string) $_GET['preview']) : '';
$chips = [];
foreach ($chipOrder as $k) {
    $c = $all[$k] ?? null;
    if ($c === null) {
        continue;
    }
    if ($k === ($route['equip'] ?? '') || $previewing || route_is_live($c['path'])) {
        $chips[] = ['key' => $k, 'name' => $c['name'], 'path' => $c['path'], 'current' => $k === $route['equip']];
    }
}
$sheetRows = [
    ['Equipment', $eq['sheet']['equipment']],
    ['Common freight', $eq['sheet']['freight']],
    ['We confirm', $eq['sheet']['confirm']],
];
$faq = route_faq_items($route);

ob_start();
?>
<div class="cut sa-sheet">
  <div class="sa-sheet__head">
    <h2 class="t-meta sa-sheet__title">Equipment sheet</h2>
    <?= ns_star(22) ?>
  </div>
  <dl class="sa-sheet__rows">
    <?php foreach ($sheetRows as $r): ?>
    <div class="sa-sheet__row"><dt class="mono"><?= e($r[0]) ?></dt><dd><?= e($r[1]) ?></dd></div>
    <?php endforeach; ?>
  </dl>
  <?php if (count($chips) > 1): ?>
  <nav class="sa-sheet__chips" aria-label="Equipment pages">
    <?php foreach ($chips as $c): ?>
      <?php if ($c['current']): ?><span class="sa-chip sa-chip--on" aria-current="page"><?= e($c['name']) ?></span>
      <?php else: ?><a class="sa-chip" href="<?= e(url($c['path'] . $previewQs)) ?>"><?= e($c['name']) ?></a><?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</div>
<?php
$sheet = (string) ob_get_clean();

partial('hero', [
    'route'   => $route,
    'crumbs'  => [['label' => 'Home', 'path' => '/'], ['label' => 'Services', 'path' => '/dispatch-for-owner-operators/'], ['label' => $eq['name'], 'path' => $eq['path']]],
    'h1'      => str_replace(' dispatch ', ' {hl}dispatch{/hl} ', $eq['h1']),
    'tagline' => $eq['tagline'],
    'lead'    => $eq['lead'],
    'ctas'    => [
        ['label' => 'Talk to a dispatcher', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true],
        ['label' => 'How we onboard', 'href' => url('/how-it-works/'), 'variant' => 'ghost'],
    ],
    'aside'   => $sheet,
]);
?>
<section class="section" aria-labelledby="eq-handles">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'eq-handles', 'eyebrow' => 'What the desk handles', 'title' => $eq['handles_title']]); ?>
    <div class="g4">
      <?php $last = count($eq['handles']) - 1; foreach ($eq['handles'] as $i => $h): ?>
        <?php partial('card', ['variant' => $i === $last ? 'dark' : 'light', 'cls' => 'sa-n30', 'min_title' => true, 'num' => sprintf('%02d', $i + 1), 'title' => $h[0], 'text' => $h[1]]); ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section--dark" aria-labelledby="eq-checks">
  <div class="container sa-need">
    <div class="sa-need__main">
      <?php partial('section-head', ['id' => 'eq-checks', 'theme' => 'dark', 'eyebrow' => 'Before we book', 'title' => 'What we check on every load']); ?>
      <ol class="sa-checks">
        <?php foreach ($eq['checks'] as $i => $c): ?>
        <li><span class="mono sa-checks__n"><?= sprintf('%02d', $i + 1) ?></span><span><?= e($c) ?></span></li>
        <?php endforeach; ?>
      </ol>
    </div>
    <aside class="cut sa-need__card" aria-labelledby="eq-need">
      <span class="t-meta sa-need__tag">To get started</span>
      <h3 class="head sa-need__title" id="eq-need">What we need from you</h3>
      <ul class="sa-need__list">
        <?php foreach ($eq['needs'] as $n): ?>
        <li><?= ns_star(14) ?><span><?= e($n) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <a class="btn btn--navy btn--card sa-need__btn" href="<?= e(url('/contact/')) ?>">Send your details <span class="arrow" aria-hidden="true">→</span></a>
    </aside>
  </div>
</section>

<?php if ($faq): ?>
<section class="section section--surface pre-cta" aria-labelledby="faq-title">
  <div class="container">
    <div class="sa-faq">
      <div class="sa-faq__head">
        <span class="t-eyebrow">FAQ</span>
        <h2 class="t-h2 c-navy" id="faq-title"><?= e($eq['faq_title']) ?></h2>
      </div>
      <div class="sa-faq__list">
        <?php foreach ($faq as $it): ?>
        <details class="sa-faq__item">
          <summary class="sa-faq__q"><span><?= phtext($it['q']) ?></span><svg class="sa-faq__plus" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg></summary>
          <p class="sa-faq__a t-body"><?= phtext($it['a']) ?></p>
        </details>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>
