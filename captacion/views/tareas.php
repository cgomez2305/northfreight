<?php
use Captacion\Clock;
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\Tasks;
use Captacion\Ui;

/** Variables: $tab, $counts, $groups | $week, $canWrite, $now, $csrf */
$back = $tab === 'week' && isset($week) ? '/tareas?tab=week&w=' . $week['from'] : '/tareas' . ($tab === 'today' ? '' : '?tab=' . $tab);
$tabHint = ['today' => 'Lo que falta por hacer hoy', 'overdue' => 'Pasó su hora y siguen abiertas', 'upcoming' => 'De mañana en adelante y sin fecha', 'done' => 'Completadas en los últimos 30 días', 'week' => 'Lunes a domingo'];
$emptyCopy = [
    'today' => ['Nada pendiente para el resto del día', 'Las tareas y citas con fecha de hoy aparecerán aquí. Agenda el siguiente paso de un lead desde su ficha o con «Nueva tarea».'],
    'overdue' => ['Sin tareas vencidas', 'Todo lo que tenía fecha ya se atendió. Así se mantiene el seguimiento al día.'],
    'upcoming' => ['No hay tareas próximas', 'Cuando agendes seguimientos para mañana o más adelante los verás aquí, ordenados por fecha.'],
    'done' => ['Aún no hay tareas hechas', 'Las que completes en los próximos días quedarán aquí durante 30 días.'],
];
$taskRow = static function (array $it) use ($e, $csrf, $canWrite, $back, $now): string {
    ob_start();
    $done = ($it['done_at'] ?? null) !== null;
    $isTask = $it['kind'] === 'task';
    $late = $isTask && !$done && $it['due_at'] !== null && (int) Clock::parse((string) $it['due_at']) < $now;
    $lead = $it['lead_id'] !== null && $it['first_name'] !== null ? Fmt::name($it) : null;
    $when = $isTask ? ($it['due_at'] !== null ? Fmt::et((string) $it['due_at'])->format('H:i') : null) : Fmt::et((string) $it['starts_at'])->format('H:i');
    $data = $isTask ? json_encode(['id' => (int) $it['id'], 'title' => $it['title'], 'due' => Fmt::toEtInput($it['due_at']), 'priority' => (int) $it['priority'], 'lead_id' => $it['lead_id'] !== null ? (int) $it['lead_id'] : null, 'lead_name' => $lead], JSON_UNESCAPED_UNICODE) : '';
    ?>
    <li class="trow<?= $done ? ' is-done' : '' ?><?= $late ? ' is-late' : '' ?><?= $isTask ? '' : ' trow--appt' ?>">
      <?php if ($isTask): ?>
        <?php if ($canWrite): ?>
          <form method="post" action="/tareas/<?= $e($it['id']) ?>/<?= $done ? 'reopen' : 'done' ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>">
            <button class="tick<?= $done ? ' is-on' : '' ?>" type="submit" aria-label="<?= $done ? 'Reabrir' : 'Completar' ?>: <?= $e($it['title']) ?>"><?= Ui::icon('check') ?></button></form>
        <?php else: ?><span class="tick<?= $done ? ' is-on' : '' ?>" aria-hidden="true"><?= Ui::icon('check') ?></span><?php endif; ?>
      <?php else: ?><span class="trow__ic" aria-hidden="true"><?= Ui::icon('calendar') ?></span><?php endif; ?>
      <div class="trow__t">
        <span class="trow__n"><?= $isTask ? ($done ? '<s>' . $e($it['title']) . '</s>' : $e($it['title'])) : 'Cita' . ($it['title'] ? ': ' . $e($it['title']) : '') ?></span>
        <small>
          <?php if ($isTask): ?><i class="pri pri--<?= $e((int) $it['priority']) ?>"></i><?= $e(Catalog::PRIORITIES[(int) $it['priority']] ?? '') ?><?php else: ?><span class="chip chip--plain"><?= $e($it['status'] === 'booked' ? 'Agendada' : ucfirst((string) $it['status'])) ?></span><?php endif; ?>
          <?php if ($lead !== null): ?><a href="/leads/<?= $e($it['lead_id']) ?>"><?= $e($lead) ?></a><?php elseif ($isTask): ?><span class="muted">Sin lead</span><?php endif; ?>
          <?php if ($done): ?><span>Completada <?= $e(Fmt::dateTime((string) $it['done_at'])) ?></span><?php endif; ?>
        </small>
      </div>
      <time class="trow__w<?= $late ? ' is-late' : '' ?>"><?= $when !== null ? $e($when) : '' ?><?php if ($late): ?><small>vencida <?= $e(Fmt::ago((string) $it['due_at'])) ?></small><?php endif; ?></time>
      <?php if ($isTask && $canWrite): ?>
        <div class="dd trow__a" data-dd>
          <button class="icon-btn icon-btn--sm" type="button" aria-expanded="false" aria-haspopup="menu" aria-controls="tm-<?= $e($it['id']) ?>" aria-label="Acciones de la tarea: <?= $e($it['title']) ?>"><?= Ui::icon('dots') ?></button>
          <div class="dd__panel" id="tm-<?= $e($it['id']) ?>" role="menu" hidden>
            <?php if (!$done): ?>
              <form method="post" action="/tareas/<?= $e($it['id']) ?>/snooze"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>"><input type="hidden" name="mode" value="1h"><button class="dd__item" type="submit" role="menuitem"><?= Ui::icon('clock') ?>Posponer 1 hora</button></form>
              <form method="post" action="/tareas/<?= $e($it['id']) ?>/snooze"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>"><input type="hidden" name="mode" value="tomorrow"><button class="dd__item" type="submit" role="menuitem"><?= Ui::icon('calendar') ?>Posponer a mañana</button></form>
              <button class="dd__item" type="button" role="menuitem" data-snooze="<?= $e($data) ?>"><?= Ui::icon('calendar') ?>Elegir fecha…</button>
            <?php endif; ?>
            <button class="dd__item" type="button" role="menuitem" data-edit="<?= $e($data) ?>"><?= Ui::icon('pencil') ?>Editar…</button>
            <form method="post" action="/tareas/<?= $e($it['id']) ?>/delete"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>"><button class="dd__item dd__item--bad" type="submit" role="menuitem" data-confirm="¿Eliminar la tarea «<?= $e($it['title']) ?>»? Dejará de verse en la lista."><?= Ui::icon('trash') ?>Eliminar</button></form>
          </div>
        </div>
      <?php endif; ?>
    </li>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="page-head page-head--kb">
  <div>
    <h1 class="h1">Tareas</h1>
    <p class="sub">Seguimientos y citas · horas en <?= $e(Fmt::zone()) ?>. Son las mismas tareas que ves en la ficha de cada lead.</p>
  </div>
  <?php if ($canWrite): ?>
  <div class="page-head__a">
    <button class="btn btn--primary" type="button" data-open="#task-new"><span class="btn__t">Nueva tarea</span></button>
  </div>
  <?php endif; ?>
</div>

<div class="ptabs" role="group" aria-label="Vista de tareas">
  <?php foreach (Tasks::TABS as $k => $label): $n = $counts[$k] ?? null; ?>
    <a class="ptab<?= $tab === $k ? ' is-on' : '' ?><?= $k === 'overdue' && ($n ?? 0) > 0 ? ' ptab--late' : '' ?>" href="/tareas<?= $k === 'today' ? '' : '?tab=' . $e($k) ?>"<?= $tab === $k ? ' aria-current="true"' : '' ?> title="<?= $e($tabHint[$k]) ?>"><?= $e($label) ?><?php if ($n !== null): ?><span class="ptab__n"><?= $e($n) ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'week'): ?>
  <section aria-label="Semana" class="wk">
    <div class="wk__nav">
      <a class="btn btn--secondary btn--sm" href="/tareas?tab=week&amp;w=<?= $e($week['prev']) ?>" aria-label="Semana anterior"><?= Ui::icon('chevron-left') ?>Anterior</a>
      <p class="wk__range"><b><?= $e(Fmt::dayLabel(new DateTimeImmutable($week['from'], Fmt::tz()))) ?> a <?= $e(Fmt::dayLabel(new DateTimeImmutable($week['to'], Fmt::tz()))) ?></b><?= $week['this'] >= $week['from'] && $week['this'] <= $week['to'] ? ' · esta semana' : '' ?></p>
      <?php if (!($week['this'] >= $week['from'] && $week['this'] <= $week['to'])): ?><a class="btn btn--ghost btn--sm" href="/tareas?tab=week">Esta semana</a><?php endif; ?>
      <a class="btn btn--secondary btn--sm" href="/tareas?tab=week&amp;w=<?= $e($week['next']) ?>" aria-label="Semana siguiente">Siguiente<?= Ui::icon('chevron-right') ?></a>
    </div>
    <ol class="wk__g">
      <?php foreach ($week['days'] as $d): ?>
        <li class="wk__d<?= $d['today'] ? ' is-today' : '' ?>">
          <h2 class="wk__h"><span><?= $e($d['short']) ?></span><b><?= $e($d['day']) ?></b><?php if ($d['today']): ?><i class="wk__now">Hoy</i><?php endif; ?></h2>
          <?php if (!$d['items']): ?><p class="wk__e">Sin tareas ni citas</p><?php else: ?>
            <ul class="wk__l">
              <?php foreach ($d['items'] as $it): $done = $it['kind'] === 'task' && $it['done_at'] !== null; $late = $it['kind'] === 'task' && !$done && (int) Clock::parse((string) $it['at']) < $now; ?>
                <li class="wk__i<?= $done ? ' is-done' : '' ?><?= $late ? ' is-late' : '' ?><?= $it['kind'] === 'appt' ? ' wk__i--appt' : '' ?>">
                  <time><?= $e(Fmt::et((string) $it['at'])->format('H:i')) ?></time>
                  <span><?= $it['kind'] === 'appt' ? Ui::icon('calendar') : '' ?><?php $label = $it['kind'] === 'appt' ? 'Cita' : (string) $it['title']; ?>
                    <?php if ($it['lead_id'] !== null && $it['first_name'] !== null): ?><a href="/leads/<?= $e($it['lead_id']) ?>"><?= $e($label) ?></a><small><?= $e(Fmt::name($it)) ?></small><?php else: ?><?= $done ? '<s>' . $e($label) . '</s>' : $e($label) ?><?php endif; ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
<?php elseif (!$groups): ?>
  <section class="card empty empty--lg">
    <?= Ui::icon('star', 'empty__star empty__star--lg ic--star') ?>
    <h3><?= $e($emptyCopy[$tab][0]) ?></h3>
    <p><?= $e($emptyCopy[$tab][1]) ?></p>
    <?php if ($canWrite && $tab !== 'done'): ?><button class="btn btn--secondary" type="button" data-open="#task-new"><?= Ui::icon('plus') ?>Nueva tarea</button><?php endif; ?>
  </section>
<?php else: ?>
  <div class="tgroups">
    <?php foreach ($groups as $g): ?>
      <section class="card tgroup" aria-label="<?= $e($g['label']) ?>">
        <header class="card__h"><h2 class="label"><?= $e($g['label']) ?></h2><span class="muted"><?= $e(count($g['items'])) ?> <?= count($g['items']) === 1 ? 'elemento' : 'elementos' ?></span></header>
        <ul class="tlist"><?php foreach ($g['items'] as $it) { echo $taskRow($it); } ?></ul>
      </section>
    <?php endforeach; ?>
  </div>
  <?php $total = array_sum(array_map(static fn($g) => count($g['items']), $groups)); if ($total >= Tasks::LIMIT): ?><p class="foot-note">Se muestran los primeros <?= $e(Tasks::LIMIT) ?>. Completa o pospón algunos para ver el resto.</p><?php endif; ?>
<?php endif; ?>

<?php if ($canWrite): ?>
<?php
$leadPicker = static function (string $id) use ($e): string {
    return '<div class="lp" data-lp><label class="field"><span>Lead <small>(opcional)</small></span><input type="search" data-lp-q placeholder="Buscar por nombre, teléfono o USDOT" autocomplete="off" maxlength="100" role="combobox" aria-expanded="false" aria-controls="' . $e($id) . '" aria-autocomplete="list"></label>'
        . '<input type="hidden" name="lead_id" value="" data-lp-id><p class="lp__sel" data-lp-sel hidden><span data-lp-name></span><button type="button" class="link" data-lp-clear>Quitar</button></p>'
        . '<ul class="lp__list" id="' . $e($id) . '" role="listbox" aria-label="Resultados" data-lp-list hidden></ul></div>';
};
?>
<dialog class="dlg" id="task-new" aria-labelledby="task-new-t">
  <form method="post" action="/tareas/new">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="task-new-t">Nueva tarea</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <label class="field"><span>Qué hay que hacer</span><input type="text" name="title" required maxlength="190" placeholder="Llamar para confirmar documentos"></label>
    <?= $leadPicker('lp-new') ?>
    <div class="dlg__row">
      <label class="field"><span>Para (hora <?= $e(Fmt::zone()) ?>)</span><input type="datetime-local" name="due"></label>
      <label class="field"><span>Prioridad</span><select name="priority"><?php foreach (Catalog::PRIORITIES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $k === 2 ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Crear tarea</span></button></div>
  </form>
</dialog>
<dialog class="dlg" id="task-edit" aria-labelledby="task-edit-t">
  <form method="post" action="/tareas/0/edit" data-edit-form>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="task-edit-t">Editar tarea</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <label class="field"><span>Qué hay que hacer</span><input type="text" name="title" required maxlength="190"></label>
    <?= $leadPicker('lp-edit') ?>
    <div class="dlg__row">
      <label class="field"><span>Para (hora <?= $e(Fmt::zone()) ?>)</span><input type="datetime-local" name="due"></label>
      <label class="field"><span>Prioridad</span><select name="priority"><?php foreach (Catalog::PRIORITIES as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Guardar cambios</span></button></div>
  </form>
</dialog>
<dialog class="dlg" id="task-snooze" aria-labelledby="task-snooze-t">
  <form method="post" action="/tareas/0/snooze" data-snooze-form>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>"><input type="hidden" name="mode" value="date">
    <div class="dlg__h"><h2 class="dlg__t" id="task-snooze-t">Posponer tarea</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m" data-snooze-title></p>
    <label class="field"><span>Nueva fecha y hora (<?= $e(Fmt::zone()) ?>)</span><input type="datetime-local" name="date" required></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Posponer</span></button></div>
  </form>
</dialog>
<?php endif; ?>
