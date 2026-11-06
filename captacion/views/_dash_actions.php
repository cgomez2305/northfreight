<?php
use Captacion\Ui;

/** Cuerpo del panel «Siguiente mejor acción» (Q6). Variable: $actions (DashboardActions::compute()). Reglas y fórmulas de impacto: DashboardActions y README §24. */
?>
<?php if (!$actions): ?>
  <div class="act-ok"><span class="act-ok__i"><?= Ui::icon('check-circle') ?></span><div><h3>Todo al día</h3><p>Ningún caliente espera contacto, no hay tareas vencidas ni trabajos fallidos y nada espera revisión.</p></div></div>
<?php else: ?>
  <ol class="acts__l">
    <?php foreach ($actions as $i => $a): ?>
      <li class="act act--<?= $e($a['level']) ?>">
        <p class="act__k mono">0<?= $e($i + 1) ?></p>
        <h3 class="act__t"><?= $e($a['title']) ?></h3>
        <p class="act__d"><?= $e($a['detail']) ?></p>
        <div class="act__f">
          <span class="act__i"><span class="label">IMPACTO</span><?= Ui::ticks((int) round($a['impact'] / 5), 20, 'Impacto ' . $a['impact'] . ' de 100', 'tk--i tk--' . $a['level']) ?><b class="mono"><?= $e($a['impact']) ?></b></span>
          <a class="btn btn--secondary btn--sm" href="<?= $e($a['href']) ?>"><?= $e($a['cta']) ?><?= Ui::icon('chevron-right') ?></a>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
<?php endif; ?>
