<?php
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\LeadCalendar;
use Captacion\Ui;

/**
 * Calendario (Q5c-C): mes, semana y día con tareas, citas, primer contacto pendiente, pasos de secuencias y entregas programadas. Se sirve desde el servidor: cada botón es un enlace o un formulario.
 * Con JS se pueden arrastrar las tareas abiertas a otro día (Deshacer de 6 s), mover con Alt + flechas y crear una tarea con un clic en el día.
 * Variables: $cal (LeadCalendar::view o null si hubo error), $calError, $mode, $anchor, $sel, $types, $allTypes, $newDay, $now, $prios, $startTime, $f, $facets, $csrf, $canWrite, $activeView, $sysViews, $myViews.
 */
$q = static fn(array $over = []): string => '/calendario' . Ui::qs(array_merge($f, ['vm' => $mode, 'd' => $anchor, 'ev' => $allTypes ? null : implode(',', $types)], $over));
$backUrl = $q();
$nFilters = count($f);
$tz = Fmt::zone();
$weekdays = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
$fa = ['path' => '/calendario', 'keep' => ['vm' => $mode, 'd' => $anchor, 'ev' => $allTypes ? null : implode(',', $types)], 'temp' => true, 'fold' => true];
$lbl = static fn(array $ev): string => $ev['title'] . ($ev['lead'] !== null ? ' · ' . $ev['lead'] : '');
$href = static fn(array $ev): string => $ev['lead_id'] !== null ? '/leads/' . $ev['lead_id'] : ($ev['kind'] === 'task' ? '/tareas?tab=week&w=' . substr((string) Fmt::et((string) $ev['at'])->format('Y-m-d'), 0, 10) : '/leads');
$hm = static fn(array $ev): string => Fmt::et((string) $ev['at'])->format('H:i');
$chip = static function (array $ev) use ($e, $lbl, $href, $hm): string {
    $t = LeadCalendar::TYPES[$ev['kind']];
    $attrs = $ev['lead_id'] !== null ? ' data-peek="' . $e($ev['lead_id']) . '" data-peek-link' : '';
    $attrs .= $ev['drag'] ? ' draggable="true" data-task="' . $e($ev['task_id']) . '" data-time="' . $e($hm($ev)) . '"' : ' draggable="false"';
    return '<a class="ev ev--' . $e($t[2]) . ($ev['done'] ? ' is-done' : '') . ($ev['late'] ? ' is-late' : '') . '" href="' . $e($href($ev)) . '"' . $attrs . ' aria-label="' . $e($t[0] . ', ' . $hm($ev) . ': ' . $lbl($ev) . ($ev['done'] ? ' (hecha)' : '') . ($ev['late'] ? ' (vencida)' : '')) . '">'
        . '<span class="ev__t mono">' . $e($hm($ev)) . '</span><span class="ev__n">' . $e($ev['title']) . '</span></a>';
};
$card = static function (array $ev) use ($e, $lbl, $href, $hm, $csrf, $canWrite, $backUrl, $now): string {
    $t = LeadCalendar::TYPES[$ev['kind']];
    $attrs = $ev['lead_id'] !== null ? ' data-peek="' . $e($ev['lead_id']) . '" data-peek-link' : '';
    $attrs .= $ev['drag'] ? ' draggable="true" data-task="' . $e($ev['task_id']) . '" data-time="' . $e($hm($ev)) . '"' : ' draggable="false"';
    ob_start(); ?>
    <li class="evc ev--<?= $e($t[2]) ?><?= $ev['done'] ? ' is-done' : '' ?><?= $ev['late'] ? ' is-late' : '' ?>">
      <?php if ($ev['kind'] === 'task' && $ev['task_id'] !== null && $canWrite && !$ev['done']): ?>
        <form method="post" action="/tareas/<?= $e($ev['task_id']) ?>/done" class="evc__tick"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($backUrl) ?>"><button class="tick" type="submit" aria-label="Completar: <?= $e($ev['title']) ?>"><?= Ui::icon('check') ?></button></form>
      <?php else: ?><span class="evc__ic" aria-hidden="true"><?= Ui::icon($ev['done'] ? 'check-circle' : $t[1]) ?></span><?php endif; ?>
      <a class="evc__m" href="<?= $e($href($ev)) ?>"<?= $attrs ?>>
        <span class="evc__h"><time class="mono"><?= $e($hm($ev)) ?></time><span class="evc__k"><?= $e($t[0]) ?><?= $ev['late'] ? ' · vencida' : '' ?><?= $ev['done'] ? ' · hecha' : '' ?></span></span>
        <b class="evc__t"><?= $ev['done'] ? '<s>' . $e($ev['title']) . '</s>' : $e($ev['title']) ?></b>
        <?php if ($ev['lead'] !== null): ?><small><?= $e($ev['lead']) ?></small><?php endif; ?>
      </a>
      <?php if ($ev['drag'] && $canWrite): ?>
        <details class="evm" data-evm>
          <summary class="icon-btn icon-btn--sm" aria-label="Posponer o mover: <?= $e($ev['title']) ?>"><?= Ui::icon('clock') ?></summary>
          <div class="evm__p">
            <form method="post" action="/tareas/<?= $e($ev['task_id']) ?>/snooze" data-cal-snooze="1h"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($backUrl) ?>"><input type="hidden" name="mode" value="1h"><input type="hidden" name="task_id" value="<?= $e($ev['task_id']) ?>"><button class="dd__item" type="submit"><?= Ui::icon('clock') ?>Posponer 1 hora</button></form>
            <form method="post" action="/tareas/<?= $e($ev['task_id']) ?>/snooze" data-cal-snooze="tomorrow"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($backUrl) ?>"><input type="hidden" name="mode" value="tomorrow"><input type="hidden" name="task_id" value="<?= $e($ev['task_id']) ?>"><button class="dd__item" type="submit"><?= Ui::icon('calendar') ?>Posponer a mañana</button></form>
            <form method="post" action="/calendario/move" class="evm__d" data-cal-move><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($backUrl) ?>"><input type="hidden" name="task_id" value="<?= $e($ev['task_id']) ?>">
              <label class="field"><span>Mover a otro día</span><input type="date" name="ymd" value="<?= $e(Fmt::et((string) $ev['at'])->format('Y-m-d')) ?>" required></label>
              <button class="btn btn--secondary btn--sm" type="submit">Mover</button></form>
          </div>
        </details>
      <?php endif; ?>
    </li>
    <?php return (string) ob_get_clean();
};
$dayHref = static fn(string $ymd): string => $q(['vm' => 'dia', 'd' => $ymd, 'nueva' => null]);
$addHref = static fn(string $ymd): string => $q(['nueva' => $ymd]) . '#nueva';
?>
<div class="page-head">
  <div>
    <h1 class="h1">Leads</h1>
    <p class="sub">Calendario de seguimiento · horas en <?= $e($tz) ?><?= $cal !== null ? ' · ' . $e(Fmt::num($cal['total'])) . ($cal['total'] === 1 ? ' evento' : ' eventos') . ' en esta ' . ($mode === 'mes' ? 'vista del mes' : ($mode === 'semana' ? 'semana' : 'jornada')) : '' ?></p>
  </div>
  <div class="page-head__a">
    <button class="btn btn--secondary filters-toggle filters-toggle--on" type="button" data-toggle="#filters" aria-expanded="<?= $nFilters > 0 ? 'true' : 'false' ?>" aria-controls="filters"><?= Ui::icon('filter') ?>Filtros<?= $nFilters > 0 ? ' <b class="badge">' . $e($nFilters) . '</b>' : '' ?></button>
    <?php if ($canWrite): ?>
      <a class="btn btn--primary" href="<?= $e($addHref($sel)) ?>" data-cal-new="<?= $e($sel) ?>"><span class="btn__t">Nueva tarea</span></a>
    <?php endif; ?>
  </div>
</div>

<?php $screen = 'calendario'; require __DIR__ . '/_viewbar.php'; ?>
<?php require __DIR__ . '/_lfilters.php'; ?>

<section class="cal" data-cal data-mode="<?= $e($mode) ?>" data-anchor="<?= $e($anchor) ?>" data-prev="<?= $e($cal !== null ? $q(['d' => $cal['prev'], 'nueva' => null]) : '') ?>" data-next="<?= $e($cal !== null ? $q(['d' => $cal['next'], 'nueva' => null]) : '') ?>" data-back="<?= $e($backUrl) ?>" data-start="<?= $e($startTime) ?>" aria-label="Calendario">
  <header class="cal__bar">
    <div class="cal__nav">
      <a class="btn btn--secondary btn--sm" href="<?= $e($q(['d' => $cal['prev'] ?? $anchor, 'nueva' => null])) ?>" aria-label="<?= $e(['mes' => 'Mes anterior', 'semana' => 'Semana anterior', 'dia' => 'Día anterior'][$mode]) ?>" data-cal-prev><?= Ui::icon('chevron-left') ?></a>
      <a class="btn btn--secondary btn--sm" href="<?= $e($q(['d' => $now ? Fmt::et($now)->format('Y-m-d') : $anchor, 'nueva' => null])) ?>">Hoy</a>
      <a class="btn btn--secondary btn--sm" href="<?= $e($q(['d' => $cal['next'] ?? $anchor, 'nueva' => null])) ?>" aria-label="<?= $e(['mes' => 'Mes siguiente', 'semana' => 'Semana siguiente', 'dia' => 'Día siguiente'][$mode]) ?>" data-cal-next><?= Ui::icon('chevron-right') ?></a>
    </div>
    <h2 class="cal__t" id="cal-t" aria-live="polite"><?= $e($cal['title'] ?? '') ?></h2>
    <div class="seg seg--tight cal__m" role="group" aria-label="Escala del calendario">
      <?php foreach (LeadCalendar::MODES as $k => $label): ?><a class="seg__i<?= $mode === $k ? ' is-on' : '' ?>" href="<?= $e($q(['vm' => $k, 'nueva' => null])) ?>"<?= $mode === $k ? ' aria-current="true"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?>
    </div>
  </header>
  <ul class="cal__types" aria-label="Tipos de evento">
    <?php foreach (LeadCalendar::TYPES as $k => [$label, $ic, $tone]):
        $on = in_array($k, $types, true);
        $next = $on ? array_values(array_diff($types, [$k])) : array_values(array_unique([...$types, $k]));
        $nextQ = !$next || count($next) === count(LeadCalendar::TYPES) ? null : implode(',', $next);
        $n = $cal['counts'][$k] ?? 0; ?>
      <li><a class="tchip ev--<?= $e($tone) ?><?= $on ? ' is-on' : '' ?>" href="<?= $e($q(['ev' => $on && count($types) === 1 ? null : $nextQ, 'nueva' => null])) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>" aria-label="<?= $e($label . ($on ? ': visible. Ocultar' : ': oculto. Mostrar')) ?>"><i></i><?= $e($label) ?><?php if ($on && $cal !== null): ?><b><?= $e($n) ?></b><?php endif; ?></a></li>
    <?php endforeach; ?>
  </ul>

  <?php if ($newDay !== null && $canWrite): ?>
  <section class="card cal__new" id="nueva" aria-label="Nueva tarea">
    <form method="post" action="/tareas/new">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($backUrl) ?>">
      <h3 class="h2">Nueva tarea · <?= $e(Fmt::weekdayLong((int) (new DateTimeImmutable($newDay, Fmt::tz()))->format('N'))) ?> <?= $e((int) substr($newDay, 8, 2)) ?> de <?= $e(Fmt::monthLong((int) substr($newDay, 5, 2))) ?></h3>
      <label class="field"><span>Qué hay que hacer</span><input type="text" name="title" required maxlength="190" placeholder="Llamar para confirmar documentos"></label>
      <div class="dlg__row">
        <label class="field"><span>Para (hora <?= $e($tz) ?>)</span><input type="datetime-local" name="due" value="<?= $e($newDay . 'T' . $startTime) ?>"></label>
        <label class="field"><span>Prioridad</span><select name="priority"><?php foreach ($prios as $k => $l): ?><option value="<?= $e($k) ?>"<?= $k === 2 ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="dlg__a"><a class="btn btn--ghost" href="<?= $e($q(['nueva' => null])) ?>">Cancelar</a><button class="btn btn--primary" type="submit"><span class="btn__t">Crear tarea</span></button></div>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($calError || $cal === null): ?>
    <div class="card empty empty--sm" role="alert"><?= Ui::icon('alert', 'empty__ic') ?><h3>El calendario tardó demasiado en cargar</h3><p>Prueba con un filtro más acotado o reintenta.</p><a class="btn btn--secondary btn--sm" href="<?= $e($q()) ?>">Reintentar</a></div>
  <?php else: ?>
    <?php if ($cal['truncated']): ?><p class="cal__warn" role="status"><?= Ui::icon('info') ?>Hay más eventos de los que caben: se muestran hasta <?= $e(LeadCalendar::CAP) ?> de cada tipo. Acota con un filtro para verlos todos.</p><?php endif; ?>
    <?php if ($cal['total'] === 0): ?><p class="cal__none" role="status"><?= Ui::icon('calendar') ?>Nada programado <?= $mode === 'dia' ? 'este día' : ($mode === 'semana' ? 'esta semana' : 'este mes') ?><?= $nFilters > 0 || !$allTypes ? ' con los filtros actuales' : '' ?>. <?php if ($canWrite): ?>Haz clic en un día para crear una tarea.<?php endif; ?></p><?php endif; ?>

    <?php if ($mode === 'mes'): ?>
      <div class="cal__g cal__g--m" role="grid" aria-labelledby="cal-t">
        <div class="cal__dow" role="row"><?php foreach ($weekdays as $w): ?><span role="columnheader"><?= $e($w) ?></span><?php endforeach; ?></div>
        <?php foreach (array_chunk($cal['days'], 7) as $week): ?>
        <div class="cal__w" role="row">
          <?php foreach ($week as $d): $n = count($d['events']); ?>
            <div class="cal__d<?= $d['today'] ? ' is-today' : '' ?><?= $d['out'] ? ' is-out' : '' ?><?= $d['ymd'] === $sel && $explicitAnchor ? ' is-sel' : '' ?>" role="gridcell" data-ymd="<?= $e($d['ymd']) ?>"<?= $d['today'] ? ' aria-current="date"' : '' ?>>
              <div class="cal__dh">
                <a class="cal__n" href="<?= $e($dayHref($d['ymd'])) ?>" data-day="<?= $e($d['ymd']) ?>" aria-label="<?= $e($d['label'] . ', ' . ($n === 0 ? 'sin eventos' : $n . ($n === 1 ? ' evento' : ' eventos')) . ($d['today'] ? ', hoy' : '')) ?>"><?= $e($d['day']) ?></a>
                <?php if ($canWrite): ?><a class="cal__add" href="<?= $e($addHref($d['ymd'])) ?>" data-cal-new="<?= $e($d['ymd']) ?>" aria-label="Nueva tarea el <?= $e($d['label']) ?>"><?= Ui::icon('plus') ?></a><?php endif; ?>
              </div>
              <?php if ($n): ?><ul class="cal__e">
                <?php foreach (array_slice($d['events'], 0, 3) as $ev): ?><li><?= $chip($ev) ?></li><?php endforeach; ?>
                <?php if ($n > 3): ?><li><a class="cal__more" href="<?= $e($dayHref($d['ymd'])) ?>">+<?= $e($n - 3) ?> más<span class="sr"> el <?= $e($d['label']) ?></span></a></li><?php endif; ?>
              </ul><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php elseif ($mode === 'semana'): ?>
      <ol class="cal__g cal__g--w">
        <?php foreach ($cal['days'] as $d): ?>
          <li class="cal__d<?= $d['today'] ? ' is-today' : '' ?>" data-ymd="<?= $e($d['ymd']) ?>"<?= $d['today'] ? ' aria-current="date"' : '' ?>>
            <div class="cal__dh">
              <a class="cal__n cal__n--w" href="<?= $e($dayHref($d['ymd'])) ?>" data-day="<?= $e($d['ymd']) ?>" aria-label="<?= $e($d['label'] . ($d['today'] ? ', hoy' : '')) ?>"><span><?= $e($weekdays[$d['dow'] - 1]) ?></span><b><?= $e($d['day']) ?></b></a>
              <?php if ($canWrite): ?><a class="cal__add" href="<?= $e($addHref($d['ymd'])) ?>" data-cal-new="<?= $e($d['ymd']) ?>" aria-label="Nueva tarea el <?= $e($d['label']) ?>"><?= Ui::icon('plus') ?></a><?php endif; ?>
            </div>
            <?php if (!$d['events']): ?><p class="cal__e0">Sin eventos</p><?php else: ?><ul class="cal__c"><?php foreach ($d['events'] as $ev) { echo $card($ev); } ?></ul><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php else: $d = $cal['days'][0]; ?>
      <div class="cal__g cal__g--d">
        <div class="cal__d<?= $d['today'] ? ' is-today' : '' ?>" data-ymd="<?= $e($d['ymd']) ?>"<?= $d['today'] ? ' aria-current="date"' : '' ?>>
          <div class="cal__dh"><a class="cal__n cal__n--w" href="<?= $e($dayHref($d['ymd'])) ?>" data-day="<?= $e($d['ymd']) ?>" aria-label="<?= $e($d['label']) ?>"><span><?= $e($weekdays[$d['dow'] - 1]) ?></span><b><?= $e($d['day']) ?></b></a>
            <?php if ($canWrite): ?><a class="cal__add cal__add--t" href="<?= $e($addHref($d['ymd'])) ?>" data-cal-new="<?= $e($d['ymd']) ?>"><?= Ui::icon('plus') ?>Nueva tarea</a><?php endif; ?></div>
          <?php if (!$d['events']): ?><p class="cal__e0">Nada programado para este día.</p><?php else: ?><ul class="cal__c"><?php foreach ($d['events'] as $ev) { echo $card($ev); } ?></ul><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  <p class="cal__help"><?= Ui::icon('keyboard') ?>Con el foco en un día: <kbd>←</kbd><kbd>→</kbd><kbd>↑</kbd><kbd>↓</kbd> te mueven, <kbd>RePág</kbd><kbd>AvPág</kbd> cambian de mes, <kbd>Intro</kbd> abre el día. Con una tarea enfocada, <kbd>Alt</kbd> + flechas la cambian de día.</p>
</section>

<?php if ($canWrite): ?>
<dialog class="dlg" id="cal-new" aria-labelledby="cal-new-t">
  <form method="post" action="/tareas/new">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($backUrl) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="cal-new-t">Nueva tarea</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m" data-cal-new-day></p>
    <label class="field"><span>Qué hay que hacer</span><input type="text" name="title" required maxlength="190" placeholder="Llamar para confirmar documentos"></label>
    <div class="lp" data-lp><label class="field"><span>Lead <small>(opcional)</small></span><input type="search" data-lp-q placeholder="Buscar por nombre, teléfono o USDOT" autocomplete="off" maxlength="100" role="combobox" aria-expanded="false" aria-controls="lp-cal" aria-autocomplete="list"></label>
      <input type="hidden" name="lead_id" value="" data-lp-id><p class="lp__sel" data-lp-sel hidden><span data-lp-name></span><button type="button" class="link" data-lp-clear>Quitar</button></p>
      <ul class="lp__list" id="lp-cal" role="listbox" aria-label="Resultados" data-lp-list hidden></ul></div>
    <div class="dlg__row">
      <label class="field"><span>Para (hora <?= $e($tz) ?>)</span><input type="datetime-local" name="due" required></label>
      <label class="field"><span>Prioridad</span><select name="priority"><?php foreach ($prios as $k => $l): ?><option value="<?= $e($k) ?>"<?= $k === 2 ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Crear tarea</span></button></div>
  </form>
</dialog>
<?php endif; ?>
<?php require __DIR__ . '/_peekshell.php'; ?>
