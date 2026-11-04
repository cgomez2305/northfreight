<?php use Captacion\Ui; require __DIR__ . '/_auth_open.php'; ?>
      <div class="auth__card">
        <svg class="auth__mini" aria-hidden="true"><use href="#i-star"/></svg>
        <h1 class="auth__t"><?= $e($heading ?? '') ?></h1>
        <p class="auth__s"><?= $e($text ?? '') ?></p>
        <?php if (!empty($linkHref)): ?><p><a class="btn btn--primary btn--lg" href="<?= $e($linkHref) ?>"><span class="btn__t"><?= $e($linkText ?? 'Continuar') ?></span><span class="btn__cell"><?= Ui::icon('arrow-up-right') ?></span></a></p>
        <?php else: ?><p><a class="btn btn--secondary" href="/">Volver al panel</a></p><?php endif; ?>
      </div>
<?php require __DIR__ . '/_auth_close.php'; ?>
