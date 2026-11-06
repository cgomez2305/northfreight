<?php
/**
 * /careers/thank-you/ — página de gracias de una solicitud (brief P). Solo se sirve con el token firmado de un envío real (index.php, `gate` => 'lead-thanks'; sin él, 303 a /careers/apply/).
 * noindex, fuera de sitemap y llms, sin CTA final. Disponibles: $route (con 'thanks_profile'), $cfg y helpers.
 * Sin promesas de tiempo de respuesta ni de cobertura (§8.2.3). El evento de analítica (generate_lead / Lead) lo emite main.js a partir de [data-lead-event]; no se carga ningún script de terceros.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$profile = (string) ($route['thanks_profile'] ?? 'owner-operator');
$waKey = ['owner-operator' => 'thanks-owner', 'small-fleet' => 'thanks-fleet', 'company-driver' => 'thanks-driver'][$profile] ?? 'thanks-owner';

$aside = '<div class="cr-thanks__act">'
    . '<a class="btn btn--primary" href="' . e(wa($waKey)) . '" rel="noopener"><span class="btn__t">Message the desk on WhatsApp</span></a>'
    . '<a class="btn btn--ghost" href="' . e(tel()) . '">Call ' . e(cfg('phone')) . '</a>'
    . '</div><span data-lead-event data-lead-type="' . e($profile) . '" hidden></span>';

partial('hero', [
    'route' => $route,
    'cls'   => 'cr-hero cr-hero--thanks',
    'h1'    => 'Application {hl}received{/hl}',
    'lead'  => 'Thanks for applying. Here is what happens next. You can also message the desk on WhatsApp now.',
    'aside' => $aside,
    'stack' => true,
]);
?>
<section class="section pre-cta" aria-labelledby="th-next">
  <div class="container stack">
    <?php partial('section-head', ['id' => 'th-next', 'eyebrow' => 'What happens next', 'title' => 'Your next steps']); ?>
    <?php partial('steps', ['label' => 'What happens after you apply', 'items' => [
        ['title' => 'The desk reads your application', 'text' => 'A dispatcher goes through your answers.'],
        ['title' => 'We get in touch if it is a fit', 'text' => 'On WhatsApp or the way you chose, so we can learn your operation.'],
        ['title' => 'We agree on scope & contacts', 'text' => 'Who approves loads, how and when we report, and which tasks the desk owns from day one.'],
    ]]); ?>
    <p class="cr-more"><a class="cr-link" href="<?= e(url('/how-it-works/')) ?>">See how dispatching works with NorthStar <span class="arrow" aria-hidden="true">→</span></a></p>
  </div>
</section>
