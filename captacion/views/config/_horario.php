<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

$days = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
$nowIn = static fn(string $tz): string => (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('H:i');
?>
<p class="cfg-intro">La zona cambia cómo se muestran las horas del panel (la base siempre guarda UTC) y cómo se leen las fechas que escribes. El horario comercial es el que usan «Posponer a mañana» (mañana a la hora de inicio) y las tareas con fecha sin hora.</p>
<form method="post" action="/config/display" class="cfg-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="hz-t">
      <header class="card__h"><h2 class="label" id="hz-t">ZONA HORARIA DE VISUALIZACIÓN</h2></header>
      <div class="card__b">
        <label class="field hz-tz"><span>Zona</span><select name="timezone"><?php foreach ($zones as $k => [$name, $abbr]): ?><option value="<?= $e($k) ?>"<?= $tz === $k ? ' selected' : '' ?>><?= $e($name) ?> · <?= $e($abbr) ?> · ahora <?= $e($nowIn($k)) ?></option><?php endforeach; ?></select><small>Por defecto, la del este de EE. UU. (America/New_York), donde está NorthStar.</small></label>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="hh-t">
      <header class="card__h"><h2 class="label" id="hh-t">HORARIO COMERCIAL</h2></header>
      <div class="card__b">
        <div class="dlg__row hz-h">
          <label class="field"><span>Abre</span><input type="time" name="start" value="<?= $e($hours['start']) ?>" required></label>
          <label class="field"><span>Cierra</span><input type="time" name="end" value="<?= $e($hours['end']) ?>" required></label>
        </div>
        <fieldset class="hz-days"><legend>Días laborables</legend>
          <div class="eqgrid"><?php foreach ($days as $n => $l): ?><label class="check check--box"><input type="checkbox" name="days[]" value="<?= $e($n) ?>"<?= in_array($n, $hours['days'], true) ? ' checked' : '' ?>><span><?= $e($l) ?></span></label><?php endforeach; ?></div>
        </fieldset>
      </div>
      <footer class="card__f"><span class="muted">Hoy son las <?= $e(Fmt::et(\Captacion\Clock::time())->format('H:i')) ?> <?= $e(Fmt::zone()) ?>.</span>
        <button class="btn btn--primary" type="submit"><span class="btn__t">Guardar horario</span><span class="btn__cell"><?= Ui::icon('check') ?></span></button></footer>
    </section>
  </fieldset>
</form>
