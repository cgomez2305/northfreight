<?php
use Captacion\Services\Catalog;
use Captacion\Services\Rules;
use Captacion\Ui;

/** Una accion ENTONCES. Variables: $np, $dp, $a (del formulario), $kind (tipo de conjunto), $errors, $delOp */
$type = in_array((string) ($a['type'] ?? ''), Rules::KIND_ACTIONS[$kind] ?? [], true) ? (string) $a['type'] : '';
$er = static fn(string $k): string => (string) ($errors[$dp . '.' . $k] ?? '');
$f = static fn(string $k): string => (string) ($a[$k] ?? '');
$vars = implode(' ', array_map(static fn($k) => '{' . $k . '}', array_keys(Rules::VARS)));
?>
<div class="ra" data-act data-np="<?= $e($np) ?>">
  <label class="ra__t"><span class="sr">Acción</span>
    <select name="<?= $e($np) ?>[type]" data-ra="type"<?= $er('type') !== '' ? ' aria-invalid="true"' : '' ?>>
      <?php if ($type === ''): ?><option value="" selected>Elige la acción…</option><?php endif; ?>
      <?php foreach (Rules::KIND_ACTIONS[$kind] ?? [] as $t): ?><option value="<?= $e($t) ?>"<?= $type === $t ? ' selected' : '' ?>><?= $e(Rules::ACTIONS[$t]) ?></option><?php endforeach; ?>
    </select></label>
  <div class="ra__p" data-ra="params">
  <?php if ($type === 'add_points'): ?>
    <label class="ra__f"><span>Puntos (−<?= $e(Rules::MAX_POINTS) ?> a <?= $e(Rules::MAX_POINTS) ?>)</span><input type="text" inputmode="numeric" name="<?= $e($np) ?>[points]" value="<?= $e($f('points')) ?>" maxlength="4" autocomplete="off"<?= $er('points') !== '' ? ' aria-invalid="true"' : '' ?>></label>
    <label class="ra__f"><span>Tope (opcional)</span><input type="text" inputmode="numeric" name="<?= $e($np) ?>[cap]" value="<?= $e($f('cap')) ?>" maxlength="3" placeholder="sin tope" autocomplete="off"<?= $er('cap') !== '' ? ' aria-invalid="true"' : '' ?>></label>
    <p class="ra__h">Los puntos se suman a la puntuación base. Con tope: al sumar, no pasa de ese valor; al restar, no baja de él. Todas las reglas juntas ajustan como máximo ±<?= $e(Rules::MAX_ADJUST) ?>.</p>
  <?php elseif ($type === 'min_temperature'): ?>
    <label class="ra__f"><span>Al menos</span><select name="<?= $e($np) ?>[level]"><option value="warm"<?= $f('level') === 'warm' ? ' selected' : '' ?>>Tibio</option><option value="hot"<?= $f('level') === 'hot' ? ' selected' : '' ?>>Caliente</option></select></label>
    <p class="ra__h">Sube la temperatura si estaba por debajo; nunca la baja ni cambia la puntuación. «Caliente» activa las alertas de siempre.</p>
  <?php elseif ($type === 'telegram_alert'): ?>
    <label class="ra__f"><span>Urgencia</span><select name="<?= $e($np) ?>[urgency]"><option value="normal"<?= $f('urgency') !== 'urgent' ? ' selected' : '' ?>>Normal (espera al horario de silencio)</option><option value="urgent"<?= $f('urgency') === 'urgent' ? ' selected' : '' ?>>Urgente (sale ya)</option></select></label>
    <label class="ra__f ra__f--wide"><span>Texto del aviso</span><input type="text" name="<?= $e($np) ?>[text]" value="<?= $e($f('text')) ?>" maxlength="<?= $e(Rules::MAX_TEXT) ?>" autocomplete="off" placeholder="Flota de {camiones} camiones: {nombre} ({estado})"<?= $er('text') !== '' ? ' aria-invalid="true"' : '' ?>></label>
    <p class="ra__h">Variables: <code><?= $e($vars) ?></code>. Se envía el nombre de la regla, este texto y el enlace al lead; nunca teléfono ni correo. Tope de <?= $e(\Captacion\Services\RuleEffects::ALERT_CAP) ?> alertas de reglas por hora.</p>
  <?php elseif ($type === 'create_task'): ?>
    <label class="ra__f ra__f--wide"><span>Título de la tarea</span><input type="text" name="<?= $e($np) ?>[title]" value="<?= $e($f('title')) ?>" maxlength="120" autocomplete="off" placeholder="Llamar a {nombre} ({perfil})"<?= $er('title') !== '' ? ' aria-invalid="true"' : '' ?>></label>
    <label class="ra__f"><span>Vence en (horas)</span><input type="text" inputmode="numeric" name="<?= $e($np) ?>[due_hours]" value="<?= $e($f('due_hours')) ?>" maxlength="3" autocomplete="off"<?= $er('due_hours') !== '' ? ' aria-invalid="true"' : '' ?>></label>
    <label class="ra__f"><span>Prioridad</span><select name="<?= $e($np) ?>[priority]"><?php foreach (Catalog::PRIORITIES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f('priority') === (string) $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <p class="ra__h">Variables: <code><?= $e($vars) ?></code>. Solo se crea para leads nuevos o que cambian, una vez por regla y lead.</p>
  <?php elseif ($type === 'tag'): ?>
    <label class="ra__f"><span>Etiqueta</span><input type="text" name="<?= $e($np) ?>[tag]" value="<?= $e($f('tag')) ?>" maxlength="24" autocomplete="off" placeholder="revisar-fmcsa"<?= $er('tag') !== '' ? ' aria-invalid="true"' : '' ?>></label>
    <p class="ra__h">Se ve en el lead como «Etiqueta: …». Se quita sola si la regla deja de coincidir.</p>
  <?php elseif ($type === 'review_queue'): ?>
    <p class="ra__h">El lead queda marcado «Por revisar» y entra en la vista del mismo nombre.</p>
  <?php else: ?>
    <p class="ra__h">Elige qué debe pasar.</p>
  <?php endif; ?>
  </div>
  <button class="icon-btn icon-btn--sm ra__x" type="submit" name="op" value="<?= $e($delOp) ?>" data-del="act" aria-label="Quitar esta acción" title="Quitar"><?= Ui::icon('x') ?></button>
  <small class="rp-err" data-err="<?= $e($dp) ?>.type"<?= $er('type') === '' ? ' hidden' : '' ?>><?= $e($er('type')) ?></small>
  <?php foreach (['points', 'cap', 'text', 'title', 'due_hours', 'tag', 'level'] as $k): ?><small class="rp-err" data-err="<?= $e($dp) ?>.<?= $e($k) ?>"<?= $er($k) === '' ? ' hidden' : '' ?>><?= $e($er($k)) ?></small><?php endforeach; ?>
</div>
