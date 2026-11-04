<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

$f = $costForm;
$money = static fn(?float $v): string => $v === null ? '—' : '$' . Fmt::num($v, 2);
$monthName = static fn(string $p): string => Fmt::monthLabel((int) substr($p, 5, 2)) . ' ' . substr($p, 0, 4);
$rows = $costTable['rows'];
$monthSum = [];
foreach ($costTable['months'] as $m) {
    $monthSum[$m['period']] = $m;
}
?>
<p class="cfg-intro">Cuánto gastas por canal cada mes. Con eso el dashboard calcula el <b>costo por lead</b> y, cuando ya hay firmados, el <b>costo por firmado</b>. Sin costos cargados, el dashboard dice «Sin costos cargados»: nunca inventa un número.</p>

<section class="card cfg-card" aria-labelledby="co-m">
  <header class="card__h"><h2 class="label" id="co-m">ESTE MES · <?= $e(strtoupper($monthName(Fmt::et($now)->format('Y-m')))) ?></h2></header>
  <div class="card__b">
    <?php if (!$month['has_costs']): ?><p class="muted">Todavía no hay costos cargados. Agrega el primero abajo.</p>
    <?php else: ?><dl class="strip strip--3"><div><dt>Gasto del mes</dt><dd><?= $e($money($month['amount'])) ?></dd></div><div><dt>Costo por lead</dt><dd><?= $month['cpl'] !== null ? $e($money($month['cpl'])) : '<span class="dd-na">' . ($month['amount'] > 0 ? 'Sin leads de esos canales' : 'Sin gasto este mes') . '</span>' ?></dd></div><div><dt>Costo por firmado</dt><dd><?= $month['cps'] !== null ? $e($money($month['cps'])) : '<span class="dd-na">' . ($month['amount'] > 0 ? 'Aún sin firmados' : 'Sin gasto este mes') . '</span>' ?></dd></div></dl><?php endif; ?>
  </div>
</section>

<section class="card cfg-card" aria-labelledby="co-f">
  <header class="card__h"><h2 class="label" id="co-f"><?= $f['id'] > 0 ? 'EDITAR COSTO' : 'AGREGAR COSTO' ?></h2><?php if ($f['id'] > 0): ?><a class="link" href="/config?tab=costos">Cancelar edición</a><?php endif; ?></header>
  <div class="card__b">
    <form method="post" action="/config/costs/save" class="cfg-form co-form" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
        <div class="co-grid">
          <label class="field<?= isset($errors['source']) ? ' has-err' : '' ?>"><span>Fuente</span>
            <select name="source" required<?= isset($errors['source']) ? ' aria-invalid="true"' : '' ?>><option value="">Elige una fuente…</option><?php foreach ($sources as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f['source'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
            <?php if (isset($errors['source'])): ?><small class="num__e"><?= $e($errors['source']) ?></small><?php endif; ?></label>
          <label class="field<?= isset($errors['period']) ? ' has-err' : '' ?>"><span>Mes</span><input type="month" name="period" value="<?= $e($f['period']) ?>" min="2020-01" max="<?= $e($maxPeriod) ?>" required<?= isset($errors['period']) ? ' aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['period'])): ?><small class="num__e"><?= $e($errors['period']) ?></small><?php endif; ?></label>
          <label class="field<?= isset($errors['amount']) ? ' has-err' : '' ?>"><span>Monto (USD)</span><input type="text" name="amount" value="<?= $e($f['amount']) ?>" inputmode="decimal" placeholder="350.00" maxlength="12" autocomplete="off" required<?= isset($errors['amount']) ? ' aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['amount'])): ?><small class="num__e"><?= $e($errors['amount']) ?></small><?php endif; ?></label>
          <button class="btn btn--primary co-go" type="submit"><span class="btn__t"><?= $f['id'] > 0 ? 'Guardar cambio' : 'Guardar costo' ?></span><span class="btn__cell"><?= Ui::icon('check') ?></span></button>
        </div>
        <p class="muted">Si ya hay un costo de esa fuente en ese mes, se reemplaza el monto.</p>
      </fieldset>
    </form>
  </div>
</section>

<section class="card cfg-card" aria-labelledby="co-l">
  <header class="card__h"><h2 class="label" id="co-l">COSTOS CARGADOS</h2><span class="muted"><?= $e(count($rows)) ?> registro<?= count($rows) === 1 ? '' : 's' ?></span></header>
  <?php if (!$rows): ?>
    <div class="empty empty--sm"><?= Ui::icon('star', 'empty__star ic--star') ?><h3>Sin costos cargados</h3><p>Agrega cuánto gastaste en Meta, Indeed u otros canales y el dashboard calculará el costo por lead.</p></div>
  <?php else: ?>
    <div class="tw"><table class="tbl tbl--costs">
      <thead><tr><th scope="col">Mes</th><th scope="col">Fuente</th><th scope="col" class="num-c">Monto</th><th scope="col" class="num-c">Leads</th><th scope="col" class="num-c">Costo por lead</th><th scope="col" class="num-c">Firmados</th><th scope="col" class="num-c">Costo por firmado</th><th scope="col"><span class="sr">Acciones</span></th></tr></thead>
      <tbody>
        <?php $lastP = null; foreach ($rows as $r): ?>
          <tr class="<?= $r['period'] !== $lastP ? 'is-first' : '' ?>">
            <th scope="row" class="c-n"><span class="mth<?= $r['period'] === $lastP ? ' rep' : '' ?>"><?= $e($monthName($r['period'])) ?></span></th>
            <td data-l="Fuente"><?= $e(\Captacion\Services\Catalog::source($r['source'])) ?></td>
            <td class="num-c" data-l="Monto"><?= $e($money((float) $r['amount'])) ?></td>
            <td class="num-c" data-l="Leads"><?= $e(Fmt::num($r['leads'])) ?></td>
            <td class="num-c" data-l="Costo por lead"><?= $e($money($r['cpl'])) ?></td>
            <td class="num-c" data-l="Firmados"><?= $e(Fmt::num($r['signed'])) ?></td>
            <td class="num-c" data-l="Costo por firmado"><?= $e($money($r['cps'])) ?></td>
            <td class="c-act"><a class="icon-btn icon-btn--sm" href="/config?tab=costos&amp;edit=<?= $e($r['id']) ?>#co-f" aria-label="Editar el costo de <?= $e(\Captacion\Services\Catalog::source($r['source'])) ?> de <?= $e($monthName($r['period'])) ?>"><?= Ui::icon('pencil') ?></a>
              <?php if (!$locked): ?><form class="inl" method="post" action="/config/costs/delete"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= $e($r['id']) ?>"><button class="icon-btn icon-btn--sm" type="submit" aria-label="Eliminar el costo de <?= $e(\Captacion\Services\Catalog::source($r['source'])) ?> de <?= $e($monthName($r['period'])) ?>" data-confirm="¿Eliminar este costo? El costo por lead de ese mes se recalcula sin él."><?= Ui::icon('trash') ?></button></form><?php endif; ?></td>
          </tr>
          <?php $lastP = $r['period']; ?>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="foot-note cfg-note">Leads y firmados cuentan los leads creados ese mes (hora de <?= $e(Fmt::zone()) ?>) por esa fuente. «Firmado» es un lead con firma o en etapa Firmado/Contratado. El costo por firmado aparece solo cuando hay firmados.</p>
  <?php endif; ?>
</section>
