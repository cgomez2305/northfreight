<?php
use Captacion\Services\Fmt;
use Captacion\Services\RepView;
use Captacion\Ui;

/** /resultados: dashboard agregado para NorthStar. SOLO totales; nada que identifique a un lead. Variables: $range, $m, $p, $presets */
$kpis = RepView::kpis($m, $p, false);
$prevLabel = $range['prev_label'];
?>
<div class="page-head">
  <div>
    <h1 class="h1">Resultados de captación</h1>
    <p class="sub"><?= $e($range['label']) ?> · <?= $e(Fmt::dayLabel(Fmt::et($range['from']))) ?> a <?= $e(Fmt::dayLabel(Fmt::et($range['to'] - 1))) ?> · <?= $e($prevLabel) ?></p>
  </div>
  <div class="page-head__a">
    <div class="seg seg--wrap" role="group" aria-label="Rango">
      <?php foreach ($presets as $k => $label): ?><a class="seg__i<?= $range['key'] === $k ? ' is-on' : '' ?>" href="/resultados<?= $e(Ui::qs(['r' => $k === '30d' ? null : $k])) ?>"<?= $range['key'] === $k ? ' aria-current="true"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/rep/_kpis.php'; ?>

<div class="rep-grid">
  <section class="card" aria-labelledby="r-fn">
    <header class="card__h"><h2 class="label" id="r-fn">EMBUDO · NUEVO → FIRMADO</h2></header>
    <div class="card__b"><?php if ($m['received'] === 0): ?><p class="muted">Sin leads recibidos en el rango.</p><?php else: ?><?php require __DIR__ . '/rep/_funnel.php'; ?><?php endif; ?></div>
  </section>
  <section class="card" aria-labelledby="r-tr">
    <header class="card__h"><h2 class="label" id="r-tr">TENDENCIA</h2></header>
    <div class="card__b"><?php require __DIR__ . '/rep/_tendencia.php'; ?></div>
  </section>
</div>
<section class="card card--table" aria-labelledby="r-ch">
  <header class="card__h"><h2 class="label" id="r-ch">COSTO POR FIRMADO POR CANAL</h2></header>
  <?php require __DIR__ . '/rep/_canales.php'; ?>
</section>
<section class="card" aria-labelledby="r-ls">
  <header class="card__h"><h2 class="label" id="r-ls">MOTIVOS DE PÉRDIDA</h2><span class="muted"><?= $e($m['loss']['total']) ?> en total</span></header>
  <div class="card__b"><?php require __DIR__ . '/rep/_perdidas.php'; ?></div>
</section>
<p class="foot-note rep-note">Este panel muestra solo totales. «Recibidos» son los leads que llegaron en el rango; «calificados» los que llegaron a la etapa Calificado; «firmados» los de esa cohorte que firmaron. Los costos se cargan por mes, así que el costo por firmado usa solo los meses completos del rango. Los informes mensuales están en <a href="/informes">Informes</a>.</p>
