<?php
use Captacion\Services\Fmt;
use Captacion\Services\Reports;
use Captacion\Ui;

/** /informes. Variables: $monthly, $weekly, $isAdminView, $genDefault, $csrf */
?>
<div class="page-head">
  <div>
    <h1 class="h1">Informes</h1>
    <p class="sub"><?= $isAdminView ? 'Informes mensuales de captación para NorthStar. Se generan solos el día 1 como borrador: revisa las acciones y publícalos.' : 'Informes mensuales de captación. Se pueden guardar como PDF desde el navegador.' ?></p>
  </div>
  <?php if ($isAdminView): ?><div class="page-head__a"><a class="btn btn--secondary" href="/resultados"><?= Ui::icon('bar-chart') ?>Ver como NorthStar</a></div><?php endif; ?>
</div>

<section class="card card--table" aria-labelledby="inf-m">
  <header class="card__h"><h2 class="label" id="inf-m">Informes mensuales</h2><span class="muted"><?= $e(count($monthly)) ?></span></header>
  <?php if (!$monthly): ?>
    <div class="empty empty--sm"><?= Ui::icon('star', 'empty__star ic--star') ?><h3>Todavía no hay informes</h3><p><?= $isAdminView ? 'El primero se genera solo el día 1 del mes siguiente. También puedes generar uno de un mes pasado abajo.' : 'Cuando se publique el primer informe mensual aparecerá aquí.' ?></p></div>
  <?php else: ?>
    <div class="tw"><table class="tbl tbl--rep"><thead><tr><th scope="col">Informe</th><?php if ($isAdminView): ?><th scope="col">Estado</th><?php endif; ?><th scope="col"><?= $isAdminView ? 'Publicado' : 'Fecha' ?></th><th scope="col"><span class="sr">Abrir</span></th></tr></thead><tbody>
      <?php foreach ($monthly as $r): ?>
        <tr><th scope="row" class="c-n"><a href="/informes/<?= $e($r['id']) ?>"><?= $e(ucfirst(Reports::monthName((string) $r['period']))) ?></a> <small class="mono muted"><?= $e($r['ident']) ?></small></th>
          <?php if ($isAdminView): ?><td data-l="Estado"><span class="chip chip--<?= $r['status'] === 'published' ? 'warm' : 'plain' ?>"><i class="dot"></i><?= $r['status'] === 'published' ? 'Publicado' : 'Borrador' ?></span></td><?php endif; ?>
          <td class="c-when" data-l="<?= $isAdminView ? 'Publicado' : 'Fecha' ?>"><?= $r['published_at'] !== null ? $e(Fmt::date($r['published_at'])) : '—' ?></td>
          <td class="c-act"><a class="btn btn--sm btn--secondary" href="/informes/<?= $e($r['id']) ?>">Abrir</a></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<?php if ($isAdminView): ?>
<section class="card" aria-labelledby="inf-g">
  <header class="card__h"><h2 class="label" id="inf-g">Generar un informe</h2></header>
  <div class="card__b">
    <form class="rep-range" method="post" action="/informes/generate" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="field"><span>Mes (solo meses ya terminados)</span><input type="month" name="period" value="<?= $e($genDefault) ?>" max="<?= $e($genDefault) ?>" min="2020-01" required></label>
      <button class="btn btn--primary" type="submit"><span class="btn__t">Generar ahora</span></button>
    </form>
    <p class="muted rep-pad">Crea el informe como borrador con los datos de ese mes. Si ya existe, te lleva a él (no se duplica).</p>
  </div>
</section>
<section class="card card--table" aria-labelledby="inf-w">
  <header class="card__h"><h2 class="label" id="inf-w">Resúmenes semanales enviados</h2><span class="muted">Los lunes 8:00 a. m. ET por correo</span></header>
  <?php if (!$weekly): ?><p class="muted rep-pad">Aún no se ha enviado ninguno.</p><?php else: ?>
    <div class="tw"><table class="tbl tbl--rep"><thead><tr><th scope="col">Semana</th><th scope="col">Generado</th><th scope="col">Correo</th></tr></thead><tbody>
      <?php foreach ($weekly as $w): ?><tr><th scope="row" class="c-n"><?= $e($w['period']) ?></th><td class="c-when" data-l="Generado"><?= $e(Fmt::dateTime($w['created_at'])) ?></td><td data-l="Correo"><?= $w['notified_at'] !== null ? 'Enviado ' . $e(Fmt::dateTime($w['notified_at'])) : 'No enviado (sin destinatario o sin SMTP)' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
