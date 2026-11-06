<?php use Captacion\Ui; require __DIR__ . '/_auth_open.php'; ?>
      <form class="auth__card" method="post" action="/login/2fa" autocomplete="off" novalidate>
        <p class="label auth__step">Paso 2 de 2</p>
        <h1 class="auth__t">Verificación en dos pasos</h1>
        <p class="auth__s">Escribe el código de 6 dígitos de tu app de autenticación, o uno de tus códigos de recuperación.</p>
        <?php if (!empty($error)): ?><p class="flash flash--bad" role="alert" id="err"><?= Ui::icon('alert') ?><?= $e($error) ?></p><?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="field"><span>Código</span>
          <input class="input--code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="24" placeholder="000 000"<?= !empty($error) ? ' aria-invalid="true" aria-describedby="err"' : '' ?>>
        </label>
        <button class="btn btn--primary btn--block btn--lg" type="submit"><span class="btn__t">Verificar</span></button>
        <p class="auth__alt"><a href="/login">Volver al inicio de sesión</a></p>
      </form>
<?php require __DIR__ . '/_auth_close.php'; ?>
