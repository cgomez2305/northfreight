<?php
/**
 * CTA final "Let's talk about your operation." en un panel redondeado.
 * El bloque anterior se separa con .pre-cta (el layout marca <main class="has-cta"> y lo aplica a la última .section).
 * Params: wa(string|null clave/mensaje), title(string con marcas), text(string),
 *         image(array|null, opcional: ['base'=>'nf-cta-night-truck','alt'=>''] foto de fondo DECORATIVA, oscurecida con --cta-veil (desde la izquierda; en móvil la foto va arriba, recorte <base>-m si existe);
 *         con foto el texto va a la izquierda. Sin image queda el panel liso de siempre),
 *         coverage(bool, fase S: añade al texto COVERAGE_TEXT e idiomas de LANGUAGES_TEXT; solo lo activa /our-desk/ con 'cta_coverage' => true en su ruta; con ambas constantes vacías no añade nada).
 */
require_once __DIR__ . '/kit.php';
$wa = $wa ?? null;
$email = (string) cfg('email');
$image = !empty($image['base']) ? $image : null;
$note = [];
if (!empty($coverage)) {
    if ((string) cfg('coverage_text') !== '') { $note[] = (string) cfg('coverage_text') . '.'; }
    if ((string) cfg('languages_text') !== '') { $note[] = 'Languages: ' . (string) cfg('languages_text') . '.'; }
}
?>
<section class="cta on-dark<?= $image ? ' cta--photo' : '' ?>" aria-labelledby="cta-title">
  <?php if ($image): $ctaM = (string) $image['base'] . '-m'; ?><div class="cta__bg" aria-hidden="true"><?= ns_img(['base' => (string) $image['base'], 'alt' => '', 'sizes' => '(min-width:761px) 100vw, 65vw',
      'art' => is_file(NS_ROOT . '/assets/img/' . $ctaM . '-640.webp') ? [['media' => '(max-width:760px)', 'base' => $ctaM, 'sizes' => '(min-width:641px) 100vw, 65vw']] : []]) ?></div><?php endif; ?>
  <div class="container cta__in">
    <div class="cta__main">
      <img class="cta__mark" src="<?= e(asset('img/isotype-white.svg')) ?>" alt="" width="56" height="56">
      <h2 class="t-cta" id="cta-title"><?= ns_rich($title ?? 'Let’s talk about{br}{hl}your operation.{/hl}') ?></h2>
      <p class="cta__text"><?= ns_rich($text ?? 'No forms first. Open WhatsApp with a ready message to send to a dispatcher.') ?><?= $note ? ' ' . e(implode(' ', $note)) : '' ?></p>
    </div>
    <div class="cta__actions">
      <a class="btn btn--primary btn--contact" href="<?= e(wa($wa)) ?>" rel="noopener">
        <span class="btn__stack btn__t"><span class="btn__label t-meta">WhatsApp</span><span class="btn__num"><?= e(cfg('phone')) ?></span></span>
      </a>
      <a class="btn btn--mail" href="mailto:<?= e($email) ?>">
        <span class="btn__stack"><span class="btn__label t-meta">Email</span><span class="btn__mail"><?= e($email) ?></span></span>
      </a>
    </div>
  </div>
</section>
