<?php
use Captacion\Services\Fmt;
use Captacion\Services\RepView;

/** Variables: $m (con channels). Costos de los meses completos del rango; nunca un numero inventado. */
$c = $m['channels'];
$cell = static fn($r, string $k, string $none) => $r[$k] !== null ? RepView::money((float) $r[$k]) : '<span class="dd-na">' . $none . '</span>';
?>
<div class="tw"><table class="tbl tbl--rep">
  <thead><tr><th scope="col">Canal</th><th scope="col" class="num-c">Leads</th><th scope="col" class="num-c">Calificados</th><th scope="col" class="num-c">Presentados</th><th scope="col" class="num-c">Firmados</th><th scope="col" class="num-c">Costo</th><th scope="col" class="num-c">Costo por lead</th><th scope="col" class="num-c">Costo por firmado</th></tr></thead>
  <tbody>
    <?php foreach ($c['rows'] as $r): ?>
      <tr><th scope="row" class="c-n"><?= $e($r['label']) ?></th>
        <td class="num-c" data-l="Leads"><?= $e(Fmt::num($r['leads'])) ?></td><td class="num-c" data-l="Calificados"><?= $e(Fmt::num($r['qualified'])) ?></td><td class="num-c" data-l="Presentados"><?= $e(Fmt::num($r['presented'])) ?></td><td class="num-c" data-l="Firmados"><?= $e(Fmt::num($r['signed'])) ?></td>
        <td class="num-c" data-l="Costo"><?= $r['cost'] !== null ? $e(RepView::money((float) $r['cost'])) : '<span class="dd-na">Sin costos cargados</span>' ?></td>
        <td class="num-c" data-l="Costo por lead"><?= $r['cpl'] !== null ? $e(RepView::money($r['cpl'])) : '<span class="dd-na">' . ($r['cost'] === null ? 'Sin costos cargados' : 'Sin leads') . '</span>' ?></td>
        <td class="num-c" data-l="Costo por firmado"><?= $r['cps'] !== null ? $e(RepView::money($r['cps'], 0)) : '<span class="dd-na">' . ($r['cost'] === null ? 'Sin costos cargados' : 'Sin firmados') . '</span>' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$c['rows']): ?><tr><td colspan="8" class="muted">Sin leads en el rango.</td></tr><?php endif; ?>
  </tbody>
  <?php if ($c['total']['cost'] > 0): ?><tfoot><tr><th scope="row" class="c-n">Total con costo</th><td class="num-c" data-l="Leads"><?= $e(Fmt::num($c['total']['leads'])) ?></td><td></td><td></td><td class="num-c" data-l="Firmados"><?= $e(Fmt::num($c['total']['signed'])) ?></td><td class="num-c" data-l="Costo"><?= $e(RepView::money($c['total']['cost'])) ?></td>
    <td class="num-c" data-l="Costo por lead"><?= $c['total']['cpl'] !== null ? $e(RepView::money($c['total']['cpl'])) : '—' ?></td><td class="num-c" data-l="Costo por firmado"><?= $c['total']['cps'] !== null ? $e(RepView::money($c['total']['cps'], 0)) : '—' ?></td></tr></tfoot><?php endif; ?>
</table></div>
<p class="foot-note rep-note"><?= $c['cost_months'] ? 'Costos de los meses completos del rango: ' . $e(implode(', ', array_map([\Captacion\Services\Reports::class, 'monthName'], $c['cost_months']))) . '. Los leads y firmados de las columnas de costo son solo de esos meses.' : 'El rango no incluye ningún mes completo, y los costos se cargan por mes: por eso no hay costo por lead ni por firmado. Elige «Mes pasado» o un rango de meses completos.' ?></p>
