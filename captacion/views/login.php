<main class="auth">
  <form class="card auth__card" method="post" action="/login" autocomplete="on">
    <p class="brand">NorthStar <span>Captación</span></p>
    <h1>Entrar</h1>
    <?php if (!empty($notice)): ?><p class="notice" role="status"><?= $e($notice) ?></p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field">Correo
      <input type="email" name="email" value="<?= $e($email ?? '') ?>" required autocomplete="username" autofocus>
    </label>
    <label class="field">Contraseña
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn" type="submit">Continuar</button>
  </form>
</main>
