<?php use Captacion\Ui; require __DIR__ . '/_auth_open.php'; ?>
<?php if (($step ?? 'form') === 'form'): ?>
      <form class="auth__card" method="post" action="/invite/<?= $e($token) ?>" autocomplete="off" novalidate>
        <p class="label auth__step">Activar cuenta · paso 1 de 2</p>
        <h1 class="auth__t">Elige tu contraseña</h1>
        <p class="auth__s">Hola, <b><?= $e($invName) ?></b>. Esta cuenta (<?= $e($invEmail) ?>) solo ve los resultados y los informes de captación. Este enlace sirve una sola vez.</p>
        <?php if (!empty($error)): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?><?= $e($error) ?></p><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <label class="field"><span>Contraseña</span><input type="password" name="password" required minlength="12" autocomplete="new-password" aria-describedby="pw-h"><small id="pw-h">Mínimo 12 caracteres.</small></label>
        <label class="field"><span>Repite la contraseña</span><input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>
        <button class="btn btn--primary btn--block btn--lg" type="submit"><span class="btn__t">Continuar</span></button>
      </form>
<?php elseif ($step === 'confirm'): ?>
      <form class="auth__card auth__card--wide" method="post" action="/invite/<?= $e($token) ?>" autocomplete="off" novalidate>
        <p class="label auth__step">Activar cuenta · paso 2 de 2</p>
        <h1 class="auth__t">Activa la verificación en dos pasos</h1>
        <p class="flash flash--warn" role="alert"><?= Ui::icon('alert') ?>Guarda esto AHORA. No se vuelve a mostrar una vez confirmado el código.</p>
        <?php if (!empty($error)): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?><?= $e($error) ?></p><?php endif; ?>
        <h2 class="auth__h2"><span>1</span>Clave de la app de autenticación</h2>
        <p class="auth__s">Añádela en Google Authenticator, Authy, 1Password o similar (basada en tiempo, SHA-1, 6 dígitos).</p>
        <p class="secret" aria-label="Clave TOTP"><code><?= $e($secretGrouped) ?></code></p>
        <p class="auth__s">Desde el móvil: <a href="<?= $e($otpauth) ?>">abrir en la app de autenticación</a>.</p>
        <h2 class="auth__h2"><span>2</span>Códigos de recuperación (un solo uso)</h2>
        <ol class="codes"><?php foreach ($codes as $c): ?><li><code><?= $e($c) ?></code></li><?php endforeach; ?></ol>
        <h2 class="auth__h2"><span>3</span>Confirma</h2>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="confirm">
        <label class="field"><span>Código actual de 6 dígitos</span><input class="input--code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="7" autofocus></label>
        <button class="btn btn--primary btn--block btn--lg" type="submit"><span class="btn__t">Activar la cuenta</span></button>
      </form>
<?php else: ?>
      <div class="auth__card">
        <svg class="auth__mini" aria-hidden="true"><use href="#i-star"/></svg>
        <h1 class="auth__t">Cuenta activada</h1>
        <p class="auth__s">Ya puedes entrar con tu correo, tu contraseña y el código de la app.</p>
        <p><a class="btn btn--primary btn--lg" href="/login"><span class="btn__t">Ir a entrar</span></a></p>
      </div>
<?php endif; ?>
<?php require __DIR__ . '/_auth_close.php'; ?>
