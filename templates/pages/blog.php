<?php
/**
 * Índice del blog (Blog.html de la maqueta): hero, tarjeta destacada, filtro por tema + rejilla.  Sin newsletter: no hay herramienta de correo (decisión de fase O).
 * Datos: app/data/posts.php. Sin JS se ven todas las tarjetas; blog-filter.js añade el filtro (chips con aria-pressed).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$catSlug = ['Dispatch' => 'dispatch', 'Amazon Relay' => 'relay', 'Paperwork & pay' => 'pay'];
$posts = data('posts');
$featured = null;
$items = [];
foreach ($posts as $p) {
    if (!empty($p['featured']) && ($p['type'] ?? '') === 'article' && $featured === null) {
        $featured = $p;
        continue;
    }
    if (route_is_live($p['path'])) {
        $items[] = $p;
    }
}
$chips = ['all' => 'All', 'dispatch' => 'Dispatch', 'relay' => 'Amazon Relay', 'pay' => 'Paperwork & pay'];
?>
<?php partial('hero', [
    'route'   => $route,
    'h1'      => 'From the {hl}dispatch desk{/hl}',
    'eyebrow' => 'Blog',
    'lead'    => 'Practical guides for owner-operators and small fleets: what dispatch costs, how Amazon Relay works, and how to get paid for every hour at the dock.',
    'stack'   => true,
    'cls'     => 'blog-hero blog-hero--index',
]); ?>

<?php if ($featured): ?>
<section class="blog-featured" aria-label="Featured article">
  <div class="container">
    <a class="blog-feat" href="<?= e(url($featured['path'])) ?>">
      <span class="blog-feat__media px">
        <?php if (!empty($featured['image'])): ?><?= ns_img($featured['image'] + ['sizes' => '(min-width:1025px) 1152px, (min-width:641px) 100vw, calc(80vw - 32px)']) ?><?php endif; ?>
      </span>
      <span class="blog-feat__body">
        <span class="blog-feat__meta">
          <span class="blog-feat__badge t-meta">Featured</span>
          <span class="blog-feat__cat t-meta"><?= e($featured['category']) ?></span>
          <span class="mono blog-feat__min"><?= e((int) $featured['minutes']) ?> MIN READ<?php $fpub = post_dates($featured)['published']; if ($fpub !== '') { echo ' · ' . e(fmt_date($fpub)); } ?></span>
        </span>
        <h2 class="t-h2-s c-navy"><?= e($featured['title']) ?></h2>
        <span class="t-body c-slate"><?= e($featured['excerpt']) ?></span>
        <span class="blog-feat__link">Read the article <span class="arrow" aria-hidden="true">→</span></span>
      </span>
    </a>
  </div>
</section>
<?php endif; ?>

<section class="section blog-list" aria-labelledby="blog-list-title">
  <div class="container stack">
    <div class="blog-head">
      <?php partial('section-head', ['eyebrow' => 'All articles', 'title' => 'Read by topic', 'id' => 'blog-list-title']); ?>
      <div class="blog-filter" role="group" aria-label="Filter by topic" data-blog-filter>
        <?php foreach ($chips as $key => $label): ?>
        <button type="button" class="blog-chip" data-filter="<?= e($key) ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <p class="sr-only" role="status" aria-live="polite" data-filter-status></p>
    <ul class="blog-grid g3">
      <?php foreach ($items as $p):
          $isLink = ($p['type'] ?? '') === 'link';
          $code = $isLink ? (string) ($p['kind'] ?? '') : (int) $p['minutes'] . ' MIN';
          $pub = $isLink ? '' : post_dates($p)['published'];   // fecha solo cuando el cliente confirmó la publicación
          if ($pub !== '') { $code .= ' · ' . fmt_date($pub); }
      ?>
      <li class="blog-item" data-cat="<?= e($catSlug[$p['category']] ?? 'dispatch') ?>">
        <?php partial('card', [
            'variant' => 'link',
            'href'    => url($p['path']),
            'tag'     => $p['category'],
            'code'    => $code,
            'title'   => $p['title'],
            'text'    => $p['excerpt'],
            'cta'     => 'Read',
            'image'   => !empty($p['image']) ? $p['image'] + ['sizes' => '(min-width:1025px) 500px, (min-width:641px) 42vw, calc(80vw - 32px)'] : null,
        ]); ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
