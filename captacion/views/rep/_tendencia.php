<?php
use Captacion\Services\Charts;

/** Variables: $m (con trend) */
$t = $m['trend'];
?>
<?php if (array_sum($t['received']) + array_sum($t['signed']) === 0): ?>
  <p class="muted rep-pad">Sin leads ni firmas en el rango.</p>
<?php else: ?>
  <?= Charts::line($t['unit'] === 'day' ? 'Leads recibidos y firmados por día' : 'Leads recibidos y firmados por semana', $t['labels'], [['name' => 'Recibidos', 'cls' => 'ops', 'values' => $t['received']], ['name' => 'Firmados (por fecha de firma)', 'cls' => 'drv', 'values' => $t['signed']]], $t['tips']) ?>
  <ul class="legend rep-pad" aria-hidden="true"><li><i class="sw sw--ops"></i>Recibidos</li><li><i class="sw sw--drv"></i>Firmados (por fecha de firma)</li></ul>
<?php endif; ?>
