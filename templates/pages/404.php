<?php
/**
 * Página 404 propia (clave de ruta '404'; el front controller responde con status 404). No existe en la maqueta:
 * hero navy con "404", texto corto (el de la meta de la ruta), WhatsApp + Home y enlaces a los servicios live.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

ob_start(); ?>
<div class="co-404" aria-hidden="true">
  <span class="co-404__num">404</span>
</div>
<?php
$aside = ob_get_clean();

partial('hero', [
    'route'   => $route,
    'h1'      => 'Page {hl}not found{/hl}',
    'eyebrow' => 'Error 404',
    'lead'    => 'The page you are looking for does not exist. Talk to a NorthStar dispatcher on WhatsApp or go back to the home page.',
    'ctas'    => [
        ['label' => 'Talk to a dispatcher', 'href' => wa($route['wa'] ?? null), 'wa' => true, 'arrow' => true],
        ['label' => 'Back to the home page', 'href' => url('/'), 'variant' => 'ghost'],
    ],
    'aside'   => $aside,
    'cls'     => 'co-hero',
]);

$menu = ns_menu();
$links = array_merge($menu['dispatch'], $menu['ops']);
if ($links):
?>
<section class="section" aria-labelledby="nf-services">
  <div class="container stack">
    <?php partial('section-head', [ 'title' => 'Where to next?', 'id' => 'nf-services']); ?>
    <div class="<?= count($links) === 5 ? 'g5' : (count($links) % 3 === 0 ? 'g3' : 'g2') ?>">
      <?php foreach ($links as $l): ?>
      <?php partial('card', ['variant' => 'link', 'size' => 'tile', 'title' => $l[0], 'text' => $l[1], 'href' => url($l[2]), 'cta' => 'Open']); ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
