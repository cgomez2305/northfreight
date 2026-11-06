<?php
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\ScoringConfig;
use Captacion\Services\UsStates;
use Captacion\Ui;

$num = static fn(string $name, mixed $val, string $path, string $label, string $unit = 'pts', bool $hide = false) => '<label class="num' . (isset($errors[$path]) ? ' has-err' : '') . '"><span' . ($hide ? ' class="sr"' : '') . '>' . $label . '</span><span class="num__in"><input type="text" inputmode="numeric" name="' . $name . '" value="' . htmlspecialchars((string) $val, ENT_QUOTES) . '" maxlength="4"' . (isset($errors[$path]) ? ' aria-invalid="true" aria-describedby="e-' . str_replace('.', '-', $path) . '"' : '') . ' autocomplete="off"><i>' . $unit . '</i></span>' . (isset($errors[$path]) ? '<small class="num__e" id="e-' . str_replace('.', '-', $path) . '">' . htmlspecialchars($errors[$path], ENT_QUOTES) . '</small>' : '') . '</label>';
$tierMax = static fn(array $t): int => max(array_map(static fn($r) => (int) $r['points'], $t) ?: [0]);
$ownerMax = array_sum(array_map('intval', array_intersect_key($form['owner'], array_flip(['cdl_standard', 'english_yes', 'fmcsa_authority_active', 'fmcsa_insured', 'dispatched_equipment', 'experience', 'referral', 'complete'])))) + $tierMax($form['owner']['authority_age']) + $tierMax($form['owner']['trucks']);
$tierRows = static function (string $g, string $tk, array $meta, array $tiers) use ($num, $errors): string {
    [$label, $unit] = $meta;
    $h = '<fieldset class="tiers"><legend>' . htmlspecialchars($label, ENT_QUOTES) . ' <small>(' . $unit . ')</small></legend>';
    $h .= '<div class="tier tier--h" aria-hidden="true"><span></span><span>Límite</span><span>Puntos</span></div>';
    foreach ($tiers as $i => $t) {
        $h .= '<div class="tier"><span class="tier__l">' . ($i < 2 ? ($i === 0 ? 'Menos de' : 'De ' . htmlspecialchars((string) ($tiers[0]['lt'] ?? '?'), ENT_QUOTES) . ' hasta menos de') : 'El resto') . '</span>';
        if ($i < 2) {
            $h .= $num($g . '[' . $tk . '][' . $i . '][lt]', $t['lt'], $g . '.' . $tk . '.' . $i . '.lt', 'Límite', $unit, true);
        } else {
            $h .= '<span></span>';
        }
        $h .= $num($g . '[' . $tk . '][' . $i . '][points]', $t['points'], $g . '.' . $tk . '.' . $i . '.points', 'Puntos', 'pts', true) . '</div>';
    }
    return $h . '</fieldset>';
};
$tempLabels = ['hot' => 'Calientes', 'warm' => 'Tibios', 'cold' => 'Fríos', 'disqualified' => 'Descartados'];
$delta = static fn(int $a, int $b): string => $a === $b ? '' : ' <span class="' . ($b > $a ? 'up' : 'down') . '">' . ($b > $a ? '+' : '−') . abs($b - $a) . '</span>';
$resultBlock = static function (array $p, string $title, string $note) use ($tempLabels, $delta, $e): string {
    $h = '<div class="pv"><p class="pv__t">' . $e($title) . '</p><table class="pv__t2"><thead><tr><th scope="col">Temperatura</th><th scope="col">Antes</th><th scope="col">Después</th></tr></thead><tbody>';
    foreach ($tempLabels as $k => $l) {
        $h .= '<tr><th scope="row">' . $l . '</th><td>' . Fmt::num($p['before'][$k]) . '</td><td>' . Fmt::num($p['after'][$k]) . $delta($p['before'][$k], $p['after'][$k]) . '</td></tr>';
    }
    return $h . '</tbody></table><p class="pv__n">' . $e($note) . '</p></div>';
};
?>
<p class="cfg-intro">Pesos del documento §6. Los owner-operators y carriers pueden sumar hasta 110 y se limitan a 100. La temperatura sale de la puntuación: caliente avisa por Telegram al instante. Mira el efecto con «Vista previa» antes de guardar.</p>

<form method="post" action="/config/scoring" class="cfg-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="sc-o">
      <header class="card__h"><h2 class="label" id="sc-o">Owner-operators y carriers pequeños</h2><span class="muted">Máximo posible: <b data-sum="owner"><?= $e($ownerMax) ?></b> (se limita a 100)</span></header>
      <div class="card__b">
        <div class="numgrid"><?php foreach (ScoringConfig::OWNER_POINTS as $k => $l) { echo $num("owner[$k]", $form['owner'][$k], "owner.$k", $e($l)); } ?>
          <?= $num('owner[experience_min_years]', $form['owner']['experience_min_years'], 'owner.experience_min_years', 'Años mínimos de experiencia', 'años') ?></div>
        <div class="tiergrid"><?php foreach (ScoringConfig::TIERS['owner'] as $tk => $meta) { echo $tierRows('owner', $tk, $meta, $form['owner'][$tk]); } ?></div>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="sc-d">
      <header class="card__h"><h2 class="label" id="sc-d">Company drivers</h2></header>
      <div class="card__b">
        <?php if (in_array('scoring.driver.experience', $provPending, true)): ?><p class="prov-note"><?= Ui::icon('alert') ?>Los tramos de experiencia están <b>pendientes de confirmar con NorthStar</b>.</p><?php endif; ?>
        <div class="numgrid"><?php foreach (ScoringConfig::DRIVER_POINTS as $k => $l) { echo $num("driver[$k]", $form['driver'][$k], "driver.$k", $e($l)); } ?></div>
        <div class="tiergrid"><?php foreach (ScoringConfig::TIERS['driver'] as $tk => $meta) { echo $tierRows('driver', $tk, $meta, $form['driver'][$tk]); } ?></div>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="sc-t">
      <header class="card__h"><h2 class="label" id="sc-t">Umbrales de temperatura</h2></header>
      <div class="card__b">
        <?php if (isset($errors['temp'])): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?><?= $e($errors['temp']) ?></p><?php endif; ?>
        <div class="numgrid numgrid--2"><?= $num('temp[hot_min]', $form['temp']['hot_min'], 'temp.hot_min', 'Caliente desde', 'pts') ?><?= $num('temp[warm_min]', $form['temp']['warm_min'], 'temp.warm_min', 'Tibio desde', 'pts') ?></div>
        <p class="muted">Frío es todo lo que queda por debajo de «tibio». «Descartado» sale solo de la regla de CDL no domiciliada o sin inglés (no depende de estos números).</p>
      </div>
      <footer class="card__f cfg-actions">
        <div class="cfg-actions__a">
          <button class="btn btn--primary" type="submit"><span class="btn__t">Guardar puntuación</span></button>
          <button class="btn btn--secondary" type="submit" formaction="/config/scoring/preview"><?= Ui::icon('refresh') ?>Vista previa</button>
        </div>
        <div class="cfg-actions__b">
          <button class="btn btn--ghost btn--sm" type="submit" formaction="/config/scoring/reset" name="group" value="all" formnovalidate data-confirm="¿Restablecer pesos de owners, drivers y umbrales a los valores del documento? Los leads existentes no cambian hasta que los recalcules.">Restablecer a los valores del documento</button>
        </div>
      </footer>
    </section>
  </fieldset>
</form>

<?php if ($preview !== null): ?>
  <section class="card cfg-card" aria-labelledby="pv-h"><header class="card__h"><h2 class="label" id="pv-h">Vista previa · nada guardado</h2></header><div class="card__b">
    <?= $resultBlock($preview, 'Con los valores del formulario, sobre los ' . Fmt::num($preview['total']) . ' leads actuales', $preview['changed'] === 0 ? 'Ningún lead cambiaría de puntuación ni de temperatura.' : Fmt::num($preview['changed']) . ' leads cambiarían de puntuación o temperatura (' . $preview['to_hot'] . ' pasarían a caliente y ' . $preview['from_hot'] . ' dejarían de serlo).') ?>
  </div></section>
<?php endif; ?>

<section class="card cfg-card" id="recalcular" aria-labelledby="rc-h">
  <header class="card__h"><h2 class="label" id="rc-h">Recalcular leads existentes</h2></header>
  <div class="card__b">
    <?php if ($recalc !== null): ?>
      <?php $running = ($recalc['status'] ?? '') === 'running'; ?>
      <div class="rc-state<?= $running ? ' is-run' : '' ?>"<?= $running ? ' data-running' : '' ?>>
        <p><b><?= $running ? 'Recalculando…' : 'Último recálculo terminado' ?></b> <?= $e(Fmt::num((int) $recalc['processed'])) ?> de <?= $e(Fmt::num((int) $recalc['total'])) ?> leads · <?= $e(Fmt::num((int) $recalc['changed'])) ?> cambiaron · <?= $e(Fmt::dateTime((string) $recalc['started_at'])) ?></p>
        <?php if ($running): ?><?php $pct = $recalc['total'] > 0 ? (int) round($recalc['processed'] / $recalc['total'] * 100) : 100; ?><progress class="rc-bar" max="100" value="<?= $e($pct) ?>"><?= $e($pct) ?> %</progress><p class="muted">Corre por la cola, 200 leads por minuto. Esta página se actualiza sola.</p>
        <?php elseif (is_array($recalc['after'] ?? null)): ?><?= $resultBlock(['before' => $recalc['before'], 'after' => $recalc['after']], 'Antes y después de ese recálculo', 'Cada lead que cambió dejó el evento «Puntuación recalculada» en su línea de tiempo. No se enviaron alertas.') ?><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($pending !== null): ?>
      <?= $resultBlock($pending, 'Hoy, con los valores guardados, sobre los ' . Fmt::num($pending['total']) . ' leads', $pending['changed'] === 0 ? 'Todos los leads ya tienen la puntuación que dan los valores guardados. No hace falta recalcular.' : Fmt::num($pending['changed']) . ' leads tienen una puntuación distinta a la que dan los valores guardados.') ?>
    <?php endif; ?>
    <form method="post" action="/config/scoring/recalc" class="cfg-form rc-form">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <fieldset class="cfg-fs"<?= $locked || $recalcRunning ? ' disabled' : '' ?>>
        <label class="check"><input type="checkbox" name="confirm" value="1" required><span>Entiendo que se vuelve a puntuar a todos los leads. No se envía ninguna alerta por esto.</span></label>
        <button class="btn btn--secondary" type="submit"><?= Ui::icon('refresh') ?><?= $recalcRunning ? 'Recálculo en marcha…' : 'Recalcular todos los leads' ?></button>
      </fieldset>
    </form>
  </div>
</section>

<section class="card cfg-card" id="provisionales" aria-labelledby="pr-h">
  <header class="card__h"><h2 class="label" id="pr-h">Ajustes provisionales</h2><?php if ($provPending): ?><span class="chip chip--warm-o"><?= Ui::icon('alert') ?>Pendientes de confirmar con NorthStar</span><?php else: ?><span class="chip chip--plain"><?= Ui::icon('check') ?>Todos confirmados</span><?php endif; ?></header>
  <div class="card__b">
    <p class="muted">Valores que el documento deja «por confirmar» con el cliente. Cámbialos cuando NorthStar los confirme y marca «Confirmado» para quitar el aviso.</p>
    <form method="post" action="/config/provisional" class="cfg-form" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
        <?php
        $pf = $provForm ?? null;
        $curEq = $pf !== null ? (array) ($pf['dispatched_equipment'] ?? []) : (array) $prov['dispatched_equipment'];
        $curStates = $pf !== null ? (string) ($pf['hiring_states'] ?? '') : implode(', ', (array) $prov['hiring_states']);
        $curYears = $pf !== null ? (string) ($pf['driver_min_years'] ?? '') : (string) $prov['driver_min_years'];
        $conf = static fn(string $k) => '<label class="check check--conf"><input type="checkbox" name="confirmed[]" value="' . $k . '"' . (!in_array($k, $provPending, true) ? ' checked' : '') . '><span>Confirmado por NorthStar</span></label>';
        ?>
        <div class="pr-item"><fieldset class="opts-eq<?= isset($provErrors['dispatched_equipment']) ? ' has-err' : '' ?>"><legend>Equipos que NorthStar despacha <?= in_array('dispatched_equipment', $provPending, true) ? '<span class="chip chip--warm-o">Provisional</span>' : '' ?></legend>
          <div class="eqgrid"><?php foreach (Catalog::EQUIPMENT as $k => $l): ?><label class="check check--box"><input type="checkbox" name="dispatched_equipment[]" value="<?= $e($k) ?>"<?= in_array($k, $curEq, true) ? ' checked' : '' ?>><span><?= $e($l) ?></span></label><?php endforeach; ?></div>
          <?php if (isset($provErrors['dispatched_equipment'])): ?><small class="num__e"><?= $e($provErrors['dispatched_equipment']) ?></small><?php endif; ?>
          <p class="muted">Un lead suma los puntos de «equipo que NorthStar despacha» si tiene al menos uno de estos. Amazon Relay no viene marcado: decisión pendiente.</p><?= $conf('dispatched_equipment') ?></fieldset></div>
        <div class="pr-item"><label class="field<?= isset($provErrors['hiring_states']) ? ' has-err' : '' ?>"><span>Estados de contratación (company drivers) <?= in_array('hiring_states', $provPending, true) ? '<span class="chip chip--warm-o">Provisional</span>' : '' ?></span>
          <input type="text" name="hiring_states" value="<?= $e($curStates) ?>" placeholder="FL, TX, GA" maxlength="200" autocomplete="off"<?= isset($provErrors['hiring_states']) ? ' aria-invalid="true"' : '' ?>>
          <small><?= isset($provErrors['hiring_states']) ? '<span class="num__e">' . $e($provErrors['hiring_states']) . '</span>' : 'Abreviaturas de 2 letras separadas por comas. Vacío: nadie recibe esos puntos todavía.' ?></small></label><?= $conf('hiring_states') ?></div>
        <div class="pr-item"><label class="field<?= isset($provErrors['driver_min_years']) ? ' has-err' : '' ?>"><span>Experiencia mínima de company drivers (años) <?= in_array('driver_min_years', $provPending, true) ? '<span class="chip chip--warm-o">Provisional</span>' : '' ?></span>
          <input type="text" name="driver_min_years" value="<?= $e($curYears) ?>" inputmode="numeric" maxlength="2" autocomplete="off"<?= isset($provErrors['driver_min_years']) ? ' aria-invalid="true"' : '' ?>>
          <small><?= isset($provErrors['driver_min_years']) ? '<span class="num__e">' . $e($provErrors['driver_min_years']) . '</span>' : 'Por debajo se marca la bandera «Experiencia bajo el mínimo» (no descarta).' ?></small></label><?= $conf('driver_min_years') ?></div>
        <div class="pr-item"><p class="pr-item__t">Tramos de experiencia de company drivers <?= in_array('scoring.driver.experience', $provPending, true) ? '<span class="chip chip--warm-o">Provisional</span>' : '' ?></p><p class="muted">Se editan arriba, en «Company drivers».</p><?= $conf('scoring.driver.experience') ?></div>
        <button class="btn btn--primary" type="submit"><span class="btn__t">Guardar ajustes provisionales</span></button>
      </fieldset>
    </form>
    <h3 class="cfg-h3">Historial de cambios</h3>
    <?php if (!$history): ?><p class="muted">Todavía no hay cambios guardados desde el panel.</p><?php else: ?>
      <ul class="hist"><?php foreach ($history as $h): ?>
        <li><time><?= $e(Fmt::dateTime($h['at'])) ?></time><span><b><?= $e($h['user'] ?? 'Sistema') ?></b> cambió <code><?= $e($h['group']) ?></code>: <?php $parts = []; foreach (array_slice($h['diff'], 0, 4, true) as $path => [$a, $b]) { $parts[] = $path . ': ' . (is_array($a) ? json_encode($a, JSON_UNESCAPED_UNICODE) : ($a ?? '—')) . ' → ' . (is_array($b) ? json_encode($b, JSON_UNESCAPED_UNICODE) : ($b ?? '—')); } echo $e(implode(' · ', $parts)) . (count($h['diff']) > 4 ? $e(' · y ' . (count($h['diff']) - 4) . ' más') : ''); ?></span></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
</section>
