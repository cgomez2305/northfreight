<?php
use Captacion\Clock;
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\Kanban;
use Captacion\Ui;

/** Tarjeta del kanban (un <li>). Variables: $c (fila de Kanban::cards), $canWrite (bool). */
$name = Fmt::name($c);
$task = $c['next_task'];
$late = $task !== null && $task['due_at'] !== null && (int) Clock::parse((string) $task['due_at']) < Clock::time();
$red = array_map(static fn($f) => Catalog::label(Catalog::FLAGS, (string) $f), $c['red_flags']);
$meta = array_filter([
    Catalog::label(Catalog::PROFILES_SHORT, $c['profile']),
    $c['state'] ?: null,
    $c['equipment_types'] ? Ui::equipment($c['equipment_types'], 1) : null,
    $c['truck_count'] !== null ? (int) $c['truck_count'] . ((int) $c['truck_count'] === 1 ? ' camión' : ' camiones') : null,
]);
?>
<li class="kcard" data-id="<?= $e($c['id']) ?>" data-score="<?= $e($c['score']) ?>" data-stage="<?= $e($c['stage']) ?>" data-name="<?= $e($name) ?>">
  <article class="kc">
    <header class="kc__h">
      <a class="kc__n" href="/leads/<?= $e($c['id']) ?>"><?= $e($name) ?></a>
      <?= Ui::score((int) $c['score'], (string) $c['temperature']) ?>
    </header>
    <p class="kc__m"><?= $e(implode(' · ', $meta)) ?></p>
    <div class="kc__c"><?= Ui::temp((string) $c['temperature']) ?><?php if ($red): ?><span class="chip chip--bad" title="Bandera roja de FMCSA"><?= Ui::icon('flag') ?><?= $e($red[0]) ?><?= count($red) > 1 ? ' +' . (count($red) - 1) : '' ?></span><?php endif; ?></div>
    <?php if ($c['stage'] === 'lost'): ?>
      <p class="kc__lost"><?= Ui::icon('x') ?>Motivo: <?= $e(Catalog::label(Catalog::LOSS_REASONS, $c['lost_reason'], 'sin motivo')) ?></p>
    <?php endif; ?>
    <footer class="kc__f">
      <span class="kc__age" title="Tiempo en esta etapa"><?= Ui::icon('clock') ?><?= $e(Kanban::age((string) $c['in_stage_since'])) ?><span class="sr"> en esta etapa</span></span>
      <?php if ($task !== null): ?>
        <span class="kc__task<?= $late ? ' is-late' : '' ?>" title="<?= $e($task['title']) ?>"><?= Ui::icon('calendar') ?><span><?= $task['due_at'] !== null ? $e(Fmt::dateTime((string) $task['due_at'], false)) : 'Sin fecha' ?></span><span class="sr"> · <?= $e($task['title']) ?></span></span>
      <?php else: ?>
        <span class="kc__task kc__task--none"><?= Ui::icon('calendar') ?><span>Sin tarea</span></span>
      <?php endif; ?>
    </footer>
    <?php if ($canWrite): ?>
      <button class="kc__mv" type="button" data-move aria-haspopup="menu" aria-expanded="false" aria-label="Mover a otra etapa: <?= $e($name) ?>"><?= Ui::icon('layers') ?>Mover a…</button>
    <?php endif; ?>
  </article>
</li>
