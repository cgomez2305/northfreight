<?php
use Captacion\Services\Charts;
use Captacion\Services\Fmt;

/** Variables: $m (con funnel). Opcional $minBase (Q6-I, solo /resultados): con menos de ese numero en la etapa anterior (o en los recibidos) no hay porcentaje, se dice el conteo. */
$minBase = (int) ($minBase ?? 0);
$top = max(1, (int) ($m['funnel'][0]['n'] ?? 0));
?>
<ol class="fn" aria-label="Embudo de captación">
  <?php foreach ($m['funnel'] as $i => $f): $prevN = $i > 0 ? (int) $m['funnel'][$i - 1]['n'] : 0; ?>
    <li class="fn__r">
      <span class="fn__l"><b><?= $e($f['label']) ?></b><small><?= $f['of_prev'] === null ? 'Todos los recibidos' : ($prevN < $minBase ? $e(Fmt::num($f['n'])) . ' de ' . $e(Fmt::num($prevN)) . ' de la etapa anterior' : $e(Fmt::pct($f['of_prev'], 0)) . ' de la etapa anterior') ?></small></span>
      <?= Charts::hbar($f['n'] / $top * 100, $i === count($m['funnel']) - 1 ? 'hb--end' : '') ?>
      <span class="fn__n"><b><?= $e(Fmt::num($f['n'])) ?></b><small><?= $f['of_total'] === null ? '—' : ($top < $minBase ? '' : $e(Fmt::pct($f['of_total'], 0))) ?></small></span>
    </li>
  <?php endforeach; ?>
</ol>
