<?php
use Captacion\Services\Fmt;
use Captacion\Services\RepView;
use Captacion\Ui;

/** /informes/{id}. Variables: $report, $meta, $m (periodo), $p (anterior), $actions, $isAdminView, $recipients, $csrf */
$kpis = RepView::kpis($m, $p, false);
$prevLabel = 'vs ' . ($meta['prev_label'] ?? 'el mes anterior');
$published = $report['status'] === 'published';
$cmp = RepView::compareRows($m, $p);
?>
<div class="page-head rep-nop">
  <div>
    <p class="label"><?= $e($report['ident']) ?> · <?= $published ? 'PUBLICADO' : 'BORRADOR' ?></p>
    <h1 class="h1">Informe de captación · <?= $e($meta['label'] ?? $report['period']) ?></h1>
    <p class="sub">NorthStar Freight Solutions · captación de choferes<?= $published && $report['published_at'] ? ' · publicado el ' . $e(Fmt::date($report['published_at'])) : '' ?></p>
  </div>
  <div class="page-head__a"><button class="btn btn--secondary" type="button" data-print><?= Ui::icon('printer') ?>Imprimir o guardar como PDF</button></div>
</div>
<div class="rep-print-h" aria-hidden="true"><b>NORTHSTAR FREIGHT SOLUTIONS</b> · Informe de captación de <?= $e($meta['label'] ?? $report['period']) ?> · <?= $e($report['ident']) ?></div>
<?php if ($isAdminView && !$published): ?>
  <p class="flash flash--warn rep-nop" role="status"><?= Ui::icon('info') ?><span><b>Borrador.</b> NorthStar todavía no lo ve. Revisa las tres acciones del mes siguiente, edítalas si quieres y publica.</span></p>
<?php endif; ?>

<?php require __DIR__ . '/rep/_kpis.php'; ?>

<section class="card" aria-labelledby="i-cmp">
  <header class="card__h"><h2 class="label" id="i-cmp">Tendencia frente al mes anterior</h2></header>
  <div class="tw"><table class="tbl tbl--rep"><thead><tr><th scope="col">Indicador</th><th scope="col" class="num-c"><?= $e(ucfirst($meta['label'] ?? 'Este mes')) ?></th><th scope="col" class="num-c"><?= $e(ucfirst($meta['prev_label'] ?? 'Mes anterior')) ?></th><th scope="col" class="num-c">Variación</th></tr></thead><tbody>
    <?php foreach ($cmp as $c): ?><tr><th scope="row" class="c-n"><?= $e($c['label']) ?></th><td class="num-c" data-l="Este mes"><?= $e($c['cur']) ?></td><td class="num-c" data-l="Mes anterior"><?= $e($c['prev']) ?></td><td class="num-c" data-l="Variación"><?= Ui::delta($c['delta']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

<div class="rep-grid">
  <section class="card" aria-labelledby="r-fn">
    <header class="card__h"><h2 class="label" id="r-fn">Embudo del mes</h2></header>
    <div class="card__b"><?php if ($m['received'] === 0): ?><p class="muted">Sin leads recibidos ese mes.</p><?php else: ?><?php require __DIR__ . '/rep/_funnel.php'; ?><?php endif; ?></div>
  </section>
  <section class="card" aria-labelledby="r-ls">
    <header class="card__h"><h2 class="label" id="r-ls">Motivos de pérdida</h2><span class="muted"><?= $e($m['loss']['total']) ?> en total</span></header>
    <div class="card__b"><?php require __DIR__ . '/rep/_perdidas.php'; ?></div>
  </section>
</div>
<section class="card card--table" aria-labelledby="r-ch">
  <header class="card__h"><h2 class="label" id="r-ch">Costo por firmado por canal</h2></header>
  <?php require __DIR__ . '/rep/_canales.php'; ?>
</section>

<section class="card" id="acciones" aria-labelledby="i-ac">
  <header class="card__h"><h2 class="label" id="i-ac">Las tres acciones para el mes siguiente</h2><?php if ($isAdminView && !$published): ?><span class="muted">Editables hasta publicar</span><?php endif; ?></header>
  <div class="card__b">
  <?php if ($isAdminView && !$published): ?>
    <form method="post" action="/informes/<?= $e($report['id']) ?>/actions" class="cfg-form" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <?php for ($i = 0; $i < 3; $i++): ?><label class="field"><span>Acción <?= $e($i + 1) ?></span><input type="text" name="action[]" value="<?= $e($actions[$i] ?? '') ?>" maxlength="220" autocomplete="off"></label><?php endfor; ?>
      <div class="q3a"><button class="btn btn--secondary" type="submit"><span class="btn__t">Guardar acciones</span></button></div>
    </form>
    <p class="muted rep-pad">Las sugirió el sistema con reglas simples sobre tus datos (leads sin contactar, tareas vencidas, canales, costos). Cámbialas por lo que de verdad vayas a hacer.</p>
  <?php else: ?>
    <ol class="rep-acts"><?php foreach ($actions as $a): ?><li><?= $e($a) ?></li><?php endforeach; ?></ol>
  <?php endif; ?>
  </div>
</section>

<?php if ($isAdminView): ?>
<section class="card rep-nop" aria-labelledby="i-pb">
  <header class="card__h"><h2 class="label" id="i-pb">Publicación</h2></header>
  <div class="card__b">
    <?php if (!$published): ?>
      <p>Al publicar, NorthStar lo ve en <b>Informes</b><?= $recipients ? ' y se envía el enlace a ' . $e(implode(', ', $recipients)) : ' (no hay destinatarios configurados para el enlace: Configuración &gt; Correo)' ?>.</p>
      <form method="post" action="/informes/<?= $e($report['id']) ?>/publish"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><div class="q3a"><button class="btn btn--primary" type="submit" data-confirm="¿Publicar este informe para NorthStar? Las acciones ya no se podrán editar."><span class="btn__t">Publicar</span></button></div></form>
    <?php else: ?>
      <p>Publicado <?= $e(Fmt::dateTime($report['published_at'])) ?>. <?= $report['sent_at'] !== null ? 'Enlace enviado ' . $e(Fmt::dateTime($report['sent_at'])) . '.' : 'El enlace no se ha enviado.' ?></p>
      <form method="post" action="/informes/<?= $e($report['id']) ?>/resend"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><div class="q3a"><button class="btn btn--secondary" type="submit"<?= $recipients ? '' : ' disabled' ?>><span class="btn__t">Reenviar el enlace</span></button></div></form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<p class="foot-note rep-note">Instantánea generada el <?= $e(Fmt::dateTime($meta['generated_at'] ?? $report['created_at'])) ?>: los números de este informe no cambian aunque después se edite un lead. «Recibidos» son los leads que llegaron en el mes; «calificados» los que llegaron a la etapa Calificado; «firmados» los de esos leads que firmaron. El costo por firmado usa los costos mensuales cargados para ese mes.</p>
