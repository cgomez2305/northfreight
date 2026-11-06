<?php
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Ui;

/** Vista previa de impacto (HTML ya escapado; lo pide el JS con /ajax/rules/preview o se pinta en la pagina). Variables: $impact (RuleSim::preview) o null, $impactErr (mensaje) o '' */
if ($impactErr !== ''): ?>
  <p class="rp-note rp-note--warn"><?= Ui::icon('alert') ?><span><?= $e($impactErr) ?></span></p>
<?php elseif ($impact === null): ?>
  <p class="rp-note"><?= Ui::icon('info') ?><span>Edita una regla y aquí verás cuántos leads cambiarían, con ejemplos. No se guarda nada.</span></p>
<?php else:
    $s = $impact['summary'];
    $temps = ['hot' => 'Caliente', 'warm' => 'Tibio', 'cold' => 'Frío', 'disqualified' => 'Descartado'];
    $tot = max(1, (int) $s['scanned']);
    $W = 260; ?>
  <p class="rp-big"><b><?= $e(Fmt::num((int) $s['changed'])) ?></b><span>de <?= $e(Fmt::num((int) $s['scanned'])) ?> leads cambiarían de puntuación o de temperatura</span></p>
  <?php if ($impact['partial']): ?><p class="rp-note rp-note--warn"><?= Ui::icon('clock') ?><span>Se agotó el tiempo: es un cálculo parcial de <?= $e(Fmt::num((int) $s['scanned'])) ?> leads. Usa un alcance menor o ejecuta la simulación.</span></p><?php endif; ?>
  <figure class="rp-chart" aria-label="Distribución de temperaturas antes y después">
    <svg viewBox="0 0 <?= $e($W) ?> 78" role="img" aria-label="Antes: <?= $e(implode(', ', array_map(static fn($k) => ($temps[$k]) . ' ' . (int) $s['before'][$k], array_keys($temps)))) ?>. Después: <?= $e(implode(', ', array_map(static fn($k) => ($temps[$k]) . ' ' . (int) $s['after'][$k], array_keys($temps)))) ?>.">
      <?php foreach (['before' => [6, 'Antes'], 'after' => [44, 'Después']] as $which => [$y, $lab]): $x = 0; ?>
        <text x="0" y="<?= $e($y - 1) ?>" class="rp-chart__l"><?= $e($lab) ?></text>
        <?php foreach (['hot', 'warm', 'cold', 'disqualified'] as $t): $w = round(((int) $s[$which][$t]) / $tot * $W, 2); if ($w > 0): ?>
          <rect x="<?= $e($x) ?>" y="<?= $e($y + 4) ?>" width="<?= $e($w) ?>" height="20" class="rp-bar rp-bar--<?= $e($t) ?>"><title><?= $e($lab . ': ' . $temps[$t] . ' ' . (int) $s[$which][$t]) ?></title></rect>
        <?php $x += $w; endif; endforeach; ?>
      <?php endforeach; ?>
    </svg>
    <figcaption class="rp-legend"><?php foreach ($temps as $t => $l): ?><span class="rp-key"><i class="rp-dot rp-dot--<?= $e($t) ?>"></i><?= $e($l) ?> <b class="mono"><?= $e((int) $s['before'][$t]) ?> → <?= $e((int) $s['after'][$t]) ?></b></span><?php endforeach; ?></figcaption>
  </figure>
  <dl class="rp-kv">
    <div><dt>Pasarían a caliente</dt><dd><?= $e((int) $s['to_hot']) ?></dd></div>
    <div><dt>Dejarían de ser calientes</dt><dd><?= $e((int) $s['from_hot']) ?></dd></div>
    <div><dt>Leads en los que actúa</dt><dd><?= $e((int) $s['matched']) ?></dd></div>
    <div><dt>Alertas que se habrían disparado</dt><dd><?= $e((int) $s['alerts']) ?></dd></div>
    <div><dt>Tareas que se habrían creado</dt><dd><?= $e((int) $s['tasks']) ?></dd></div>
    <div><dt>Etiquetas o cola de revisión</dt><dd><?= $e((int) $s['tags'] + (int) $s['review']) ?></dd></div>
  </dl>
  <h4 class="label rp-h">MUESTRAS (<?= $e(count($impact['samples'])) ?>)</h4>
  <?php if (!$impact['samples']): ?><p class="rp-note"><?= Ui::icon('info') ?><span>Ningún lead de este alcance cambiaría ni coincidiría con la regla.</span></p><?php endif; ?>
  <ul class="rp-samples">
    <?php foreach ($impact['samples'] as $m): $b = $m['before']; $a = $m['after']; $moved = $b !== $a; ?>
      <li><span class="rp-s__who"><b><?= $e($m['name'] !== '' ? $m['name'] : $m['code']) ?></b><small class="mono"><?= $e($m['code']) ?> · <?= $e(Catalog::label(Catalog::PROFILES_SHORT, $m['profile'])) ?><?= $m['state'] !== '' ? ' · ' . $e($m['state']) : '' ?></small></span>
        <span class="rp-s__chg"><?= Ui::temp($b['temperature']) ?><span class="mono"><?= $e($b['score']) ?></span><?= Ui::icon('arrow-right') ?><?= Ui::temp($a['temperature']) ?><span class="mono"><?= $e($a['score']) ?></span></span>
        <small class="rp-s__r"><?= $e($m['rules'] ? 'Regla: ' . implode(', ', $m['rules']) : '') ?><?= $m['fx'] ? ' · ' . $e(implode(', ', $m['fx'])) : '' ?><?= !$moved && !$m['fx'] ? ' · sin cambio de puntuación' : '' ?></small></li>
    <?php endforeach; ?>
  </ul>
  <p class="rp-foot muted">Calculado en <?= $e(number_format((float) $impact['seconds'], 2, ',', '.')) ?> s con los datos de hoy. «Antes» incluye lo que ya está publicado. Los datos de las muestras son los que ya ves en la bandeja.</p>
<?php endif; ?>
