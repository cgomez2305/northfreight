<?php
use Captacion\Services\RuleForm;
use Captacion\Ui;

/** Bloque CUANDO o EXCEPTO. Variables: $np (rules[K][when]), $dp (rules.K.when), $block (del formulario), $errors, $which ('when'|'except'), $key */
$items = is_array($block['items'] ?? null) ? $block['items'] : [];
$mode = ($block['mode'] ?? 'all') === 'any' ? 'any' : 'all';
$er = (string) ($errors[$dp . '.items'] ?? '');
$render = static function (array $c, string $cnp, string $cdp, string $delOp) use ($errors, $e): string {
    return \Captacion\View::render('config/_rule_cond', ['np' => $cnp, 'dp' => $cdp, 'c' => $c, 'errors' => $errors, 'delOp' => $delOp, 'e' => $e], false);
};
?>
<div class="rb" data-block data-which="<?= $e($which) ?>" data-mode="<?= $e($mode) ?>" data-np="<?= $e($np) ?>">
  <label class="rb__mode"><span><?= $which === 'except' ? 'Se anula si se cumplen' : 'Se cumplen' ?></span>
    <select name="<?= $e($np) ?>[mode]" data-rb="mode" aria-label="Cómo se combinan las condiciones"><option value="all"<?= $mode === 'all' ? ' selected' : '' ?>>todas (Y)</option><option value="any"<?= $mode === 'any' ? ' selected' : '' ?>>alguna (O)</option></select></label>
  <div class="rb__items" data-items>
    <?php foreach ($items as $ik => $it):
        $inp = $np . '[items][' . $ik . ']';
        $idp = $dp . '.items.' . $ik;
        if (is_array($it) && array_key_exists('items', $it)): ?>
      <?= \Captacion\View::render('config/_rule_group', ['np' => $inp, 'dp' => $idp, 'g' => $it, 'errors' => $errors, 'e' => $e], false) ?>
    <?php else: echo $render((array) $it, $inp, $idp, 'del_item:' . $idp); endif; endforeach; ?>
  </div>
  <div class="rb__acts">
    <button class="link" type="submit" name="op" value="add_cond:<?= $e($dp) ?>" data-add="cond"><?= Ui::icon('plus') ?>Añadir condición</button>
    <button class="link" type="submit" name="op" value="add_group:<?= $e($dp) ?>" data-add="group"><?= Ui::icon('plus') ?>Añadir grupo (O / Y)</button>
  </div>
  <small class="rp-err" data-err="<?= $e($dp) ?>.items"<?= $er === '' ? ' hidden' : '' ?>><?= $e($er) ?></small>
</div>
