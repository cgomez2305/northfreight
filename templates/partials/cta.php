<?php
/**
 * CTA final "Let's talk about your operation." en un panel redondeado.
 * El bloque anterior se separa con .pre-cta (el layout marca <main class="has-cta"> y lo aplica a la última .section).
 * Params: wa(string|null clave/mensaje), title(string con marcas), text(string),
 *         image(array|null, opcional: ['base'=>'truck-night-rain-headlights','alt'=>''] foto de fondo DECORATIVA, oscurecida con --cta-veil;
 *         con foto el contenido va centrado. Sin image queda el panel liso de siempre).
 */
require_once __DIR__ . '/kit.php';
$wa = $wa ?? null;
$email = (string) cfg('email');
$image = !empty($image['base']) ? $image : null;
?>
<section class="cta on-dark<?= $image ? ' cta--photo' : '' ?>" aria-labelledby="cta-title">
  <?php if ($image): ?><div class="cta__bg" aria-hidden="true"><?= ns_img(['base' => (string) $image['base'], 'alt' => '', 'sizes' => '(min-width:1025px) 1012px, (min-width:761px) 100vw, 250vw']) ?></div><?php endif; ?>
  <div class="container cta__in">
    <div class="cta__main">
      <img class="cta__mark" src="<?= e(asset('img/isotype-white.svg')) ?>" alt="" width="56" height="56">
      <h2 class="t-cta" id="cta-title"><?= ns_rich($title ?? 'Let’s talk about{br}{hl}your operation.{/hl}') ?></h2>
      <p class="cta__text"><?= ns_rich($text ?? 'No forms first. Open WhatsApp with a ready message and a dispatcher replies.') ?></p>
    </div>
    <div class="cta__actions">
      <a class="btn btn--primary btn--contact" href="<?= e(wa($wa)) ?>" rel="noopener">
        <span class="btn__stack"><span class="btn__label t-meta">WhatsApp</span><span class="btn__num"><?= e(cfg('phone')) ?></span></span>
      </a>
      <a class="btn btn--mail" href="mailto:<?= e($email) ?>">
        <span class="btn__stack"><span class="btn__label t-meta">Email</span><span class="btn__mail"><?= e($email) ?></span></span>
      </a>
    </div>
  </div>
</section>
