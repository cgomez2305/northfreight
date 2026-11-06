<?php
use Captacion\Clock;
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\LeadTable;
use Captacion\Ui;

/**
 * Tabla de leads (Q5c-B): tabla densa y ordenable por columna para comparar muchos leads. Mismos filtros que la Lista; columnas elegibles y reordenables (por usuario),
 * columna del nombre fija en horizontal, selección masiva (las acciones de siempre), paginación y exportación CSV de lo filtrado.
 * Variables: $f, $paging, $list, $counts, $tcols, $nextTasks, $facets, $grandTotal, $csrf, $canWrite, $activeView, $sysViews, $myViews.
 */
$rows = $list['rows'];
$base = $f;
$sortBase = ['sort' => $paging['sort'] !== 'when' ? $paging['sort'] : null, 'dir' => $paging['dir'] !== 'desc' ? $paging['dir'] : null, 'per' => $paging['per'] !== 25 ? $paging['per'] : null];
$qsAll = static fn(array $over = []): string => Ui::qs(array_merge($base, $sortBase, $over));
$nFilters = count(array_diff_key($f, ['t' => 1]));
$sortLink = static function (string $key, string $label) use ($paging, $base, $e): string {
    $on = $paging['sort'] === $key;
    $dir = $on && $paging['dir'] === 'desc' ? 'asc' : 'desc';
    $href = '/tabla' . Ui::qs(array_merge($base, ['sort' => $key !== 'when' || $dir !== 'desc' ? $key : null, 'dir' => $dir !== 'desc' ? $dir : null]));
    $full = LeadTable::COLUMNS[$key] ?? 'Lead';
    return '<a class="sort' . ($on ? ' is-on' : '') . '" href="' . $e($href) . '"' . ($on ? ' aria-label="Ordenado por ' . $e($full) . ($paging['dir'] === 'asc' ? ', ascendente' : ', descendente') . '. Cambiar el orden"' : ' aria-label="Ordenar por ' . $e($full) . '"') . '>' . $e($label)
        . '<span class="sort__a" aria-hidden="true">' . ($on ? ($paging['dir'] === 'asc' ? '↑' : '↓') : '') . '</span></a>';
};
$tabs = ['' => 'Todos', 'hot' => 'Calientes', 'warm' => 'Tibios', 'cold' => 'Fríos', 'disqualified' => 'Descartados'];
$isEmptyDb = $grandTotal === 0;
$ago = static fn(?string $v): string => $v === null ? '<span class="muted">—</span>' : '<span title="' . $e(Fmt::dateTime($v)) . '">' . $e(Fmt::ago($v)) . '</span>';
$cell = static function (string $c, array $r) use ($e, $nextTasks, $ago): string {
    switch ($c) {
        case 'code': return '<span class="mono">' . $e(Fmt::code((int) $r['id'])) . '</span>';
        case 'prof': return $e(Catalog::label(Catalog::PROFILES_SHORT, $r['profile']));
        case 'st': return '<span class="mono">' . $e($r['state'] ?? '—') . '</span>';
        case 'lang': return $e(Catalog::label(Catalog::LANGS, $r['language']));
        case 'eq': return $e(Ui::equipment($r['equipment_types'], 2));
        case 'trucks': return $r['truck_count'] === null ? '<span class="muted">—</span>' : '<span class="mono">' . $e($r['truck_count']) . '</span>';
        case 'exp': return $r['years_experience'] === null ? '<span class="muted">—</span>' : '<span class="mono">' . $e($r['years_experience']) . '</span>';
        case 'usdot': return $r['usdot'] === null ? '<span class="muted">—</span>' : '<span class="mono">' . $e($r['usdot']) . '</span>';
        case 'phone': return '<span class="mono">' . $e(Fmt::phone($r['phone_e164'])) . '</span>';
        case 'email': return $r['email'] === null ? '<span class="muted">—</span>' : '<span class="tt" title="' . $e($r['email']) . '">' . $e($r['email']) . '</span>';
        case 'pts': return Ui::score((int) $r['score'], $r['temperature']);
        case 'temp': return Ui::temp($r['temperature']);
        case 'stage': return Ui::stage($r['stage'], $r['pipeline']);
        case 'src': return $e(Catalog::source($r['source']));
        case 'contact': return $ago($r['first_contact_at']);
        case 'when': return $ago($r['created_at']);
        case 'changed': return $ago($r['updated_at']);
        case 'task':
            $nt = $nextTasks[(int) $r['id']] ?? null;
            $late = $nt !== null && $nt !== '' && (int) Clock::parse($nt) < Clock::time();
            return $nt === null ? '<span class="muted">Sin tarea</span>' : '<span class="mono' . ($late ? ' late' : '') . '">' . ($nt === '' ? 'Sin fecha' : $e(Fmt::dateTime($nt, false))) . '</span>';
    }
    return '';
};
$numCols = ['trucks' => 1, 'exp' => 1, 'pts' => 1];
?>
<div class="page-head">
  <div>
    <h1 class="h1">Leads</h1>
    <p class="sub"><?= $isEmptyDb ? 'Aquí aparecerán los leads de owner-operators, carriers y company drivers.' : 'Tabla para comparar · ' . $e(Fmt::num($list['total'])) . ' lead' . ($list['total'] === 1 ? '' : 's') . ($nFilters + (isset($f['t']) ? 1 : 0) > 0 ? ' con los filtros actuales' : ' en total') ?></p>
  </div>
  <?php if (!$isEmptyDb): ?>
  <div class="page-head__a">
    <button class="btn btn--primary" type="button" data-new-lead>Nuevo lead</button>
    <button class="btn btn--secondary filters-toggle" type="button" data-toggle="#filters" aria-expanded="<?= $nFilters > 0 ? 'true' : 'false' ?>" aria-controls="filters"><?= Ui::icon('filter') ?>Filtros<?= $nFilters > 0 ? ' <b class="badge">' . $e($nFilters) . '</b>' : '' ?></button>
    <form method="post" action="/tabla/export">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="scope" value="filters"><input type="hidden" name="qs" value="<?= $e(ltrim($qsAll(), '?')) ?>">
      <button class="btn btn--secondary" type="submit"><span class="btn__t">Exportar CSV</span></button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($isEmptyDb): ?>
<?php $screen = 'tabla'; require __DIR__ . '/_viewbar.php'; ?>
<section class="card empty empty--lg">
  <svg class="empty__star empty__star--lg" aria-hidden="true"><use href="#i-star"/></svg>
  <h2 class="h2">Todavía no ha llegado ningún lead</h2>
  <p>Cuando llegue el primero, aparecerá en esta tabla con todos sus datos para compararlo con los demás. Mientras tanto puedes crear uno a mano.</p>
  <button class="btn btn--primary" type="button" data-new-lead>Nuevo lead</button>
</section>
<?php else: ?>

<?php $screen = 'tabla'; require __DIR__ . '/_viewbar.php'; ?>

<nav class="ptabs" aria-label="Temperatura">
  <?php foreach ($tabs as $k => $label): $on = ($f['t'] ?? '') === $k; $n = $k === '' ? $counts['all'] : $counts[$k]; ?>
    <a class="ptab<?= $on ? ' is-on' : '' ?><?= $k !== '' ? ' ptab--' . ($k === 'disqualified' ? 'dq' : $k) : '' ?>" href="/tabla<?= $e(Ui::qs(array_merge(array_diff_key($base, ['t' => 1]), $sortBase, $k !== '' ? ['t' => $k] : []))) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= $k !== '' ? '<i class="dot"></i>' : '' ?><?= $e($label) ?><span class="ptab__n"><?= $e(Fmt::num($n)) ?></span></a>
  <?php endforeach; ?>
</nav>

<?php $fa = ['path' => '/tabla', 'keep' => array_filter($sortBase, static fn($v) => $v !== null), 'temp' => false]; require __DIR__ . '/_lfilters.php'; ?>

<form method="post" action="/leads/bulk" class="card card--table" id="bulk" data-bulk>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="/tabla<?= $e($qsAll(['page' => $list['page'] > 1 ? $list['page'] : null])) ?>">
  <input type="hidden" name="qs" value="<?= $e(ltrim($qsAll(), '?')) ?>">
  <?php if (!$rows): ?>
    <div class="empty"><svg class="empty__star" aria-hidden="true"><use href="#i-star"/></svg><h3>Ningún lead coincide con estos filtros</h3><p>Prueba quitando algún filtro o buscando por otro dato.</p><a class="btn btn--secondary" href="/tabla">Quitar filtros</a></div>
  <?php else: ?>
  <div class="tw tw--tabla">
    <table class="tbl tbl--tabla" data-tabla>
      <caption class="sr">Leads (<?= $e($list['total']) ?>), ordenados por <?= $e(mb_strtolower(LeadTable::COLUMNS[$paging['sort']] ?? 'nombre')) ?><?= $paging['dir'] === 'asc' ? ', de menor a mayor' : ', de mayor a menor' ?>. Desplázate en horizontal para ver todas las columnas.</caption>
      <thead><tr>
        <th scope="col" class="c-chk"><input type="checkbox" data-all aria-label="Seleccionar todos los de esta página"></th>
        <th scope="col" class="c-name"><?= $sortLink('name', 'Lead') ?></th>
        <?php foreach ($tcols as $c): ?>
          <th scope="col" class="tc tc-<?= $e($c) ?><?= isset($numCols[$c]) ? ' tc--n' : '' ?>"><?= isset(LeadTable::SORTS[$c]) ? $sortLink($c, LeadTable::HEAD[$c]) : $e(LeadTable::HEAD[$c]) ?></th>
        <?php endforeach; ?>
        <th scope="col" class="c-act"><span class="sr">Llamar</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $name = Fmt::name($r); ?>
        <tr data-peek="<?= $e($r['id']) ?>"<?= $r['temperature'] === 'hot' && $r['first_contact_at'] === null && $r['stage'] === 'new' ? ' class="is-urgent"' : '' ?>>
          <td class="c-chk"><input type="checkbox" name="ids[]" value="<?= $e($r['id']) ?>" aria-label="Seleccionar a <?= $e($name) ?>"></td>
          <th scope="row" class="c-name"><a class="who" href="/leads/<?= $e($r['id']) ?>"><?= Ui::avatar($name, 'md') ?><span><b><?= $e($name) ?></b><small class="mono"><?= $e(Fmt::code((int) $r['id'])) ?></small></span></a></th>
          <?php foreach ($tcols as $c): ?><td class="tc tc-<?= $e($c) ?><?= isset($numCols[$c]) ? ' tc--n' : '' ?>"><?= $cell($c, $r) ?></td><?php endforeach; ?>
          <td class="c-act"><?php if ($r['phone_e164']): ?><a class="icon-btn icon-btn--sm" href="tel:<?= $e($r['phone_e164']) ?>" aria-label="Llamar a <?= $e($name) ?>"><?= Ui::icon('phone') ?></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <footer class="card__f">
    <p class="muted">Mostrando <?= $e(Fmt::num(($list['page'] - 1) * $list['per'] + 1)) ?> a <?= $e(Fmt::num(($list['page'] - 1) * $list['per'] + count($rows))) ?> de <?= $e(Fmt::num($list['total'])) ?></p>
    <?= Ui::pager('/tabla', array_merge($base, $sortBase), 'page', $list['page'], $list['pages']) ?>
    <label class="perpage"><span>Por página</span><select data-per aria-label="Leads por página"><?php foreach (LeadTable::PER as $n): ?><option value="<?= $e($n) ?>"<?= $paging['per'] === $n ? ' selected' : '' ?>><?= $e($n) ?></option><?php endforeach; ?></select></label>
  </footer>
  <?php $exportAction = '/tabla/export'; require __DIR__ . '/_bulkbar.php'; ?>
  <?php endif; ?>
</form>
<?php endif; ?>
<?php if (!$isEmptyDb) { require __DIR__ . '/_peekshell.php'; } ?>
