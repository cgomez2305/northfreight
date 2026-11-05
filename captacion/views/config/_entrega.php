<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

/** Variables: $hand (email, webhook_url, webhook_on, secret_set), $handView (counts, recent, last_test, waiting), $once, $errors, $locked, $csrf */
$fe = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="ee-' . $k . '"' : '';
$em = static fn(string $k) => isset($errors[$k]) ? '<small class="num__e" id="ee-' . $k . '">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</small>' : '';
$st = ['pending' => 'En cola', 'retrying' => 'Reintentando', 'sent' => 'Enviada', 'partial' => 'Parcial', 'failed' => 'Falló', 'no_target' => 'Sin destino', 'skipped' => 'Omitida'];
$conf = ['signed' => 'Firmó', 'rejected' => 'Rechazado', 'superseded' => 'Reemplazada'];
$tn = ['email' => 'Correo', 'webhook' => 'Webhook', 'dispatch_crm' => 'CRM de dispatch'];
$lt = $handView['last_test'];
$active = ($hand['email'] !== '' ? 1 : 0) + (($hand['webhook_on'] && $hand['webhook_url'] !== '' && $hand['secret_set']) ? 1 : 0);
?>
<p class="cfg-intro">A dónde va la ficha de un lead cuando lo pasas a «Presentado a NorthStar» (documento maestro §11). NorthStar confirma <b>Firmó</b> o <b>Lo rechazamos</b> con un clic desde el correo, sin iniciar sesión. Los documentos de los conductores nunca pasan por este sistema.</p>
<?php if ($active === 0): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><span><b>Destino de entrega sin configurar.</b> Mientras no haya un correo o un webhook, los leads que presentes quedan en «pendiente» y no se envía nada a nadie.<?php if ($handView['waiting'] > 0): ?> Hay <b><?= $e($handView['waiting']) ?></b> esperando destino.<?php endif; ?></span></p><?php endif; ?>
<?php if (!empty($once) && $once['kind'] === 'secret'): ?>
  <section class="card cfg-card en-once" aria-labelledby="en-once"><header class="card__h"><h2 class="label" id="en-once">SECRETO DE FIRMA NUEVO · SE MUESTRA UNA SOLA VEZ</h2></header>
    <div class="card__b"><p class="secret"><code><?= $e($once['value']) ?></code></p><p class="muted">Cópialo ahora en tu n8n (o donde recibas el webhook). Después solo se verá «configurado»; si lo pierdes, genera otro.</p></div></section>
<?php endif; ?>

<form method="post" action="/config/handoff/save" class="cfg-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="en-m">
      <header class="card__h"><h2 class="label" id="en-m">1 · CORREO A NORTHSTAR</h2><span class="chip chip--<?= $hand['email'] !== '' ? 'warm' : 'plain' ?>"><i class="dot"></i><?= $hand['email'] !== '' ? 'Activo' : 'Sin configurar' ?></span></header>
      <div class="card__b">
        <label class="field<?= isset($errors['email']) ? ' has-err' : '' ?>"><span>Correo del equipo de NorthStar que recibe la ficha</span><input type="email" name="email" value="<?= $e($hand['email']) ?>" maxlength="190" autocomplete="off" placeholder="equipo@dominio.com"<?= $fe('email') ?>><?= $em('email') ?>
          <small>Lleva los botones «Firmó» y «Lo rechazamos». Sin SMTP en el servidor, la ficha queda en <code>storage/mail.log</code> (no sale).</small></label>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="en-w">
      <header class="card__h"><h2 class="label" id="en-w">2 · WEBHOOK FIRMADO (n8n → Google Sheet)</h2><span class="chip chip--<?= ($hand['webhook_on'] && $hand['secret_set']) ? 'warm' : 'plain' ?>"><i class="dot"></i><?= ($hand['webhook_on'] && $hand['secret_set']) ? 'Activo' : 'Apagado' ?></span></header>
      <div class="card__b en-grid">
        <label class="tgl"><input type="checkbox" name="webhook_on" value="1"<?= $hand['webhook_on'] ? ' checked' : '' ?>><span class="tgl__t"><b>Enviar también por webhook</b><small>POST JSON (<code>northstar.handoff.v1</code>) firmado con HMAC-SHA256. Reintenta a 1, 5 y 15 minutos.</small></span></label>
        <label class="field<?= isset($errors['webhook_url']) ? ' has-err' : '' ?>"><span>URL del webhook</span><input type="url" name="webhook_url" value="<?= $e($hand['webhook_url']) ?>" maxlength="300" autocomplete="off" placeholder="https://n8n.ejemplo.com/webhook/northstar"<?= $fe('webhook_url') ?>><?= $em('webhook_url') ?></label>
        <fieldset class="warm en-secret<?= isset($errors['webhook_secret']) ? ' has-err' : '' ?>"><legend>Secreto de firma <span class="chip chip--<?= $hand['secret_set'] ? 'warm' : 'plain' ?>"><?= $hand['secret_set'] ? 'configurado' : 'sin configurar' ?></span></legend>
          <div class="seg seg--wrap warm__o">
            <label class="warm__i"><input type="radio" name="secret_mode" value="keep" checked><span>Dejar como está</span></label>
            <label class="warm__i"><input type="radio" name="secret_mode" value="generate"><span>Generar uno nuevo</span></label>
            <label class="warm__i"><input type="radio" name="secret_mode" value="set"><span>Escribir uno</span></label>
            <label class="warm__i"><input type="radio" name="secret_mode" value="clear"><span>Borrar</span></label>
          </div>
          <label class="field"><span>Secreto (solo con «Escribir uno»; mínimo 16 caracteres)</span><input type="password" name="webhook_secret" value="" autocomplete="new-password" maxlength="200"<?= $fe('webhook_secret') ?>><?= $em('webhook_secret') ?></label>
          <p class="muted">El secreto se guarda cifrado y nunca se muestra después de guardarlo.</p>
        </fieldset>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="en-c">
      <header class="card__h"><h2 class="label" id="en-c">3 · CRM DE DISPATCH</h2><span class="chip chip--plain"><i class="dot"></i>No activo</span></header>
      <div class="card__b"><p class="muted">El CRM grande de NorthStar está en pausa. El código (<code>Handoff/DispatchCrmTarget.php</code>) trae el esqueleto documentado; cuando exista, solo cambia ese archivo.</p></div>
      <footer class="card__f"><span class="muted"><?= $active ?> destino<?= $active === 1 ? '' : 's' ?> activo<?= $active === 1 ? '' : 's' ?>.</span>
        <button class="btn btn--primary" type="submit"><span class="btn__t">Guardar destino</span><span class="btn__cell"><?= Ui::icon('check') ?></span></button></footer>
    </section>
  </fieldset>
</form>

<section class="card cfg-card" id="prueba" aria-labelledby="en-t">
  <header class="card__h"><h2 class="label" id="en-t">PRUEBA DE ENTREGA</h2></header>
  <div class="card__b">
    <p class="muted">Envía una ficha de un lead <b>ficticio</b> a cada destino activo, marcada como «PRUEBA» y sin enlaces de confirmación. Si el correo de NorthStar está activo y hay SMTP, ellos recibirán ese correo.</p>
    <?php if ($lt): ?><ul class="en-res"><?php foreach ($lt['results'] as $k => $r): ?><li><span class="chip chip--<?= $r['ok'] ? 'warm' : 'hot' ?>"><i class="dot"></i><?= $e($tn[$k] ?? $k) ?></span> <?= $e($r['msg']) ?></li><?php endforeach; ?></ul><p class="muted">Última prueba: <?= $e(Fmt::dateTime($lt['at'])) ?> · <?= $e(Fmt::ago($lt['at'])) ?></p><?php endif; ?>
    <form method="post" action="/config/handoff/test"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>><div class="en-act"><button class="btn btn--secondary" type="submit"<?= $active === 0 ? ' disabled' : '' ?>><span class="btn__t">Enviar prueba</span><span class="btn__cell"><?= Ui::icon('send') ?></span></button></div></fieldset></form>
  </div>
</section>

<section class="card cfg-card" aria-labelledby="en-s">
  <header class="card__h"><h2 class="label" id="en-s">ÚLTIMAS ENTREGAS</h2>
    <?php if ($handView['waiting'] > 0): ?><form method="post" action="/config/handoff/retry"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--sm btn--secondary" type="submit"<?= $active === 0 || $locked ? ' disabled' : '' ?>><span class="btn__t">Poner en cola las <?= $e($handView['waiting']) ?> pendientes</span></button></form><?php endif; ?></header>
  <?php if (!$handView['recent']): ?>
    <div class="empty empty--sm"><?= Ui::icon('star', 'empty__star ic--star') ?><h3>Aún no se ha presentado ningún lead</h3><p>Cuando pases uno a «Presentado a NorthStar», su entrega aparece aquí con el estado de cada destino.</p></div>
  <?php else: ?>
    <div class="tw"><table class="tbl"><thead><tr><th scope="col">Lead</th><th scope="col">Versión</th><th scope="col">Entrega</th><th scope="col">Destinos</th><th scope="col">NorthStar</th><th scope="col">Fecha</th></tr></thead><tbody>
      <?php foreach ($handView['recent'] as $h): ?>
        <tr><th scope="row" class="c-n"><a href="/leads/<?= $e($h['lead_id']) ?>"><?= $e(Fmt::name($h)) ?></a></th><td data-l="Versión">v<?= $e($h['version']) ?></td><td data-l="Entrega"><?= $e($st[$h['status']] ?? $h['status']) ?></td>
          <td data-l="Destinos"><?= $e(implode(' · ', array_map(static function ($p) use ($tn, $st) { [$t, $s] = array_pad(explode(':', $p, 2), 2, ''); return ($tn[$t] ?? $t) . ' ' . strtolower($st[$s] ?? $s); }, array_filter(explode(' · ', (string) $h['deliveries']))))) ?: '—' ?></td>
          <td data-l="NorthStar"><?= $e($h['confirm_status'] !== null ? ($conf[$h['confirm_status']] ?? $h['confirm_status']) : 'Esperando') ?></td><td class="c-when" data-l="Fecha"><?= $e(Fmt::dateTime($h['created_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="card cfg-card" aria-labelledby="en-d">
  <header class="card__h"><h2 class="label" id="en-d">PARA QUIEN RECIBE EL WEBHOOK</h2></header>
  <div class="card__b en-doc">
    <p>Cada entrega es un <code>POST</code> con <code>Content-Type: application/json</code>. Verifica la firma antes de usar el cuerpo:</p>
    <ol>
      <li><code>X-NS-Timestamp</code>: segundos Unix. Rechaza si difiere más de 5 minutos de tu reloj.</li>
      <li><code>X-NS-Signature</code>: <code>sha256=</code> + HMAC-SHA256 en hexadecimal de <code>timestamp + "." + cuerpo_sin_modificar</code>, con el secreto de arriba.</li>
      <li><code>Idempotency-Key</code> / <code>X-NS-Delivery</code>: úsalos para no duplicar filas si llega la misma entrega dos veces.</li>
    </ol>
    <p>Cuerpo: <code>schema</code> (<code>northstar.handoff.v1</code>), <code>event</code> (<code>lead.presented</code>), <code>handoff_id</code>, <code>version</code>, <code>sent_at</code>, <code>lead</code> (contacto, perfil, equipo, puntuación y desglose, FMCSA, notas, llamadas, consentimientos, atribución) y <code>confirm_urls</code> (<code>signed</code>, <code>rejected</code>). Responde con cualquier 2xx; otro código se reintenta.</p>
  </div>
</section>
