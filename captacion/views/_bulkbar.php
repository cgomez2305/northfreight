<?php
use Captacion\Services\Catalog;
use Captacion\Ui;

/** Barra de acciones sobre la selección (Lista y Tabla). Variable opcional $exportAction: ruta a la que va «Exportar selección» (por defecto /leads/bulk). */
?>
  <div class="bulk" data-bulkbar hidden role="region" aria-label="Acciones sobre la selección">
    <p class="bulk__n"><b data-count>0</b> seleccionados</p>
    <div class="bulk__a">
      <label class="sr" for="bulk-stage">Cambiar etapa a</label>
      <select id="bulk-stage" name="stage" data-bulk-stage><option value="contacted">Contactado</option><option value="qualified">Calificado / Precalificado</option><option value="lost">Perdido / Descartado</option></select>
      <label class="sr" for="bulk-reason">Motivo</label>
      <select id="bulk-reason" name="reason" data-bulk-reason hidden><option value="">Motivo…</option><?php foreach (Catalog::LOSS_REASONS as $k => $l): if ($k === 'other') { continue; } ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select>
      <button class="btn btn--primary btn--sm" type="submit" name="do" value="stage"><span class="btn__t">Cambiar etapa</span></button>
      <button class="btn btn--secondary btn--sm" type="submit" name="do" value="export"<?= !empty($exportAction) ? ' formaction="' . $e($exportAction) . '"' : '' ?>><?= Ui::icon('download') ?>Exportar selección</button>
      <button class="btn btn--ghost btn--sm" type="button" data-clear>Deseleccionar</button>
    </div>
  </div>
