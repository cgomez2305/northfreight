<?php
/**
 * Pestañas de sección (fase AR): una sección del menú con dos pantallas hermanas (Prospectos: llamadas y correos en lote; Reportes: métricas e informes).
 * Variables: $stabs = [[href, texto, activa], ...], $stabsLabel (nombre accesible del grupo).
 */
?>
<nav class="utabs" aria-label="<?= $e($stabsLabel) ?>">
  <?php foreach ($stabs as [$href, $label, $on]): ?><a class="utab<?= $on ? ' is-on' : '' ?>" href="<?= $e($href) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?>
</nav>
