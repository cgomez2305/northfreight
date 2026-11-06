<?php
use Captacion\Services\Rules;

/** Valor de una condicion (Q5b). Variables: $np (prefijo del nombre del campo de la condicion), $field, $op, $value, $err (mensaje o ''). Sin datos del lead: solo catalogos cerrados. */
$type = Rules::fieldType($field);
$n = $np . '[value]';
$inv = $err !== '' ? ' aria-invalid="true"' : '';
$sel = is_array($value) ? array_map('strval', $value) : array_values(array_filter(preg_split('/[,;\s]+/', (string) $value) ?: [], static fn($x) => $x !== ''));
$one = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
$opts = Rules::options($field);
?>
<?php if ($type === null): ?>
  <span class="rc__none">Elige primero el dato</span>
<?php elseif ($op === 'exists' || $op === 'not_exists'): ?>
  <span class="rc__none">No necesita valor</span>
<?php elseif ($type === 'bool'): ?>
  <select name="<?= $e($n) ?>" aria-label="Valor"<?= $inv ?>><option value="yes"<?= $value === true || $one === 'yes' || $one === '1' ? ' selected' : '' ?>>Sí</option><option value="no"<?= $value === false || $one === 'no' || $one === '0' ? ' selected' : '' ?>>No</option></select>
<?php elseif ($type === 'number' && $op === 'between'): $u = Rules::NUM[$field][2]; $lo = is_array($value) ? (string) ($value[0] ?? '') : ''; $hi = is_array($value) ? (string) ($value[1] ?? '') : ''; ?>
  <span class="rc__between"><input type="text" inputmode="decimal" name="<?= $e($n) ?>[0]" value="<?= $e($lo) ?>" aria-label="Desde" maxlength="8" autocomplete="off"<?= $inv ?>><i aria-hidden="true">y</i><input type="text" inputmode="decimal" name="<?= $e($n) ?>[1]" value="<?= $e($hi) ?>" aria-label="Hasta" maxlength="8" autocomplete="off"<?= $inv ?>><small><?= $e($u) ?></small></span>
<?php elseif ($type === 'number'): $u = Rules::NUM[$field][2]; ?>
  <span class="rc__num"><input type="text" inputmode="decimal" name="<?= $e($n) ?>" value="<?= $e($one) ?>" aria-label="Valor" maxlength="8" autocomplete="off"<?= $inv ?>><small><?= $e($u) ?></small></span>
<?php elseif ($type === 'text'): ?>
  <input type="text" name="<?= $e($n) ?>" value="<?= $e(is_array($value) ? implode(', ', $value) : $one) ?>" aria-label="Valor" maxlength="300" autocomplete="off" placeholder="<?= $e($op === 'in' ? 'varios, separados por comas' : 'texto') ?>"<?= $inv ?>>
<?php elseif (in_array($op, ['in', 'not_in', 'all'], true) && count($opts) <= 10): ?>
  <span class="rc__checks" role="group" aria-label="Valores"><?php foreach ($opts as $k => $l): ?><label class="rc__chk"><input type="checkbox" name="<?= $e($n) ?>[]" value="<?= $e($k) ?>"<?= in_array((string) $k, $sel, true) ? ' checked' : '' ?>><span><?= $e($l) ?></span></label><?php endforeach; ?></span>
<?php elseif (in_array($op, ['in', 'not_in', 'all'], true)): ?>
  <input type="text" name="<?= $e($n) ?>" value="<?= $e(implode(', ', $sel)) ?>" aria-label="Valores" maxlength="200" autocomplete="off" placeholder="<?= $e($field === 'state' ? 'TX, TN, FL' : 'varios, separados por comas') ?>"<?= $inv ?>>
<?php else: ?>
  <select name="<?= $e($n) ?>" aria-label="Valor"<?= $inv ?>><option value="">Elige…</option><?php foreach ($opts as $k => $l): ?><option value="<?= $e($k) ?>"<?= $one === (string) $k ? ' selected' : '' ?>><?= $e($field === 'state' ? $k . ' · ' . $l : $l) ?></option><?php endforeach; ?></select>
<?php endif; ?>
