<?php
/**
 * /resources/ — hub de recursos (Resources.html): hero con 6 iconos, Free tools, Templates, Learn y banda cian de cierre.
 * Secciones = las del mega menú de Resources. Tarjetas: company/res-card.php (solo rutas live).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$bookSvg = '<svg class="icon" width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 21V5M8 7h7"/></svg>';

$tools = [
    ['path' => '/resources/rate-per-mile-calculator/', 'icon' => 'trend', 'code' => 'TOOL · 01', 'title' => 'Rate per mile calculator',
     'text' => 'Loaded and all-in rate per mile, fuel cost and what is left after the trip. Know if a load pays before you say yes.'],
    ['path' => '/resources/ifta-calculator/', 'icon' => 'calc', 'code' => 'TOOL · 02', 'title' => 'IFTA calculator',
     'text' => 'Miles and gallons by state, your fleet MPG and the net taxable gallons for your quarterly IFTA return.'],
];
$templates = [
    ['path' => '/resources/bol-template/', 'icon' => 'doc-down', 'code' => 'TEMPLATE · PDF + WORD', 'title' => 'Bill of lading template', 'dark' => true,
     'text' => 'A clean, printable BOL with every field brokers and receivers expect. It is the same one our desk files on every load.'],
    ['path' => '/resources/dispatcher-carrier-agreement/', 'icon' => 'doc', 'code' => 'TEMPLATE · WORD', 'title' => 'Dispatcher-carrier agreement',
     'text' => 'What a fair dispatch contract covers: scope, fees, your right to refuse loads and how to end it.'],
];
$learn = [
    ['path' => '/resources/glossary/', 'icon_svg' => $bookSvg, 'code' => 'GUIDE', 'title' => 'Trucking glossary',
     'text' => 'Deadhead, lumper, TONU, rate con: the terms you hear on every broker call.'],
    ['path' => '/blog/amazon-relay-requirements/', 'code' => 'AMAZON RELAY', 'title' => 'Amazon Relay requirements',
     'text' => 'Authority, insurance, equipment and the steps to get approved.'],
    ['path' => '/blog/amazon-relay-load-board/', 'code' => 'AMAZON RELAY', 'title' => 'How the Relay load board works',
     'text' => 'One-ways, round trips, blocks and how loads get booked.'],
    ['path' => '/blog/', 'code' => 'BLOG', 'title' => 'All articles', 'dark' => true,
     'text' => 'Dispatch costs, detention, lumper fees and more from the desk.'],
];

ob_start(); ?>
<div class="co-tiles" aria-hidden="true">
  <span class="co-tile"><?= ns_icon('calc', 28) ?></span>
  <span class="co-tile co-tile--star"><?= ns_star(44) ?></span>
  <span class="co-tile"><?= ns_icon('trend', 28) ?></span>
  <span class="co-tile"><?= ns_icon('doc-down', 28) ?></span>
  <span class="co-tile"><?= ns_icon('doc', 28) ?></span>
  <span class="co-tile"><?= $bookSvg ?></span>
</div>
<?php
$aside = ob_get_clean();

partial('hero', [
    'route'   => $route,
    'h1'      => 'Free trucking resources {hl}from the desk{/hl}',
    'eyebrow' => 'Tools · Templates · Guides',
    'lead'    => 'The same calculators, templates and guides our dispatchers use every day. Free for owner-operators and small fleets, with no sign-up for the tools.',
    'aside'   => $aside,
    'cls'     => 'co-hero co-res-hero',
]);
?>
<section class="section" aria-labelledby="res-tools">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'Free tools', 'title' => 'Run the numbers before you book', 'id' => 'res-tools']); ?>
    <div class="g2">
      <?php foreach ($tools as $rc) { include __DIR__ . '/company/res-card.php'; } ?>
    </div>
  </div>
</section>
<section class="section section--surface" aria-labelledby="res-templates">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'Templates', 'title' => 'Paperwork, ready to use', 'id' => 'res-templates']); ?>
    <div class="g2">
      <?php foreach ($templates as $rc) { include __DIR__ . '/company/res-card.php'; } ?>
    </div>
  </div>
</section>
<section class="section" aria-labelledby="res-learn">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'Learn', 'title' => 'Guides written by dispatchers', 'id' => 'res-learn']); ?>
    <div class="g4">
      <?php foreach ($learn as $rc) { include __DIR__ . '/company/res-card.php'; } ?>
    </div>
  </div>
</section>
<section class="section co-bandsec" aria-labelledby="res-band">
  <div class="container">
    <div class="co-band">
      <div class="co-band__main">
        <span class="t-eyebrow">Need it done, not just a template?</span>
        <h2 class="t-h2-s co-band__title" id="res-band">Let the desk handle your paperwork</h2>
        <p class="co-band__text">Rate cons, BOLs, PODs and settlements organized per load by the same team that books and tracks it.</p>
      </div>
      <a class="btn btn--navy btn--block-m" href="<?= e(url('/trucking-back-office/')) ?>">See back-office services <span class="arrow" aria-hidden="true">→</span></a>
    </div>
  </div>
</section>
