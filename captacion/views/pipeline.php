<?php
use Captacion\Services\Catalog;
use Captacion\Services\Kanban;
use Captacion\Ui;

/** Variables: $pl, $stages, $counts, $columns (stage => [cards,total,next]), $f, $totals, $canWrite, $facets, $render (callable cards -> html) */
$labels = ['A' => 'Owner-operators y carriers', 'B' => 'Company drivers'];
$qsBase = static fn(array $over = []): string => Ui::qs(array_merge(['pl' => $pl === 'A' ? null : $pl], $f, $over));
$tempTabs = ['' => 'Todos', 'hot' => 'Calientes', 'warm' => 'Tibios', 'cold' => 'Fríos', 'disqualified' => 'Descartados'];
$boardTotal = array_sum($counts);
$emptyHint = [
    'new' => 'Aquí llegan los leads nuevos.', 'contacted' => 'Los que ya hablaron con alguien del equipo.', 'qualified' => 'Leads que cumplen los requisitos.', 'prequalified' => 'Drivers que cumplen los requisitos.',
    'call_booked' => 'Llamadas agendadas con el lead.', 'presented' => 'Presentados a NorthStar.', 'signed' => 'Aquí se ven los firmados.', 'hired' => 'Aquí se ven los contratados.',
];
$filtered = count($f) > 0;
?>
<div class="page-head page-head--kb">
  <div>
    <h1 class="h1">Pipeline</h1>
    <p class="sub">Una columna por etapa. Arrastra una tarjeta o usa «Mover a…».</p>
  </div>
  <div class="page-head__a">
    <a class="btn btn--secondary" href="/leads<?= $e(Ui::qs(array_merge(['pf' => $pl === 'B' ? 'company_driver' : null], $f))) ?>"><?= Ui::icon('users') ?><span>Ver como lista</span></a>
  </div>
</div>

<div class="ptabs" role="group" aria-label="Pipeline">
  <?php foreach ($labels as $k => $label): ?>
    <a class="ptab<?= $pl === $k ? ' is-on' : '' ?>" href="/pipeline<?= $e(Ui::qs(array_merge(['pl' => $k === 'A' ? null : $k], $f))) ?>"<?= $pl === $k ? ' aria-current="true"' : '' ?>><?= $e($k) ?> · <?= $e($label) ?><span class="ptab__n"><?= $e($totals[$k]) ?></span></a>
  <?php endforeach; ?>
</div>

<form class="kq" method="get" action="/pipeline" data-autosubmit>
  <?php if ($pl === 'B'): ?><input type="hidden" name="pl" value="B"><?php endif; ?>
  <div class="seg seg--wrap kq__t" role="group" aria-label="Temperatura">
    <?php foreach ($tempTabs as $k => $label): ?>
      <a class="seg__i<?= ($f['t'] ?? '') === $k ? ' is-on' : '' ?>" href="/pipeline<?= $e($qsBase(['t' => $k === '' ? null : $k])) ?>"<?= ($f['t'] ?? '') === $k ? ' aria-current="true"' : '' ?>><?= $e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if (isset($f['t'])): ?><input type="hidden" name="t" value="<?= $e($f['t']) ?>"><?php endif; ?>
  <label class="field kq__s"><span class="sr">Fuente</span>
    <select name="src" aria-label="Fuente"><option value="">Todas las fuentes</option>
      <?php foreach ($facets['sources'] as $s): ?><option value="<?= $e($s) ?>"<?= ($f['src'] ?? '') === $s ? ' selected' : '' ?>><?= $e(Catalog::source($s)) ?></option><?php endforeach; ?>
    </select></label>
  <label class="inp kq__q"><?= Ui::icon('search') ?><input type="search" name="q" value="<?= $e($f['q'] ?? '') ?>" placeholder="Buscar lead…" maxlength="100" aria-label="Buscar por nombre, teléfono, correo o USDOT"></label>
  <button class="btn btn--secondary btn--sm kq__go" type="submit">Filtrar</button>
  <?php if ($filtered): ?><a class="btn btn--ghost btn--sm" href="/pipeline<?= $pl === 'B' ? '?pl=B' : '' ?>"><?= Ui::icon('x') ?>Quitar filtros</a><?php endif; ?>
</form>

<?php if ($boardTotal === 0 && !$filtered && $totals[$pl] === 0): ?>
  <section class="card empty empty--lg">
    <?= Ui::icon('star', 'empty__star empty__star--lg ic--star') ?>
    <h3>El pipeline <?= $e($pl) ?> está vacío</h3>
    <p>Cuando lleguen leads de <?= $e(strtolower($labels[$pl])) ?> los verás aquí, una columna por etapa. El formulario de <em>Careers</em> y la API los crean solos.</p>
    <a class="btn btn--secondary" href="/leads"><?= Ui::icon('users') ?>Ir a la bandeja</a>
  </section>
<?php else: ?>
<div class="kb" data-board data-pl="<?= $e($pl) ?>" data-qs="<?= $e(http_build_query($f)) ?>" data-stages="<?= $e(json_encode($stages, JSON_UNESCAPED_UNICODE)) ?>" data-write="<?= $canWrite ? '1' : '0' ?>" data-closed="<?= $e(json_encode(array_values(array_intersect(['signed', 'hired'], array_keys($stages))))) ?>">
  <?php if ($boardTotal === 0): ?><p class="flash flash--warn" role="status"><?= Ui::icon('info') ?>Ningún lead de este pipeline coincide con los filtros.</p><?php endif; ?>
  <nav class="kb__chips" aria-label="Ir a una etapa">
    <?php foreach ($stages as $st => $label): ?>
      <a class="kb__chip" href="#col-<?= $e($st) ?>" data-chip="<?= $e($st) ?>"><i class="dot st-<?= $e($st) ?>"></i><?= $e($label) ?><b data-chip-n><?= $e($counts[$st]) ?></b></a>
    <?php endforeach; ?>
  </nav>
  <div class="kb__scroll" data-scroll tabindex="-1">
    <div class="kb__cols">
      <?php foreach ($stages as $st => $label): $col = $columns[$st] ?? null; $isLost = $st === 'lost'; ?>
        <section class="kcol<?= $isLost ? ' kcol--lost is-collapsed' : '' ?>" id="col-<?= $e($st) ?>" data-col="<?= $e($st) ?>" data-total="<?= $e($counts[$st]) ?>" data-next="<?= $e($col['next'] ?? '') ?>" aria-labelledby="kh-<?= $e($st) ?>">
          <header class="kcol__h">
            <?php if ($isLost): ?>
              <button class="kcol__tog" type="button" data-tog aria-expanded="false" aria-controls="kl-<?= $e($st) ?>"><i class="dot st-<?= $e($st) ?>"></i><span class="kcol__t" id="kh-<?= $e($st) ?>"><?= $e($label) ?></span><span class="kcol__n" data-count><?= $e($counts[$st]) ?></span><?= Ui::icon('chevron-right') ?></button>
            <?php else: ?>
              <h2 class="kcol__t" id="kh-<?= $e($st) ?>"><i class="dot st-<?= $e($st) ?>"></i><?= $e($label) ?></h2><span class="kcol__n" data-count><?= $e($counts[$st]) ?></span>
            <?php endif; ?>
          </header>
          <div class="kcol__b"<?= $isLost ? ' hidden' : '' ?> id="kl-<?= $e($st) ?>">
            <ul class="kcol__l" data-list aria-label="<?= $e($label) ?>"><?php if ($col) { echo $render($col['cards']); } ?></ul>
            <p class="kcol__empty" data-empty<?= $counts[$st] > 0 ? ' hidden' : '' ?>><?= $e($filtered ? 'Nada en esta etapa con estos filtros.' : ($emptyHint[$st] ?? ($isLost ? 'Nadie ha salido del pipeline.' : 'Sin leads en esta etapa.'))) ?></p>
            <button class="btn btn--secondary btn--sm kcol__more" type="button" data-more<?= ($col['next'] ?? null) !== null ? '' : ' hidden' ?>>Ver más <span data-rest>(<?= $e(max(0, $counts[$st] - count($col['cards'] ?? []))) ?> restantes)</span></button>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
  <p class="sr" role="status" aria-live="polite" data-live></p>
</div>

<div class="kmenu" role="menu" data-menu hidden aria-label="Mover a…"></div>

<dialog class="dlg" id="kb-dlg" aria-labelledby="kb-dlg-t">
  <form method="dialog" data-kb-form>
    <div class="dlg__h"><h2 class="dlg__t" id="kb-dlg-t">Mover lead</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m" data-kb-msg></p>
    <label class="field" data-kb-reason hidden><span>Motivo</span>
      <select name="reason"><option value="">Elige un motivo…</option><?php foreach (Catalog::LOSS_REASONS as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field" data-kb-note hidden><span>Nota <small data-kb-note-hint>(opcional)</small></span><textarea name="note" maxlength="500" rows="3"></textarea></label>
    <label class="check" data-kb-override hidden><input type="checkbox" name="override" value="1"><span>Sí, quiero saltarme el orden de las etapas.</span></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit" value="go"><span class="btn__t">Mover</span><span class="btn__cell"><?= Ui::icon('arrow-up-right') ?></span></button></div>
  </form>
</dialog>
<?php endif; ?>
