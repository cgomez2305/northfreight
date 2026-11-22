<?php
use Captacion\Services\Rules;
use Captacion\Ui;

/** Una regla (tarjeta). Variables: $k (clave), $r (del formulario), $errors, $kind, $phr (frases de esta regla o null), $total (reglas del conjunto) */
$np = 'rules[' . $k . ']';
$dp = 'rules.' . $k;
$er = static fn(string $f): string => (string) ($errors[$dp . '.' . $f] ?? '');
$enabled = !empty($r['enabled']) && (string) $r['enabled'] !== '0';
$stop = !empty($r['stop']) && (string) $r['stop'] !== '0';
$example = !empty($r['example']) && (string) $r['example'] !== '0';
$when = is_array($r['when'] ?? null) ? $r['when'] : ['mode' => 'all', 'items' => []];
$except = is_array($r['except'] ?? null) && !empty($r['except']['items']) ? $r['except'] : null;
$then = is_array($r['then'] ?? null) ? $r['then'] : [];
$any = false;
foreach ($errors as $p => $_) {
    if (str_starts_with($p, $dp . '.')) {
        $any = true;
        break;
    }
}
?>
<article class="rule<?= $enabled ? '' : ' is-off' ?><?= $any ? ' has-err' : '' ?>" data-rule data-key="<?= $e($k) ?>" aria-label="Regla">
  <input type="hidden" name="<?= $e($np) ?>[id]" value="<?= $e((string) ($r['id'] ?? '')) ?>">
  <input type="hidden" name="<?= $e($np) ?>[example]" value="<?= $example ? '1' : '0' ?>">
  <header class="rule__h">
    <span class="rule__grip" data-grip aria-hidden="true" title="Arrastra para cambiar la prioridad"><?= Ui::icon('grip') ?></span>
    <span class="rule__n" aria-hidden="true"></span>
    <label class="rule__name"><span class="sr">Nombre de la regla</span><input type="text" name="<?= $e($np) ?>[name]" value="<?= $e((string) ($r['name'] ?? '')) ?>" maxlength="80" placeholder="Nombre de la regla" autocomplete="off"<?= $er('name') !== '' ? ' aria-invalid="true"' : '' ?>></label>
    <?php if ($example): ?><span class="chip chip--plain rule__ex" title="Regla de ejemplo: ajusta los valores antes de activarla">ejemplo</span><?php endif; ?>
    <label class="tgl tgl--sm rule__on"><input type="hidden" name="<?= $e($np) ?>[enabled]" value="0"><input type="checkbox" name="<?= $e($np) ?>[enabled]" value="1" data-on<?= $enabled ? ' checked' : '' ?>><span class="tgl__t"><b>Activa</b></span></label>
    <span class="rule__ops">
      <button class="icon-btn icon-btn--sm" type="submit" name="op" value="up:<?= $e($k) ?>" data-move="up" aria-label="Subir la prioridad de esta regla" title="Subir"><?= Ui::icon('arrow-up') ?></button>
      <button class="icon-btn icon-btn--sm" type="submit" name="op" value="down:<?= $e($k) ?>" data-move="down" aria-label="Bajar la prioridad de esta regla" title="Bajar"><?= Ui::icon('arrow-down') ?></button>
      <button class="icon-btn icon-btn--sm" type="submit" name="op" value="dup_rule:<?= $e($k) ?>" data-dup aria-label="Duplicar esta regla" title="Duplicar"><?= Ui::icon('copy') ?></button>
      <button class="icon-btn icon-btn--sm" type="submit" name="op" value="del_rule:<?= $e($k) ?>" data-delrule aria-label="Borrar esta regla" title="Borrar"><?= Ui::icon('trash') ?></button>
    </span>
  </header>
  <small class="rp-err" data-err="<?= $e($dp) ?>.name"<?= $er('name') === '' ? ' hidden' : '' ?>><?= $e($er('name')) ?></small>
  <p class="rule__phrase" data-phrase aria-live="polite"><?php if ($phr): ?><?= $e($phr['when']) ?>. <b><?= $e($phr['then']) ?>.</b><?= $phr['except'] !== '' ? ' ' . $e($phr['except']) . '.' : '' ?><?php else: ?><span class="muted">La frase aparece cuando la regla está completa.</span><?php endif; ?></p>

<?php if ($kind === 'automation'):
    $tr = is_array($r['trigger'] ?? null) ? $r['trigger'] : ['type' => 'stage_change', 'to' => ''];
    $tt = in_array((string) ($tr['type'] ?? ''), array_keys(Rules::TRIGGERS), true) ? (string) $tr['type'] : '';
    $tnp = $np . '[trigger]';
    $tdp = $dp . '.trigger';
    $te = static fn(string $f): string => (string) ($errors[$tdp . '.' . $f] ?? ''); ?>
  <section class="rule__s" aria-label="Disparador">
    <h4 class="label rule__l">Cuando ocurre</h4>
    <div class="rt" data-trigger data-helps="<?= $e(json_encode(Rules::TRIGGER_HELP, JSON_UNESCAPED_UNICODE)) ?>">
      <label class="rt__t"><span class="sr">Evento que activa la regla</span>
        <select name="<?= $e($tnp) ?>[type]" data-rt="type"<?= $te('type') !== '' ? ' aria-invalid="true"' : '' ?>>
          <?php if ($tt === ''): ?><option value="" selected>Elige el evento…</option><?php endif; ?>
          <?php foreach (Rules::TRIGGERS as $tk => $tl): ?><option value="<?= $e($tk) ?>"<?= $tt === $tk ? ' selected' : '' ?>><?= $e($tl) ?></option><?php endforeach; ?>
        </select></label>
      <label class="rt__p" data-rt-p="stage_change"<?= $tt === 'stage_change' ? '' : ' hidden' ?>><span>A la etapa</span>
        <select name="<?= $e($tnp) ?>[to]"><option value="">cualquiera</option><?php foreach (Rules::options('stage') as $sk => $sl): ?><option value="<?= $e($sk) ?>"<?= (string) ($tr['to'] ?? '') === $sk ? ' selected' : '' ?>><?= $e($sl) ?></option><?php endforeach; ?></select></label>
      <label class="rt__p" data-rt-p="no_first_contact task_overdue"<?= in_array($tt, Rules::TIME_TRIGGERS, true) ? '' : ' hidden' ?>><span>Horas <small>(<?= $tt === 'task_overdue' ? '0 a 720' : '1 a 720' ?>)</small></span>
        <input type="text" inputmode="numeric" name="<?= $e($tnp) ?>[hours]" value="<?= $e((string) ($tr['hours'] ?? '')) ?>" maxlength="3" autocomplete="off"<?= $te('hours') !== '' ? ' aria-invalid="true"' : '' ?>></label>
      <label class="rt__p" data-rt-p="consent_revoked"<?= $tt === 'consent_revoked' ? '' : ' hidden' ?>><span>Canal</span>
        <select name="<?= $e($tnp) ?>[channel]"><option value="">cualquiera</option><?php foreach (Rules::CONSENT as $ck => $cl): ?><option value="<?= $e($ck) ?>"<?= (string) ($tr['channel'] ?? '') === $ck ? ' selected' : '' ?>><?= $e($cl) ?></option><?php endforeach; ?></select></label>
      <p class="rt__h" data-rt-help><?= $e($tt !== '' ? Rules::TRIGGER_HELP[$tt] : 'Elige qué evento activa la regla.') ?></p>
      <?php foreach (['type', 'to', 'hours', 'channel'] as $f): ?><small class="rp-err" data-err="<?= $e($tdp) ?>.<?= $e($f) ?>"<?= $te($f) === '' ? ' hidden' : '' ?>><?= $e($te($f)) ?></small><?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
  <section class="rule__s" aria-label="Cuando">
    <h4 class="label rule__l"><?= $kind === 'automation' ? 'Solo si (opcional)' : 'Cuando' ?></h4>
    <?= \Captacion\View::render('config/_rule_block', ['np' => $np . '[when]', 'dp' => $dp . '.when', 'block' => $when, 'errors' => $errors, 'which' => 'when', 'key' => $k, 'e' => $e], false) ?>
  </section>

  <section class="rule__s" aria-label="Entonces">
    <h4 class="label rule__l">Entonces</h4>
    <div class="ra__list" data-acts>
      <?php foreach ($then as $ak => $a): echo \Captacion\View::render('config/_rule_act', ['np' => $np . '[then][' . $ak . ']', 'dp' => $dp . '.then.' . $ak, 'a' => (array) $a, 'kind' => $kind, 'errors' => $errors, 'delOp' => 'del_act:' . $k . '.' . $ak, 'e' => $e], false); endforeach; ?>
    </div>
    <button class="link" type="submit" name="op" value="add_act:<?= $e($k) ?>" data-add="act"><?= Ui::icon('plus') ?>Añadir acción</button>
    <small class="rp-err" data-err="<?= $e($dp) ?>.then"<?= $er('then') === '' ? ' hidden' : '' ?>><?= $e($er('then')) ?></small>
  </section>

  <section class="rule__s rule__s--except" aria-label="Excepto" data-except-wrap>
    <h4 class="label rule__l">Excepto</h4>
    <div data-except<?= $except === null ? ' hidden' : '' ?>>
      <?php if ($except !== null): ?><?= \Captacion\View::render('config/_rule_block', ['np' => $np . '[except]', 'dp' => $dp . '.except', 'block' => $except, 'errors' => $errors, 'which' => 'except', 'key' => $k, 'e' => $e], false) ?><?php endif; ?>
      <button class="link rule__noex" type="submit" name="op" value="del_except:<?= $e($k) ?>" data-del="except"><?= Ui::icon('x') ?>Quitar la excepción</button>
    </div>
    <button class="link" type="submit" name="op" value="add_except:<?= $e($k) ?>" data-add="except"<?= $except !== null ? ' hidden' : '' ?>><?= Ui::icon('plus') ?>Añadir una excepción (anula la regla si se cumple)</button>
  </section>

  <footer class="rule__f">
    <label class="tgl tgl--sm"><input type="hidden" name="<?= $e($np) ?>[stop]" value="0"><input type="checkbox" name="<?= $e($np) ?>[stop]" value="1"<?= $stop ? ' checked' : '' ?>><span class="tgl__t"><b>Detener al coincidir</b><small>No se evalúan las reglas de abajo de este conjunto.</small></span></label>
  </footer>
</article>
