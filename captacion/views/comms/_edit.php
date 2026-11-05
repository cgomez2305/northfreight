<?php
use Captacion\Services\Templates;
use Captacion\Ui;

/** Variables: $tpl, $form, $errors, $preview, $creating, $userEmail, $csrf */
$fe = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="et-' . $k . '"' : '';
$em = static fn(string $k) => isset($errors[$k]) ? '<small class="num__e" id="et-' . $k . '">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</small>' : '';
$action = $creating ? '/comunicaciones/plantillas/new' : '/comunicaciones/plantillas/' . (int) $tpl['id'] . '/save';
$mark = static fn(string $s): string => (string) preg_replace('/(\[\[[^\]]{1,200}\]\]|\{[a-z_]{2,30}\})/', '<mark class="mk">$1</mark>', htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
?>
<div class="cm-edit">
  <form method="post" action="<?= $e($action) ?>" class="card cm-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <header class="card__h"><h2 class="label"><?= $creating ? 'NUEVA PLANTILLA' : 'EDITAR · ' . $e(strtoupper($tpl['tkey'])) . ' · ' . $e(strtoupper($tpl['lang'])) ?></h2><a class="link" href="/comunicaciones">Volver</a></header>
    <div class="card__b cm-fields">
      <label class="field<?= isset($errors['name']) ? ' has-err' : '' ?>"><span>Nombre</span><input type="text" name="name" value="<?= $e($form['name']) ?>" maxlength="120" required<?= $fe('name') ?>><?= $em('name') ?></label>
      <?php if ($creating): ?>
        <label class="field<?= isset($errors['lang']) ? ' has-err' : '' ?>"><span>Idioma</span><select name="lang" required><?php foreach (Templates::LANGS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $form['lang'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select><?= $em('lang') ?></label>
      <?php endif; ?>
      <label class="field<?= isset($errors['subject']) ? ' has-err' : '' ?>"><span>Asunto</span><input type="text" name="subject" value="<?= $e($form['subject']) ?>" maxlength="190" required<?= $fe('subject') ?>><?= $em('subject') ?></label>
      <label class="field<?= isset($errors['body']) ? ' has-err' : '' ?>"><span>Mensaje (texto plano; deja una línea en blanco entre párrafos)</span><textarea name="body" rows="14" maxlength="8000" required<?= $fe('body') ?>><?= $e($form['body']) ?></textarea><?= $em('body') ?></label>
      <label class="check"><input type="checkbox" name="active" value="1"<?= !empty($form['active']) ? ' checked' : '' ?>><span>Activa (una plantilla desactivada hace esperar a las secuencias que la usan)</span></label>
      <p class="muted">Variables: <code>{nombre}</code> <code>{equipo}</code> <code>{estado}</code> <code>{link_baja}</code> <code>{link_calculadora}</code> <code>{whatsapp}</code>. El pie con la dirección física y el enlace de baja se añade siempre, no hace falta escribirlo.</p>
    </div>
    <footer class="card__f"><span class="muted"><?= $creating ? 'La clave se genera a partir del nombre.' : 'Clave: ' . $e($tpl['tkey']) . ($tpl['kind'] === 'system' ? ' · de fábrica' : '') ?></span>
      <button class="btn btn--primary" type="submit"><span class="btn__t"><?= $creating ? 'Crear plantilla' : 'Guardar' ?></span><span class="btn__cell"><?= Ui::icon('check') ?></span></button></footer>
  </form>

  <aside class="cm-side">
    <section class="card" aria-labelledby="pv-t">
      <header class="card__h"><h2 class="label" id="pv-t">VISTA PREVIA</h2><span class="muted">Con un lead de ejemplo</span></header>
      <div class="card__b cm-prev">
        <?php if ($preview['unresolved']): ?><p class="flash flash--bad" role="status"><?= Ui::icon('alert') ?><span><b>No se enviará.</b> Hay <?= $e(count($preview['unresolved'])) ?> texto<?= count($preview['unresolved']) === 1 ? '' : 's' ?> sin resolver: <?= $e(implode(' ', array_map(static fn($x) => mb_substr($x, 0, 120), array_slice($preview['unresolved'], 0, 3)))) ?></span></p><?php else: ?><p class="flash flash--ok" role="status"><?= Ui::icon('check-circle') ?>Sin marcadores pendientes: lista para enviar.</p><?php endif; ?>
        <p class="cm-subj"><b>Asunto:</b> <?= $mark($preview['subject']) ?></p>
        <div class="cm-body"><?= nl2br($mark($preview['text'])) ?></div>
      </div>
    </section>
    <?php if (!$creating): ?>
    <section class="card" aria-labelledby="ts-t">
      <header class="card__h"><h2 class="label" id="ts-t">ENVIAR PRUEBA</h2></header>
      <div class="card__b">
        <form method="post" action="/comunicaciones/plantillas/<?= $e($tpl['id']) ?>/test" class="cm-fields">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <label class="field"><span>Enviar a</span><input type="email" name="to" value="<?= $e($userEmail) ?>" maxlength="190" required></label>
          <button class="btn btn--secondary" type="submit"><span class="btn__t">Enviar prueba</span><span class="btn__cell"><?= Ui::icon('send') ?></span></button>
          <p class="muted"><?= $canDeliver ? 'Sale por el SMTP configurado, marcada como PRUEBA.' : 'Sin SMTP: la prueba solo queda registrada en storage/mail.log.' ?></p>
        </form>
        <div class="q3a">
          <?php if ($tpl['kind'] === 'system'): ?><form method="post" action="/comunicaciones/plantillas/<?= $e($tpl['id']) ?>/restore"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--sm btn--ghost" type="submit" data-confirm="Se pierden tus cambios y vuelve el texto de fábrica. ¿Restaurar?"><?= Ui::icon('refresh') ?>Restaurar texto de fábrica</button></form>
          <?php else: ?><form method="post" action="/comunicaciones/plantillas/<?= $e($tpl['id']) ?>/delete"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--sm btn--ghost" type="submit" data-confirm="¿Eliminar esta plantilla?"><?= Ui::icon('trash') ?>Eliminar</button></form><?php endif; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>
  </aside>
</div>
