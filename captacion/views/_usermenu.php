<?php
use Captacion\Ui;

/** Contenido del menu de usuario (barra lateral y barra superior): quien es, el tema, los atajos y salir. */
$tp = $prefs['theme'] ?? 'auto';
?>
<div class="dd__head"><b><?= $e($user['name']) ?></b><small><?= $e($user['email']) ?></small></div>
<div class="dd__theme" role="group" aria-label="Tema de color">
  <span class="label">TEMA</span>
  <div class="seg seg--tight">
    <?php foreach (['light' => ['sun', 'Claro'], 'dark' => ['moon', 'Oscuro'], 'auto' => ['monitor', 'Auto']] as $k => [$ic, $l]): ?>
      <button class="seg__i<?= $tp === $k ? ' is-on' : '' ?>" type="button" data-theme-set="<?= $e($k) ?>" aria-pressed="<?= $tp === $k ? 'true' : 'false' ?>"><?= Ui::icon($ic) ?><?= $e($l) ?></button>
    <?php endforeach; ?>
  </div>
</div>
<?php if (empty($isViewer)): ?><button class="dd__item" type="button" role="menuitem" data-keys><?= Ui::icon('keyboard') ?>Atajos de teclado<kbd>?</kbd></button><?php endif; ?>
<form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="dd__item" type="submit" role="menuitem"><?= Ui::icon('logout') ?>Salir</button></form>
