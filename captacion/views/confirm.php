<?php
use Captacion\Ui;

/** Pagina publica /c/{token}. Variables: $state (form|done|invalid|used|expired|limited), $action (signed|rejected), $name, $token, $reasons, $reason, $note, $error */
require __DIR__ . '/_pub_open.php';
?>
<?php if ($state === 'form' && $action === 'signed'): ?>
      <form class="pub__card" method="post" action="/c/<?= $e($token) ?>">
        <p class="label">Confirmar firma</p>
        <h1 class="pub__t"><?= $e($name) ?></h1>
        <p class="pub__s">¿Confirmas que este lead <b>firmó</b> con NorthStar? Se registrará como conversión.</p>
        <button class="btn btn--primary btn--block btn--lg" type="submit"><span class="btn__t">Sí, firmó</span></button>
        <p class="pub__n">Si no era este lead o te equivocaste de botón, cierra esta página: no se guarda nada hasta que pulses.</p>
      </form>
<?php elseif ($state === 'form'): ?>
      <form class="pub__card" method="post" action="/c/<?= $e($token) ?>" novalidate>
        <p class="label">Rechazar lead</p>
        <h1 class="pub__t"><?= $e($name) ?></h1>
        <p class="pub__s">Cuéntanos por qué NorthStar no sigue adelante. El lead vuelve a la bandeja de captación con este motivo.</p>
        <?php if (!empty($error)): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?><?= $e($error) ?></p><?php endif; ?>
        <label class="field"><span>Motivo</span>
          <select name="reason" required><?php foreach ($reasons as $k => $label): ?><option value="<?= $e($k) ?>"<?= $reason === $k ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Nota (opcional; obligatoria con «Otro»)</span><textarea name="note" rows="3" maxlength="500"><?= $e($note) ?></textarea></label>
        <button class="btn btn--primary btn--block btn--lg" type="submit"><span class="btn__t">Rechazar lead</span></button>
      </form>
<?php elseif ($state === 'done'): ?>
      <div class="pub__card">
        <svg class="auth__mini" aria-hidden="true"><use href="#i-star"/></svg>
        <h1 class="pub__t"><?= $action === 'signed' ? 'Firma registrada' : 'Rechazo registrado' ?></h1>
        <p class="pub__s"><?= $action === 'signed' ? 'Gracias. Registramos que <b>' . $e($name) . '</b> firmó. Ya puedes cerrar esta página.' : 'Gracias. Registramos el rechazo de <b>' . $e($name) . '</b> y avisamos al equipo de captación. Ya puedes cerrar esta página.' ?></p>
      </div>
<?php elseif ($state === 'limited'): ?>
      <div class="pub__card"><h1 class="pub__t">Demasiadas visitas</h1><p class="pub__s">Espera un rato e inténtalo de nuevo.</p></div>
<?php else: ?>
      <div class="pub__card">
        <h1 class="pub__t">Enlace no válido o ya usado</h1>
        <p class="pub__s">Este enlace no existe, ya se usó o caducó. Si necesitas confirmar un lead, responde al correo original o pide un enlace nuevo al equipo de captación.</p>
      </div>
<?php endif; ?>
<?php require __DIR__ . '/_pub_close.php'; ?>
