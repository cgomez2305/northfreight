<?php
use Captacion\Services\Fmt;

/** Fase AU: llamadas de hoy (día de Nueva York) frente a la meta diaria. Variables: $stats (Prospects::todayStats) */
$goal = (int) $stats['goal'];
$pct = $goal > 0 ? min(100, (int) round($stats['calls'] * 100 / $goal)) : 0;
?>
<section class="pgoal" aria-label="Llamadas de hoy">
  <p class="pgoal__n"><b><?= $e(Fmt::num($stats['calls'])) ?></b><?= $goal > 0 ? ' de ' . $e(Fmt::num($goal)) : '' ?> llamadas hoy<?= $stats['mine'] !== $stats['calls'] ? ' <span class="muted">(' . $e(Fmt::num($stats['mine'])) . ' tuyas)</span>' : '' ?></p>
  <?php if ($goal > 0): ?><span class="pgoal__bar" role="progressbar" aria-label="Avance de la meta diaria" aria-valuemin="0" aria-valuemax="<?= $e($goal) ?>" aria-valuenow="<?= $e(min($goal, $stats['calls'])) ?>"><i class="pgoal__fill pgoal__fill--<?= $e((int) floor($pct / 10) * 10) ?>"></i></span><?php endif; ?>
  <p class="pgoal__k"><?php if (!empty($voice) && isset($vstats)): ?><span><b><?= $e(Fmt::num($vstats['calls'])) ?></b> por Vonage · <b><?= $e(Fmt::num($vstats['minutes'])) ?></b> min hablados</span><?php endif; ?><span><b><?= $e(Fmt::num($stats['spoke'])) ?></b> conversaciones</span><span><b><?= $e(Fmt::num($stats['interested'])) ?></b> interesados</span><span><b><?= $e(Fmt::num($stats['converted'])) ?></b> pasados a lead</span></p>
</section>
