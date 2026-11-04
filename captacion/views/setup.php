<main class="auth">
  <?php if (($step ?? 'form') === 'form'): ?>
  <form class="card auth__card" method="post" action="/setup.php" autocomplete="off">
    <p class="brand">NorthStar <span>Captación</span></p>
    <h1>Crear el administrador</h1>
    <p class="muted">Solo funciona una vez, mientras no exista ningún usuario. Necesitas el <code>SETUP_TOKEN</code> del archivo <code>config/.env</code>.</p>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="create">
    <label class="field">Token de instalación
      <input type="password" name="setup_token" required autocomplete="off">
    </label>
    <label class="field">Nombre
      <input type="text" name="name" value="<?= $e($old['name'] ?? '') ?>" required maxlength="120" autocomplete="off">
    </label>
    <label class="field">Correo
      <input type="email" name="email" value="<?= $e($old['email'] ?? '') ?>" required autocomplete="off">
    </label>
    <label class="field">Contraseña (mínimo 12 caracteres)
      <input type="password" name="password" required minlength="12" autocomplete="new-password">
    </label>
    <label class="field">Repite la contraseña
      <input type="password" name="password2" required minlength="12" autocomplete="new-password">
    </label>
    <button class="btn" type="submit">Continuar</button>
  </form>
  <?php elseif ($step === 'confirm'): ?>
  <form class="card auth__card auth__card--wide" method="post" action="/setup.php" autocomplete="off">
    <p class="brand">NorthStar <span>Captación</span></p>
    <h1>Activa la verificación en dos pasos</h1>
    <p class="alert" role="alert">Guarda esto AHORA. No se vuelve a mostrar una vez confirmado el código.</p>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <h2>1. Clave de la app de autenticación</h2>
    <p>Añádela en Google Authenticator, Authy, 1Password o similar (tipo «basada en tiempo», SHA-1, 6 dígitos).</p>
    <p class="secret" aria-label="Clave TOTP"><code><?= $e($secretGrouped) ?></code></p>
    <p class="muted">Desde el móvil: <a href="<?= $e($otpauth) ?>">abrir en la app de autenticación</a> (enlace otpauth://).</p>
    <h2>2. Códigos de recuperación (un solo uso cada uno)</h2>
    <ol class="codes"><?php foreach ($codes as $c): ?><li><code><?= $e($c) ?></code></li><?php endforeach; ?></ol>
    <h2>3. Confirma</h2>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="confirm">
    <label class="field">Código actual de 6 dígitos
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="7" autofocus>
    </label>
    <button class="btn" type="submit">Activar la cuenta</button>
  </form>
  <?php else: ?>
  <div class="card auth__card">
    <p class="brand">NorthStar <span>Captación</span></p>
    <h1>Cuenta creada</h1>
    <p>El administrador está activo y esta página ya no volverá a funcionar. Entra con tu correo, tu contraseña y el código de la app.</p>
    <?php if (!empty($algoNote)): ?><p class="notice"><?= $e($algoNote) ?></p><?php endif; ?>
    <p><a class="btn" href="/login">Ir a entrar</a></p>
  </div>
  <?php endif; ?>
</main>
