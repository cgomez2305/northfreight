<?php
use Captacion\Services\Fmt;
use Captacion\Services\LeadFeed;
use Captacion\Ui;

/**
 * Cronología global de actividad de los leads (Q5c-D): llegadas, etapas, notas, llamadas, tareas, citas, entregas, firmas, mensajes y alertas, agrupadas por día.
 * Mismos filtros que la Lista, filtro por tipo y por lead, y «Cargar más» por cursor. Variables: $feed (o null si hubo error), $feedError, $types, $allTypes, $ld, $ldName, $cursor, $render, $f, $facets.
 */
$q = static fn(array $over = []): string => '/cronologia' . Ui::qs(array_merge($f, ['tp' => $allTypes ? null : implode(',', $types), 'ld' => $ld], $over));
$nFilters = count($f);
$fa = ['path' => '/cronologia', 'keep' => ['tp' => $allTypes ? null : implode(',', $types), 'ld' => $ld], 'temp' => true, 'fold' => true];
$qsNext = ltrim(Ui::qs(array_merge($f, ['tp' => $allTypes ? null : implode(',', $types), 'ld' => $ld])), '?');
?>
<div class="page-head">
  <div>
    <h1 class="h1">Leads</h1>
    <p class="sub">Cronología · lo último que pasó con tus leads · horas en <?= $e(Fmt::zone()) ?></p>
  </div>
  <div class="page-head__a">
    <button class="btn btn--secondary filters-toggle filters-toggle--on" type="button" data-toggle="#filters" aria-expanded="<?= $nFilters > 0 ? 'true' : 'false' ?>" aria-controls="filters"><?= Ui::icon('filter') ?>Filtros<?= $nFilters > 0 ? ' <b class="badge">' . $e($nFilters) . '</b>' : '' ?></button>
  </div>
</div>

<?php $screen = 'cronologia'; require __DIR__ . '/_viewbar.php'; ?>
<?php require __DIR__ . '/_lfilters.php'; ?>

<section class="feed" data-feed data-qs="<?= $e($qsNext) ?>" aria-label="Actividad de los leads">
  <ul class="cal__types feed__types" aria-label="Tipos de actividad">
    <?php foreach (LeadFeed::TYPES as $k => [$label, $ic]):
        $on = in_array($k, $types, true);
        $next = $on ? array_values(array_diff($types, [$k])) : array_values(array_unique([...$types, $k]));
        $nextQ = !$next || count($next) === count(LeadFeed::TYPES) ? null : implode(',', $next); ?>
      <li><a class="tchip<?= $on ? ' is-on' : '' ?>" href="<?= $e($q(['tp' => $on && count($types) === 1 ? null : $nextQ])) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>" aria-label="<?= $e($label . ($on ? ': visible. Ocultar' : ': oculto. Mostrar')) ?>"><?= Ui::icon($ic) ?><?= $e($label) ?></a></li>
    <?php endforeach; ?>
    <?php if (!$allTypes): ?><li><a class="link" href="<?= $e($q(['tp' => null])) ?>">Todos los tipos</a></li><?php endif; ?>
  </ul>
  <?php if ($ld !== null): ?>
    <p class="feed__lead"><?= Ui::icon('user') ?>Solo este lead: <b><?= $e($ldName ?? 'Lead ' . $ld) ?></b><a class="link" href="<?= $e($q(['ld' => null])) ?>">Ver todos</a></p>
  <?php endif; ?>
  <?php if ($cursor !== null): ?><p class="feed__back"><a class="link" href="<?= $e($q()) ?>"><?= Ui::icon('arrow-up') ?>Volver a lo más reciente</a></p><?php endif; ?>

  <?php if ($feedError || $feed === null): ?>
    <div class="card empty empty--sm" role="alert"><?= Ui::icon('alert', 'empty__ic') ?><h3>La cronología tardó demasiado en cargar</h3><p>Acota con un filtro o elige menos tipos, o reintenta.</p><a class="btn btn--secondary btn--sm" href="<?= $e($q(['cur' => $cursor])) ?>">Reintentar</a></div>
  <?php elseif (!$feed['days']): ?>
    <div class="card empty empty--lg"><?= Ui::icon('star', 'empty__star empty__star--lg ic--star') ?>
      <h2 class="h2"><?= $grandTotal === 0 ? 'Todavía no hay actividad' : 'Nada coincide con estos filtros' ?></h2>
      <p><?= $grandTotal === 0 ? 'Cuando lleguen leads, aquí verás cada llegada, cambio de etapa, nota, llamada, entrega y firma, en orden.' : 'Prueba quitando algún filtro o eligiendo más tipos de actividad.' ?></p>
      <?php if ($nFilters > 0 || !$allTypes || $ld !== null): ?><a class="btn btn--secondary" href="/cronologia">Quitar los filtros</a><?php endif; ?></div>
  <?php else: ?>
    <div class="feed__days" data-feed-days><?= $render($feed['days']) ?></div>
    <div class="feed__more" data-feed-more>
      <?php if ($feed['next'] !== null): ?>
        <a class="btn btn--secondary" href="<?= $e($q(['cur' => $feed['next']])) ?>" data-feed-next="<?= $e($feed['next']) ?>"><?= Ui::icon('arrow-down') ?>Cargar más</a>
      <?php else: ?>
        <p class="muted">Eso es todo lo que hay con estos filtros.</p>
      <?php endif; ?>
    </div>
    <p class="sr" role="status" aria-live="polite" data-feed-live></p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/_peekshell.php'; ?>
