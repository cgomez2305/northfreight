<?php
use Captacion\Ui;

/** Contenido del menu de usuario (barra lateral y barra superior): quien es, el tema, los atajos y salir. */
$tp = $prefs['theme'] ?? 'auto';
?>
<div class="dd__head"><b><?= $e($user['name']) ?></b><small><?= $e($user['email']) ?></small></div>
<div class="dd__theme" role="group" aria-label="Tema de color">
  <span class="label">Tema</span>
  <div class="seg seg--tight">
    <?php foreach (['light' => ['sun', 'Claro'], 'dark' => ['moon', 'Oscuro'], 'auto' => ['monitor', 'Auto']] as $k => [$ic, $l]): ?>
      <button class="seg__i<?= $tp === $k ? ' is-on' : '' ?>" type="button" data-theme-set="<?= $e($k) ?>" aria-pressed="<?= $tp === $k ? 'true' : 'false' ?>"><?= Ui::icon($ic) ?><?= $e($l) ?></button>
    <?php endforeach; ?>
  </div>
</div>
<?php if (empty($isViewer)): $kon = ($prefs['keys'] ?? 'on') !== 'off'; ?>
<a class="dd__item" href="/#cola"><i class="dot<?= !empty($sysOk) ? ' is-ok' : '' ?>"></i>Estado del sistema<span class="dd__state"><?= !empty($sysOk) ? 'Al día' : 'Revisar' ?></span></a>
<button class="dd__item" type="button" data-keys><?= Ui::icon('keyboard') ?>Ver los atajos de teclado<kbd>?</kbd></button>
<button class="dd__item dd__item--sw" type="button" role="switch" aria-checked="<?= $kon ? 'true' : 'false' ?>" data-keys-toggle><?= Ui::icon('zap') ?><span class="dd__sw-t">Atajos de una tecla</span><span class="dd__state" data-keys-state><?= $kon ? 'Activados' : 'Desactivados' ?></span></button>
<?php endif; ?>
<form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="dd__item" type="submit"><?= Ui::icon('logout') ?>Salir</button></form>
