<?php
use Captacion\Ui;
use Captacion\Services\Fmt;
use Captacion\Services\LeadImport;
use Captacion\Services\Catalog;

/** Variables: $imp (?array), $recent, $headers, $preview, $mapping (columna => campo), $options, $sim (?array), $formError, $progress (?array), $csrf */
$status = ['uploaded' => 'Subido', 'queued' => 'En cola', 'running' => 'Importando', 'done' => 'Terminada', 'failed' => 'Falló', 'cancelled' => 'Descartada'];
$n = static fn($v): string => number_format((int) $v, 0, ',', '.');
?>
<div class="page-head">
  <div>
    <h1 class="h1">Importar leads desde un CSV</h1>
    <p class="sub">Hasta 2 MB y 2.000 filas. Pasa por el mismo motor que los demás leads: validación, deduplicación, puntuación y reglas. <b>Los importados quedan sin consentimiento</b>: un archivo no puede probarlo.</p>
  </div>
  <div class="page-head__a"><a class="btn btn--ghost" href="/leads"><?= Ui::icon('x') ?>Volver a los leads</a></div>
</div>

<?php if ($imp === null): ?>
<section class="card" aria-labelledby="h-up">
  <header class="card__h"><h2 class="label" id="h-up">Subir el archivo</h2></header>
  <div class="card__b">
    <form class="dimp__up" method="post" action="/importar" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="field dimp__file"><span>Archivo CSV</span><input type="file" name="csv" accept=".csv,.txt,text/csv,text/plain" required><small>UTF-8 o Windows-1252. El separador se detecta solo (coma, punto y coma, tabulador o barra vertical). La primera fila son los encabezados.</small></label>
      <button class="btn btn--primary" type="submit"><span class="btn__t">Subir y revisar</span></button>
    </form>
    <p class="foot-note">El archivo se guarda en el servidor solo mientras dura la importación y se borra al terminar. La fuente de los leads será «Importación CSV» y llevarán una etiqueta con el nombre del archivo. Sin alertas de Telegram por cada uno.</p>
  </div>
</section>

<section class="card" aria-labelledby="h-rec">
  <header class="card__h"><h2 class="label" id="h-rec">Importaciones recientes</h2></header>
  <?php if (!$recent): ?>
    <div class="card__b"><p class="muted">Todavía no se ha importado nada.</p></div>
  <?php else: ?>
  <div class="tw"><table aria-labelledby="h-rec" class="tbl">
    <thead><tr><th scope="col">Archivo</th><th scope="col">Estado</th><th scope="col">Filas</th><th scope="col">Creados</th><th scope="col">Duplicados</th><th scope="col">Rechazados</th><th scope="col">Cuándo</th></tr></thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
      <tr>
        <th scope="row"><a href="/importar/<?= $e($r['id']) ?>"><?= $e($r['filename']) ?></a></th>
        <td><?= $e($status[$r['status']] ?? $r['status']) ?></td>
        <td><?= $e($n($r['total_rows'])) ?></td><td><?= $e($n($r['created_count'])) ?></td><td><?= $e($n($r['duplicate_count'])) ?></td><td><?= $e($n($r['rejected_count'])) ?></td>
        <td class="c-when"><?= $e(Fmt::dateTime((string) $r['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php elseif ($progress !== null): $p = $progress; $pct = (int) $p['total_rows'] > 0 ? (int) round(100 * (int) $p['processed'] / (int) $p['total_rows']) : 0; $probs = \Captacion\Db::decode($p['problems']); ?>
<section class="card" aria-labelledby="h-st">
  <header class="card__h"><h2 class="label" id="h-st"><?= $e($p['filename']) ?></h2><span class="chip chip--plain"><?= $e($status[$p['status']] ?? $p['status']) ?></span></header>
  <div class="card__b">
    <p role="status"><progress class="dimp__bar" max="<?= $e((int) $p['total_rows']) ?>" value="<?= $e((int) $p['processed']) ?>" aria-label="Avance de la importación"><?= $e($pct) ?> %</progress>
      <b><?= $e($n($p['processed'])) ?></b> de <b><?= $e($n($p['total_rows'])) ?></b> filas procesadas (<?= $e($pct) ?> %).</p>
    <dl class="dimp__sum">
      <div><dt>Creados</dt><dd><?= $e($n($p['created_count'])) ?></dd></div>
      <div><dt>Duplicados</dt><dd><?= $e($n($p['duplicate_count'])) ?></dd></div>
      <div><dt>Rechazados</dt><dd><?= $e($n($p['rejected_count'])) ?></dd></div>
    </dl>
    <?php if (in_array($p['status'], ['queued', 'running'], true)): ?>
      <p class="muted">La cola procesa un lote de <?= $e(LeadImport::BATCH) ?> filas cada minuto. <a class="link" href="/importar/<?= $e($p['id']) ?>">Actualizar</a></p>
    <?php elseif ($p['status'] === 'done'): ?>
      <p class="muted">Terminó <?= $e($p['finished_at'] ? Fmt::ago((string) $p['finished_at']) : '') ?>. El archivo ya se borró del servidor. <a class="link" href="/leads?src=csv_import">Ver los leads importados</a></p>
    <?php elseif ($p['status'] === 'failed'): ?>
      <p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?><span><?= $e($p['error'] ?? 'La importación falló.') ?></span></p>
    <?php endif; ?>
  </div>
</section>
<?php if ($probs): ?>
<section class="card" aria-labelledby="h-pr">
  <header class="card__h"><h2 class="label" id="h-pr">Filas con novedad</h2><span class="muted">primeras <?= $e(count($probs)) ?></span></header>
  <div class="tw"><table aria-labelledby="h-pr" class="tbl">
    <thead><tr><th scope="col">Fila</th><th scope="col">Resultado</th><th scope="col">Motivo</th></tr></thead>
    <tbody><?php foreach ($probs as $pr): ?><tr><th scope="row"><?= $e($pr['row']) ?></th><td><?= $e($pr['status'] === 'reject' ? 'Rechazada' : 'Duplicada') ?></td><td><?= $e($pr['reason']) ?><?php if (!empty($pr['lead_id'])): ?> · <a href="/leads/<?= $e($pr['lead_id']) ?>"><?= $e(Fmt::code((int) $pr['lead_id'])) ?></a><?php endif; ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>

<?php else: $id = (int) $imp['id']; ?>
<?php if ($formError !== ''): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?><span><?= $e($formError) ?></span></p><?php endif; ?>
<form method="post" action="/importar/<?= $e($id) ?>/start" class="dimp__form">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <section class="card" aria-labelledby="h-map">
    <header class="card__h"><h2 class="label" id="h-map">Columnas de «<?= $e($imp['filename']) ?>»</h2><span class="muted"><?= $e($n($imp['total_rows'])) ?> filas · separador <?= $e(LeadImport::DELIMITERS[$imp['delimiter_char']] ?? $imp['delimiter_char']) ?> · <?= $e($imp['encoding']) ?></span></header>
    <div class="tw"><table aria-labelledby="h-map" class="tbl dimp__map">
      <thead><tr><th scope="col">Columna del archivo</th><th scope="col">Ejemplo</th><th scope="col">Campo del lead</th></tr></thead>
      <tbody>
        <?php foreach ($headers as $i => $h): $sample = ''; foreach ($preview as $pr) { if (($pr['cells'][$i] ?? '') !== '') { $sample = (string) $pr['cells'][$i]; break; } } ?>
        <tr>
          <th scope="row"><?= $e($h !== '' ? $h : 'Columna ' . ($i + 1)) ?></th>
          <td class="dimp__ex"><?= $e(mb_substr($sample, 0, 40)) ?></td>
          <td><label class="sr" for="map-<?= $e($i) ?>">Campo para la columna <?= $e($h !== '' ? $h : $i + 1) ?></label>
            <select id="map-<?= $e($i) ?>" name="map[<?= $e($i) ?>]"><option value="">No importar</option><?php foreach (LeadImport::FIELDS as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($mapping[$i] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="card__f foot-note">Hace falta al menos el nombre y un teléfono o un correo. Las columnas de consentimiento se ignoran: los importados quedan sin consentimiento.</p>
  </section>

  <section class="card" aria-labelledby="h-opt">
    <header class="card__h"><h2 class="label" id="h-opt">Opciones</h2></header>
    <div class="card__b dimp__opts">
      <label class="field"><span>Perfil de los leads (si el archivo no trae una columna de perfil o la celda no es válida)</span>
        <select name="profile"><option value="">Elige un perfil…</option><?php foreach (Catalog::PROFILES as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($options['profile'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
      <fieldset class="dimp__dup"><legend class="label">Si el teléfono o el correo ya existen</legend>
        <label class="check"><input type="radio" name="dup" value="skip"<?= !empty($options['skip_duplicates']) ? ' checked' : '' ?>><span>Omitirlos sin tocar el lead que ya existe</span></label>
        <label class="check"><input type="radio" name="dup" value="fill"<?= empty($options['skip_duplicates']) ? ' checked' : '' ?>><span>Completar los campos vacíos del lead existente (queda como un reingreso)</span></label>
      </fieldset>
    </div>
  </section>

  <?php if ($sim !== null): ?>
  <section class="card" aria-labelledby="h-sim">
    <header class="card__h"><h2 class="label" id="h-sim">Resultado de la simulación</h2><span class="chip chip--plain">No se escribió nada</span></header>
    <div class="card__b">
      <dl class="dimp__sum">
        <div><dt>Se crearían</dt><dd><?= $e($n($sim['create'])) ?></dd></div>
        <div><dt>Duplicados</dt><dd><?= $e($n($sim['duplicate'])) ?></dd></div>
        <div><dt>Se rechazan</dt><dd><?= $e($n($sim['reject'])) ?></dd></div>
      </dl>
      <?php if ($sim['reasons']): ?>
      <h3 class="label dimp__h">Por qué</h3>
      <ul class="dimp__why"><?php foreach (array_slice($sim['reasons'], 0, 8, true) as $why => $cnt): ?><li><b><?= $e($n($cnt)) ?></b> · <?= $e($why) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if ($sim['problems']): ?>
      <details class="dimp__det"><summary>Ver las primeras <?= $e(count($sim['problems'])) ?> filas con novedad</summary>
        <div class="tw"><table aria-label="Filas con novedad de la simulación" class="tbl">
          <thead><tr><th scope="col">Fila</th><th scope="col">Resultado</th><th scope="col">Motivo</th></tr></thead>
          <tbody><?php foreach ($sim['problems'] as $pr): ?><tr><th scope="row"><?= $e($pr['row']) ?></th><td><?= $e($pr['status'] === 'reject' ? 'Rechazada' : 'Duplicada') ?></td><td><?= $e($pr['reason']) ?><?php if (!empty($pr['lead_id'])): ?> · <a href="/leads/<?= $e($pr['lead_id']) ?>"><?= $e(Fmt::code((int) $pr['lead_id'])) ?></a><?php endif; ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
      </details>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="card" aria-labelledby="h-go">
    <header class="card__h"><h2 class="label" id="h-go">Importar</h2></header>
    <div class="card__b dimp__go">
      <label class="check"><input type="checkbox" name="confirm" value="1"><span>Entiendo que los leads se crean de verdad, sin consentimiento, y que los teléfonos y correos de la lista de supresión se saltan.</span></label>
      <div class="dimp__act">
        <button class="btn btn--primary" type="submit"><span class="btn__t">Importar <?= $e($n($imp['total_rows'])) ?> filas</span></button>
        <button class="btn btn--secondary" type="submit" formaction="/importar/<?= $e($id) ?>/simular">Simular (sin escribir nada)</button>
      </div>
    </div>
  </section>
</form>
<form method="post" action="/importar/<?= $e($id) ?>/discard"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--ghost btn--sm" type="submit" data-confirm-title="¿Descartar la importación?" data-confirm-label="Descartar" data-confirm-danger data-confirm="Se descarta la subida y se borra el archivo del servidor.">Descartar esta subida</button></form>
<?php endif; ?>
