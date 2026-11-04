<?php use Captacion\Ui; require __DIR__ . '/_auth_open.php'; ?>
      <form class="auth__card" method="post" action="/login" autocomplete="on" novalidate>
        <h1 class="auth__t">Entrar</h1>
        <p class="auth__s">Con tu correo y contraseña. Después te pediremos el código de verificación.</p>
        <?php if (!empty($notice)): ?><p class="flash flash--ok" role="status"><?= Ui::icon('info') ?><?= $e($notice) ?></p><?php endif; ?>
        <?php if (!empty($error)): ?><p class="flash flash--bad" role="alert" id="err"><?= Ui::icon('alert') ?><?= $e($error) ?></p><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="field"><span>Correo</span>
          <input type="email" name="email" value="<?= $e($email ?? '') ?>" required autocomplete="username" autofocus<?= !empty($error) ? ' aria-invalid="true" aria-describedby="err"' : '' ?>>
        </label>
        <label class="field"><span>Contraseña</span>
          <input type="password" name="password" required autocomplete="current-password"<?= !empty($error) ? ' aria-describedby="err"' : '' ?>>
        </label>
        <button class="btn btn--primary btn--block btn--lg" type="submit"><span class="btn__t">Continuar</span><span class="btn__cell"><?= Ui::icon('arrow-up-right') ?></span></button>
      </form>
<?php require __DIR__ . '/_auth_close.php'; ?>
