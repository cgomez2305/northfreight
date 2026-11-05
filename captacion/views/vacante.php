<?php
use Captacion\Ui;
use Captacion\Services\JobPostings;

/** Variables: $job (null = nueva), $v (valores), $errors, $siteUrl, $states, $today, $csrf */
$isNew = $job === null;
$locked = !$isNew && in_array($job['status'], ['active', 'paused'], true);
$f = static fn(string $k, string $d = ''): string => (string) ($v[$k] ?? $d);
$err = static fn(string $k): string => isset($errors[$k]) ? '<small class="ferr" role="alert">' . $e($errors[$k]) . '</small>' : '';
$action = $isNew ? '/vacantes/new' : '/vacantes/' . $job['id'] . '/save';
?>
<div class="page-head">
  <div>
    <h1 class="h1"><?= $isNew ? 'Nueva vacante' : $e($job['title']) ?></h1>
    <p class="sub"><?php if ($isNew): ?>Se guarda como borrador; no aparece en el sitio hasta que la publiques.<?php else: ?>Estado: <b><?= $e(JobPostings::expired($job) ? 'Vencida' : JobPostings::STATUSES[$job['status']]) ?></b><?= $locked ? ' · los cambios se envían al sitio al guardar' : '' ?>.<?php endif; ?></p>
  </div>
  <div class="page-head__a"><a class="btn btn--ghost" href="/vacantes">Volver a vacantes</a></div>
</div>

<?php if ($errors): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?>Revisa los campos marcados en rojo.</p><?php endif; ?>

<form class="card vform" method="post" action="<?= $e($action) ?>" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="vform__g">
    <legend class="label">Puesto</legend>
    <label class="field vf-2"><span>Título</span><input type="text" name="title" value="<?= $e($f('title')) ?>" maxlength="90" required placeholder="Regional CDL-A Company Driver"><?= $err('title') ?></label>
    <label class="field"><span>Tipo de empleo</span><select name="employment_type"><?php foreach (JobPostings::TYPES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f('employment_type', 'FULL_TIME') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field vf-2"><span>Dirección de la página (slug)</span><input type="text" name="slug" value="<?= $e($f('slug')) ?>" maxlength="80" autocomplete="off" placeholder="Se arma con el título"<?= $locked ? ' readonly' : '' ?>><small><?= $e($siteUrl) ?>/careers/cdl-a-drivers/<b><?= $e($f('slug') !== '' ? $f('slug') : 'slug') ?></b>/<?= $locked ? ' · no cambia mientras esté publicada' : '' ?></small><?= $err('slug') ?></label>
    <label class="field"><span>Equipo (opcional)</span><input type="text" name="equipment" value="<?= $e($f('equipment')) ?>" maxlength="80" placeholder="Dry van"><?= $err('equipment') ?></label>
  </fieldset>
  <fieldset class="vform__g">
    <legend class="label">Fechas y lugar de trabajo</legend>
    <label class="field"><span>Publicación</span><input type="date" name="posted_on" value="<?= $e($f('posted_on')) ?>" required><?= $err('posted_on') ?></label>
    <label class="field"><span>Vence</span><input type="date" name="valid_through" value="<?= $e($f('valid_through')) ?>" min="<?= $e($today) ?>" required><small>Debe ser futura para publicar. Al vencer, la página deja de existir sola.</small><?= $err('valid_through') ?></label>
    <label class="field"><span>Ciudad</span><input type="text" name="city" value="<?= $e($f('city')) ?>" maxlength="80"><?= $err('city') ?></label>
    <label class="field"><span>Estado</span><select name="region"><option value="">Elige…</option><?php foreach ($states as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f('region') === $k ? ' selected' : '' ?>><?= $e($k) ?> · <?= $e($l) ?></option><?php endforeach; ?></select><?= $err('region') ?></label>
    <label class="field"><span>Código postal (opcional)</span><input type="text" name="postal" value="<?= $e($f('postal')) ?>" maxlength="10" inputmode="numeric"><?= $err('postal') ?></label>
  </fieldset>
  <fieldset class="vform__g vform__g--1">
    <legend class="label">Contenido de la página</legend>
    <label class="field"><span>Resumen (una o dos frases)</span><textarea name="summary" rows="3" maxlength="500"><?= $e($f('summary')) ?></textarea><?= $err('summary') ?></label>
    <?php foreach (JobPostings::LIST_FIELDS as $k => $label): ?>
      <label class="field"><span><?= $e($label) ?> (una línea por punto)</span><textarea name="<?= $e($k) ?>" rows="4"><?= $e($f($k)) ?></textarea><?= $err($k) ?></label>
    <?php endforeach; ?>
    <p class="muted">Solo texto simple: sin HTML. Escribe únicamente lo que NorthStar ha confirmado, sin cifras inventadas.</p>
  </fieldset>
  <fieldset class="vform__g">
    <legend class="label">Pago</legend>
    <label class="check vf-all"><input type="checkbox" name="pay_on" value="1"<?= $f('pay_on') !== '' ? ' checked' : '' ?>><span>Publicar el pago (solo si está confirmado). Sin marcar no se publica ninguna cifra.</span></label>
    <label class="field"><span>Mínimo (USD)</span><input type="text" name="pay_min" value="<?= $e($f('pay_min')) ?>" inputmode="decimal" maxlength="10" autocomplete="off"></label>
    <label class="field"><span>Máximo (USD)</span><input type="text" name="pay_max" value="<?= $e($f('pay_max')) ?>" inputmode="decimal" maxlength="10" autocomplete="off"></label>
    <label class="field"><span>Unidad</span><select name="pay_unit"><?php foreach (JobPostings::UNITS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f('pay_unit', 'WEEK') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <div class="vf-all"><?= $err('pay') ?></div>
  </fieldset>
  <div class="vform__a">
    <button class="btn btn--primary" type="submit"><span class="btn__t"><?= $isNew ? 'Guardar borrador' : 'Guardar cambios' ?></span><span class="btn__cell"><?= Ui::icon('arrow-up-right') ?></span></button>
    <a class="btn btn--ghost" href="/vacantes">Cancelar</a>
  </div>
</form>

<?php if (!$isNew): ?>
<section class="card vform vstate" aria-labelledby="vs-t">
  <h2 class="h2" id="vs-t">Estado en el sitio</h2>
  <p class="muted"><?php if ($job['status'] === 'active'): ?>Publicada en <a href="<?= $e($siteUrl . '/careers/cdl-a-drivers/' . $job['slug'] . '/') ?>" target="_blank" rel="noopener"><?= $e('/careers/cdl-a-drivers/' . $job['slug'] . '/') ?></a>. Pausarla o cerrarla hace que esa página responda 404 y salga del sitemap.
    <?php elseif ($job['status'] === 'paused'): ?>En pausa: la página no existe en el sitio (404). Publícala para volver a mostrarla.
    <?php elseif ($job['status'] === 'closed'): ?>Cerrada: no está en el sitio. Puedes reabrirla como borrador.
    <?php else: ?>Borrador: todavía no está en el sitio. Al publicar se revisa que tenga resumen, ciudad y estado, al menos un requisito o responsabilidad y una fecha de vencimiento futura.<?php endif; ?></p>
  <div class="vform__a">
    <?php foreach ([['publish', 'Publicar en el sitio', in_array($job['status'], ['draft', 'paused'], true), 'primary'], ['pause', 'Pausar', $job['status'] === 'active', 'secondary'], ['close', 'Cerrar', in_array($job['status'], ['active', 'paused'], true), 'secondary'], ['reopen', 'Reabrir como borrador', $job['status'] === 'closed', 'secondary'], ['delete', 'Borrar borrador', $job['status'] === 'draft' && $job['published_at'] === null, 'danger']] as [$op, $label, $show, $kind]): if (!$show) { continue; } ?>
      <form method="post" action="/vacantes/<?= $e($job['id']) ?>/status"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="<?= $e($op) ?>">
        <button class="btn btn--<?= $e($kind) ?>" type="submit"<?= in_array($op, ['pause', 'close', 'delete'], true) ? ' data-confirm="' . $e($op === 'delete' ? '¿Borrar este borrador? No se puede deshacer.' : ($op === 'pause' ? '¿Pausar la vacante? Su página dejará de existir (404) hasta que la publiques otra vez.' : '¿Cerrar la vacante? Su página dejará de existir (404).')) . '"' : '' ?>><?= $e($label) ?></button></form>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
