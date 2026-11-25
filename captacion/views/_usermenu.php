<?php
use Captacion\Ui;

/**
 * Menú de la cuenta (pie de la barra lateral). Fase AT: ancho fijo de 280 px (antes heredaba el de la barra y cortaba «Auto»), tres bloques separados
 * (quién eres y el tema · sistema y atajos · salir) y la misma columna de 16 px para los iconos y el punto de estado.
 */
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
<div class="dd__grp">
  <a class="dd__item" href="/#cola"><span class="dd__ic" aria-hidden="true"><i class="dot<?= !empty($sysOk) ? ' is-ok' : '' ?>"></i></span><span class="dd__sw-t">Estado del sistema</span><span class="dd__state"><?= !empty($sysOk) ? 'Al día' : 'Revisar' ?></span></a>
  <button class="dd__item" type="button" data-keys><?= Ui::icon('keyboard') ?><span class="dd__sw-t">Atajos de teclado</span><kbd>?</kbd></button>
  <button class="dd__item dd__item--sw" type="button" role="switch" aria-checked="<?= $kon ? 'true' : 'false' ?>" data-keys-toggle><?= Ui::icon('zap') ?><span class="dd__sw-t">Atajos de una tecla</span><span class="dd__state" data-keys-state><?= $kon ? 'Activados' : 'Desactivados' ?></span></button>
</div>
<?php endif; ?>
<form class="dd__grp" method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="dd__item" type="submit"><?= Ui::icon('logout') ?>Salir</button></form>
