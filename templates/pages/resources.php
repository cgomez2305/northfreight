<?php
/**
 * /resources/ — hub de recursos (Resources.html): hero con 6 iconos, Free tools, Templates, Learn y banda cian de cierre.
 * Secciones = las del mega menú de Resources + una mini calculadora de rate per mile. Filas: partial row-card (solo rutas live).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$bookSvg = '<svg class="icon" width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 21V5M8 7h7"/></svg>';

// Filas-tarjeta (partial row-card): chips mono = tipo de recurso. Miniatura de ns_thumb() (kit.php) por ruta destino, sin repetir foto en la página.
$tools = [
    ['path' => '/resources/rate-per-mile-calculator/', 'chips' => ['Free', 'Calculator'], 'title' => 'Rate per mile calculator',
     'text' => 'Loaded and all-in rate per mile, fuel cost and what is left after the trip. Know if a load pays before you say yes.'],
    ['path' => '/resources/ifta-calculator/', 'chips' => ['Free', 'Calculator'], 'title' => 'IFTA calculator',
     'text' => 'Miles and gallons by state, your fleet MPG and the net taxable gallons for your quarterly IFTA return.'],
];
$templates = [
    ['path' => '/resources/bol-template/', 'chips' => ['Template', 'PDF + Word'], 'title' => 'Bill of lading template', 'dark' => true,
     'text' => 'A clean, printable BOL with every field brokers and receivers expect. It is the same one our desk files on every load.'],
    ['path' => '/resources/dispatcher-carrier-agreement/', 'chips' => ['Template', 'Word'], 'title' => 'Dispatcher-carrier agreement',
     'text' => 'What a fair dispatch contract covers: scope, fees, your right to refuse loads and how to end it.'],
];
$learn = [
    ['path' => '/resources/glossary/', 'chips' => ['Guide'], 'title' => 'Trucking glossary',
     'text' => 'Deadhead, lumper, TONU, rate con: the terms you hear on every broker call.'],
    ['path' => '/blog/amazon-relay-requirements/', 'chips' => ['Amazon Relay', 'Article'], 'title' => 'Amazon Relay requirements',
     'text' => 'Authority, insurance, equipment and the steps to get approved.'],
    ['path' => '/blog/amazon-relay-load-board/', 'chips' => ['Amazon Relay', 'Article'], 'title' => 'How the Relay load board works',
     'text' => 'One-ways, round trips, blocks and how loads get booked.'],
    ['path' => '/blog/', 'chips' => ['Blog'], 'title' => 'All articles', 'dark' => true,
     'text' => 'Dispatch costs, detention, lumper fees and more from the desk.'],
];
// Fase S: sin plantillas aprobadas (DOWNLOADS_APPROVED) en producción no hay ninguna fila live: la sección, el eyebrow y el lead de arriba no las nombran.
$hasTemplates = array_filter($templates, static fn(array $t): bool => route_is_live($t['path'])) !== [];
$rows = static function (array $list): void {
    foreach ($list as $rc) {
        if (!route_is_live($rc['path'])) {
            continue;
        }
        $t = ns_thumb($rc['path']);
        partial('row-card', ['href' => url($rc['path']), 'title' => $rc['title'], 'text' => $rc['text'], 'chips' => $rc['chips'], 'dark' => !empty($rc['dark']),
            'image' => $t ? ['base' => $t[0], 'alt' => $t[1]] : null]);
    }
};

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
    'eyebrow' => $hasTemplates ? 'Tools · Templates · Guides' : 'Tools · Guides',
    'lead'    => $hasTemplates ? 'The same calculators, templates and guides our dispatchers use every day. Free for owner-operators and small fleets, with no sign-up for the tools.'
                               : 'The same calculators and guides our dispatchers use every day. Free for owner-operators and small fleets, with no sign-up for the tools.',
    'aside'   => $aside,
    'cls'     => 'co-hero co-res-hero',
]);
?>
<section class="section" aria-labelledby="res-tools">
  <div class="container stack">
    <?php partial('section-head', [ 'title' => 'Run the numbers before you book', 'id' => 'res-tools']); ?>
    <div class="rlist"><?php $rows($tools); ?></div>
  </div>
</section>
<section class="section co-quick" aria-labelledby="res-quick">
  <div class="container stack">
    <?php partial('section-head', [ 'title' => 'Does the load pay? Try it here', 'id' => 'res-quick', 'lead' => 'Example values are filled in. Change them to your own load and read the result.']); ?>
    <?php partial('minicalc', ['id' => 'res-rpm', 'cls' => 'mcalc--wide']); ?>
  </div>
</section>
<?php if ($hasTemplates): ?>
<section class="section section--surface" aria-labelledby="res-templates">
  <div class="container stack">
    <?php partial('section-head', [ 'title' => 'Paperwork, ready to use', 'id' => 'res-templates']); ?>
    <div class="rlist"><?php $rows($templates); ?></div>
  </div>
</section>
<?php endif; ?>
<section class="section<?= $hasTemplates ? '' : ' section--surface' ?>" aria-labelledby="res-learn">
  <div class="container stack">
    <?php partial('section-head', [ 'title' => 'Guides written by dispatchers', 'id' => 'res-learn']); ?>
    <div class="rlist"><?php $rows($learn); ?></div>
  </div>
</section>
<section class="section co-bandsec" aria-labelledby="res-band">
  <div class="container">
    <div class="co-band">
      <div class="co-band__main">
        <h2 class="t-h2-s co-band__title" id="res-band">Let the desk handle your paperwork</h2>
        <p class="co-band__text">Rate cons, BOLs, PODs and settlements organized per load by the same team that books and tracks it.</p>
      </div>
      <a class="btn btn--navy btn--block-m" href="<?= e(url('/trucking-back-office/')) ?>"><span class="btn__t">See back-office services <span class="arrow" aria-hidden="true">→</span></span></a>
    </div>
  </div>
</section>
