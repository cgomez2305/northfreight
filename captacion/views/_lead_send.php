<?php
use Captacion\Services\LeadSend;

/** Fase AM · tarjeta «Enviar plantilla» de la ficha (solo administradores; el resto de variables vienen de lead.php). */
if (empty($isAdmin) || !empty($lead['anonymized_at'])) {
    return;
}
$sendId = (int) $lead['id'];
$sendOpts = LeadSend::options($lead);
$sendLangs = ['en' => 'Inglés', 'es' => 'Español'];
?>
<section class="card" id="enviar" aria-labelledby="h-send">
  <header class="card__h"><h2 class="label" id="h-send">Enviar plantilla</h2></header>
  <div class="card__b">
    <p class="muted pad-b">Un correo o un WhatsApp con un texto ya escrito. Verás la vista previa antes de enviar.</p>
    <form class="addrow" method="get" action="/leads/<?= $e($sendId) ?>/enviar">
      <label class="field field--grow"><span>Plantilla</span>
        <select name="tpl" required><option value="">Elige una plantilla…</option><?php foreach ($sendOpts as $t): ?><option value="<?= $e($t['id']) ?>"><?= $e($t['name']) ?> · <?= $e($sendLangs[$t['lang']] ?? $t['lang']) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn--secondary" type="submit">Preparar envío</button>
    </form>
  </div>
</section>
