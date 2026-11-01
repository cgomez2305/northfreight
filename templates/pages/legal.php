<?php
/**
 * /privacy-policy/ y /terms/ (clave de ruta `doc`: privacy|terms). Maqueta: Privacy.html / Terms.html.
 * Hero largo (.t-h1-long) + índice lateral sticky con anclas reales + texto. Contenido en templates/pages/legal/{doc}.php.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$docKey = in_array($route['doc'] ?? '', ['privacy', 'terms'], true) ? $route['doc'] : 'privacy';
$doc = require NS_ROOT . '/templates/pages/legal/' . $docKey . '.php';
$email = (string) cfg('email');

/** Texto del documento: ph() para huecos, {email} => enlace mailto con el correo visible. */
$legalText = static function (string $s) use ($email): string {
    $parts = explode('{email}', $s);
    $out = [];
    foreach ($parts as $p) {
        $out[] = phtext($p);
    }
    return implode('<a href="mailto:' . e($email) . '">' . e($email) . '</a>', $out);
};

partial('hero', [
    'route'   => $route,
    'h1'      => $doc['h1'],
    'size'    => 'h1-long',
    'eyebrow' => $doc['updated'],
    'lead'    => $doc['lead'],
    'cls'     => 'co-hero co-legal-hero',
]);
?>
<section class="co-legal" aria-label="<?= e($route['h1']) ?> text">
  <div class="container co-legal__in">
    <aside class="co-legal__aside">
      <nav class="co-toc" aria-label="On this page">
        <span class="co-toc__label t-meta">Sections</span>
        <?php foreach ($doc['sections'] as $s): ?>
        <a href="#<?= e($s[0]) ?>"><?= e($s[1]) ?></a>
        <?php endforeach; ?>
      </nav>
    </aside>
    <article class="co-prose">
      <div class="co-notice"><strong>Draft for review.</strong> Final text to be approved by NorthStar&#39;s legal counsel before publishing.</div>
      <?php foreach ($doc['sections'] as $s): $body = $legalText($s[2]); ?>
      <h2 class="t-prose-h2" id="<?= e($s[0]) ?>"><?= e($s[1]) ?></h2>
      <?php if (trim(strip_tags($body)) !== ''): ?><p><?= $body ?></p><?php endif; ?>
      <?php endforeach; ?>
      <p class="co-prose__more"><a href="<?= e(url($doc['other'][1])) ?>"><?= e($doc['other'][0]) ?> <span class="arrow" aria-hidden="true">→</span></a></p>
    </article>
  </div>
</section>
