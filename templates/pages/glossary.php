<?php
/**
 * Glosario (Glossary.html de la maqueta): hero → índice A–Z → términos agrupados por letra → herramientas relacionadas.
 * Términos: app/data/glossary.php. Los enlaces a rutas draft se omiten con route_is_live().
 */
$terms = data('glossary');

// Agrupa por inicial, conservando el orden alfabético del dato.
$groups = [];
foreach ($terms as $t) {
    $letter = strtoupper(substr((string) $t['term'], 0, 1));
    $groups[$letter][] = $t;
}

$related = [
    ['code' => 'TOOL', 'title' => 'Rate per mile calculator', 'text' => 'RPM, deadhead and fuel in one place.', 'path' => '/resources/rate-per-mile-calculator/', 'dark' => false],
    ['code' => 'TOOL', 'title' => 'IFTA calculator', 'text' => 'Miles and gallons by state.', 'path' => '/resources/ifta-calculator/', 'dark' => false],
    ['code' => 'BLOG', 'title' => 'Guides from the desk', 'text' => 'Dispatch costs, Relay, detention.', 'path' => '/blog/', 'dark' => true],
];
$related = array_values(array_filter($related, static fn(array $c): bool => route_is_live($c['path'])));

partial('hero', [
    'route'   => $route,
    'h1'      => 'Trucking {hl}glossary{/hl}',
    'eyebrow' => 'Guide',
    'lead'    => 'The words you hear on every broker call, explained in one or two lines by the people who use them all day.',
    'cls'     => 'tools-hero tools-hero--gloss',
]);
?>
<section class="tools-band tools-band--az" aria-label="Glossary index">
  <div class="container">
    <nav class="tools-az" aria-label="Jump to letter">
      <?php foreach (array_keys($groups) as $letter): ?>
      <a href="#letter-<?= e($letter) ?>" aria-label="Terms starting with <?= e($letter) ?>"><?= e($letter) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</section>

<section class="tools-gloss" aria-labelledby="gloss-title">
  <div class="container">
    <h2 class="sr-only" id="gloss-title">Glossary terms, A to Z</h2>
    <div class="tools-gloss__list">
      <?php foreach ($groups as $letter => $items): ?>
      <div class="tools-letter" id="letter-<?= e($letter) ?>">
        <span class="tools-letter__mark" aria-hidden="true"><?= e($letter) ?></span>
        <div class="tools-letter__terms">
          <?php foreach ($items as $t): ?>
          <div class="tools-term" id="term-<?= e($t['id']) ?>">
            <h3 class="tools-term__name"><?= e($t['term']) ?></h3>
            <p class="tools-term__def t-body"><?= e($t['def']) ?><?php if (!empty($t['link']['path']) && route_is_live($t['link']['path'])): ?> <a href="<?= e(url($t['link']['path'])) ?>"><?= e($t['link']['label']) ?></a>.<?php endif; ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($related): ?>
<section class="section section--surface tools-related" aria-labelledby="gloss-tools-title">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'Put it to work', 'title' => 'Tools that use these terms', 'id' => 'gloss-tools-title']); ?>
    <div class="g3">
      <?php foreach ($related as $c):
        partial('card', [
            'variant' => 'link',
            'cls'     => $c['dark'] ? 'card--dark' : '',
            'code'    => $c['code'],
            'title'   => $c['title'],
            'text'    => $c['text'],
            'href'    => url($c['path']),
            'cta'     => 'Open',
        ]);
      endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
