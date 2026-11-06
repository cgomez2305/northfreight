<?php
use Captacion\Services\Charts;

/** Variables: $m (con trend), $cnote (ResultadosBoard::chartNote: anotación, promedio y frases del tooltip). Q6-I: etiquetas directas al final de cada línea en lugar de leyenda, una anotación calculada y promedio punteado. */
$t = $m['trend'];
$empty = array_sum($t['received']) + array_sum($t['signed']) === 0;
$title = $t['unit'] === 'day' ? 'Leads recibidos y firmados por día' : 'Leads recibidos y firmados por semana';
?>
<?php if ($empty): ?>
  <div class="empty empty--sm"><svg class="empty__star" aria-hidden="true"><use href="#i-star"/></svg><h3>Sin leads ni firmas en el rango</h3><p>Cuando lleguen, aquí verás su evolución por <?= $t['unit'] === 'day' ? 'día' : 'semana' ?>, con el pico señalado.</p></div>
<?php else: ?>
  <?= Charts::line($title, $t['labels'], [
      ['name' => 'Recibidos', 'cls' => 'ops', 'values' => $t['received'], 'end' => 'Recibidos'],
      ['name' => 'Firmados (por fecha de firma)', 'cls' => 'drv', 'values' => $t['signed'], 'end' => 'Firmados'],
  ], $t['tips'], 'leads', ['uid' => 'rs', 'area' => 'ops', 'note' => $cnote['note'], 'avg' => $cnote['avg'], 'avgu' => $cnote['avgu'], 'delta' => 0, 'prev' => $cnote['prev']]) ?>
  <p class="foot-note rep-note">Firmados cuenta por fecha de firma, no por la de llegada del lead.</p>
<?php endif; ?>
