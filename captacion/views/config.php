<?php
use Captacion\Ui;

/** Variables: $tab, $tabs, $locked, $needsUnlock, $reauthLeft, $reauthTtl, $csrf + las de cada pestaña */
$err = static fn(string $path): string => $errors[$path] ?? '';
?>
<div class="page-head">
  <div>
    <h1 class="h1">Configuración</h1>
    <p class="sub">Puntuación, alertas, costos, integraciones, horario y copias. Solo administradores; cada cambio queda en el registro de actividad.</p>
  </div>
</div>

<nav class="ptabs cfg-tabs" aria-label="Secciones de configuración">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="ptab<?= $tab === $k ? ' is-on' : '' ?>" href="/config<?= $k === 'puntuacion' ? '' : '?tab=' . $e($k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($needsUnlock): ?>
  <?php if ($locked): ?>
  <section class="card cfg-unlock" aria-labelledby="unlock-t">
    <span class="cfg-unlock__i" aria-hidden="true"><?= Ui::icon('lock') ?></span>
    <div class="cfg-unlock__t"><h2 id="unlock-t">Edición bloqueada</h2><p>Para guardar cambios confirma tu identidad con tu contraseña y un código <b>nuevo</b> de tu app de autenticación. Vale <?= $e(intdiv($reauthTtl, 60)) ?> minutos. Mientras tanto puedes mirar todo.</p></div>
    <form class="cfg-unlock__f" method="post" action="/config/unlock" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tab" value="<?= $e($tab) ?>">
      <label class="field"><span>Contraseña</span><input type="password" name="password" required autocomplete="current-password"></label>
      <label class="field"><span>Código de 6 dígitos</span><input type="text" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>
      <button class="btn btn--primary" type="submit"><span class="btn__t">Confirmar</span></button>
    </form>
  </section>
  <?php else: ?>
  <div class="cfg-ok" role="status"><?= Ui::icon('check-circle') ?><span>Edición habilitada · quedan <b data-left="<?= $e($reauthLeft) ?>"><?= $e(max(1, (int) ceil($reauthLeft / 60))) ?> min</b></span>
    <form method="post" action="/config/lock"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tab" value="<?= $e($tab) ?>"><button class="link" type="submit">Bloquear ahora</button></form></div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/config/_' . $tab . '.php'; ?>
