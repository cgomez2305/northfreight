<?php
use Captacion\Services\Charts;
use Captacion\Services\Fmt;

/** Variables: $m (con funnel) */
$top = max(1, (int) ($m['funnel'][0]['n'] ?? 0));
?>
<ol class="fn" aria-label="Embudo de captación">
  <?php foreach ($m['funnel'] as $i => $f): ?>
    <li class="fn__r">
      <span class="fn__l"><b><?= $e($f['label']) ?></b><small><?= $f['of_prev'] !== null ? $e(Fmt::pct($f['of_prev'], 0)) . ' de la etapa anterior' : 'Todos los recibidos' ?></small></span>
      <?= Charts::hbar($f['n'] / $top * 100, $i === count($m['funnel']) - 1 ? 'hb--end' : '') ?>
      <span class="fn__n"><b><?= $e(Fmt::num($f['n'])) ?></b><small><?= $f['of_total'] !== null ? $e(Fmt::pct($f['of_total'], 0)) : '—' ?></small></span>
    </li>
  <?php endforeach; ?>
</ol>
