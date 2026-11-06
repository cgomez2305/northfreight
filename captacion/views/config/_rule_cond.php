<?php
use Captacion\Services\Rules;
use Captacion\Ui;

/** Una condicion CUANDO / EXCEPTO. Variables: $np, $dp (ruta con puntos), $c (condicion del formulario), $errors, $delOp (operacion «quitar» sin JS) */
$field = isset(Rules::FIELDS[(string) ($c['field'] ?? '')]) ? (string) $c['field'] : '';
$type = $field !== '' ? Rules::fieldType($field) : null;
$ops = $type !== null ? Rules::OPS_BY_TYPE[$type] : [];
$op = in_array((string) ($c['op'] ?? ''), $ops, true) ? (string) $c['op'] : ($ops[0] ?? 'is');
$er = static fn(string $k): string => (string) ($errors[$dp . '.' . $k] ?? '');
?>
<div class="rc" data-cond data-np="<?= $e($np) ?>">
  <label class="rc__f"><span class="sr">Dato</span>
    <select name="<?= $e($np) ?>[field]" data-rc="field"<?= $er('field') !== '' ? ' aria-invalid="true"' : '' ?>>
      <?php if ($field === ''): ?><option value="" selected>Elige el dato…</option><?php endif; ?>
      <?php foreach (Rules::FIELDS as $k => $d): ?><option value="<?= $e($k) ?>"<?= $field === $k ? ' selected' : '' ?>><?= $e($d[2]) ?></option><?php endforeach; ?>
    </select></label>
  <label class="rc__o"><span class="sr">Operador</span>
    <select name="<?= $e($np) ?>[op]" data-rc="op"<?= $er('op') !== '' ? ' aria-invalid="true"' : '' ?>>
      <?php foreach ($ops as $o): ?><option value="<?= $e($o) ?>"<?= $op === $o ? ' selected' : '' ?>><?= $e(Rules::opLabel($field, $o)) ?></option><?php endforeach; ?>
    </select></label>
  <div class="rc__v" data-rc="value"><?= \Captacion\View::render('config/_rule_value', ['np' => $np, 'field' => $field, 'op' => $op, 'value' => $c['value'] ?? '', 'err' => $er('value'), 'e' => $e], false) ?></div>
  <button class="icon-btn icon-btn--sm rc__x" type="submit" name="op" value="<?= $e($delOp) ?>" data-del="cond" aria-label="Quitar esta condición" title="Quitar"><?= Ui::icon('x') ?></button>
  <small class="rp-err" data-err="<?= $e($dp) ?>.field"<?= $er('field') === '' ? ' hidden' : '' ?>><?= $e($er('field')) ?></small>
  <small class="rp-err" data-err="<?= $e($dp) ?>.op"<?= $er('op') === '' ? ' hidden' : '' ?>><?= $e($er('op')) ?></small>
  <small class="rp-err" data-err="<?= $e($dp) ?>.value"<?= $er('value') === '' ? ' hidden' : '' ?>><?= $e($er('value')) ?></small>
</div>
