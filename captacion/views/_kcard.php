<?php
use Captacion\Clock;
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\Kanban;
use Captacion\Ui;

/** Tarjeta del kanban (un <li>), Q5a-D. Variables: $c (fila de Kanban::cards), $canWrite (bool). */
$name = Fmt::name($c);
$task = $c['next_task'];
$late = $task !== null && $task['due_at'] !== null && (int) Clock::parse((string) $task['due_at']) < Clock::time();
$red = array_map(static fn($f) => Catalog::label(Catalog::FLAGS, (string) $f), $c['red_flags']);
[$pos, $tot] = Kanban::progress((string) $c['pipeline'], (string) $c['stage']);
$temp = Catalog::label(Catalog::TEMPS, (string) $c['temperature']);
?>
<li class="kcard" data-id="<?= $e($c['id']) ?>" data-peek="<?= $e($c['id']) ?>" data-score="<?= $e($c['score']) ?>" data-stage="<?= $e($c['stage']) ?>" data-name="<?= $e($name) ?>">
  <article class="kc kc--<?= $e($c['temperature'] === 'disqualified' ? 'dq' : $c['temperature']) ?>">
    <div class="kc__tags"><?= Ui::tags($c, 1) ?><?php if ($red): ?><span class="tag tag--bad" title="Bandera roja de FMCSA"><?= Ui::icon('flag') ?><?= $e($red[0]) ?><?= count($red) > 1 ? ' +' . (count($red) - 1) : '' ?></span><?php endif; ?></div>
    <a class="kc__n" href="/leads/<?= $e($c['id']) ?>"><?= $e($name) ?></a>
    <?php if ($c['stage'] === 'lost'): ?>
      <p class="kc__lost"><?= Ui::icon('x') ?>Motivo: <?= $e(Catalog::label(Catalog::LOSS_REASONS, $c['lost_reason'], 'sin motivo')) ?></p>
    <?php endif; ?>
    <div class="kc__m">
      <span class="kc__code mono"><?= $e(Fmt::code((int) $c['id'])) ?></span>
      <span class="kc__sc" title="<?= $e($temp . ' · ' . $c['score'] . ' de 100') ?>"><?= Ui::scoreBar((int) $c['score'], (string) $c['temperature']) ?><b><?= $e($c['score']) ?></b></span>
      <?php if ($task !== null): ?>
        <span class="kc__task<?= $late ? ' is-late' : '' ?>" title="<?= $e($task['title']) ?>"><?= Ui::icon('calendar') ?><span><?= $task['due_at'] !== null ? $e(Fmt::dateTime((string) $task['due_at'], false)) : 'Sin fecha' ?></span><span class="sr"> · <?= $e($task['title']) ?><?= $late ? ' (vencida)' : '' ?></span></span>
      <?php else: ?>
        <span class="kc__task kc__task--none"><?= Ui::icon('calendar') ?><span>Sin tarea</span></span>
      <?php endif; ?>
    </div>
    <div class="kc__f">
      <span class="kc__prog" title="Avance en el pipeline"><?= $pos > 0 ? $e($pos . '/' . $tot) : '—' ?><i class="prog" role="img" aria-label="<?= $pos > 0 ? $e('Etapa ' . $pos . ' de ' . $tot) : 'Fuera del pipeline' ?>"><?php for ($i = 1; $i <= $tot; $i++): ?><b<?= $i <= $pos ? ' class="on"' : '' ?>></b><?php endfor; ?></i></span>
      <span class="kc__age" title="Tiempo en esta etapa"><?= Ui::icon('clock') ?><?= $e(Kanban::age((string) $c['in_stage_since'])) ?><span class="sr"> en esta etapa</span></span>
      <?php if (!empty($c['assignee'])): ?><?= Ui::avatar((string) $c['assignee'], 'sm') ?><?php endif; ?>
    </div>
    <?php if ($canWrite): ?>
      <button class="kc__mv" type="button" data-move aria-haspopup="menu" aria-expanded="false" aria-label="Mover a otra etapa: <?= $e($name) ?>"><?= Ui::icon('layers') ?><span class="kc__mvt">Mover a…</span></button>
    <?php endif; ?>
  </article>
</li>
