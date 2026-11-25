<?php
use Captacion\Ui;

/** Fase AU: el módulo de referidos está apagado (por defecto). Variables: $counts (codes, refs), $csrf */
?>
<div class="page-head">
  <div>
    <h1 class="h1">Referidos</h1>
    <p class="sub">Módulo desactivado.</p>
  </div>
</div>
<section class="card empty empty--lg">
  <svg class="empty__star empty__star--lg" aria-hidden="true"><use href="#i-star"/></svg>
  <h2 class="h2">El programa de referidos está apagado</h2>
  <p>Mientras esté apagado no sale en el menú, los códigos <code>?ref=</code> que lleguen del sitio se ignoran (sin puntos de referido), no se manda el correo A12 de pedido de referidos y no se crean bonos.<?= $counts['codes'] + $counts['refs'] > 0 ? ' Lo guardado (' . $e($counts['codes']) . ' códigos y ' . $e($counts['refs']) . ' referidos) se conserva.' : '' ?></p>
  <form method="post" action="/referidos/module"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="on" value="1">
    <button class="btn btn--secondary" type="submit"><?= Ui::icon('user-plus') ?><span class="btn__t">Activar el módulo</span></button></form>
</section>
