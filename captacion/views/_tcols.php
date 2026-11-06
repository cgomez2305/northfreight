<?php
use Captacion\Services\LeadTable;
use Captacion\Ui;

/** Menú de columnas de la Tabla (Q5c-B): elegir, subir o bajar. Es un <details> con un formulario POST, así que funciona sin JS. Variables: $tcols (columnas visibles, en orden), $csrf, $here. */
$off = array_values(array_diff(array_keys(LeadTable::COLUMNS), $tcols));
?>
<details class="tcm" data-tcm>
  <summary class="btn btn--secondary btn--sm"><?= Ui::icon('columns') ?>Columnas</summary>
  <form class="tcm__p" method="post" action="/tabla/cols">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($here) ?>">
    <p class="label tcm__h">COLUMNAS Y ORDEN</p>
    <p class="tcm__fix"><?= Ui::icon('lock') ?>Lead · siempre visible, fija a la izquierda</p>
    <ul class="tcm__l">
      <?php foreach ($tcols as $i => $k): ?>
        <li><label class="check check--c"><input type="checkbox" name="col[]" value="<?= $e($k) ?>" checked><span><?= $e(LeadTable::COLUMNS[$k]) ?></span></label>
          <button class="icon-btn icon-btn--sm" type="submit" name="move" value="<?= $e($k) ?>:up" aria-label="Subir la columna <?= $e(LeadTable::COLUMNS[$k]) ?>"<?= $i === 0 ? ' disabled' : '' ?>><?= Ui::icon('arrow-up') ?></button>
          <button class="icon-btn icon-btn--sm" type="submit" name="move" value="<?= $e($k) ?>:down" aria-label="Bajar la columna <?= $e(LeadTable::COLUMNS[$k]) ?>"<?= $i === count($tcols) - 1 ? ' disabled' : '' ?>><?= Ui::icon('arrow-down') ?></button></li>
      <?php endforeach; ?>
      <?php foreach ($off as $k): ?>
        <li class="is-off"><label class="check check--c"><input type="checkbox" name="col[]" value="<?= $e($k) ?>"><span><?= $e(LeadTable::COLUMNS[$k]) ?></span></label></li>
      <?php endforeach; ?>
    </ul>
    <div class="tcm__a"><button class="btn btn--primary btn--sm" type="submit"><span class="btn__t">Aplicar</span></button><button class="btn btn--ghost btn--sm" type="submit" name="reset" value="1">Restablecer</button></div>
  </form>
</details>
