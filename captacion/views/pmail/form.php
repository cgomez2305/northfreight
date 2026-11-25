<?php
use Captacion\Services\Fmt;
use Captacion\Services\ProspectMail;
use Captacion\Services\Prospects;
use Captacion\Services\UsStates;
use Captacion\Ui;

/** Borrador de campaña (nueva o existente). Variables: $c (null = nueva), $form, $errors, $aud, $preview, $sampleName, $blockers, $userEmail, $settings, $hasAddress, $canDeliver, $csrf */
$seg = $form['segment'];
$isNew = $c === null;
$action = $isNew ? '/prospectos/correos/nueva' : '/prospectos/correos/' . (int) $c['id'] . '/guardar';
$fe = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="pe-' . $k . '"' : '';
$em = static fn(string $k) => isset($errors[$k]) ? '<small class="num__e" id="pe-' . $k . '">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</small>' : '';
$mark = static fn(string $s): string => (string) preg_replace('/(\[\[[^\]]{1,200}\]\]|\{[a-z_]{2,30}\})/', '<mark class="mk">$1</mark>', htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
$ex = $aud['excluded'];
$exLabels = ['blocked' => 'no llamar, no interesados o ya leads', 'unsubscribed' => 'dados de baja', 'recent' => 'recibieron un correo hace menos de ' . $settings['cooldown_days'] . ' días', 'invalid' => 'correo inválido', 'duplicate' => 'correo repetido'];
$canLaunch = !$isNew && !$blockers;
?>
<div class="page-head">
  <div>
    <h1 class="h1"><?= $isNew ? 'Nueva campaña' : $e($c['name']) ?></h1>
    <p class="sub"><?= $isNew ? 'Elige el segmento, escribe el correo y guárdalo como borrador.' : 'Borrador · ' . $e(ProspectMail::segmentLabel($c['segment'])) ?></p>
  </div>
  <div class="page-head__a"><a class="btn btn--ghost" href="/prospectos/correos">Volver a las campañas</a></div>
</div>

<?php if (!$hasAddress || !$canDeliver): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><span><?= $e(!$hasAddress ? 'Falta la dirección física del remitente (Configuración > Correo): sin ella se puede preparar el borrador, pero no lanzarlo.' : 'No hay servidor de correo (SMTP): se puede preparar el borrador, pero no enviar la prueba ni lanzarlo.') ?></span></p><?php endif; ?>

<div class="cm-edit">
  <form method="post" action="<?= $e($action) ?>" class="card" id="pm-form" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <header class="card__h"><h2 class="label">Segmento y mensaje</h2><?php if ($isNew): ?><span class="pm-st">Empezar con: <?php foreach (ProspectMail::STARTERS as $k => $s): ?><a class="link" href="/prospectos/correos/nueva?inicio=<?= $e($k) ?>"><?= $e($s['label']) ?></a><?php endforeach; ?></span><?php endif; ?></header>
    <div class="card__b cm-fields">
      <label class="field<?= isset($errors['name']) ? ' has-err' : '' ?>"><span>Nombre de la campaña (solo lo ves tú)</span><input type="text" name="name" value="<?= $e($form['name']) ?>" maxlength="120" required<?= $fe('name') ?>><?= $em('name') ?></label>
      <fieldset class="pm-seg">
        <legend>¿A quién?</legend>
        <label class="field"><span>Flota</span><select name="fl" data-recount><option value="">Cualquiera</option><?php foreach (Prospects::FLEETS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $seg['fl'] === (string) $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Estado (EE. UU.)</span><select name="us" data-recount><option value="">Todos</option><?php foreach (UsStates::NAMES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $seg['us'] === $k ? ' selected' : '' ?>><?= $e($k) ?> · <?= $e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Autoridad</span><select name="ag" data-recount><option value="">Cualquier antigüedad</option><?php foreach (Prospects::AGES as $k => $l): ?><option value="<?= $e($k) ?>"<?= (int) $seg['ag'] === (int) $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Estado del prospecto</span><select name="tab" data-recount><?php foreach (ProspectMail::SEG_TABS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $seg['tab'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
        <button class="btn btn--secondary btn--sm pm-seg__b" type="submit" name="do" value="count" data-count-btn><?= Ui::icon('refresh') ?><span class="btn__t">Contar destinatarios</span></button>
      </fieldset>
      <label class="field<?= isset($errors['subject']) ? ' has-err' : '' ?>"><span>Asunto</span><input type="text" name="subject" value="<?= $e($form['subject']) ?>" maxlength="190" required<?= $fe('subject') ?>><?= $em('subject') ?></label>
      <label class="field<?= isset($errors['body']) ? ' has-err' : '' ?>"><span>Mensaje (texto plano; deja una línea en blanco entre párrafos)</span><textarea name="body" id="pm-body" rows="14" maxlength="20000" required<?= $fe('body') ?>><?= $e($form['body']) ?></textarea><?= $em('body') ?></label>
      <div class="pm-vars"><span class="muted">Variables (pulsa para insertar):</span><?php foreach (ProspectMail::VARS as $k => $l): if ($k === '{link_baja}') { continue; } ?><button class="pm-var" type="button" data-var="<?= $e($k) ?>" title="<?= $e($l) ?>"><?= $e($k) ?></button><?php endforeach; ?></div>
      <p class="muted">El pie con la dirección física, el motivo del correo y la baja de un clic se añade siempre. Los transportistas están en EE. UU.: escribe en inglés.</p>
    </div>
    <footer class="card__f"><span class="muted"><?= $isNew ? 'Se guarda como borrador: nada se envía todavía.' : 'Guardar cambia el borrador y pide una prueba nueva antes de lanzar.' ?></span>
      <button class="btn <?= $canLaunch ? 'btn--secondary' : 'btn--primary' ?>" type="submit" name="do" value="save"><span class="btn__t"><?= $isNew ? 'Guardar borrador' : 'Guardar cambios' ?></span></button></footer>
  </form>

  <aside class="cm-side">
    <section class="card" aria-labelledby="pm-aud">
      <header class="card__h"><h2 class="label" id="pm-aud">Destinatarios</h2></header>
      <div class="card__b pm-aud" role="status">
        <p class="pm-big"><b><?= $e(Fmt::num($aud['n'])) ?></b> <?= $aud['n'] === 1 ? 'correo saldría' : 'correos saldrían' ?></p>
        <p class="muted"><?= $e(Fmt::num($aud['in_segment'])) ?> prospectos en el segmento · <?= $e(Fmt::num($aud['with_email'])) ?> con correo en FMCSA.</p>
        <?php $exList = array_filter($ex); if ($exList): ?><ul class="pm-ex"><?php foreach ($exList as $k => $n): ?><li>No reciben <?= $e(Fmt::num($n)) ?>: <?= $e($exLabels[$k]) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($aud['capped']): ?><p class="flash flash--warn"><?= Ui::icon('alert') ?><span>Se envían como máximo <?= $e(Fmt::num(ProspectMail::MAX_RECIPIENTS)) ?> por campaña: acota el segmento o haz otra campaña después.</span></p><?php endif; ?>
        <p class="muted">A <?= $e($settings['cap_hour']) ?> por hora, <?= $aud['n'] > 0 ? 'terminaría en unas ' . $e(Fmt::num(max(1, (int) ceil($aud['n'] / $settings['cap_hour'])))) . ' h' : 'no saldría nada' ?>.</p>
      </div>
    </section>

    <section class="card" aria-labelledby="pm-pv">
      <header class="card__h"><h2 class="label" id="pm-pv">Vista previa</h2><span class="muted"><?= $sampleName !== '' ? 'Con ' . $e(\Captacion\Services\Fmt::company($sampleName)) : 'Con un prospecto de ejemplo' ?></span></header>
      <div class="card__b cm-prev">
        <?php $unres = array_values(array_filter($preview['unresolved'], static fn($x) => $x !== '{link_baja}')); if ($unres): ?><p class="flash flash--bad" role="status"><?= Ui::icon('alert') ?><span><b>No se puede lanzar.</b> Texto sin resolver: <?= $e(implode(' ', array_map(static fn($x) => mb_substr($x, 0, 80), array_slice($unres, 0, 3)))) ?></span></p><?php endif; ?>
        <p class="cm-subj"><b>Asunto:</b> <?= $mark($preview['subject']) ?></p>
        <div class="cm-body"><?= nl2br($mark($preview['text'])) ?></div>
      </div>
    </section>

    <?php if (!$isNew): ?>
    <section class="card" aria-labelledby="pm-go">
      <header class="card__h"><h2 class="label" id="pm-go">Probar y lanzar</h2></header>
      <div class="card__b cm-fields">
        <form method="post" action="/prospectos/correos/<?= $e($c['id']) ?>/prueba">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn btn--secondary btn--block" type="submit"<?= $canDeliver ? '' : ' disabled' ?>><?= Ui::icon('send') ?><span class="btn__t">Enviarme una prueba</span></button>
          <p class="muted pm-help">Llega a <?= $e($userEmail) ?> con «[PRUEBA]» en el asunto y los datos de <?= $sampleName !== '' ? $e(Fmt::company($sampleName)) : 'un prospecto de ejemplo' ?>.<?= $c['test_sent_at'] ? ' Última prueba: ' . $e(Fmt::dateTime((string) $c['test_sent_at'])) . '.' : '' ?></p>
        </form>
        <?php if ($blockers): ?>
          <ul class="pm-block" aria-label="Antes de lanzar"><?php foreach ($blockers as $b): ?><li><?= Ui::icon('alert') ?><span><?= $e($b) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <form method="post" action="/prospectos/correos/<?= $e($c['id']) ?>/lanzar">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn btn--primary btn--block" type="submit"<?= $canLaunch ? Ui::confirm(['title' => '¿Lanzar la campaña?', 'text' => 'Se enviarán ' . Fmt::num($aud['n']) . ' correos a prospectos de FMCSA, como máximo ' . $settings['cap_hour'] . ' por hora. Puedes pausarla o cancelarla mientras se envía.', 'label' => 'Lanzar']) : ' disabled' ?>><span class="btn__t">Lanzar a <?= $e(Fmt::num($aud['n'])) ?> prospectos</span></button>
        </form>
        <form method="post" action="/prospectos/correos/<?= $e($c['id']) ?>/borrar">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn btn--ghost btn--sm" type="submit"<?= Ui::confirm(['title' => '¿Eliminar el borrador?', 'text' => 'Se borra el borrador «' . $c['name'] . '». No se ha enviado nada.', 'label' => 'Eliminar', 'danger' => true]) ?>><?= Ui::icon('trash') ?>Eliminar borrador</button>
        </form>
      </div>
    </section>
    <?php endif; ?>
  </aside>
</div>
