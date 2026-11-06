<?php
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\UsStates;
use Captacion\Ui;

/**
 * Filtros compartidos por la Lista, la Tabla, el Calendario y la Cronología (Q5c): formulario con los mismos controles y chips de filtros activos.
 * Variables: $f (filtros validos), $facets (fuentes y estados presentes), $fa = ['fold' => bool (plegado salvo que haya filtros activos; con JS), 'path' => ruta de la pantalla, 'keep' => parámetros que no son filtros y se conservan (orden, página, modo…), 'temp' => bool (con selector de temperatura; la Lista y la Tabla lo tienen en pestañas)].
 */
$keep = $fa['keep'] ?? [];
$withTemp = !empty($fa['temp']);
$nFilters = count($withTemp ? $f : array_diff_key($f, ['t' => 1]));
$chipLabels = [
    't' => static fn($v) => 'Temperatura: ' . Catalog::label(Catalog::TEMPS, $v),
    'pf' => static fn($v) => 'Perfil: ' . Catalog::label(Catalog::PROFILES, $v), 'src' => static fn($v) => 'Fuente: ' . Catalog::source($v), 'st' => static fn($v) => 'Etapa: ' . Catalog::stageAny($v),
    'eq' => static fn($v) => 'Equipo: ' . Catalog::label(Catalog::EQUIPMENT, $v), 'us' => static fn($v) => 'Estado: ' . $v, 'lg' => static fn($v) => 'Idioma: ' . Catalog::label(Catalog::LANGS, $v),
    'from' => static fn($v) => 'Desde: ' . $v, 'to' => static fn($v) => 'Hasta: ' . $v, 'q' => static fn($v) => 'Texto: «' . $v . '»', 'ct' => static fn() => 'Sin contactar', 'fl' => static fn($v) => $v === 'review_queue' ? 'Por revisar' : (str_starts_with((string) $v, 'tag:') ? 'Etiqueta: ' . substr((string) $v, 4) : 'FMCSA pendiente'), 'nt' => static fn() => 'Sin tarea abierta', 'op' => static fn() => 'Solo abiertos', 'hs' => static fn() => 'Presentados sin respuesta',
];
?>
<form class="filters<?= !empty($fa['fold']) ? ' filters--fold' : '' ?><?= $nFilters > 0 ? ' is-open' : '' ?>" id="filters" method="get" action="<?= $e($fa['path']) ?>" aria-label="Filtros">
  <?php if (isset($f['t']) && !$withTemp): ?><input type="hidden" name="t" value="<?= $e($f['t']) ?>"><?php endif; ?>
  <?php foreach (['nt', 'op', 'hs', 'fl'] as $hk): if (isset($f[$hk])): ?><input type="hidden" name="<?= $e($hk) ?>" value="<?= $e($f[$hk]) ?>"><?php endif; endforeach; ?>
  <?php foreach ($keep as $k => $kv): if ($kv !== null && $kv !== ''): ?><input type="hidden" name="<?= $e($k) ?>" value="<?= $e($kv) ?>"><?php endif; endforeach; ?>
  <label class="field field--q"><span>Buscar</span><div class="inp"><?= Ui::icon('search') ?><input type="search" name="q" value="<?= $e($f['q'] ?? '') ?>" placeholder="Nombre, teléfono, correo o USDOT" maxlength="100"></div></label>
  <?php if ($withTemp): ?><label class="field"><span>Temperatura</span><select name="t"><option value="">Todas</option><?php foreach (Catalog::TEMPS as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($f['t'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label class="field"><span>Perfil</span><select name="pf"><option value="">Todos</option><?php foreach (Catalog::PROFILES as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($f['pf'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Etapa</span><select name="st"><option value="">Todas</option><?php foreach (Catalog::allStages() as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($f['st'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Fuente</span><select name="src"><option value="">Todas</option><?php foreach ($facets['sources'] as $k): ?><option value="<?= $e($k) ?>"<?= ($f['src'] ?? '') === $k ? ' selected' : '' ?>><?= $e(Catalog::source($k)) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Equipo</span><select name="eq"><option value="">Todos</option><?php foreach (Catalog::EQUIPMENT as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($f['eq'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Estado (EE. UU.)</span><select name="us"><option value="">Todos</option><?php foreach ($facets['states'] as $k): ?><option value="<?= $e($k) ?>"<?= ($f['us'] ?? '') === $k ? ' selected' : '' ?>><?= $e($k) ?> · <?= $e(UsStates::NAMES[$k] ?? '') ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Idioma</span><select name="lg"><option value="">Todos</option><?php foreach (Catalog::LANGS as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($f['lg'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Desde (<?= $e(Fmt::zone()) ?>)</span><input type="date" name="from" value="<?= $e($f['from'] ?? '') ?>"></label>
  <label class="field"><span>Hasta (<?= $e(Fmt::zone()) ?>)</span><input type="date" name="to" value="<?= $e($f['to'] ?? '') ?>"></label>
  <label class="check check--f"><input type="checkbox" name="ct" value="none"<?= isset($f['ct']) ? ' checked' : '' ?>><span>Solo sin contactar</span></label>
  <div class="filters__a"><button class="btn btn--primary btn--sm" type="submit"><span class="btn__t">Aplicar</span></button><?php if ($nFilters > 0 || isset($f['t'])): ?><a class="btn btn--ghost btn--sm" href="<?= $e($fa['path'] . Ui::qs($keep)) ?>">Limpiar</a><?php endif; ?></div>
</form>

<?php if ($nFilters > 0): ?>
<ul class="chips" aria-label="Filtros activos">
  <?php foreach ($withTemp ? $f : array_diff_key($f, ['t' => 1]) as $k => $v): ?>
    <li><a class="fchip" href="<?= $e($fa['path'] . Ui::qs(array_merge(array_diff_key($f, [$k => 1]), $keep))) ?>" aria-label="Quitar filtro <?= $e($chipLabels[$k]($v)) ?>"><?= $e($chipLabels[$k]($v)) ?><?= Ui::icon('x') ?></a></li>
  <?php endforeach; ?>
  <li><a class="link" href="<?= $e($fa['path'] . Ui::qs(array_merge($withTemp || !isset($f['t']) ? [] : ['t' => $f['t']], $keep))) ?>">Quitar todos</a></li>
</ul>
<?php endif; ?>
