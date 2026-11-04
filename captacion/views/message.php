<main class="auth">
  <div class="card auth__card">
    <p class="brand">NorthStar <span>Captación</span></p>
    <h1><?= $e($heading ?? '') ?></h1>
    <p><?= $e($text ?? '') ?></p>
    <?php if (!empty($linkHref)): ?><p><a class="btn" href="<?= $e($linkHref) ?>"><?= $e($linkText ?? 'Continuar') ?></a></p><?php endif; ?>
  </div>
</main>
