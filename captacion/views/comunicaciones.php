<?php
use Captacion\Ui;

/** /comunicaciones. Variables: $tab, $tabs, $view (list|edit|enroll|sent), $warnings, $canDeliver, $csrf */
?>
<div class="page-head">
  <div>
    <h1 class="h1">Comunicaciones</h1>
    <p class="sub">Plantillas de correo en inglés y español, secuencias de seguimiento (A6 tibios, A12 referidos) y registro de envíos. Solo administradores.</p>
  </div>
  <?php if ($view === 'list'): ?><div class="page-head__a"><a class="btn btn--primary" href="/comunicaciones/plantillas/new"><span class="btn__t">Nueva plantilla</span></a></div><?php endif; ?>
</div>
<nav class="utabs" aria-label="Secciones de comunicaciones">
  <?php foreach ($tabs as $k => $label): ?><a class="utab<?= $tab === $k ? ' is-on' : '' ?>" href="/comunicaciones<?= $k === 'plantillas' ? '' : '/' . $e($k) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?>
</nav>
<?php foreach ($warnings as $w): ?><p class="flash flash--<?= $w['level'] === 'bad' ? 'bad' : ($w['level'] === 'warn' ? 'warn' : 'info') ?>" role="status"><?= Ui::icon($w['level'] === 'info' ? 'info' : 'alert') ?><span><?= $e($w['text']) ?><?= $w['level'] !== 'info' && !$canDeliver && str_contains($w['text'], 'SMTP') ? '' : '' ?></span></p><?php endforeach; ?>
<?php require __DIR__ . '/comms/_' . $view . '.php'; ?>
