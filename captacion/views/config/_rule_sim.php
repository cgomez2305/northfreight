<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

/** Estado de la ultima simulacion del conjunto. Variables: $sim (RuleSim::status) o null, $csrf, $setId */
if ($sim === null): ?>
  <p class="rp-note"><?= Ui::icon('flask') ?><span>La simulación revisa muchos más leads que la vista previa, guarda el resultado y permite descargarlo. No modifica ningún lead ni envía alertas.</span></p>
<?php else:
    $s = $sim['summary'];
    $st = $sim['status'];
    $scopeLabel = ($sim['scope']['mode'] ?? '') === 'last' ? 'últimos ' . (int) $sim['scope']['n'] . ' leads' : (($sim['scope']['mode'] ?? '') === 'days' ? 'últimos ' . (int) $sim['scope']['days'] . ' días' : 'del ' . ($sim['scope']['from'] ?? '') . ' al ' . ($sim['scope']['to'] ?? '')); ?>
  <div class="rp-sim" data-sim="<?= $e($sim['id']) ?>" data-sim-status="<?= $e($st) ?>">
    <p class="rp-sim__h"><span class="chip chip--<?= $st === 'done' ? 'warm' : ($st === 'failed' ? 'bad' : 'plain') ?>"><i class="dot"></i><?= $st === 'done' ? 'Terminada' : ($st === 'failed' ? 'Falló' : 'En curso') ?></span>
      <span class="muted">Simulación #<?= $e($sim['id']) ?> · <?= $e($scopeLabel) ?> · <?= $e(Fmt::ago($sim['created_at'])) ?></span></p>
    <?php if ($st === 'running'): ?>
      <div class="rp-prog" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $e($sim['percent']) ?>" aria-label="Avance de la simulación"><svg viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><rect width="100" height="6" rx="3" class="rp-prog__bg"/><rect width="<?= $e(max(2, $sim['percent'])) ?>" height="6" rx="3" class="rp-prog__fg"/></svg></div>
      <p class="muted"><?= $e(Fmt::num($sim['processed'])) ?> de <?= $e(Fmt::num($sim['total'])) ?> leads (<?= $e($sim['percent']) ?> %). Corre por la cola, un lote por minuto del cron; puedes cerrar este panel.</p>
    <?php elseif ($st === 'failed'): ?>
      <p class="rp-note rp-note--warn"><?= Ui::icon('alert') ?><span><?= $e($sim['error'] !== '' ? $sim['error'] : 'La simulación no pudo terminar.') ?></span></p>
    <?php else: ?>
      <p class="rp-big rp-big--s"><b><?= $e(Fmt::num((int) $s['changed'])) ?></b><span>de <?= $e(Fmt::num((int) $s['scanned'])) ?> leads cambiarían · <?= $e((int) $s['to_hot']) ?> pasarían a caliente · <?= $e((int) $s['alerts']) ?> alertas · <?= $e((int) $s['tasks']) ?> tareas</span></p>
      <?php if (!empty($s['rows_truncated'])): ?><p class="muted">Se guardaron los primeros <?= $e(Fmt::num((int) ($s['rows'] ?? 0))) ?> leads afectados; las cifras de arriba cuentan todos.</p><?php endif; ?>
      <form method="post" action="/config/rules/simcsv" class="rp-sim__dl"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="sim" value="<?= $e($sim['id']) ?>"><input type="hidden" name="set" value="<?= $e($setId) ?>">
        <button class="btn btn--secondary btn--sm" type="submit"><?= Ui::icon('download') ?>Descargar CSV</button><small class="muted">Código corto y datos de segmentación, sin nombres ni teléfonos.</small></form>
    <?php endif; ?>
  </div>
<?php endif; ?>
