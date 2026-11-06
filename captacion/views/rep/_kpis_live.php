<?php
use Captacion\Services\Charts;
use Captacion\Ui;

/**
 * Tarjetas de indicadores de /resultados (Q6-I): número protagonista con conteo, medidor de rayas o microlínea, variación con base mínima y contexto.
 * Variables: $kpis (ResultadosBoard::kpis), $range. Solo agregados.
 */
$prevLabel = (string) $range['prev_label'];
?>
<section class="kpis kpis--rs" aria-label="Indicadores del rango">
  <?php foreach ($kpis as $k): $viz = $k['viz']; ?>
    <article class="kpi" data-k="<?= $e($k['k']) ?>">
      <header class="kpi__h"><span class="label"><?= $e($k['label']) ?></span><?= Ui::tip($k['tip']) ?></header>
      <div class="kpi__m">
        <?= Ui::big($k['text'], 'kpi__v' . ($k['na'] ? ' kpi__v--na' : ''), $k['count']) ?>
        <?php if (($viz['type'] ?? '') === 'spark'): ?><?= Charts::spark($viz['values']) ?>
        <?php elseif (($viz['type'] ?? '') === 'ticks'): ?><div class="kpi__z"><?= Ui::ticks($viz['on'], $viz['total'], $viz['label'], $viz['cls']) ?><span class="kpi__c"><b><?= $e($viz['cap']) ?></b></span></div><?php endif; ?>
      </div>
      <p class="kpi__s"><?= $e($k['sub']) ?></p>
      <footer class="kpi__f"><?php if ($k['delta'] !== null): ?><?= Ui::delta($k['delta']) ?><span><?= $e($prevLabel) ?></span><?php else: ?><span class="delta delta--flat"><?= Ui::icon('minus') ?>Sin dato</span><span><?= $e($prevLabel) ?></span><?php endif; ?></footer>
    </article>
  <?php endforeach; ?>
</section>
