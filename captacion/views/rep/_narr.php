<?php
/** Subtítulo-conclusión de /resultados (Q6-I). Variable: $narr (ResultadosBoard::headline()), calculado solo de agregados. La negrita va solo en cifras y sustantivos clave; todo escapado. */
?>
<p class="sub sub--n"><?php foreach ($narr['segments'] as [$t, $b]): ?><?= $b ? '<b>' . $e($t) . '</b>' : $e($t) ?><?php endforeach; ?></p>
