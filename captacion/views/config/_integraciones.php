<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

$lvl = ['ok' => ['ok', 'check-circle'], 'warn' => ['warn', 'alert'], 'bad' => ['bad', 'alert'], 'off' => ['off', 'minus']];
?>
<p class="cfg-intro">Estado en vivo de lo que usa el CRM. «Probar» hace un uso real (envía un mensaje, consulta un USDOT…). Nunca se muestran llaves ni contraseñas, solo si están configuradas y sus últimos 4 caracteres.</p>
<div class="intg">
  <?php foreach ($cards as $c): [$cls, $ic] = $lvl[$c['level']]; $res = $testResult !== null && $testResult['name'] === $c['key'] ? $testResult : null; ?>
    <section class="card intg__c" id="int-<?= $e($c['key']) ?>" aria-labelledby="ih-<?= $e($c['key']) ?>">
      <header class="intg__h"><h2 class="intg__t" id="ih-<?= $e($c['key']) ?>"><?= $e($c['title']) ?></h2><span class="stat stat--<?= $e($cls) ?>"><?= Ui::icon($ic) ?><?= $e($c['headline']) ?></span></header>
      <?php if (!empty($c['webhook'])): ?>
        <div class="intg__u2"><span class="intg__k">URL del webhook</span><span class="rlink"><input class="mono" type="text" readonly value="<?= $e($c['url']) ?>" aria-label="URL del webhook de <?= $e($c['title']) ?>"><button class="btn btn--secondary btn--sm" type="button" data-copy="<?= $e($c['url']) ?>"><?= Ui::icon('link') ?>Copiar</button></span></div>
      <?php endif; ?>
      <dl class="intg__d"><?php foreach ($c['details'] as [$k, $v]): ?><div><dt><?= $e($k) ?></dt><dd><?= $e($v) ?></dd></div><?php endforeach; ?></dl>
      <?php if ($c['last'] !== ''): ?><p class="intg__l"><?= Ui::icon('clock') ?><?= $e($c['last']) ?></p><?php endif; ?>
      <?php if ($c['error'] !== ''): ?><p class="intg__x"><?= Ui::icon('alert') ?>Último error: <?= $e($c['error']) ?></p><?php endif; ?>
      <?php if ($c['help'] !== ''): ?><p class="intg__h2"><?= $e($c['help']) ?></p><?php endif; ?>
      <?php if ($res !== null): ?><p class="flash flash--<?= $res['level'] === 'ok' ? 'ok' : ($res['level'] === 'warn' ? 'warn' : 'bad') ?> intg__r" role="status"><?= Ui::icon($res['level'] === 'ok' ? 'check-circle' : 'alert') ?><span><b>Prueba · <?= $e(Fmt::dateTime($res['at'], false)) ?>:</b> <?= $e($res['msg']) ?></span></p><?php endif; ?>
      <?php if (!empty($c['link'])): ?><p class="intg__lnk"><a href="<?= $e($c['link']) ?>">Ajustes de este canal<?= Ui::icon('arrow-right') ?></a></p><?php endif; ?>
      <?php if ($c['testable']): ?>
        <form class="intg__f" method="post" action="/config/integrations/test">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="name" value="<?= $e($c['key']) ?>">
          <?php if ($c['key'] === 'fmcsa'): ?><label class="field intg__u"><span>USDOT de prueba</span><input type="text" name="usdot" value="<?= $e($testUsdot) ?>" inputmode="numeric" maxlength="8" pattern="[0-9]{1,8}" autocomplete="off"></label><?php endif; ?>
          <button class="btn btn--secondary btn--sm" type="submit"><?= Ui::icon($c['key'] === 'telegram' ? 'send' : 'play') ?>Probar</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>

<?php
$whLbl = ['pending' => 'En cola', 'sent' => 'Enviada', 'failed' => 'Falló', 'skipped' => 'Omitida'];
$whErr = static fn(string $k): string => isset($whErrors[$k]) ? '<small class="num__e" id="wh-e-' . $k . '">' . htmlspecialchars($whErrors[$k], ENT_QUOTES) . '</small>' : '';
$whOn = $wh['enabled'] && $wh['url'] !== '' && $wh['secret_set'];
?>
<section class="card cfg-card whout" id="webhooks-out" aria-labelledby="wh-h">
  <header class="card__h"><h2 class="label" id="wh-h">Webhooks salientes</h2><span class="chip chip--<?= $whOn ? 'warm' : 'plain' ?>"><i class="dot"></i><?= $whOn ? 'Activos' : 'Apagados' ?></span></header>
  <div class="card__b">
    <p class="muted">Avisa a n8n u otro sistema de lo que pasa en el CRM: un POST JSON firmado (<code><?= $e(\Captacion\Services\OutboundWebhooks::SCHEMA) ?></code>) con el id del lead, su código, etapa, temperatura, fuente, puntuación y el enlace a la ficha. Reintenta a 1, 5 y 15 minutos. Es distinto del webhook de entrega a NorthStar (pestaña «Entrega a NorthStar»).</p>
    <?php if (!empty($once) && $once['kind'] === 'secret'): ?>
      <div class="whout__once" role="status"><b>Secreto de firma nuevo · se muestra una sola vez</b><p class="secret"><code><?= $e($once['value']) ?></code></p>
        <p class="muted">Cópialo ahora donde recibas el webhook. Después solo se verá «configurado»; si lo pierdes, genera otro.</p></div>
    <?php endif; ?>
    <form method="post" action="/config/webhooks/save" class="cfg-form" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
        <label class="tgl"><input type="checkbox" name="enabled" value="1"<?= $wh['enabled'] ? ' checked' : '' ?>><span class="tgl__t"><b>Enviar webhooks salientes</b><small>Necesita la URL, el secreto y al menos un evento.</small></span></label>
        <label class="field<?= isset($whErrors['url']) ? ' has-err' : '' ?>"><span>URL de destino (HTTPS)</span><input type="url" name="url" value="<?= $e($wh['url']) ?>" maxlength="300" autocomplete="off" placeholder="https://n8n.ejemplo.com/webhook/captacion"<?= isset($whErrors['url']) ? ' aria-invalid="true" aria-describedby="wh-e-url"' : '' ?>><?= $whErr('url') ?></label>
        <fieldset class="warm whout__ev<?= isset($whErrors['events']) ? ' has-err' : '' ?>"><legend>Eventos a los que está suscrito</legend>
          <?php foreach (\Captacion\Services\OutboundWebhooks::EVENTS as $ek => $el): ?>
            <label class="check"><input type="checkbox" name="events[]" value="<?= $e($ek) ?>"<?= in_array($ek, $wh['events'], true) ? ' checked' : '' ?>><span><code><?= $e($ek) ?></code> · <?= $e($el) ?></span></label>
          <?php endforeach; ?>
          <?= $whErr('events') ?>
        </fieldset>
        <label class="tgl"><input type="checkbox" name="contact" value="1"<?= $wh['contact'] ? ' checked' : '' ?>><span class="tgl__t"><b>Incluir contacto (nombre y teléfono)</b><small>Apagado por defecto: sin esto el cuerpo no lleva ningún dato personal. <code>lead.anonymized</code> nunca lo lleva.</small></span></label>
        <fieldset class="warm"><legend>Secreto de firma <span class="chip chip--<?= $wh['secret_set'] ? 'warm' : 'plain' ?>"><?= $wh['secret_set'] ? 'configurado' : 'sin configurar' ?></span></legend>
          <div class="seg seg--wrap warm__o">
            <label class="warm__i"><input type="radio" name="secret_mode" value="keep" checked><span>Dejar como está</span></label>
            <label class="warm__i"><input type="radio" name="secret_mode" value="generate"><span>Generar uno nuevo</span></label>
            <label class="warm__i"><input type="radio" name="secret_mode" value="clear"><span>Borrar</span></label>
          </div>
          <p class="muted">Firma: cabecera <code>X-NS-Signature: sha256=…</code> = HMAC-SHA256 del texto <code>&lt;X-NS-Timestamp&gt;.&lt;cuerpo&gt;</code>. Rechaza marcas de tiempo de más de 5 minutos y descarta un <code>event_id</code> repetido. Se guarda cifrado y no se vuelve a mostrar.</p>
        </fieldset>
      </fieldset>
      <footer class="card__f"><span class="muted">Guardar exige tu confirmación reciente.</span><button class="btn btn--primary" type="submit"><span class="btn__t">Guardar webhooks</span></button></footer>
    </form>
    <form method="post" action="/config/webhooks/test" class="whout__t"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>><button class="btn btn--secondary btn--sm" type="submit"<?= $wh['url'] !== '' && $wh['secret_set'] ? '' : ' disabled' ?>><?= Ui::icon('play') ?>Enviar prueba</button>
        <small class="muted">Manda un evento <code>webhook.test</code> con un lead ficticio, ahora mismo.</small></fieldset></form>
    <?php if ($whView['recent']): ?>
      <h3 class="label whout__h">Últimas entregas <span class="muted">· 7 días: <?= $e($whView['counts']['sent']) ?> enviadas, <?= $e($whView['counts']['pending']) ?> en cola, <?= $e($whView['counts']['failed']) ?> fallidas</span></h3>
      <div class="tw"><table class="tbl" aria-label="Últimas entregas del webhook saliente"><thead><tr><th scope="col">Evento</th><th scope="col">Lead</th><th scope="col">Estado</th><th scope="col">Intentos</th><th scope="col">Fecha</th></tr></thead><tbody>
        <?php foreach ($whView['recent'] as $d): ?>
          <tr><th scope="row" class="c-n"><code><?= $e($d['event']) ?></code></th><td data-l="Lead"><?= $d['lead_id'] !== null && (int) $d['lead_id'] > 0 ? '<a href="/leads/' . $e($d['lead_id']) . '">' . $e(Fmt::code((int) $d['lead_id'])) . '</a>' : '—' ?></td>
            <td data-l="Estado"><?= $e($whLbl[$d['status']] ?? $d['status']) ?><?= $d['last_error'] !== null && $d['last_error'] !== '' ? ' · ' . $e($d['last_error']) : '' ?></td><td data-l="Intentos"><?= $e($d['attempts']) ?></td><td data-l="Fecha"><?= $e(Fmt::dateTime((string) $d['created_at'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?><p class="muted">Todavía no se ha emitido ningún evento.</p><?php endif; ?>
  </div>
</section>
