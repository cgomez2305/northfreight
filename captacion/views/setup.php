<?php use Captacion\Ui; require __DIR__ . '/_auth_open.php'; ?>
<?php if (($step ?? 'form') === 'form'): ?>
      <form class="auth__card" method="post" action="/setup.php" autocomplete="off" novalidate>
        <p class="label auth__step">Instalación · paso 1 de 2</p>
        <h1 class="auth__t">Crear el administrador</h1>
        <p class="auth__s">Solo funciona una vez, mientras no exista ningún usuario. Necesitas el <code>SETUP_TOKEN</code> de <code>config/.env</code>.</p>
        <?php if (!empty($error)): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?><?= $e($error) ?></p><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <label class="field"><span>Token de instalación</span><input type="password" name="setup_token" required autocomplete="off"></label>
        <label class="field"><span>Nombre</span><input type="text" name="name" value="<?= $e($old['name'] ?? '') ?>" required maxlength="120" autocomplete="off"></label>
        <label class="field"><span>Correo</span><input type="email" name="email" value="<?= $e($old['email'] ?? '') ?>" required autocomplete="off"></label>
        <label class="field"><span>Contraseña</span><input type="password" name="password" required minlength="12" autocomplete="new-password" aria-describedby="pw-h"><small id="pw-h">Mínimo 12 caracteres.</small></label>
        <label class="field"><span>Repite la contraseña</span><input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>
        <button class="btn btn--primary btn--block btn--lg" type="submit"><span class="btn__t">Continuar</span></button>
      </form>
<?php elseif ($step === 'confirm'): ?>
      <form class="auth__card auth__card--wide" method="post" action="/setup.php" autocomplete="off" novalidate>
        <p class="label auth__step">Instalación · paso 2 de 2</p>
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
        <h1 class="auth__t">Cuenta creada</h1>
        <p class="auth__s">El administrador está activo y esta página ya no volverá a funcionar. Entra con tu correo, tu contraseña y el código de la app.</p>
        <?php if (!empty($algoNote)): ?><p class="flash flash--warn"><?= Ui::icon('info') ?><?= $e($algoNote) ?></p><?php endif; ?>
        <p><a class="btn btn--primary btn--lg" href="/login"><span class="btn__t">Ir a entrar</span></a></p>
      </div>
<?php endif; ?>
<?php require __DIR__ . '/_auth_close.php'; ?>
