<?php
use Captacion\Services\Fmt;
use Captacion\Services\Prefs;
use Captacion\Services\SavedViews;
use Captacion\Ui;

/**
 * Barra de vistas de los leads (Q5a-C, ampliada en Q5c): pestañas Lista / Tablero / Tabla / Calendario / Cronología que comparten filtros, vistas del sistema y del usuario (guardar, renombrar, fijar, borrar),
 * densidad y columnas. Variables: $screen ('leads'|'pipeline'|'tabla'|'calendario'|'cronologia'), $f (filtros), $paging (Lista y Tabla), $pl (solo tablero), $activeView, $sysViews, $myViews, $csrf, $prefs, $cols (solo Lista), $tcols (solo Tabla).
 */
$isList = $screen === 'leads';
$isTable = $screen === 'tabla';
$hasSort = $isList || $isTable;
$defSort = $isTable ? 'when' : 'created';
$sortQ = $hasSort ? ['sort' => ($paging['sort'] ?? $defSort) !== $defSort ? $paging['sort'] : null, 'dir' => ($paging['dir'] ?? 'desc') !== 'desc' ? $paging['dir'] : null] : [];
$plQ = $screen === 'pipeline' && ($pl ?? 'A') === 'B' ? ['pl' => 'B'] : [];
$keepV = isset($activeView) && $activeView !== null ? ['v' => $activeView['view']['id']] : [];
// El orden viaja entre Lista y Tabla cuando existe en las dos (puntos, recibido, nombre, etapa); si no, se descarta.
$toList = ['pts' => 'score', 'when' => 'created', 'name' => 'name', 'stage' => 'stage', 'score' => 'score', 'created' => 'created'];
$sortFor = static function (string $target) use ($sortQ, $hasSort, $isTable, $toList): array {
    if (!$hasSort) {
        return [];
    }
    $sort = $sortQ['sort'];
    if ($sort !== null && $target === 'leads' && $isTable) {
        $sort = $toList[$sort] ?? null;
        if ($sort === null) {
            return [];
        }
        $sort = $sort === 'created' ? null : $sort;
    }
    return ['sort' => $sort, 'dir' => $sortQ['dir']];
};
$vtabs = [
    'leads' => ['Lista', 'list', '/leads' . Ui::qs($f + $sortFor('leads') + $keepV)],
    'pipeline' => ['Tablero', 'columns', '/pipeline' . Ui::qs($f + (($f['pf'] ?? '') === 'company_driver' ? ['pl' => 'B'] : $plQ) + $keepV)],
    'tabla' => ['Tabla', 'table', '/tabla' . Ui::qs($f + $sortFor('tabla') + $keepV)],
    'calendario' => ['Calendario', 'calendar', '/calendario' . Ui::qs($f + $keepV)],
    'cronologia' => ['Cronología', 'history', '/cronologia' . Ui::qs($f + $keepV)],
];
$here = SavedViews::SCREENS[$screen] . Ui::qs($f + $sortQ + $plQ + $keepV);
$qsSave = ltrim(Ui::qs($f + $sortQ + $plQ), '?');
$av = $activeView['view'] ?? null;
$dirty = !empty($activeView['dirty']);
$mineAv = $av !== null && !$av['sys'];
$density = $prefs['density'] ?? 'comfortable';
$hidden = array_values(array_diff(array_keys(Prefs::COLUMNS), $cols ?? array_keys(Prefs::COLUMNS)));
$hid = static fn(): string => '<input type="hidden" name="_csrf" value="' . $e($csrf) . '"><input type="hidden" name="back" value="' . $e($here) . '"><input type="hidden" name="screen" value="' . $e($screen) . '"><input type="hidden" name="qs" value="' . $e($qsSave) . '">';
$hasFilters = count($f) > 0 || $sortQ || $plQ;
?>
<div class="vbar" data-vbar>
  <nav class="vtabs" aria-label="Vista de los leads">
    <?php foreach ($vtabs as $k => [$label, $ic, $href]): $on = $screen === $k; ?>
      <a class="vtab<?= $on ? ' is-on' : '' ?>" href="<?= $e($href) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= Ui::icon($ic) ?><?= $e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="vbar__r">
    <div class="dd" data-dd>
      <button class="btn btn--secondary btn--sm vbtn" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="vmenu"><?= Ui::icon('bookmark') ?><span class="vbtn__t"><?= $av !== null ? $e($av['name']) : 'Vistas' ?></span><?= $dirty ? '<i class="vdirty" title="Modificada"></i><span class="sr"> (modificada)</span>' : '' ?><?= Ui::icon('chevron-down') ?></button>
      <div class="dd__panel vmenu" id="vmenu" hidden>
        <p class="label vmenu__h">DEL SISTEMA</p>
        <ul class="vlist">
          <?php foreach ($sysViews as $v): ?>
            <li><a class="vitem<?= $av !== null && $av['id'] === $v['id'] ? ' is-on' : '' ?>" href="<?= $e(SavedViews::href($v, $screen)) ?>"><?= Ui::icon($v['icon']) ?><span><?= $e($v['name']) ?></span></a></li>
          <?php endforeach; ?>
        </ul>
        <p class="label vmenu__h">MIS VISTAS</p>
        <?php if (!$myViews): ?><p class="vmenu__e">Aún no guardas ninguna. Aplica filtros y pulsa «Guardar vista».</p><?php endif; ?>
        <ul class="vlist">
          <?php foreach ($myViews as $v): $on = $av !== null && $av['id'] === $v['id']; ?>
            <li class="vmrow">
              <a class="vitem<?= $on ? ' is-on' : '' ?>" href="<?= $e(SavedViews::href($v, $screen)) ?>"><?= Ui::icon('star') ?><span><?= $e($v['name']) ?></span></a>
              <form method="post" action="/vistas/<?= $e($v['id']) ?>/<?= $v['pinned'] ? 'unpin' : 'pin' ?>"><?= $hid() ?><button class="icon-btn icon-btn--sm<?= $v['pinned'] ? ' is-pin' : '' ?>" type="submit" aria-pressed="<?= $v['pinned'] ? 'true' : 'false' ?>" aria-label="<?= $v['pinned'] ? 'Quitar de la barra lateral' : 'Fijar en la barra lateral' ?>: <?= $e($v['name']) ?>"><?= Ui::icon('pin') ?></button></form>
              <button class="icon-btn icon-btn--sm" type="button" data-view-rename="<?= $e($v['id']) ?>" data-name="<?= $e($v['name']) ?>" aria-label="Renombrar la vista <?= $e($v['name']) ?>"><?= Ui::icon('pencil') ?></button>
              <form method="post" action="/vistas/<?= $e($v['id']) ?>/delete"><?= $hid() ?><button class="icon-btn icon-btn--sm" type="submit" data-confirm="¿Eliminar la vista «<?= $e($v['name']) ?>»? Los leads no se tocan." aria-label="Eliminar la vista <?= $e($v['name']) ?>"><?= Ui::icon('trash') ?></button></form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php if ($mineAv && $dirty): ?>
      <form method="post" action="/vistas/<?= $e($av['id']) ?>/update"><?= $hid() ?><button class="btn btn--primary btn--sm" type="submit"><span class="btn__t">Guardar cambios</span></button></form>
    <?php endif; ?>
    <?php if ($hasFilters && (!$av || $dirty || !$mineAv)): ?>
      <button class="btn btn--secondary btn--sm" type="button" data-open="#view-dlg"><?= Ui::icon('plus') ?>Guardar vista</button>
    <?php endif; ?>
    <div class="seg seg--tight vdens" role="group" aria-label="Densidad">
      <button class="seg__i<?= $density === 'comfortable' ? ' is-on' : '' ?>" type="button" data-density-set="comfortable" aria-pressed="<?= $density === 'comfortable' ? 'true' : 'false' ?>" title="Cómoda"><?= Ui::icon('list') ?><span class="sr">Densidad cómoda</span></button>
      <button class="seg__i<?= $density === 'compact' ? ' is-on' : '' ?>" type="button" data-density-set="compact" aria-pressed="<?= $density === 'compact' ? 'true' : 'false' ?>" title="Compacta"><?= Ui::icon('sliders') ?><span class="sr">Densidad compacta</span></button>
    </div>
    <?php if ($isTable): require __DIR__ . '/_tcols.php'; endif; ?>
    <?php if ($isList): ?>
    <div class="dd" data-dd>
      <button class="btn btn--secondary btn--sm" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="cmenu"><?= Ui::icon('columns') ?>Columnas</button>
      <div class="dd__panel vmenu vmenu--c" id="cmenu" hidden>
        <p class="label vmenu__h">COLUMNAS VISIBLES</p>
        <?php foreach (Prefs::COLUMNS as $k => $l): ?><label class="check check--c"><input type="checkbox" data-col-toggle="<?= $e($k) ?>"<?= in_array($k, $cols, true) ? ' checked' : '' ?>><span><?= $e($l) ?></span></label><?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<dialog class="dlg" id="view-dlg" aria-labelledby="view-t">
  <form method="post" action="/vistas/new"><?= $hid() ?>
    <div class="dlg__h"><h2 class="dlg__t" id="view-t">Guardar vista</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m">Guarda los filtros y el orden actuales con un nombre. Solo tú la ves.</p>
    <label class="field"><span>Nombre de la vista</span><input type="text" name="name" maxlength="60" required placeholder="Ej. Owner-operators de TX"></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Guardar</span></button></div>
  </form>
</dialog>
<dialog class="dlg" id="view-rename" aria-labelledby="view-rt">
  <form method="post" action="/vistas/0/rename" data-rename-form><?= $hid() ?>
    <div class="dlg__h"><h2 class="dlg__t" id="view-rt">Renombrar vista</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <label class="field"><span>Nombre</span><input type="text" name="name" maxlength="60" required></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Renombrar</span></button></div>
  </form>
</dialog>
