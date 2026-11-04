<?php
/**
 * Comparativa en <table> real (tarea · por tu cuenta · con el desk), sin cifras. En ≤760 px cada fila es una tarjeta con las dos columnas rotuladas (data-label).
 * CSS: pages/careers.css (.crc*).
 * Params:
 *  caption  string  texto accesible de la tabla (visible solo para lectores)       head array ['Task', 'On your own', 'With the desk']
 *  rows     array   [['Finding loads', 'texto solo', 'texto con desk'], …]
 */
require_once __DIR__ . '/kit.php';
$rows = $rows ?? [];
$head = $head ?? ['Task', 'On your own', 'With the desk'];
if (!$rows) {
    return;
}
?>
<div class="crc">
  <table class="crc__table">
    <caption class="sr-only"><?= e((string) ($caption ?? 'Running on your own versus with a dispatch desk')) ?></caption>
    <thead><tr><th scope="col"><?= e((string) $head[0]) ?></th><th scope="col"><?= e((string) $head[1]) ?></th><th scope="col" class="crc__desk"><?= e((string) $head[2]) ?></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row"><?= e((string) $r[0]) ?></th>
        <td data-label="<?= e((string) $head[1]) ?>"><?= e((string) $r[1]) ?></td>
        <td data-label="<?= e((string) $head[2]) ?>" class="crc__desk"><?= e((string) $r[2]) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
