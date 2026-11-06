<?php use Captacion\Ui; ?>
<div class="pkscrim" data-pk-scrim hidden></div>
<aside class="peek" id="peek" role="dialog" aria-modal="false" aria-labelledby="pk-title" aria-label="Vistazo rápido del lead" tabindex="-1" hidden data-peek-id="<?= $e((int) ($peekId ?? 0)) ?>" data-css-e="<?= $e((string) (@filemtime(CAPTACION_ROOT . '/public/assets/etiquetas.css') ?: 1)) ?>" data-css-v="<?= $e((string) (@filemtime(CAPTACION_ROOT . '/public/assets/peek.css') ?: 1)) ?>">
  <div class="peek__bar">
    <span class="label">VISTAZO RÁPIDO</span>
    <div class="peek__nav">
      <button class="icon-btn icon-btn--sm" type="button" data-pk-prev aria-label="Lead anterior (K)" title="Anterior (K)"><?= Ui::icon('chevron-left', 'ic--up') ?></button>
      <button class="icon-btn icon-btn--sm" type="button" data-pk-next aria-label="Lead siguiente (J)" title="Siguiente (J)"><?= Ui::icon('chevron-right', 'ic--down') ?></button>
      <button class="icon-btn icon-btn--sm" type="button" data-pk-close aria-label="Cerrar el vistazo (Esc)" title="Cerrar (Esc)"><?= Ui::icon('x') ?></button>
    </div>
  </div>
  <div class="peek__body" data-pk-body aria-live="polite"></div>
</aside>
