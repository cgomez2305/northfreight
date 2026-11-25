<?php
use Captacion\Ui;
use Captacion\Services\ProspectSources;

/** Fase AU: diálogos «Añadir por USDOT» e «Importar CSV» de /prospectos. Variables: $csrf */
?>
<dialog class="dlg" id="p-add" aria-labelledby="p-add-t">
  <form method="post" action="/prospectos/add">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="p-add-t">Añadir un prospecto por USDOT</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m">Para un transportista que encontraste por tu cuenta: un grupo de Facebook, un foro, una parada de camiones o alguien que lo recomendó. Los datos (razón social, teléfono, correo, flota y autoridad) se completan solos con el registro público de FMCSA.</p>
    <label class="field"><span>Número USDOT</span><input type="text" name="usdot" required inputmode="numeric" maxlength="12" autocomplete="off" placeholder="Por ejemplo 3912345"></label>
    <label class="field"><span>Dónde lo encontraste (opcional)</span><input type="text" name="origin" maxlength="200" autocomplete="off" placeholder="Grupo de Facebook de owner-operators de Texas"></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Buscar y añadir</span></button></div>
  </form>
</dialog>
<dialog class="dlg" id="p-import" aria-labelledby="p-import-t">
  <form method="post" action="/prospectos/import" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="p-import-t">Importar prospectos desde CSV</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m">Una fila por transportista. Obligatoria: <code>usdot</code>. Opcionales: <code>mc</code>, <code>name</code>, <code>dba</code>, <code>phone</code>, <code>email</code>, <code>state</code>, <code>city</code>, <code>fleet</code>, <code>authority_date</code>. Hasta <?= $e(number_format(ProspectSources::MAX_CSV_ROWS, 0, ',', '.')) ?> filas. No entran los USDOT que ya están, los que ya son lead ni los teléfonos de «No llamar más».</p>
    <p class="dlg__m muted">Si la lista es comprada, pide al proveedor de dónde salen los datos: la responsabilidad de cómo se contacta es de quien llama.</p>
    <label class="field"><span>Archivo CSV</span><input type="file" name="file" accept=".csv,text/csv" required></label>
    <label class="field"><span>De dónde sale la lista (opcional)</span><input type="text" name="origin" maxlength="200" autocomplete="off"></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Importar</span></button></div>
  </form>
</dialog>
