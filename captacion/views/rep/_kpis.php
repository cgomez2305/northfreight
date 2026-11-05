<?php
use Captacion\Ui;

/** Variables: $kpis (RepView::kpis), $prevLabel */
?>
<section class="rk" aria-label="Indicadores del rango">
  <?php foreach ($kpis as $k): ?>
    <article class="rk__c">
      <span class="label"><?= $e($k['label']) ?></span>
      <p class="rk__v<?= $k['na'] ? ' rk__v--na' : '' ?>"><?= $e($k['value']) ?></p>
      <?php if ($k['delta'] !== null): ?><p class="rk__d"><?= Ui::delta($k['delta']) ?> <small><?= $e($prevLabel) ?></small></p><?php endif; ?>
      <p class="rk__s"><?= $e($k['sub']) ?></p>
    </article>
  <?php endforeach; ?>
</section>
