<main class="auth">
  <form class="card auth__card" method="post" action="/login/2fa" autocomplete="off">
    <p class="brand">NorthStar <span>Captación</span></p>
    <h1>Verificación en dos pasos</h1>
    <p class="muted">Escribe el código de 6 dígitos de tu app de autenticación, o un código de recuperación.</p>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field">Código
      <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="24">
    </label>
    <button class="btn" type="submit">Verificar</button>
    <p class="muted"><a href="/login">Volver</a></p>
  </form>
</main>
