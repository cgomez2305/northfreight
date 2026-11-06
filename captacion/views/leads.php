<?php
use Captacion\Ui;
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\UsStates;

$rows = $list['rows'];
$base = $f;                                    // filtros vigentes (sin orden ni pagina)
$sortBase = ['sort' => $paging['sort'] !== 'created' ? $paging['sort'] : null, 'dir' => $paging['dir'] !== 'desc' ? $paging['dir'] : null, 'per' => $paging['per'] !== 25 ? $paging['per'] : null];
$qsAll = static fn(array $over = []): string => Ui::qs(array_merge($base, $sortBase, $over));
$nFilters = count(array_diff_key($f, ['t' => 1]));
$sortLink = static function (string $key, string $label) use ($paging, $base, $e): string {
    $on = $paging['sort'] === $key;
    $dir = $on && $paging['dir'] === 'desc' ? 'asc' : 'desc';
    $href = '/leads' . Ui::qs(array_merge($base, ['sort' => $key !== 'created' || $dir !== 'desc' ? $key : null, 'dir' => $dir !== 'desc' ? $dir : null]));
    return '<a class="sort' . ($on ? ' is-on' : '') . '" href="' . $e($href) . '"' . ($on ? ' aria-label="Ordenado por ' . $e($label) . ($paging['dir'] === 'asc' ? ', ascendente' : ', descendente') . '"' : '') . '>' . $e($label) . '<span class="sort__a" aria-hidden="true">' . ($on ? ($paging['dir'] === 'asc' ? '↑' : '↓') : '') . '</span></a>';
};
$tabs = ['' => 'Todos', 'hot' => 'Calientes', 'warm' => 'Tibios', 'cold' => 'Fríos', 'disqualified' => 'Descartados'];
$isEmptyDb = $grandTotal === 0;
?>
<div class="page-head">
  <div>
    <h1 class="h1">Leads</h1>
    <p class="sub"><?= $isEmptyDb ? 'Aquí aparecerán los leads de owner-operators, carriers y company drivers.' : $e(Fmt::num($list['total'])) . ' lead' . ($list['total'] === 1 ? '' : 's') . ($nFilters + (isset($f['t']) ? 1 : 0) > 0 ? ' con los filtros actuales' : ' en total') ?></p>
  </div>
  <?php if (!$isEmptyDb): ?>
  <div class="page-head__a">
    <button class="btn btn--secondary" type="button" data-new-lead><?= Ui::icon('plus') ?>Nuevo lead</button>
    <button class="btn btn--secondary filters-toggle" type="button" data-toggle="#filters" aria-expanded="<?= $nFilters > 0 ? 'true' : 'false' ?>" aria-controls="filters"><?= Ui::icon('filter') ?>Filtros<?= $nFilters > 0 ? ' <b class="badge">' . $e($nFilters) . '</b>' : '' ?></button>
    <form method="post" action="/leads/export">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="scope" value="filters"><input type="hidden" name="qs" value="<?= $e(ltrim($qsAll(), '?')) ?>">
      <button class="btn btn--primary" type="submit"><span class="btn__t">Exportar CSV</span><span class="btn__cell"><?= Ui::icon('download') ?></span></button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($isEmptyDb): ?>
<section class="card empty empty--lg">
  <svg class="empty__star empty__star--lg" aria-hidden="true"><use href="#i-star"/></svg>
  <h2 class="h2">Todavía no ha llegado ningún lead</h2>
  <p>Cuando llegue el primero, lo verás aquí con su puntuación, su temperatura y su etapa. Si es caliente, te avisamos por Telegram para que lo llames en minutos.</p>
  <ol class="steps">
    <li><span class="steps__n">1</span><div><b>Formulario del sitio</b><p>Cada solicitud de <code>/careers/apply/</code> entra sola cuando <code>CAPTACION_ENABLED=1</code> está en el servidor.</p></div></li>
    <li><span class="steps__n">2</span><div><b>API o automatizaciones</b><p><code>POST /api/lead.php</code> con el token interno: n8n, WhatsApp o una carga manual.</p></div></li>
    <li><span class="steps__n">3</span><div><b>Importar lo acumulado</b><p><code>php captacion/bin/import-jsonl.php</code> reimporta los leads que el sitio guardó mientras el CRM estaba apagado.</p></div></li>
  </ol>
</section>
<?php else: ?>

<?php $screen = 'leads'; require __DIR__ . '/_viewbar.php'; ?>

<nav class="ptabs" aria-label="Temperatura">
  <?php foreach ($tabs as $k => $label): $on = ($f['t'] ?? '') === $k; $n = $k === '' ? $counts['all'] : $counts[$k]; ?>
    <a class="ptab<?= $on ? ' is-on' : '' ?><?= $k !== '' ? ' ptab--' . ($k === 'disqualified' ? 'dq' : $k) : '' ?>" href="/leads<?= $e(Ui::qs(array_merge(array_diff_key($base, ['t' => 1]), $sortBase, $k !== '' ? ['t' => $k] : []))) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= $k !== '' ? '<i class="dot"></i>' : '' ?><?= $e($label) ?><span class="ptab__n"><?= $e(Fmt::num($n)) ?></span></a>
  <?php endforeach; ?>
</nav>

<?php $fa = ['path' => '/leads', 'keep' => array_filter($sortBase, static fn($v) => $v !== null), 'temp' => false]; require __DIR__ . '/_lfilters.php'; ?>

<form method="post" action="/leads/bulk" class="card card--table" id="bulk" data-bulk>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="/leads<?= $e($qsAll(['page' => $list['page'] > 1 ? $list['page'] : null])) ?>">
  <?php if (!$rows): ?>
    <div class="empty"><svg class="empty__star" aria-hidden="true"><use href="#i-star"/></svg><h3>Ningún lead coincide con estos filtros</h3><p>Prueba quitando algún filtro o buscando por otro dato.</p><a class="btn btn--secondary" href="/leads">Quitar filtros</a></div>
  <?php else: ?>
  <div class="tw">
    <table class="tbl tbl--leads" data-hide="<?= $e(implode(' ', array_values(array_diff(array_keys(\Captacion\Services\Prefs::COLUMNS), $cols)))) ?>" data-leads-table>
      <caption class="sr">Leads (<?= $e($list['total']) ?>), ordenados por <?= $e(['created' => 'fecha de llegada', 'score' => 'puntuación', 'name' => 'nombre', 'stage' => 'etapa'][$paging['sort']]) ?></caption>
      <thead><tr>
        <th scope="col" class="c-chk"><input type="checkbox" data-all aria-label="Seleccionar todos los de esta página"></th>
        <th scope="col"><?= $sortLink('name', 'Lead') ?></th><th scope="col" class="c-prof k-prof">Perfil</th><th scope="col" class="c-st k-st">Est.</th><th scope="col" class="c-eq k-eq">Equipo</th>
        <th scope="col" class="c-n k-pts"><?= $sortLink('score', 'Pts') ?></th><th scope="col" class="k-temp">Temp.</th><th scope="col" class="c-stage k-stage"><?= $sortLink('stage', 'Etapa') ?></th><th scope="col" class="c-src k-src">Fuente</th><th scope="col" class="c-when k-when"><?= $sortLink('created', 'Recibido') ?></th><th scope="col" class="c-tk k-task">Próxima tarea</th><th scope="col" class="c-act"><span class="sr">Llamar</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $name = Fmt::name($r); ?>
        <tr data-peek="<?= $e($r['id']) ?>"<?= $r['temperature'] === 'hot' && $r['first_contact_at'] === null && $r['stage'] === 'new' ? ' class="is-urgent"' : '' ?>>
          <td class="c-chk"><input type="checkbox" name="ids[]" value="<?= $e($r['id']) ?>" aria-label="Seleccionar a <?= $e($name) ?>"></td>
          <th scope="row" class="c-name"><a class="who" href="/leads/<?= $e($r['id']) ?>"><?= Ui::avatar($name, 'md') ?><span><b><?= $e($name) ?></b><small class="mono"><?= $e(Fmt::code((int) $r['id'])) ?> · <?= $e(Fmt::phone($r['phone_e164'])) ?></small></span></a></th>
          <td class="c-prof k-prof" data-l="Perfil"><?= $e(Catalog::label(Catalog::PROFILES_SHORT, $r['profile'])) ?></td>
          <td class="c-st k-st mono" data-l="Estado"><?= $e($r['state'] ?? '—') ?></td>
          <td class="c-eq k-eq" data-l="Equipo"><?= $e(Ui::equipment($r['equipment_types'], 2)) ?></td>
          <td class="c-n k-pts" data-l="Puntos"><?= Ui::score((int) $r['score'], $r['temperature']) ?></td>
          <td class="c-tmp k-temp"><?= Ui::temp($r['temperature']) ?></td>
          <td class="c-stage k-stage"><?= Ui::stage($r['stage'], $r['pipeline']) ?></td>
          <td class="c-src k-src" data-l="Fuente"><?= $e(Catalog::source($r['source'])) ?></td>
          <td class="c-when k-when mono" data-l="Recibido" title="<?= $e(Fmt::dateTime($r['created_at'])) ?>"><?= $e(Fmt::ago($r['created_at'])) ?></td>
          <?php $nt = $nextTasks[(int) $r['id']] ?? null; $ntLate = $nt !== null && $nt !== '' && (int) \Captacion\Clock::parse($nt) < \Captacion\Clock::time(); ?>
          <td class="c-tk k-task mono<?= $ntLate ? ' late' : '' ?>" data-l="Próxima tarea"><?= $nt === null ? '<span class="muted">Sin tarea</span>' : ($nt === '' ? 'Sin fecha' : $e(Fmt::dateTime($nt, false))) ?></td>
          <td class="c-act"><?php if ($r['phone_e164']): ?><a class="icon-btn icon-btn--sm" href="tel:<?= $e($r['phone_e164']) ?>" aria-label="Llamar a <?= $e($name) ?>"><?= Ui::icon('phone') ?></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <footer class="card__f">
    <p class="muted">Mostrando <?= $e(Fmt::num(($list['page'] - 1) * $list['per'] + 1)) ?> a <?= $e(Fmt::num(($list['page'] - 1) * $list['per'] + count($rows))) ?> de <?= $e(Fmt::num($list['total'])) ?></p>
    <?= Ui::pager('/leads', array_merge($base, $sortBase), 'page', $list['page'], $list['pages']) ?>
    <label class="perpage"><span>Por página</span><select data-per aria-label="Leads por página"><?php foreach (\Captacion\Services\LeadQuery::PER as $n): ?><option value="<?= $e($n) ?>"<?= $paging['per'] === $n ? ' selected' : '' ?>><?= $e($n) ?></option><?php endforeach; ?></select></label>
  </footer>

  <?php require __DIR__ . '/_bulkbar.php'; ?>
  <?php endif; ?>
</form>
<?php endif; ?>
<?php if (!$isEmptyDb) { require __DIR__ . '/_peekshell.php'; } ?>
