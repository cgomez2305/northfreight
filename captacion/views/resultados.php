<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

/**
 * /resultados: dashboard agregado para NorthStar. SOLO totales; nada que identifique a un lead. Q6-I: con las piezas visuales del dashboard vivo, todas sobre esos mismos agregados.
 * Variables: $range, $m, $p, $presets, $narr, $kpis, $cnote, $fnote, $minBase, $liveV. Sin JS (o con movimiento reducido) la pantalla es idéntica y completa: todo viene ya en el HTML.
 */
$prevLabel = $range['prev_label'];
$star = '<svg class="empty__star" aria-hidden="true"><use href="#i-star"/></svg>';
?>
<div class="page-head" data-rs data-live-v="<?= $e($liveV) ?>">
  <div>
    <h1 class="h1">Resultados de captación</h1>
    <?php require __DIR__ . '/rep/_narr.php'; ?>
    <p class="sub rs-meta"><?= $e($range['label']) ?> · <?= $e(Fmt::dayLabel(Fmt::et($range['from']))) ?> a <?= $e(Fmt::dayLabel(Fmt::et($range['to'] - 1))) ?> · <?= $e($prevLabel) ?></p>
  </div>
  <div class="page-head__a">
    <div class="seg seg--wrap" role="group" aria-label="Rango">
      <?php foreach ($presets as $k => $label): ?><a class="seg__i<?= $range['key'] === $k ? ' is-on' : '' ?>" href="/resultados<?= $e(Ui::qs(['r' => $k === '30d' ? null : $k])) ?>"<?= $range['key'] === $k ? ' aria-current="true"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/rep/_kpis_live.php'; ?>

<div class="rep-grid">
  <section class="card" aria-labelledby="r-fn" data-rs-fn>
    <header class="card__h"><h2 class="label" id="r-fn">Embudo · nuevo → firmado</h2></header>
    <div class="card__b">
      <?php if ($m['received'] === 0): ?>
        <div class="empty empty--sm"><?= $star ?><h3>Aún no hay leads en este rango</h3><p>Cuando lleguen, el embudo mostrará cuántos avanzan a cada etapa hasta la firma.</p></div>
      <?php else: ?>
        <?php require __DIR__ . '/rep/_funnel.php'; ?>
        <?php if ($fnote !== null): ?><p class="fn-note"><?php foreach ($fnote['segments'] as [$t, $b]): ?><?= $b ? '<b>' . $e($t) . '</b>' : $e($t) ?><?php endforeach; ?></p><?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
  <section class="card" aria-labelledby="r-tr" data-rs-tr>
    <header class="card__h"><h2 class="label" id="r-tr">Tendencia</h2></header>
    <div class="card__b"><?php require __DIR__ . '/rep/_tendencia.php'; ?></div>
  </section>
</div>
<section class="card card--table" aria-labelledby="r-ch" data-rs-ch>
  <header class="card__h"><h2 class="label" id="r-ch">Costo por firmado por canal</h2></header>
  <?php if (!$m['channels']['rows']): ?>
    <div class="empty empty--sm"><?= $star ?><h3>Aún no hay leads en este rango</h3><p>Cuando lleguen, verás de qué canal vienen y cuánto cuesta cada firmado.</p></div>
  <?php else: ?>
    <?php require __DIR__ . '/rep/_canales.php'; ?>
  <?php endif; ?>
</section>
<section class="card" aria-labelledby="r-ls" data-rs-ls>
  <header class="card__h"><h2 class="label" id="r-ls">Motivos de pérdida</h2><span class="muted"><?= $e($m['loss']['total']) ?> en total</span></header>
  <div class="card__b">
    <?php if (!$m['loss']['rows']): ?>
      <div class="empty empty--sm"><?= $star ?><h3><?= $m['received'] === 0 ? 'Sin leads, sin pérdidas que mostrar' : 'Ninguna pérdida todavía' ?></h3><p><?= $m['received'] === 0 ? 'Cuando haya leads, aquí verás por qué se pierden.' : 'Ningún lead recibido en el rango se perdió todavía.' ?></p></div>
    <?php else: ?>
      <?php require __DIR__ . '/rep/_perdidas.php'; ?>
    <?php endif; ?>
  </div>
</section>
<p class="foot-note rep-note">Este panel muestra solo totales. «Recibidos» son los leads que llegaron en el rango; «calificados» los que llegaron a la etapa Calificado; «firmados» los de esa cohorte que firmaron. Con menos de <?= $e($minBase) ?> leads en la base de comparación no se muestran porcentajes, solo conteos. Los costos se cargan por mes, así que el costo por firmado usa solo los meses completos del rango. Los informes mensuales están en <a href="/informes">Informes</a>.</p>
