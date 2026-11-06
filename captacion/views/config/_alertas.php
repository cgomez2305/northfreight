<?php
use Captacion\Services\Alerts;
use Captacion\Services\Fmt;
use Captacion\Ui;

$a = $alerts;
$q = $a['quiet'];
$fe = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="ea-' . $k . '"' : '';
$em = static fn(string $k) => isset($errors[$k]) ? '<small class="num__e" id="ea-' . $k . '">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</small>' : '';
?>
<p class="cfg-intro">Qué te avisa, por dónde y cuándo. Las alertas urgentes (lead caliente, segunda alerta y firmado que vuelve a escribir) nunca esperan al horario de silencio.</p>
<?php if (!$tgOk): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?>Telegram no está configurado en el servidor: los interruptores de Telegram no enviarán nada hasta que agregues TELEGRAM_BOT_TOKEN y TELEGRAM_CHAT_ID. Mira «Integraciones».</p><?php endif; ?>
<form method="post" action="/config/alerts" class="cfg-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="al-t">
      <header class="card__h"><h2 class="label" id="al-t">Telegram</h2></header>
      <div class="card__b al-list">
        <label class="tgl"><input type="checkbox" name="hot_telegram" value="1"<?= $a['hot_telegram'] ? ' checked' : '' ?>><span class="tgl__t"><b>Lead caliente (A2)</b><small>Un mensaje al instante cuando llega un lead caliente.</small></span></label>
        <label class="tgl"><input type="checkbox" name="followup_telegram" value="1"<?= $a['followup_telegram'] ? ' checked' : '' ?>><span class="tgl__t"><b>Segunda alerta (A3)</b><small>Si nadie lo contactó pasado este tiempo, vuelve a avisarte. Se cancela solo al contactar el lead.</small></span></label>
        <label class="field al-min<?= isset($errors['followup_minutes']) ? ' has-err' : '' ?>"><span>Minutos antes de la segunda alerta</span><input type="text" name="followup_minutes" value="<?= $e($a['followup_minutes']) ?>" inputmode="numeric" maxlength="3" autocomplete="off"<?= $fe('followup_minutes') ?>><?= $em('followup_minutes') ?><small>Entre 5 y 120. Por defecto 15.</small></label>
        <fieldset class="warm<?= isset($errors['warm_telegram']) ? ' has-err' : '' ?>"><legend>Lead tibio por Telegram <span class="chip chip--new">Nuevo</span></legend>
          <div class="seg seg--wrap warm__o"><?php foreach (Alerts::WARM_MODES as $k => $l): ?><label class="warm__i"><input type="radio" name="warm_telegram" value="<?= $e($k) ?>"<?= $a['warm_telegram'] === $k ? ' checked' : '' ?>><span><?= $e($l) ?></span></label><?php endforeach; ?></div>
          <?= $em('warm_telegram') ?>
          <p class="warm__why<?= $warmOn ? ' is-on' : '' ?>"><?= Ui::icon($warmOn ? 'check-circle' : 'minus') ?><span>Ahora mismo: <b><?= $warmOn ? 'se avisa de los leads tibios' : 'no se avisa de los leads tibios' ?></b>.
            <?= $fmcsaKey ? 'Con la llave de FMCSA un lead bueno puede llegar a caliente, así que «Automático» deja los tibios solo por correo.' : 'Aún no hay llave de FMCSA: sin verificar autoridad, seguro y antigüedad (hasta 35 puntos) un lead muy bueno suma 65 a 70 y casi nunca llega a caliente (70). Por eso «Automático» avisa también de los tibios hasta que agregues la llave.' ?></span></p></span></p>
        </fieldset>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="al-m">
      <header class="card__h"><h2 class="label" id="al-m">Correo</h2></header>
      <div class="card__b al-list">
        <label class="tgl"><input type="checkbox" name="email" value="1"<?= $a['email'] ? ' checked' : '' ?>><span class="tgl__t"><b>Avisos por correo</b><small>Leads tibios y fríos, reingresos y, si Telegram de calientes está apagado, también los calientes.</small></span></label>
        <label class="field<?= isset($errors['mail_to']) ? ' has-err' : '' ?>"><span>Correo de aviso</span><input type="email" name="mail_to" value="<?= $e($a['mail_to']) ?>" placeholder="<?= $e($envMail !== '' ? $envMail : 'nombre@dominio.com') ?>" maxlength="190" autocomplete="off"<?= $fe('mail_to') ?>><?= $em('mail_to') ?>
          <small>Déjalo vacío para usar <code>MAIL_TO</code> del servidor<?= $envMail !== '' ? ' (' . $e($envMail) . ')' : ' (hoy sin configurar)' ?>.</small></label>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="al-q">
      <header class="card__h"><h2 class="label" id="al-q">Horario de silencio</h2></header>
      <div class="card__b al-list">
        <label class="tgl"><input type="checkbox" name="quiet_enabled" value="1" data-quiet<?= $q['enabled'] ? ' checked' : '' ?>><span class="tgl__t"><b>Silenciar alertas no urgentes</b><small>El Telegram de tibios y los correos esperan a que termine el silencio. Hora de <?= $e(Fmt::zone()) ?>.</small></span></label>
        <div class="dlg__row al-q<?= isset($errors['quiet']) ? ' has-err' : '' ?>">
          <label class="field"><span>Desde</span><input type="time" name="quiet_start" value="<?= $e($q['start']) ?>"<?= $fe('quiet') ?>></label>
          <label class="field"><span>Hasta</span><input type="time" name="quiet_end" value="<?= $e($q['end']) ?>"<?= $fe('quiet') ?>></label>
        </div>
        <?= $em('quiet') ?>
      </div>
      <footer class="card__f"><span class="muted">Se aplica a lo que se encole a partir de guardar.</span>
        <button class="btn btn--primary" type="submit"><span class="btn__t">Guardar alertas</span></button></footer>
    </section>
  </fieldset>
</form>
