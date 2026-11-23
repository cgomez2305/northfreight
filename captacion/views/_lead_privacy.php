<?php
use Captacion\Ui;
use Captacion\Services\Fmt;
use Captacion\Services\LeadErasure;
use Captacion\Services\Reauth;

/** Fase AM · tarjeta «Privacidad y datos» de la ficha: anonimizar a petición del titular (solo administradores). */
if (empty($isAdmin)) {
    return;
}
$prId = (int) $lead['id'];
if (!empty($lead['anonymized_at'])): ?>
<section class="card" id="privacidad" aria-labelledby="h-priv">
  <header class="card__h"><h2 class="label" id="h-priv">Privacidad y datos</h2><span class="chip chip--bad"><?= Ui::icon('shield') ?>Anonimizado</span></header>
  <div class="card__b"><p class="muted">Anonimizado el <?= $e(Fmt::dateTime((string) $lead['anonymized_at'])) ?>. Sus datos personales ya no existen en el panel y su teléfono y correo están en la lista de supresión.</p></div>
</section>
<?php return; endif;
$prSame = LeadErasure::sameIdentity($lead);
$prFresh = Reauth::fresh();
?>
<section class="card" id="privacidad" aria-labelledby="h-priv">
  <header class="card__h"><h2 class="label" id="h-priv">Privacidad y datos</h2></header>
  <div class="card__b">
    <details class="fine"><summary>Anonimizar a petición del titular</summary>
      <p>Elimina el nombre, el teléfono, el correo, la ciudad, el USDOT, las notas, el texto de los mensajes y la IP de los consentimientos. Se conservan las fechas, la fuente, la etapa y la puntuación (para las estadísticas) y la constancia de qué se consintió y cuándo. Su teléfono y su correo pasan a la lista de supresión: no se les volverá a escribir. Se cancelan sus secuencias, tareas abiertas y citas futuras.</p>
      <p><b>No se puede deshacer</b> y no hay «Deshacer». Lo ya enviado a NorthStar o a otras herramientas (correo, webhook, n8n, hojas) y las copias de seguridad de los últimos 30 días no se tocan.</p>
      <?php if ($prSame): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><span>Hay otros leads con el mismo teléfono o correo (<?= $e(implode(', ', array_map(static fn($d) => Fmt::code((int) $d['id']), $prSame))) ?>). Si son la misma persona, fusiónalos antes o anonimízalos también.</span></p><?php endif; ?>
      <form class="priv__form" method="post" action="/leads/<?= $e($prId) ?>/anonimizar">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="field"><span>Para confirmar, escribe <b><?= $e(LeadErasure::CONFIRM_WORD) ?></b></span><input type="text" name="confirm" required autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="20"></label>
        <?php if (!$prFresh): ?>
        <label class="field"><span>Tu contraseña</span><input type="password" name="password" required autocomplete="current-password"></label>
        <label class="field"><span>Código de tu app de autenticación (uno nuevo, no el del acceso)</span><input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9 ]{6,8}"></label>
        <?php else: ?><p class="muted">Tu identidad está confirmada ahora mismo.</p><?php endif; ?>
        <button class="btn btn--danger" type="submit" data-confirm="Se eliminarán los datos personales de este lead de forma irreversible." data-confirm-title="¿Anonimizar el lead?" data-confirm-label="Anonimizar" data-confirm-danger><?= Ui::icon('trash') ?>Anonimizar este lead</button>
      </form>
    </details>
  </div>
</section>
