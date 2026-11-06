<?php
/** Subtítulo-conclusión del dashboard (Q6). Variable: $narr (DashboardNarrative::headline()). La negrita va solo en cifras y sustantivos clave; todo escapado. */
?>
<p class="sub sub--n" data-live="headline"><?php foreach ($narr['segments'] as [$t, $b]): ?><?= $b ? '<b>' . $e($t) . '</b>' : $e($t) ?><?php endforeach; ?></p>
