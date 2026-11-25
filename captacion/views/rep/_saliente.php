<?php
use Captacion\Services\Fmt;
use Captacion\Services\Prospects;

/** Tarjeta de captación saliente (fase AT), compartida por /reportes (con enlace a Prospectos) y /resultados del lector. Solo agregados. Variables: $ps, $pct, $outLink. */
$ml = $ps['mail'];
?>
<section class="card" aria-labelledby="r-out">
  <header class="card__h"><h2 class="label" id="r-out">Captación saliente · prospectos FMCSA</h2><?php if (!empty($outLink)): ?><a class="link" href="/prospectos">Ir a Prospectos</a><?php endif; ?></header>
  <div class="card__b">
  <?php if ($ps['calls'] === 0 && $ml['sent'] === 0): ?>
    <p class="muted">Sin llamadas ni correos a prospectos en el rango.</p>
  <?php else: ?>
    <dl class="rep-out">
      <div><dt>Llamadas</dt><dd><?= $e(Fmt::num($ps['calls'])) ?></dd><dd><small><?= $e(Fmt::num($ps['prospects'])) ?> prospectos distintos</small></dd></div>
      <div><dt>Contestaron</dt><dd><?= $e($pct($ps['reached'], $ps['calls'])) ?></dd><dd><small><?= $e(Fmt::num($ps['reached'])) ?> llamadas con alguien al otro lado</small></dd></div>
      <div><dt>Interesados</dt><dd><?= $e(Fmt::num($ps['interested'])) ?></dd><dd><small><?= $e($pct($ps['interested'], max(1, $ps['reached']))) ?> de los que contestaron</small></dd></div>
      <div><dt>Convertidos en lead</dt><dd><?= $e(Fmt::num($ps['converted'])) ?></dd><dd><small>en el rango</small></dd></div>
      <div><dt>Correos enviados</dt><dd><?= $e(Fmt::num($ml['sent'] + $ml['followups'])) ?></dd><dd><small><?= $ml['followups'] > 0 ? $e(Fmt::num($ml['followups'])) . ' segundos correos' : 'campañas en lote' ?></small></dd></div>
      <div><dt>Respuestas por correo</dt><dd><?= $e(Fmt::num($ml['replied'])) ?></dd><dd><small><?= $e(Fmt::num($ml['clicked'])) ?> con clic · <?= $e(Fmt::num($ml['unsub'])) ?> bajas</small></dd></div>
    </dl>
    <p class="rep-res"><?php foreach ($ps['by_result'] as $k => $n): ?><span><?= $e(Prospects::RESULTS[$k]) ?> <b><?= $e(Fmt::num($n)) ?></b></span><?php endforeach; ?></p>
  <?php endif; ?>
  </div>
</section>
