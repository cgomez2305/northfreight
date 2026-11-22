<?php
use Captacion\Ui;
use Captacion\Services\Fmt;

/** Variables: $lead, $templates, $tpl (?array), $preview (?array), $emailBlock, $capBlock, $waBlock, $csrf */
$id = (int) $lead['id'];
$name = Fmt::name($lead);
$langs = ['en' => 'Inglés', 'es' => 'Español'];
?>
<div class="page-head">
  <div>
    <h1 class="h1">Enviar una plantilla</h1>
    <p class="sub">A <b><?= $e($name) ?></b> · <span class="mono"><?= $e(Fmt::code($id)) ?></span>. Revisa el texto con las variables ya resueltas antes de enviarlo.</p>
  </div>
  <div class="page-head__a"><a class="btn btn--ghost" href="/leads/<?= $e($id) ?>"><?= Ui::icon('x') ?>Volver a la ficha</a></div>
</div>

<section class="card" aria-labelledby="h-pick">
  <header class="card__h"><h2 class="label" id="h-pick">Plantilla</h2></header>
  <div class="card__b">
    <?php if (!$templates): ?>
      <p class="muted">No hay plantillas activas. Actívalas o créalas en Comunicaciones.</p>
    <?php else: ?>
    <form class="dsend__pick" method="get" action="/leads/<?= $e($id) ?>/enviar">
      <label class="field dsend__sel"><span>Elige la plantilla</span>
        <select name="tpl" required>
          <option value="">Elige una plantilla…</option>
          <?php foreach ($templates as $t): ?><option value="<?= $e($t['id']) ?>"<?= $tpl !== null && (int) $tpl['id'] === (int) $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?> · <?= $e($langs[$t['lang']] ?? $t['lang']) ?></option><?php endforeach; ?>
        </select></label>
      <button class="btn btn--secondary" type="submit">Ver vista previa</button>
    </form>
    <?php endif; ?>
  </div>
</section>

<?php if ($tpl !== null && $preview !== null): ?>
<section class="card" aria-labelledby="h-prev">
  <header class="card__h"><h2 class="label" id="h-prev">Vista previa</h2><span class="chip chip--plain"><?= $e($langs[$tpl['lang']] ?? $tpl['lang']) ?></span></header>
  <div class="card__b">
    <?php if ($preview['unresolved']): ?>
      <p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><span><b>Texto pendiente de confirmar:</b> <?= $e(implode(' ', array_map(static fn($m) => mb_substr((string) $m, 0, 60), array_slice($preview['unresolved'], 0, 3)))) ?>. Mientras quede, la plantilla no se envía ni se abre en WhatsApp. Edítala en Comunicaciones.</span></p>
    <?php endif; ?>
    <p class="dsend__sub"><span class="label">Asunto</span><b><?= $e($preview['subject']) ?></b></p>
    <pre class="dsend__body"><?= $e($preview['body']) ?></pre>
    <p class="foot-note">El correo lleva además el pie obligatorio con la dirección física y la baja de un clic. En WhatsApp se usa solo el texto del mensaje, sin pie.</p>
  </div>
</section>

<div class="dsend__chan">
  <section class="card" aria-labelledby="h-mail">
    <header class="card__h"><h2 class="label" id="h-mail"><?= Ui::icon('mail') ?> Correo</h2></header>
    <div class="card__b">
      <?php $why = $emailBlock ?? $capBlock ?? ($preview['unresolved'] ? 'La plantilla tiene texto pendiente de confirmar.' : null); ?>
      <?php if ($why !== null): ?>
        <p class="flash flash--warn" role="status"><?= Ui::icon('info') ?><span><b>No se puede enviar.</b> <?= $e($why) ?></span></p>
      <?php else: ?>
        <p class="muted">Se envía un correo real a <b><?= $e($lead['email']) ?></b> y queda en la línea de tiempo y en los mensajes del lead.</p>
        <form method="post" action="/leads/<?= $e($id) ?>/enviar">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tpl" value="<?= $e($tpl['id']) ?>"><input type="hidden" name="channel" value="email">
          <button class="btn btn--primary" type="submit" data-confirm="Se enviará este correo a <?= $e($lead['email']) ?>. No se puede deshacer." data-confirm-title="¿Enviar el correo?" data-confirm-label="Enviar"><span class="btn__t">Enviar correo</span></button>
        </form>
      <?php endif; ?>
    </div>
  </section>
  <section class="card" aria-labelledby="h-wa">
    <header class="card__h"><h2 class="label" id="h-wa"><?= Ui::icon('chat') ?> WhatsApp</h2></header>
    <div class="card__b">
      <?php $why = $waBlock ?? ($preview['unresolved'] ? 'La plantilla tiene texto pendiente de confirmar.' : null); ?>
      <?php if ($why !== null): ?>
        <p class="flash flash--warn" role="status"><?= Ui::icon('info') ?><span><b>No se puede abrir.</b> <?= $e($why) ?></span></p>
      <?php else: ?>
        <p class="muted">Abre WhatsApp en una pestaña nueva con el texto ya escrito; tú lo envías. El panel anota que lo abriste, no puede saber si lo enviaste.</p>
        <form method="post" action="/leads/<?= $e($id) ?>/enviar" target="_blank">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tpl" value="<?= $e($tpl['id']) ?>"><input type="hidden" name="channel" value="whatsapp">
          <button class="btn btn--secondary" type="submit"><?= Ui::icon('chat') ?>Abrir WhatsApp con este texto</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php endif; ?>
