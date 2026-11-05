<?php
use Captacion\Services\Charts;
use Captacion\Services\Fmt;

/** Variables: $m (con loss) */
$rows = $m['loss']['rows'];
$top = max(1, (int) ($rows[0]['n'] ?? 1));
?>
<?php if (!$rows): ?>
  <p class="muted rep-pad">Ningún lead recibido en el rango se perdió todavía.</p>
<?php else: ?>
  <ol class="fn fn--loss" aria-label="Motivos de pérdida">
    <?php foreach ($rows as $r): ?>
      <li class="fn__r"><span class="fn__l"><b><?= $e($r['label']) ?></b></span><?= Charts::hbar($r['n'] / $top * 100) ?><span class="fn__n"><b><?= $e($r['n']) ?></b><small><?= $r['pct'] !== null ? $e(Fmt::pct($r['pct'], 0)) : '' ?></small></span></li>
    <?php endforeach; ?>
  </ol>
<?php endif; ?>
