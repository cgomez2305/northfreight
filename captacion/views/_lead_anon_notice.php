<?php
use Captacion\Ui;
use Captacion\Services\Fmt;

/** Fase AM · aviso de la ficha de un lead anonimizado (se incluye solo si $lead['anonymized_at'] no es null). */
?>
<p class="flash flash--bad" role="status"><?= Ui::icon('shield') ?><span><b>Lead anonimizado a petición del titular</b> el <?= $e(Fmt::dateTime((string) $lead['anonymized_at'])) ?>. Sus datos personales ya no existen en el panel, no se le puede llamar ni escribir y su teléfono y correo están en la lista de supresión. Se conservan las fechas, la fuente, la etapa y la puntuación para las estadísticas.</span></p>
