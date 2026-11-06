<?php
use Captacion\Ui;

/** Variables: $mail, $mailWarn, $canDeliver, $smtpSet, $mailFrom, $errors, $locked, $csrf */
$fe = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="em-' . $k . '"' : '';
$em = static fn(string $k) => isset($errors[$k]) ? '<small class="num__e" id="em-' . $k . '">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</small>' : '';
$monthly = is_array($mail['monthly_to']) ? implode("\n", $mail['monthly_to']) : (string) $mail['monthly_to'];
?>
<p class="cfg-intro">Remitente, pie legal y topes de las secuencias de correo (A6 para leads tibios, A12 referidos), y los destinatarios de los informes. Reglas CAN-SPAM: pie con la dirección física, baja de un clic y solo a quien dio consentimiento de correo.</p>
<?php foreach ($mailWarn as $w): ?><p class="flash flash--<?= $w['level'] === 'bad' ? 'bad' : ($w['level'] === 'warn' ? 'warn' : 'ok') ?>" role="status"><?= Ui::icon($w['level'] === 'info' ? 'info' : 'alert') ?><?= $e($w['text']) ?></p><?php endforeach; ?>
<form method="post" action="/config/mail/save" class="cfg-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="co-a">
      <header class="card__h"><h2 class="label" id="co-a">Secuencias</h2></header>
      <div class="card__b co-list">
        <label class="tgl"><input type="checkbox" name="enabled" value="1"<?= $mail['enabled'] ? ' checked' : '' ?>><span class="tgl__t"><b>Secuencias encendidas</b><small>Si están apagadas (o falta la dirección) no se inscribe a nadie ni sale ningún correo de secuencia.</small></span></label>
        <label class="field<?= isset($errors['address']) ? ' has-err' : '' ?>"><span>Dirección física del remitente (va en el pie de cada correo)</span><textarea name="address" rows="3" maxlength="300" placeholder="NorthStar Freight Solutions LLC, calle, ciudad, estado y código postal"<?= $fe('address') ?>><?= $e($mail['address']) ?></textarea><?= $em('address') ?>
          <small>Debe ser la misma que declare el sitio. Sin ella el envío de secuencias queda deshabilitado.</small></label>
        <div class="co-two">
          <label class="field"><span>Nombre del remitente</span><input type="text" name="from_name" value="<?= $e($mail['from_name']) ?>" maxlength="80" autocomplete="off"></label>
          <label class="field<?= isset($errors['reply_to']) ? ' has-err' : '' ?>"><span>Responder a (opcional)</span><input type="email" name="reply_to" value="<?= $e($mail['reply_to']) ?>" maxlength="190" autocomplete="off"<?= $fe('reply_to') ?>><?= $em('reply_to') ?></label>
        </div>
        <div class="co-two">
          <label class="field<?= isset($errors['cap_hour']) ? ' has-err' : '' ?>"><span>Tope de correos de secuencias por hora</span><input type="text" name="cap_hour" value="<?= $e($mail['cap_hour']) ?>" inputmode="numeric" maxlength="3" autocomplete="off"<?= $fe('cap_hour') ?>><?= $em('cap_hour') ?></label>
          <label class="field<?= isset($errors['cap_lead']) ? ' has-err' : '' ?>"><span>Tope por lead cada 24 horas</span><input type="text" name="cap_lead" value="<?= $e($mail['cap_lead']) ?>" inputmode="numeric" maxlength="1" autocomplete="off"<?= $fe('cap_lead') ?>><?= $em('cap_lead') ?></label>
        </div>
        <label class="field"><span>Fuente real de tarifas semanales (opcional)</span><input type="text" name="rates_source" value="<?= $e($mail['rates_source']) ?>" maxlength="200" autocomplete="off" placeholder="Vacío = el paso del día 7 se omite"><small>Mientras esté vacío, el paso «Día 7 · Tarifas de la semana» de A6 se omite: no hay nada honesto que enviar. Si lo llenas, edita antes la plantilla <code>a6_d7</code>.</small></label>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="co-i">
      <header class="card__h"><h2 class="label" id="co-i">Informes por correo</h2></header>
      <div class="card__b co-list">
        <label class="field<?= isset($errors['weekly_to']) ? ' has-err' : '' ?>"><span>Resumen semanal y aviso de informe nuevo (a Carlos)</span><input type="email" name="weekly_to" value="<?= $e($mail['weekly_to']) ?>" maxlength="190" autocomplete="off" placeholder="<?= $e($mailFrom !== '' ? $mailFrom : 'tu@correo.com') ?>"<?= $fe('weekly_to') ?>><?= $em('weekly_to') ?><small>Vacío = el correo de avisos (Alertas / <code>MAIL_TO</code>).</small></label>
        <label class="field<?= isset($errors['monthly_to']) ? ' has-err' : '' ?>"><span>Al publicar el informe mensual, enviar el enlace a (uno por línea, máximo 10)</span><textarea name="monthly_to" rows="3" maxlength="900"<?= $fe('monthly_to') ?>><?= $e($monthly) ?></textarea><?= $em('monthly_to') ?><small>Reciben un enlace al informe: para abrirlo necesitan una cuenta de lector (pestaña Usuarios).</small></label>
      </div>
      <footer class="card__f"><span class="muted"><?= $canDeliver ? 'SMTP listo.' : ($smtpSet ? 'SMTP configurado, pero falta PHPMailer en el servidor.' : 'Sin SMTP: los correos solo se registran en storage/mail.log.') ?></span>
        <button class="btn btn--primary" type="submit"><span class="btn__t">Guardar correo</span></button></footer>
    </section>
  </fieldset>
</form>
