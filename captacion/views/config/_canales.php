<?php
use Captacion\Ui;

/** Variables: $meta, $metaTargets, $metaDefaults, $cal, $tw, $n8n, $steps, $profiles, $urls, $locked, $csrf */
$rows = $meta['map'];
while (count($rows) < max(4, count($rows) + 2)) {
    $rows[] = ['from' => '', 'to' => ''];
}
$chan = ['call' => 'Llamada', 'sms' => 'SMS', 'email' => 'Correo', 'whatsapp' => 'WhatsApp'];
$btn = static fn(string $t): string => '<button class="btn btn--primary" type="submit"><span class="btn__t">' . $t . '</span><span class="btn__cell">' . Ui::icon('check') . '</span></button>';
?>
<p class="cfg-intro">Ajustes de los canales que entran por webhook. Las llaves y contraseñas NO se editan aquí: viven en <code>config/.env</code> (estado y botón «Probar» en la pestaña Integraciones).</p>

<form method="post" action="/config/canales/meta" class="cfg-form" id="cn-meta" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="mt-t">
      <header class="card__h"><h2 class="label" id="mt-t">META LEAD ADS · MAPEO DE CAMPOS</h2></header>
      <div class="card__b">
        <p class="muted">Cada pregunta del formulario del anuncio llega con un nombre propio. Escribe aquí el nombre tal como aparece en el lead (minúsculas, con guiones bajos) y a qué dato del CRM corresponde. Los nombres estándar de Meta ya están mapeados. Lo que no se mapee se ignora y queda anotado en el lead («campos sin mapear»).</p>
        <p class="cn-def"><span class="label">YA INCLUIDOS</span> <?php foreach ($metaDefaults as $k => $v): ?><span class="chip chip--plain"><code><?= $e($k) ?></code> → <?= $e($metaTargets[$v] ?? $v) ?></span> <?php endforeach; ?></p>
        <div class="cn-map" role="group" aria-label="Mapeo de preguntas del formulario">
          <div class="cn-map__h" aria-hidden="true"><span>Pregunta de Meta</span><span>Dato del CRM</span></div>
          <?php foreach ($rows as $i => $r): ?>
            <div class="cn-map__r">
              <label class="field"><span class="sr">Pregunta <?= $e($i + 1) ?></span><input type="text" name="map_from[]" value="<?= $e($r['from']) ?>" maxlength="120" placeholder="p. ej. what_type_of_equipment_do_you_run?" autocomplete="off"></label>
              <label class="field"><span class="sr">Dato <?= $e($i + 1) ?></span><select name="map_to[]"><option value="">—</option><?php foreach ($metaTargets as $k => $l): ?><option value="<?= $e($k) ?>"<?= $r['to'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
            </div>
          <?php endforeach; ?>
        </div>
        <label class="field cn-w"><span>Perfil por defecto de los leads de Meta</span><select name="default_profile"><?php foreach ($profiles as $k => $l): ?><option value="<?= $e($k) ?>"<?= $meta['default_profile'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select><small>Se usa cuando el formulario no pregunta el perfil (o la respuesta no se entiende).</small></label>
        <h3 class="cn-h">Consentimiento</h3>
        <p class="muted">Un lead de Meta entra <b>sin consentimiento de mensajería</b> salvo que el formulario lo traiga. Para registrarlo hacen falta las tres cosas: el texto exacto del aviso de ese formulario, los canales que cubre y qué pregunta (o casilla del aviso personalizado) lo confirma. Mapea esa pregunta a «Consentimiento».</p>
        <label class="field"><span>Texto exacto del aviso del formulario</span><textarea name="consent_text" rows="4" maxlength="4000"><?= $e($meta['consent_text']) ?></textarea></label>
        <fieldset class="hz-days"><legend>Canales que cubre ese texto</legend>
          <div class="eqgrid"><?php foreach ($chan as $k => $l): ?><label class="check check--box"><input type="checkbox" name="consent_channels[]" value="<?= $e($k) ?>"<?= in_array($k, $meta['consent_channels'], true) ? ' checked' : '' ?>><span><?= $e($l) ?></span></label><?php endforeach; ?></div>
        </fieldset>
        <label class="field cn-w"><span>Clave de la casilla del aviso personalizado (opcional)</span><input type="text" name="disclaimer_key" value="<?= $e($meta['disclaimer_key']) ?>" maxlength="120" autocomplete="off"><small>Solo si el consentimiento va en una casilla de «aviso personalizado» y no en una pregunta. Formato por confirmar con un lead real.</small></label>
      </div>
      <footer class="card__f"><span class="muted">Webhook: <code><?= $e($urls['meta']) ?></code></span><?= $btn('Guardar mapeo') ?></footer>
    </section>
  </fieldset>
</form>

<form method="post" action="/config/canales/calcom" class="cfg-form" id="cn-calcom" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="cc-t">
      <header class="card__h"><h2 class="label" id="cc-t">CAL.COM</h2></header>
      <div class="card__b">
        <label class="field cn-w"><span>Enlace de reserva (A4)</span><input type="url" name="booking_url" value="<?= $e($cal['booking_url']) ?>" maxlength="200" placeholder="https://cal.com/…"><small>El enlace que n8n manda por WhatsApp cuando un lead pasa a Calificado. Vacío = A4 no envía nada.</small></label>
        <label class="field cn-w"><span>Perfil de los leads que nacen de una reserva</span><select name="default_profile"><?php foreach ($profiles as $k => $l): ?><option value="<?= $e($k) ?>"<?= $cal['default_profile'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select><small>Solo cuando quien reserva no está en el CRM (si ya lo está, la cita se enlaza a su lead).</small></label>
      </div>
      <footer class="card__f"><span class="muted">Webhook: <code><?= $e($urls['calcom']) ?></code></span><?= $btn('Guardar') ?></footer>
    </section>
  </fieldset>
</form>

<form method="post" action="/config/canales/twilio" class="cfg-form" id="cn-twilio" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="tw-t">
      <header class="card__h"><h2 class="label" id="tw-t">TWILIO · SMS</h2></header>
      <div class="card__b">
        <label class="field"><span>Respuesta a HELP</span><textarea name="help_text" rows="3" maxlength="320" placeholder="<?= $e(\Captacion\Services\SmsInbound::DEFAULT_HELP) ?>"><?= $e($tw['help_custom'] ? $tw['help_text'] : '') ?></textarea><small>Vacío = el texto por defecto (borrador: que lo revise el abogado antes de enviar SMS reales). STOP, STOPALL, UNSUBSCRIBE, CANCEL, END y QUIT revocan SMS y WhatsApp; START, YES y UNSTOP restablecen solo el SMS.</small></label>
      </div>
      <footer class="card__f"><span class="muted">Webhook: <code><?= $e($urls['twilio']) ?></code></span><?= $btn('Guardar') ?></footer>
    </section>
  </fieldset>
</form>

<form method="post" action="/config/canales/n8n" class="cfg-form" id="cn-n8n" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="nn-t">
      <header class="card__h"><h2 class="label" id="nn-t">N8N · PASOS QUE PUEDE ENVIAR POR WHATSAPP</h2></header>
      <div class="card__b">
        <p class="muted">n8n pregunta a <code>/internal/sequences/due</code> y solo recibe los pasos que marques aquí, y solo de leads con consentimiento vigente en ese canal. <b>Marca un paso solo cuando su plantilla de WhatsApp ya esté aprobada por Meta.</b> Todo apagado = n8n no envía nada.</p>
        <div class="eqgrid"><?php foreach ($steps as $k => $l): ?><label class="check check--box"><input type="checkbox" name="wa_steps[]" value="<?= $e($k) ?>"<?= in_array($k, $n8n['wa_steps'], true) ? ' checked' : '' ?>><span><?= $e($l) ?></span></label><?php endforeach; ?></div>
        <label class="check check--box cn-w"><input type="checkbox" name="sms_enabled" value="1"<?= $n8n['sms_enabled'] ? ' checked' : '' ?>><span>Permitir también el canal SMS (solo después de registrar A2P 10DLC)</span></label>
      </div>
      <footer class="card__f"><span class="muted">Webhook de eventos: <code><?= $e($urls['n8n']) ?></code></span><?= $btn('Guardar pasos') ?></footer>
    </section>
  </fieldset>
</form>
