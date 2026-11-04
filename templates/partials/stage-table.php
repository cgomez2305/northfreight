<?php
/**
 * Tabla "A load, stage by stage": Stage · What the desk does · What you get · Kept on file.
 * Panel --panel con filas de borde fino y cabeceras mono; en móvil (<=760px) cada fila pasa a tarjeta con la etiqueta de cada celda.
 * Reutilizable: pasa otras filas en `rows` (misma forma que app/data/stages.php) y un `title` propio (H2 único dentro de la página).
 * Params: rows(array, por defecto data('stages')), title(string con marcas), eyebrow(string|null), lead(string|null), id(string),
 *         section(bool, true: envuelve en <section class="section"> con .pre-cta si pre_cta), pre_cta(bool).
 * Solo texto (sin foto). La tabla es una <table> real con <caption> para lectores de pantalla. Estilos (.stage*) en assets/css/pages/services-b.css (grupo de how-it-works); en otra familia de plantillas, añadir ese grupo a la ruta con `css`.
 */
require_once __DIR__ . '/kit.php';
$rows = $rows ?? data('stages');
if (!$rows) {
    return;
}
$id = $id ?? 'stage-title';
$section = $section ?? true;
?>
<?php if ($section): ?><section class="section<?= !empty($pre_cta) ? ' pre-cta' : '' ?>" aria-labelledby="<?= e($id) ?>"><div class="container stack"><?php endif; ?>
<?php partial('section-head', ['eyebrow' => $eyebrow ?? null, 'title' => $title ?? 'A load, stage by stage', 'lead' => $lead ?? null, 'id' => $id]); ?>
<div class="stage">
  <table class="stage__table">
    <caption class="sr-only">What the dispatch desk does at each stage of a load, what you get and what is kept on file</caption>
    <thead>
      <tr><th scope="col" class="t-meta">Stage</th><th scope="col" class="t-meta">What the desk does</th><th scope="col" class="t-meta">What you get</th><th scope="col" class="t-meta">Kept on file</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $i => [$stage, $does, $gets, $file]): ?>
      <tr>
        <th scope="row" class="stage__stage"><span class="stage__n" aria-hidden="true"><?= sprintf('%02d', $i + 1) ?></span><span class="stage__name"><?= phtext($stage) ?></span></th>
        <td data-label="What the desk does"><?= phtext($does) ?></td>
        <td data-label="What you get"><?= phtext($gets) ?></td>
        <td data-label="Kept on file"><span class="stage__file"><?= ns_icon('check', 14) ?><span><?= phtext($file) ?></span></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($section): ?></div></section><?php endif; ?>
