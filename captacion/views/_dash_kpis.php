<?php
use Captacion\Services\Charts;
use Captacion\Services\Fmt;
use Captacion\Ui;

/** Las cuatro tarjetas de KPI (Q6). Variable: $d (Dashboard::build() o ::pulse()). Las usan la página y el refresco ligero (POST /ajax/dashboard), así que solo reciben agregados. */
$leads = $d['leads'];
$qual = $d['qualified'];
$hot = $d['hot'];
$resp = $d['response'];
$more = max(0, $hot['n'] - count($hot['sample']));
$respText = $resp['median'] !== null ? Fmt::dur($resp['median']) : '';
?>
  <article class="kpi" data-k="leads">
    <header class="kpi__h"><span class="label">Leads</span><?= Ui::tip('Leads recibidos en ' . $d['window_label'] . '. Variación frente al periodo anterior de la misma duración; con menos de ' . \Captacion\Services\Dashboard::MIN_BASE . ' leads antes se muestra la diferencia en leads, no un porcentaje.') ?></header>
    <div class="kpi__m"><?= Ui::big(Fmt::num($leads['n']), 'kpi__v', ['v' => $leads['n'], 'dec' => 0, 'suf' => '']) ?><?= Charts::spark($leads['spark']) ?></div>
    <footer class="kpi__f"><?= Ui::delta($leads['delta']) ?><span><?= $e($d['prev_label']) ?></span></footer>
  </article>
  <article class="kpi" data-k="qualified">
    <header class="kpi__h"><span class="label">Calificados</span><?= Ui::tip('Porcentaje de leads calientes y tibios sobre el total recibido en ' . $d['window_label'] . '. La comparación en puntos exige ' . \Captacion\Services\Dashboard::MIN_BASE . ' leads o más en cada periodo.') ?></header>
    <div class="kpi__m">
      <?php if ($qual['of'] > 0): ?>
        <?= Ui::big(Fmt::num($qual['pct'], 1) . '%', 'kpi__v', ['v' => round($qual['pct'], 1), 'dec' => 1, 'suf' => '%']) ?>
        <div class="kpi__z"><?= Ui::ticks((int) round($qual['pct'] / 100 * 28), 28, 'Calificados: ' . $qual['n'] . ' de ' . $qual['of'], 'tk--q') ?><span class="kpi__c"><b><?= $e($qual['n']) ?></b> de <?= $e($qual['of']) ?></span></div>
      <?php else: ?>
        <p class="kpi__v"><span class="kpi__na">—</span></p>
      <?php endif; ?>
    </div>
    <footer class="kpi__f"><?= $qual['has_prev'] ? Ui::delta($qual['delta']) : '<span class="delta delta--flat">' . Ui::icon('minus') . ($qual['of'] > 0 ? 'Pocos datos' : 'Sin leads') . '</span>' ?><span><?= $e($d['prev_label']) ?></span></footer>
  </article>
  <article class="kpi<?= $hot['n'] > 0 ? ' kpi--alert' : '' ?>" data-k="hot">
    <header class="kpi__h"><span class="label">Calientes sin contactar</span><?= Ui::tip('Leads calientes en etapa «Nuevo» sin primer contacto registrado, sin importar el periodo. Cada raya del medidor son 15 minutos de espera del más antiguo (tope 4 horas).') ?></header>
    <div class="kpi__m"><?= Ui::big((string) $hot['n'], 'kpi__v', ['v' => $hot['n'], 'dec' => 0, 'suf' => '']) ?>
      <?php if ($hot['n'] > 0): ?><div class="kpi__z"><?= Ui::ticks($hot['ticks'], 16, 'Espera del más antiguo: ' . Fmt::dur($hot['oldest_secs']), 'tk--u' . ($hot['late'] ? ' tk--late' : '')) ?><span class="kpi__c">15 min por raya</span></div><?php endif; ?></div>
    <footer class="kpi__f">
      <?php if ($hot['n'] > 0): ?>
        <a class="delta delta--<?= $hot['late'] ? 'bad' : 'warn' ?>" href="/leads?t=hot&amp;ct=none"><?= Ui::icon('clock') ?>Espera <?= $e(Fmt::dur($hot['oldest_secs'])) ?></a><span>el más antiguo</span>
        <?php if ($hot['sample']): ?><span class="avs" aria-hidden="true"><?php foreach ($hot['sample'] as $h): ?><?= Ui::avatar(Fmt::name($h), 'sm') ?><?php endforeach; ?><?php if ($more > 0): ?><span class="av av--sm av--more">+<?= $e($more) ?></span><?php endif; ?></span><?php endif; ?>
      <?php else: ?>
        <span class="delta delta--good"><?= Ui::icon('check') ?>Todo al día</span><span>ningún caliente esperando</span>
      <?php endif; ?>
    </footer>
  </article>
  <article class="kpi" data-k="response">
    <header class="kpi__h"><span class="label">Primera respuesta</span><?= Ui::tip('Mediana del tiempo entre la llegada del lead y su primer contacto, en los leads de ' . $d['window_label'] . ' que ya fueron contactados. El medidor es la parte atendida en menos de 15 minutos.') ?></header>
    <div class="kpi__m">
      <?php if ($resp['median'] !== null): ?>
        <?= Ui::big($respText, 'kpi__v kpi__v--t', preg_match('/^\d+ (s|min|h|d)$/', $respText) === 1 ? ['v' => (int) $respText, 'dec' => 0, 'suf' => ' ' . explode(' ', $respText)[1]] : null) ?>
        <div class="kpi__z"><?= Ui::ticks((int) round(($resp['share15'] ?? 0) / 100 * 20), 20, Fmt::num($resp['share15'] ?? 0) . '% atendidos en menos de 15 minutos', 'tk--r') ?><span class="kpi__c"><b><?= $e(Fmt::num($resp['share15'] ?? 0)) ?>%</b> en &lt;15 min</span></div>
      <?php else: ?>
        <p class="kpi__v kpi__v--t"><span class="kpi__na">—</span></p>
      <?php endif; ?>
    </div>
    <footer class="kpi__f">
      <?php if ($resp['delta'] !== null): ?><?= Ui::delta($resp['delta']) ?><span><?= $e($d['prev_label']) ?></span>
      <?php else: ?><span class="delta delta--flat"><?= Ui::icon('minus') ?><?= $resp['n'] > 0 ? 'Pocos datos' : 'Sin contactos aún' ?></span><span><?= $resp['n'] > 0 ? $e($resp['n']) . ' contactado' . ($resp['n'] === 1 ? '' : 's') : $e($d['window_label']) ?></span><?php endif; ?>
    </footer>
  </article>
