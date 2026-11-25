<?php
use Captacion\Ui;
use Captacion\Services\Fmt;
use Captacion\Services\Prospects;

/**
 * Fase AU: Modo llamada (/prospectos/llamar). Un prospecto a la vez: el siguiente de la «Lista de hoy» que se puede llamar ahora, por prioridad.
 * Variables: $row (o null), $left, $skip, $now, $back, $history, $mailed, $autoMails, $stats, $script, $agent, $counts, $csrf
 */
$dayFmt = static fn(?string $utc): string => $utc === null ? '—' : Fmt::et($utc)->format('j M, g:i') . ' ' . (Fmt::et($utc)->format('A') === 'AM' ? 'a. m.' : 'p. m.');
$todayEt = Prospects::etDay($now);
$resultIcon = Prospects::RESULT_ICONS;
$skipHref = static fn(array $ids): string => '/prospectos/llamar' . ($ids ? '?skip=' . implode(',', $ids) : '');
?>
<div class="page-head">
  <div>
    <h1 class="h1">Modo llamada</h1>
    <p class="sub"><?= $row !== null ? $e(Fmt::num($left)) . ($left === 1 ? ' prospecto' : ' prospectos') . ' para llamar ahora, del más prioritario al menos' : 'Nadie más para llamar en este momento' ?><?= $skip ? ' · ' . $e(count($skip)) . ' saltados' : '' ?></p>
  </div>
  <div class="page-head__a">
    <?php if ($skip): ?><a class="btn btn--ghost" href="/prospectos/llamar">Volver a los saltados</a><?php endif; ?>
    <a class="btn btn--secondary" href="/prospectos"><?= Ui::icon('list') ?><span class="btn__t">Ver la lista</span></a>
  </div>
</div>
<?php require __DIR__ . '/_prospect_stats.php'; ?>

<?php if ($row === null): ?>
<section class="card empty empty--lg">
  <svg class="empty__star empty__star--lg" aria-hidden="true"><use href="#i-star"/></svg>
  <h2 class="h2"><?= $skip ? 'Solo quedan los que saltaste' : 'No hay a quién llamar ahora' ?></h2>
  <p><?= $skip ? 'Vuelve a ellos cuando quieras.' : ((int) $counts['today'] > 0 ? 'Quedan ' . $e(Fmt::num($counts['today'])) . ' en la lista de hoy, pero fuera de su horario (8 a. m. a 9 p. m. hora local) o sin teléfono. Vuelve más tarde.' : 'Ya atendiste la lista de hoy. Mañana a las 6:00 a. m. (Nueva York) llegan prospectos nuevos.') ?></p>
  <?php if ($skip): ?><a class="btn btn--secondary" href="/prospectos/llamar">Volver a los saltados</a><?php endif; ?>
</section>
<?php else:
    $r = $row;
    require __DIR__ . '/_prospect_vars.php';
    $mode = 'call';
    ?>
<article class="card pcall" id="p-<?= $e($id) ?>" aria-labelledby="pcall-n">
  <header class="pcall__h">
    <div>
      <h2 class="pcall__n" id="pcall-n"><?= $e($name) ?></h2>
      <p class="pcall__m"><span class="mono">USDOT <?= $e($r['usdot']) ?><?= $r['mc_number'] ? ' · ' . $e($r['mc_number']) : '' ?></span> · <?= $e($loc) ?> · <?= $r['fleet_size'] !== null ? $e($r['fleet_size']) . ((int) $r['fleet_size'] === 1 ? ' unidad' : ' unidades') : 'flota sin dato' ?> · <?= $e(Prospects::STATUSES[$r['status']] ?? $r['status']) ?></p>
    </div>
    <a class="btn btn--ghost" href="<?= $e($skipHref(array_merge($skip, [$id]))) ?>" title="Lo deja para después en esta sesión"><?= Ui::icon('arrow-right') ?><span class="btn__t">Saltar</span></a>
  </header>
  <div class="pr__b pcall__b"><?php require __DIR__ . '/_prospect_body.php'; ?></div>
</article>
<?php endif; ?>
