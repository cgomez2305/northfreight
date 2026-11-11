<?php
/**
 * Artículo del blog (PostDispatchCost / PostRelayRequirements / PostRelayLoadBoard / PostLumperDetention de la maqueta).
 * Esta plantilla pone el hero, el layout de lectura (TOC lateral + columna), el FAQ y "Related"; el cuerpo de cada
 * artículo vive en templates/pages/posts/{slug}.php, que define $hl, $takeaways, $toc, $related (el FAQ sale de app/data/faq.php, clave post-{slug}) y escribe el HTML
 * de la columna (puede llamar a $callout() para la caja "Talk to the desk").
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$slug = (string) ($route['slug'] ?? '');
$post = [];
foreach (data('posts') as $p) {
    if (($p['slug'] ?? '') === $slug && ($p['type'] ?? '') === 'article') {
        $post = $p;
        break;
    }
}
$bodyFile = NS_ROOT . '/templates/pages/posts/' . basename($slug) . '.php';

$plus = static fn(int $size): string => '<span class="blog-plus" aria-hidden="true"><svg width="' . $size . '" height="' . $size
    . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></span>';

// Caja "Talk to the desk" dentro del artículo.
$callout = static function () use ($route): void { ?>
<aside class="blog-callout" aria-label="Talk to the desk">
  <div class="blog-callout__text">
    <span class="t-meta blog-callout__tag">Talk to the desk</span>
    <p class="head blog-callout__title">Rather have a dispatcher handle this?</p>
  </div>
  <a class="btn btn--primary btn--card btn--block-m" href="<?= e(wa($route['wa'] ?? null)) ?>" rel="noopener"><span class="btn__t">WhatsApp the desk</span></a>
</aside>
<?php };

$hl = $takeaways = $toc = $faq = $related = null;
$body = '';
if ($post && is_file($bodyFile)) {
    ob_start();
    require $bodyFile;
    $body = (string) ob_get_clean();
}
$toc = $toc ?? [];
$faq = route_faq_items($route);
$related = array_values(array_filter($related ?? [], static fn(array $r): bool => route_is_live($r['href'])));
if ($faq) {
    $toc[] = ['faq', 'FAQ'];
}

$h1 = (string) ($route['h1'] ?? '');
if ($hl && strpos($h1, $hl) !== false) {
    $h1 = str_replace($hl, '{hl}' . $hl . '{/hl}', $h1);
}

// Fecha: 'published' (ISO) cuando el cliente la confirme; mientras tanto el ph() de 'date' (vacío en producción).
// 'modified' (ISO) se muestra como "Updated …" si no hay fecha publicada o si es distinta de ella.
$dates = post_dates($post);
$dateHtml = '';
if ($dates['published'] !== '') {
    $dateHtml = '<time datetime="' . e($dates['published']) . '">' . e(fmt_date($dates['published'])) . '</time>';
} elseif (!empty($post['date']) && has_ph((string) $post['date'])) {
    $dateHtml = phtext((string) $post['date']);
}
$updatedHtml = '';
if ($dates['modified'] !== '' && $dates['modified'] !== $dates['published']) {
    $updatedHtml = 'Updated <time datetime="' . e($dates['modified']) . '">' . e(fmt_date($dates['modified'])) . '</time>';
}

ob_start(); ?>
<p class="blog-byline mono">
  <span class="blog-byline__by"><img src="<?= e(asset('img/isotype-white.svg')) ?>" alt="" width="22" height="22">By the NorthStar dispatch desk</span>
  <?php if ($dateHtml !== ''): ?><span><?= $dateHtml ?></span><?php endif; ?>
  <?php if ($updatedHtml !== ''): ?><span><?= $updatedHtml ?></span><?php endif; ?>
  <?php if (!empty($post['minutes'])): ?><span><?= e((int) $post['minutes']) ?> min read</span><?php endif; ?>
</p>
<?php $byline = (string) ob_get_clean();

$tocNav = static function (array $toc, string $cls = ''): void { ?>
<nav class="blog-toc<?= $cls !== '' ? ' ' . e($cls) : '' ?>" aria-label="On this page">
  <?php foreach ($toc as [$id, $label]): ?><a href="#<?= e($id) ?>"><?= e($label) ?></a><?php endforeach; ?>
</nav>
<?php };

partial('hero', [
    'route'   => $route,
    'h1'      => $h1,
    'size'    => 'h1-long',
    'eyebrow' => (string) ($post['category'] ?? ''),
    'lead'    => (string) ($post['lead'] ?? ''),
    'stack'   => true,
    'aside'   => $byline,
    'cls'     => 'blog-hero blog-hero--post',
]);
?>
<section class="section blog-post" aria-label="Article">
  <div class="container blog-post__in">
    <aside class="blog-aside" aria-label="Article navigation">
      <div class="blog-aside__toc">
        <span class="t-meta blog-toc__label">On this page</span>
        <?php $tocNav($toc); ?>
      </div>
      <a class="blog-aside__card" href="<?= e(url('/contact/')) ?>">
        <span class="head blog-aside__title">Questions about your trucks?</span>
        <span class="blog-aside__link">Talk to a dispatcher <span class="arrow" aria-hidden="true">→</span></span>
      </a>
    </aside>
    <article class="blog-prose">
      <details class="blog-tocm">
        <summary class="blog-tocm__sum"><span>On this page</span><?= $plus(16) ?></summary>
        <?php $tocNav($toc, 'blog-toc--m'); ?>
      </details>
      <?php if (!empty($takeaways)): ?>
      <div class="blog-takeaways">
        <span class="t-meta blog-takeaways__label">Key takeaways</span>
        <ul>
          <?php foreach ($takeaways as $t): ?><li><?= $t ?></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <?= $body ?>
      <?php if ($faq): ?>
      <h2 class="t-prose-h2" id="faq">FAQ</h2>
      <div class="blog-faq">
        <?php foreach ($faq as $it): ?>
        <details class="blog-faq__item">
          <summary class="blog-faq__q"><span><?= phtext($it['q']) ?></span><?= $plus(18) ?></summary>
          <p class="blog-faq__a"><?= phtext($it['a']) ?></p>
        </details>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </article>
  </div>
</section>

<?php if ($related): ?>
<section class="section section--surface blog-related" aria-labelledby="related-title">
  <div class="container stack">
    <?php partial('section-head', [ 'title' => 'Related from the desk', 'id' => 'related-title']); ?>
    <div class="rlist">
      <?php $used = []; foreach ($related as $r):
          $t = ns_thumb($r['href'], $used);
          if ($t) { $used[] = $t[0]; }
          partial('row-card', [
              'href'  => url($r['href']),
              'title' => $r['title'],
              'text'  => $r['text'],
              'chips' => [ucfirst(strtolower((string) $r['code']))],
              'dark'  => !empty($r['dark']),
              'image' => $t ? ['base' => $t[0], 'alt' => $t[1]] : null,
          ]);
      endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
