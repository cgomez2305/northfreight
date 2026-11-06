<?php
use Captacion\Ui;

/** Grupo (un solo nivel) dentro de un bloque CUANDO / EXCEPTO. Variables: $np, $dp, $g (grupo del formulario), $errors */
$gm = ($g['mode'] ?? 'all') === 'any' ? 'any' : 'all';
?>
<div class="rg" data-group data-mode="<?= $e($gm) ?>" data-np="<?= $e($np) ?>">
  <div class="rg__h"><label class="rb__mode"><span>Grupo: se cumplen</span><select name="<?= $e($np) ?>[mode]" data-rb="mode" aria-label="Cómo se combinan las condiciones del grupo"><option value="all"<?= $gm === 'all' ? ' selected' : '' ?>>todas (Y)</option><option value="any"<?= $gm === 'any' ? ' selected' : '' ?>>alguna (O)</option></select></label>
    <button class="icon-btn icon-btn--sm" type="submit" name="op" value="del_item:<?= $e($dp) ?>" data-del="group" aria-label="Quitar este grupo" title="Quitar el grupo"><?= Ui::icon('x') ?></button></div>
  <div class="rg__items" data-items>
    <?php foreach ((array) ($g['items'] ?? []) as $ck => $c): echo \Captacion\View::render('config/_rule_cond', ['np' => $np . '[items][' . $ck . ']', 'dp' => $dp . '.items.' . $ck, 'c' => (array) $c, 'errors' => $errors, 'delOp' => 'del_item:' . $dp . '.items.' . $ck, 'e' => $e], false); endforeach; ?>
  </div>
  <button class="link rb__add" type="submit" name="op" value="add_cond:<?= $e($dp) ?>" data-add="cond-in-group"><?= Ui::icon('plus') ?>Condición del grupo</button>
  <small class="rp-err" data-err="<?= $e($dp) ?>.items"<?= ($errors[$dp . '.items'] ?? '') === '' ? ' hidden' : '' ?>><?= $e($errors[$dp . '.items'] ?? '') ?></small>
</div>
