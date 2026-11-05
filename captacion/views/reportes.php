<?php
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\RepView;
use Captacion\Ui;

/** /reportes (admin). Variables: $range, $m (periodo), $p (periodo anterior), $presets, $tables, $csrf */
$kpis = RepView::kpis($m, $p, true);
$prevLabel = $range['prev_label'];
$rk = $range['key'] === 'custom' ? '' : $range['key'];
$fromV = $range['key'] === 'custom' ? $range['from_in'] : '';
$toV = $range['key'] === 'custom' ? $range['to_in'] : '';
$exp = static fn(string $t, string $label): string => '<form method="post" action="/reportes/export" class="inl"><input type="hidden" name="_csrf" value="' . htmlspecialchars((string) $csrf, ENT_QUOTES) . '">'
    . '<input type="hidden" name="table" value="' . $t . '"><input type="hidden" name="r" value="' . htmlspecialchars($rk, ENT_QUOTES) . '"><input type="hidden" name="from" value="' . htmlspecialchars($fromV, ENT_QUOTES) . '"><input type="hidden" name="to" value="' . htmlspecialchars($toV, ENT_QUOTES) . '">'
    . '<button class="btn btn--sm btn--secondary" type="submit" aria-label="Descargar CSV: ' . htmlspecialchars($label, ENT_QUOTES) . '">' . Ui::icon('download') . 'CSV</button></form>';
$grp = static function (array $rows, string $first, callable $name): string {
    $h = '<div class="tw"><table class="tbl tbl--rep"><thead><tr><th scope="col">' . htmlspecialchars($first, ENT_QUOTES) . '</th><th scope="col" class="num-c">Leads</th><th scope="col" class="num-c">Calificados</th><th scope="col" class="num-c">Presentados</th><th scope="col" class="num-c">Firmados</th><th scope="col" class="num-c">Firmados / leads</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><th scope="row" class="c-n">' . htmlspecialchars($name($r['key']), ENT_QUOTES) . '</th><td class="num-c" data-l="Leads">' . $r['leads'] . '</td><td class="num-c" data-l="Calificados">' . $r['qualified'] . '</td><td class="num-c" data-l="Presentados">' . $r['presented'] . '</td><td class="num-c" data-l="Firmados">' . $r['signed'] . '</td><td class="num-c" data-l="Firmados / leads">' . ($r['conv'] !== null ? Fmt::pct($r['conv'], 0) : '—') . '</td></tr>';
    }
    return $h . ($rows ? '' : '<tr><td colspan="6" class="muted">Sin datos en el rango.</td></tr>') . '</tbody></table></div>';
};
?>
<div class="page-head">
  <div>
    <h1 class="h1">Reportes</h1>
    <p class="sub"><?= $e($range['label']) ?> · <?= $e(Fmt::dayLabel(Fmt::et($range['from']))) ?> a <?= $e(Fmt::dayLabel(Fmt::et($range['to'] - 1))) ?> (hora <?= $e(Fmt::zone()) ?>) · <?= $e($prevLabel) ?></p>
  </div>
  <div class="page-head__a">
    <div class="seg seg--wrap" role="group" aria-label="Rango">
      <?php foreach ($presets as $k => $label): ?><a class="seg__i<?= $range['key'] === $k ? ' is-on' : '' ?>" href="/reportes<?= $e(Ui::qs(['r' => $k === '30d' ? null : $k])) ?>"<?= $range['key'] === $k ? ' aria-current="true"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?>
    </div>
  </div>
</div>
<form class="rep-range" method="get" action="/reportes" novalidate>
  <label class="field"><span>Desde</span><input type="date" name="from" value="<?= $e($range['from_in']) ?>" required></label>
  <label class="field"><span>Hasta</span><input type="date" name="to" value="<?= $e($range['to_in']) ?>" required></label>
  <button class="btn btn--secondary" type="submit"><?= Ui::icon('calendar') ?>Aplicar rango</button>
</form>
<?php if ($range['error']): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><?= $e($range['error']) ?></p><?php endif; ?>

<?php require __DIR__ . '/rep/_kpis.php'; ?>

<div class="rep-grid">
  <section class="card" aria-labelledby="r-fn">
    <header class="card__h"><h2 class="label" id="r-fn">EMBUDO · NUEVO → FIRMADO</h2><?= $exp('embudo', 'Embudo') ?></header>
    <div class="card__b"><?php if ($m['received'] === 0): ?><p class="muted">Sin leads recibidos en el rango.</p><?php else: ?><?php require __DIR__ . '/rep/_funnel.php'; ?><?php endif; ?>
      <p class="foot-note rep-note">Cuenta, de los leads recibidos en el rango, cuántos llegaron a cada etapa. Los company drivers no tienen «Llamada agendada»: cuentan ahí si llegaron más lejos.</p></div>
  </section>
  <section class="card" aria-labelledby="r-tr">
    <header class="card__h"><h2 class="label" id="r-tr">TENDENCIA</h2></header>
    <div class="card__b"><?php require __DIR__ . '/rep/_tendencia.php'; ?></div>
  </section>
</div>

<section class="card card--table" aria-labelledby="r-ch">
  <header class="card__h"><h2 class="label" id="r-ch">CANALES · LEADS, COSTO POR LEAD Y COSTO POR FIRMADO</h2><?= $exp('canales', 'Canales y costos') ?></header>
  <?php require __DIR__ . '/rep/_canales.php'; ?>
</section>

<div class="rep-grid">
  <section class="card card--table" aria-labelledby="r-ld">
    <header class="card__h"><h2 class="label" id="r-ld">PÁGINAS DE LLEGADA (SEO)</h2><?= $exp('paginas', 'Páginas de llegada') ?></header>
    <?= $grp($m['landing'], 'Página', static fn($k) => $k) ?>
  </section>
  <section class="card" aria-labelledby="r-ls">
    <header class="card__h"><h2 class="label" id="r-ls">MOTIVOS DE PÉRDIDA</h2><?= $exp('perdidas', 'Motivos de pérdida') ?></header>
    <div class="card__b"><?php require __DIR__ . '/rep/_perdidas.php'; ?></div>
  </section>
</div>

<div class="rep-grid rep-grid--3">
  <section class="card card--table" aria-labelledby="r-pf"><header class="card__h"><h2 class="label" id="r-pf">POR PERFIL</h2><?= $exp('perfiles', 'Perfiles') ?></header><?= $grp($m['profiles'], 'Perfil', static fn($k) => Catalog::label(Catalog::PROFILES, $k)) ?></section>
  <section class="card card--table" aria-labelledby="r-eq"><header class="card__h"><h2 class="label" id="r-eq">POR EQUIPO</h2><?= $exp('equipos', 'Equipos') ?></header><?= $grp($m['equipment'], 'Equipo', static fn($k) => Catalog::label(Catalog::EQUIPMENT, $k)) ?></section>
  <section class="card card--table" aria-labelledby="r-st"><header class="card__h"><h2 class="label" id="r-st">POR ESTADO DE EE. UU.</h2><?= $exp('estados', 'Estados') ?></header><?= $grp($m['states'], 'Estado', static fn($k) => $k) ?></section>
</div>

<p class="foot-note rep-note">Definiciones: «recibidos» son los leads que llegaron en el rango; «calificados» los que llegaron a Calificado (o Precalificado); «firmados» los de esa cohorte con firma. La primera respuesta es la mediana entre la llegada y el primer contacto. Los costos se cargan por mes (Configuración &gt; Costos por canal) y solo se usan los meses completos del rango. <?= $m['hotwarm'] ?> de los <?= $m['received'] ?> recibidos tienen puntuación alta (caliente o tibio).</p>
