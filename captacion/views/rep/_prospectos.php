<?php
use Captacion\Services\Fmt;
use Captacion\Services\ProspectReport;
use Captacion\Services\Prospects;

/** Captación saliente en /reportes (fase AT): llamadas a prospectos FMCSA y correos en lote del rango. Variables: $range. Solo agregados. */
$ps = ProspectReport::summary((int) $range['from'], (int) $range['to']);
$pct = static fn(int $n, int $d): string => $d > 0 ? Fmt::pct($n * 100 / $d, 0) : "—";
$dims = ['fleet' => 'Por flota', 'age' => 'Por antigüedad de la autoridad', 'lang' => 'Por idioma que prefieren', 'source' => 'Por origen', 'state' => 'Por estado'];
$hrs = ProspectReport::hours((int) $range['from'], (int) $range['to']);   // fase AW
$camps = ProspectReport::campaigns((int) $range['from'], (int) $range['to']);
$ml = $ps['mail'];
?>
<?php $outLink = true; require __DIR__ . '/_saliente.php'; ?>
<?php if ($ps['calls'] > 0): ?>
<div class="rep-grid">
<?php foreach ($dims as $dim => $title): $rows = ProspectReport::breakdown($dim, (int) $range['from'], (int) $range['to']); ?>
<?php if ($dim === 'state'): ?>
  <section class="card card--table rep-span" aria-labelledby="r-o-h">
    <header class="card__h"><h2 class="label" id="r-o-h">A qué hora contestan</h2><span class="muted"><?= $hrs['best'] !== null ? 'Mejor franja: ' . $e(ProspectReport::HOUR_BUCKETS[$hrs['best']][2]) . ' (hora de allá)' : 'Hacen falta ' . ProspectReport::HOUR_MIN . ' llamadas en una franja para compararla' ?></span></header>
    <div class="tw"><table class="tbl tbl--rep">
      <caption class="sr">Llamadas del rango por franja de hora local del prospecto: cuántas y en cuántas contestó alguien</caption>
      <thead><tr><th scope="col">Hora local del prospecto</th><th scope="col" class="num-c">Llamadas</th><th scope="col" class="num-c">Contestaron</th><th scope="col" class="num-c"><abbr title="Contestaron / llamadas">Tasa</abbr></th></tr></thead>
      <tbody><?php foreach ($hrs['rows'] as $h): ?><tr><th scope="row" class="c-n"><?= $e($h['label']) ?><?= $h['key'] === $hrs['best'] ? ' <span class="chip chip--ok">Mejor</span>' : '' ?></th><td class="num-c" data-l="Llamadas"><?= $e($h['calls']) ?></td><td class="num-c" data-l="Contestaron"><?= $e($h['reached']) ?></td><td class="num-c" data-l="Tasa"><?= $e($pct($h['reached'], $h['calls'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  </section>
<?php endif; ?>
  <section class="card card--table<?= $dim === 'state' ? ' rep-span' : '' ?>" aria-labelledby="r-o-<?= $e($dim) ?>">
    <header class="card__h"><h2 class="label" id="r-o-<?= $e($dim) ?>"><?= $e($title) ?></h2></header>
    <div class="tw"><table class="tbl tbl--rep">
      <caption class="sr">Prospectos llamados en el rango <?= $e(mb_strtolower($title)) ?>: cuántos contestaron, se interesaron y pasaron a lead</caption>
      <thead><tr><th scope="col"><?= $e(['fleet' => 'Flota', 'age' => 'Autoridad', 'lang' => 'Idioma', 'source' => 'Origen', 'state' => 'Estado'][$dim]) ?></th><th scope="col" class="num-c">Llamados</th><th scope="col" class="num-c">Contestaron</th><th scope="col" class="num-c">Interesados</th><th scope="col" class="num-c">Leads</th><th scope="col" class="num-c"><abbr title="Leads / llamados">Conv.</abbr></th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?><tr><th scope="row" class="c-n"><?= $e($r['label']) ?></th><td class="num-c" data-l="Llamados"><?= $e($r['called']) ?></td><td class="num-c" data-l="Contestaron"><?= $e($r['reached']) ?></td><td class="num-c" data-l="Interesados"><?= $e($r['interested']) ?></td><td class="num-c" data-l="Leads"><?= $e($r['converted']) ?></td><td class="num-c" data-l="Conversión"><?= $e($pct($r['converted'], $r['called'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  </section>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($camps): ?>
<section class="card card--table" aria-labelledby="r-o-c">
  <header class="card__h"><h2 class="label" id="r-o-c">Campañas de correo con envíos en el rango</h2><a class="link" href="/prospectos/correos">Ver campañas</a></header>
  <div class="tw"><table class="tbl tbl--rep">
    <caption class="sr">Campañas de correo a prospectos: enviados, aperturas, clics y respuestas</caption>
    <thead><tr><th scope="col">Campaña</th><th scope="col" class="num-c">Enviados</th><th scope="col" class="num-c">Abiertos</th><th scope="col" class="num-c">Con clic</th><th scope="col" class="num-c">Respondieron</th></tr></thead>
    <tbody><?php foreach ($camps as $c): ?><tr><th scope="row" class="c-n"><?= $e($c['name']) ?></th><td class="num-c" data-l="Enviados"><?= $e($c['sent']) ?></td><td class="num-c" data-l="Abiertos"><?= $e($c['opened']) ?> <small class="muted"><?= $e($pct($c['opened'], $c['sent'])) ?></small></td><td class="num-c" data-l="Con clic"><?= $e($c['clicked']) ?></td><td class="num-c" data-l="Respondieron"><?= $e($c['replied']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
