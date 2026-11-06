<?php
use Captacion\Services\Sequences;
use Captacion\Ui;

/** Variables: $cfg, $scan, $tpls, $counts, $sent, $mailOn, $fresh, $csrf */
$why = ['enrolled' => 'Ya inscritos antes', 'signed' => 'Firmados', 'disqualified' => 'Descartados (CDL o inglés)', 'rejected' => 'Rechazados por NorthStar', 'dnc' => 'Teléfono en «No llamar más»', 'stop' => 'STOP o baja de correo',
    'no_email' => 'Sin correo válido', 'no_consent' => 'Sin consentimiento de correo vigente'];
$pending = array_filter($tpls, static fn($t) => $t['pending'] !== []);
$blocked = $pending !== [];
?>
<p class="cfg-intro">A11: a los owner-operators y carriers <b>perdidos hace <?= $e($cfg['min_days']) ?> días o más</b> se les escribe una vez para preguntar si ya les interesa hablar. Reutiliza el motor de las secuencias: consentimiento de correo vigente, dirección física, baja de un clic y topes de envío. Los textos son prudentes y llevan un marcador <code>[[CONFIRMAR]]</code>: <b>no se envía nada hasta que los edites</b>.</p>
<div class="cm-seqs">
  <section class="card" aria-labelledby="re-sw">
    <header class="card__h"><h2 class="label" id="re-sw">INTERRUPTOR</h2><span class="stat stat--<?= $cfg['enabled'] ? 'ok' : 'off' ?>"><?= Ui::icon($cfg['enabled'] ? 'check-circle' : 'minus') ?><?= $cfg['enabled'] ? 'Encendida' : 'Apagada' ?></span></header>
    <div class="card__b">
      <form method="post" action="/comunicaciones/reactivacion" class="cm-sw">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="check check--box"><input type="checkbox" name="enabled" value="1"<?= $cfg['enabled'] ? ' checked' : '' ?><?= $fresh ? '' : ' disabled' ?>><span>Enviar la reactivación</span></label>
        <label class="field"><span>Días desde que se perdió</span><input type="number" name="min_days" min="30" max="365" value="<?= $e($cfg['min_days']) ?>"<?= $fresh ? '' : ' disabled' ?>></label>
        <button class="btn btn--primary btn--sm" type="submit"<?= $fresh ? '' : ' disabled' ?>><span class="btn__t">Guardar</span><span class="btn__cell"><?= Ui::icon('check') ?></span></button>
      </form>
      <?php if (!$fresh): ?><p class="muted cm-note">Para cambiarlo, confirma tu identidad en <a href="/config?tab=correo">Configuración &gt; Correo</a>.</p><?php endif; ?>
      <?php if (!$mailOn): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><span>Las secuencias de correo están apagadas o falta la dirección física: aunque enciendas esto, no se enviará nada.</span></p><?php endif; ?>
      <?php if ($blocked): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><span>Texto pendiente de confirmar en <?= $e(count($pending)) ?> plantilla<?= count($pending) === 1 ? '' : 's' ?>: los pasos esperan (se revisan cada hora) hasta que los edites.</span></p><?php endif; ?>
    </div>
  </section>
  <section class="card" aria-labelledby="re-q">
    <header class="card__h"><h2 class="label" id="re-q">QUIÉN ENTRA</h2><span class="muted"><?= $e($scan['total']) ?> perdidos hace <?= $e($cfg['min_days']) ?>+ días</span></header>
    <div class="card__b">
      <p class="re-big"><b><?= $e(count($scan['eligible'])) ?></b> elegible<?= count($scan['eligible']) === 1 ? '' : 's' ?></p>
      <dl class="re-d"><?php foreach ($why as $k => $l): ?><div><dt><?= $e($l) ?></dt><dd><?= $e($scan['excluded'][$k] ?? 0) ?></dd></div><?php endforeach; ?></dl>
      <p class="muted cm-note">Solo pipeline A (owner-operators y carriers). Inscritos: <?= $e(($counts['active'] ?? 0)) ?> activos · <?= $e($counts['paused'] ?? 0) ?> en pausa · <?= $e($counts['completed'] ?? 0) ?> completados · <?= $e($counts['stopped'] ?? 0) ?> detenidos · <?= $e($sent) ?> correos enviados. Se pausa sola si el lead se reabre, responde o se da de baja.</p>
    </div>
  </section>
  <section class="card" aria-labelledby="re-t">
    <header class="card__h"><h2 class="label" id="re-t">PLANTILLAS</h2></header>
    <div class="card__b">
      <ul class="re-tpl"><?php foreach ($tpls as $t): ?>
        <li><span><b><?= $e($t['step']['label']) ?></b> · <?= $e(strtoupper($t['lang'])) ?></span>
          <?php if ($t['tpl'] === null): ?><span class="stat stat--bad"><?= Ui::icon('alert') ?>Falta</span>
          <?php else: ?><?= $t['pending'] ? '<span class="stat stat--warn">' . Ui::icon('alert') . 'Texto pendiente</span>' : '<span class="stat stat--ok">' . Ui::icon('check-circle') . 'Lista</span>' ?> <a class="btn btn--secondary btn--sm" href="/comunicaciones/plantillas/<?= $e((int) $t['tpl']['id']) ?>">Editar</a><?php endif; ?></li>
      <?php endforeach; ?></ul>
    </div>
  </section>
</div>
