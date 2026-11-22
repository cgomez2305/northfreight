<?php use Captacion\Ui;

/** Fase AM: página de error (404, 403, 500) DENTRO del shell para quien tiene sesión. Variables: $heading, $text, $backHref, $backText. */
?>
<section class="card empty empty--lg" aria-labelledby="err-t">
  <h1 class="h1" id="err-t"><?= $e($heading ?? '') ?></h1>
  <p><?= $e($text ?? '') ?></p>
  <p><a class="btn btn--secondary" href="<?= $e($backHref ?? '/') ?>"><?= Ui::icon('chevron-left') ?><span class="btn__t"><?= $e($backText ?? 'Volver al panel') ?></span></a></p>
</section>
