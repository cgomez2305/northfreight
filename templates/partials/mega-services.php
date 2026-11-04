<?php
/**
 * Panel mega menú Services (oculto por defecto: atributo hidden; lo abre JS).
 * Chips "By equipment" solo con SHOW_EQUIPMENT_CHIPS y rutas live. Tarjeta destacada de Amazon Relay:
 * imagen arriba y TEXTO DEBAJO (nunca sobre la foto).
 * Hook JS: #mega-services[data-mega="services"].
 */
require_once __DIR__ . '/kit.php';
$m = ns_menu();
$relayLive = route_is_live('/amazon-relay-dispatch/');
?>
<div id="mega-services" class="mega" data-mega="services" aria-label="Services" hidden>
  <div class="mega__in mega__in--services">
    <div class="mega__col">
      <span class="mega__label t-meta">Dispatch services</span>
      <?php foreach ($m['dispatch'] as $i): ?>
      <a class="mega__link" href="<?= e(url($i[2])) ?>"><?= ns_icon($i[3], 16) ?><span class="mega__text"><strong class="mega__title"><?= e($i[0]) ?></strong><span class="mega__desc"><?= e($i[1]) ?></span></span></a>
      <?php endforeach; ?>
      <span class="mega__label mega__label--gap t-meta">Operations &amp; back office</span>
      <?php foreach ($m['ops'] as $i): ?>
      <a class="mega__link" href="<?= e(url($i[2])) ?>"><?= ns_icon($i[3], 16) ?><span class="mega__text"><strong class="mega__title"><?= e($i[0]) ?></strong><span class="mega__desc"><?= e($i[1]) ?></span></span></a>
      <?php endforeach; ?>
      <?php if ($m['equipment']): ?>
      <span class="mega__label mega__label--gap t-meta">By equipment</span>
      <div class="chips">
        <?php foreach ($m['equipment'] as $i): ?>
        <a class="chip-link t-meta" href="<?= e(url($i[2])) ?>"><?= e($i[0]) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($relayLive): ?>
    <a class="mega__feature" href="<?= e(url('/amazon-relay-dispatch/')) ?>">
      <span class="mega__feature-img"><?= ns_img(['base' => 'nf-trailer-dock-night-wide', 'alt' => '', 'sizes' => '380px', 'widths' => [640]]) ?></span>
      <span class="mega__feature-body">
        <span class="t-meta c-cyan-light">Our specialty</span>
        <span class="head mega__feature-title">Amazon Relay, handled end to end</span>
        <span class="mega__feature-cta">See how we run Relay <span class="arrow" aria-hidden="true">→</span></span>
      </span>
    </a>
    <?php endif; ?>
  </div>
</div>
