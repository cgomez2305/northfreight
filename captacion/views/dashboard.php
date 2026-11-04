<?php
use Captacion\Ui;
use Captacion\Services\Catalog;
use Captacion\Services\Charts;
use Captacion\Services\Dashboard;
use Captacion\Services\Fmt;

$leads = $d['leads'];
$qual = $d['qualified'];
$hot = $d['hot'];
$resp = $d['response'];
$ch = $d['chart'];
$src = $d['sources'];
$strip = $d['strip'];
$heat = $d['heat'];
$today = $d['today'];
$tabs = ['weekly' => 'Semanal'] + ($ch['monthly'] ? ['monthly' => 'Mensual'] : []) + ($ch['yearly'] ? ['yearly' => 'Anual'] : []);
$chartTitle = ['weekly' => 'Leads por semana, últimas 12 semanas', 'monthly' => 'Leads por mes, últimos 12 meses', 'yearly' => 'Leads por año'];
$tip = static fn(string $t): string => '<button class="info" type="button" aria-label="Qué mide: ' . $e($t) . '" data-tip="' . $e($t) . '">' . Ui::icon('info') . '</button>';
$avatarsMore = max(0, $hot['n'] - count($hot['sample']));
$days = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
?>
<div class="page-head">
  <div>
    <h1 class="h1"><?= $e($greeting) ?></h1>
    <p class="sub">Así va la captación de NorthStar · <?= $e(Fmt::weekdayShort((int) $nowEt->format('N'))) ?> <?= $e(Fmt::dayLabel($nowEt)) ?>, <?= $e($nowEt->format('H:i')) ?> <?= $e(Fmt::zone()) ?></p>
  </div>
  <div class="page-head__a">
    <div class="seg" role="group" aria-label="Periodo">
      <?php foreach (Dashboard::PERIODS as $k => $label): ?>
        <a class="seg__i<?= $period === $k ? ' is-on' : '' ?>" href="/<?= $e(Ui::qs(['p' => $k === 'today' ? null : $k])) ?>"<?= $period === $k ? ' aria-current="true"' : '' ?>><?= $e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <form method="post" action="/leads/export">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="scope" value="period"><input type="hidden" name="period" value="<?= $e($period) ?>">
      <button class="btn btn--primary" type="submit"><span class="btn__t">Exportar CSV</span><span class="btn__cell"><?= Ui::icon('download') ?></span></button>
    </form>
  </div>
</div>

<section class="kpis" aria-label="Indicadores del periodo">
  <article class="kpi">
    <header class="kpi__h"><span class="label">LEADS</span><?= $tip('Leads recibidos en ' . $d['window_label'] . '. Variación frente al periodo anterior de la misma duración.') ?></header>
    <div class="kpi__m"><p class="kpi__v"><?= $e(Fmt::num($leads['n'])) ?></p><?= Charts::spark($leads['spark']) ?></div>
    <footer class="kpi__f"><?= Ui::delta($leads['delta']) ?><span><?= $e($d['prev_label']) ?></span></footer>
  </article>
  <article class="kpi">
    <header class="kpi__h"><span class="label">CALIFICADOS</span><?= $tip('Porcentaje de leads calientes y tibios sobre el total recibido en ' . $d['window_label'] . '.') ?></header>
    <div class="kpi__m"><p class="kpi__v"><?= $qual['of'] > 0 ? $e(Fmt::num($qual['pct'], 0)) . '<small>%</small>' : '<span class="kpi__na">—</span>' ?></p>
      <div class="kpi__ring"><?= Charts::donut($qual['pct']) ?><span><b><?= $e($qual['n']) ?></b> de <?= $e($qual['of']) ?></span></div></div>
    <footer class="kpi__f"><?= $qual['has_prev'] && $qual['of'] > 0 ? Ui::delta($qual['delta']) : '<span class="delta delta--flat">' . Ui::icon('minus') . 'Sin comparación</span>' ?><span><?= $e($d['prev_label']) ?></span></footer>
  </article>
  <article class="kpi<?= $hot['n'] > 0 ? ' kpi--alert' : '' ?>">
    <header class="kpi__h"><span class="label">CALIENTES SIN CONTACTAR</span><?= $tip('Leads calientes en etapa «Nuevo» sin primer contacto registrado, sin importar el periodo.') ?></header>
    <div class="kpi__m"><p class="kpi__v"><?= $e($hot['n']) ?></p>
      <?php if ($hot['sample']): ?>
        <div class="avs" aria-hidden="true"><?php foreach ($hot['sample'] as $i => $h): ?><?= Ui::avatar(Fmt::name($h), 'sm') ?><?php endforeach; ?><?php if ($avatarsMore > 0): ?><span class="av av--sm av--more">+<?= $e($avatarsMore) ?></span><?php endif; ?></div>
      <?php endif; ?></div>
    <footer class="kpi__f">
      <?php if ($hot['n'] > 0): ?>
        <a class="delta delta--<?= $hot['late'] ? 'bad' : 'warn' ?>" href="/leads?t=hot&amp;ct=none"><?= Ui::icon('clock') ?>Espera <?= $e(Fmt::dur($hot['oldest_secs'])) ?></a><span>el más antiguo</span>
      <?php else: ?>
        <span class="delta delta--good"><?= Ui::icon('check') ?>Todo al día</span><span>ningún caliente esperando</span>
      <?php endif; ?>
    </footer>
  </article>
  <article class="kpi">
    <header class="kpi__h"><span class="label">PRIMERA RESPUESTA</span><?= $tip('Mediana del tiempo entre la llegada del lead y su primer contacto, en los leads de ' . $d['window_label'] . ' que ya fueron contactados.') ?></header>
    <div class="kpi__m"><p class="kpi__v kpi__v--t"><?= $resp['median'] !== null ? $e(Fmt::dur($resp['median'])) : '<span class="kpi__na">—</span>' ?></p>
      <?php if ($resp['n'] > 0): ?><div class="kpi__bars"><?= Charts::bars($resp['bands']) ?><small>&lt;15m · 1h · 4h · +</small></div><?php endif; ?></div>
    <footer class="kpi__f">
      <?php if ($resp['delta'] !== null): ?><?= Ui::delta($resp['delta']) ?><span><?= $e($d['prev_label']) ?></span>
      <?php else: ?><span class="delta delta--flat"><?= Ui::icon('minus') ?><?= $resp['n'] > 0 ? 'Sin comparación' : 'Sin contactos aún' ?></span><span><?= $resp['n'] > 0 ? $e($resp['n']) . ' contactados' : $e($d['window_label']) ?></span><?php endif; ?>
    </footer>
  </article>
</section>

<div class="grid grid--main">
  <section class="card chart" aria-labelledby="h-chart" data-tabs>
    <header class="card__h"><h2 class="label" id="h-chart">LEADS POR SEMANA</h2><?= $tip('Leads recibidos por semana (lunes a domingo, hora del este), separados entre owner-operators y carriers, y company drivers.') ?></header>
    <div class="card__b">
      <div class="chart__top">
        <div>
          <p class="muted">Total <?= $e(['weekly' => 'de las últimas 12 semanas', 'monthly' => 'de los últimos 12 meses', 'yearly' => 'histórico'][array_key_first($tabs)]) ?></p>
          <p class="chart__v"><span data-chart-total><?= $e(Fmt::num($ch['weekly']['total'])) ?></span> <?= Ui::delta($ch['weekly']['delta'], 'data-chart-delta') ?></p>
        </div>
        <div class="chart__ctl">
          <ul class="legend"><li><i class="sw sw--ops"></i>Owner-operators y carriers</li><li><i class="sw sw--drv"></i>Company drivers</li></ul>
          <?php if (count($tabs) > 1): ?>
          <div class="seg seg--sm" role="tablist" aria-label="Agrupación del gráfico">
            <?php $first = true; foreach ($tabs as $k => $label): ?>
              <button class="seg__i<?= $first ? ' is-on' : '' ?>" type="button" role="tab" id="tab-<?= $e($k) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" aria-controls="pn-<?= $e($k) ?>" data-total="<?= $e(Fmt::num($ch[$k]['total'])) ?>" data-sub="<?= $e(['weekly' => 'de las últimas 12 semanas', 'monthly' => 'de los últimos 12 meses', 'yearly' => 'histórico'][$k]) ?>" data-delta="<?= $e($k === 'weekly' ? '1' : '0') ?>"><?= $e($label) ?></button>
            <?php $first = false; endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php $first = true; foreach ($tabs as $k => $label): $c = $ch[$k]; ?>
        <div id="pn-<?= $e($k) ?>" role="tabpanel" aria-labelledby="tab-<?= $e($k) ?>"<?= $first ? '' : ' hidden' ?>>
          <?= Charts::line($chartTitle[$k], $c['labels'], [['name' => 'Owner-operators y carriers', 'cls' => 'ops', 'values' => $c['ops']], ['name' => 'Company drivers', 'cls' => 'drv', 'values' => $c['drv']]], $c['tips']) ?>
        </div>
      <?php $first = false; endforeach; ?>
      <dl class="strip">
        <div><dt>Calificados (caliente + tibio)</dt><dd><?= $strip['qualified_pct'] !== null ? $e(Fmt::pct($strip['qualified_pct'], 1)) : '—' ?></dd></div>
        <div><dt>Tasa de descarte</dt><dd><?= $strip['discard_pct'] !== null ? $e(Fmt::pct($strip['discard_pct'], 1)) : '—' ?></dd></div>
        <div><dt>Costo por lead</dt><dd><?php if ($strip['cpl'] !== null): ?>$<?= $e(Fmt::num($strip['cpl'], 2)) ?><?php else: ?><span class="dd-na"><?= $strip['has_costs'] ? 'Sin leads con costo este mes' : 'Sin costos cargados' ?></span><?php endif; ?></dd></div>
      </dl>
      <p class="foot-note">Franja: últimas 12 semanas<?= $strip['n'] > 0 ? ' · ' . $e(Fmt::num($strip['n'])) . ' leads' : '' ?>.</p>
    </div>
  </section>

  <section class="card" aria-labelledby="h-src">
    <header class="card__h"><h2 class="label" id="h-src">LEADS POR FUENTE</h2><?= $tip('Reparto de los leads recibidos en los últimos 90 días según su fuente.') ?></header>
    <div class="card__b">
      <p class="pillnote"><?= Ui::icon('calendar') ?><?= $e($src['label']) ?></p>
      <p class="muted">Total</p>
      <p class="chart__v"><?= $e(Fmt::num($src['total'])) ?> <small>leads</small></p>
      <?php if ($src['total'] > 0): ?>
        <?= Charts::segbar(array_map(static fn($s) => ['value' => $s['n'], 'label' => $s['label']], $src['list'])) ?>
        <ul class="srclist">
          <?php foreach ($src['list'] as $i => $s): ?>
            <li><i class="sq seg-<?= $e(min(4, $i)) ?>"></i><span class="srclist__n"><?= $s['key'] === '_other' ? $e($s['label']) : '<a href="/leads' . $e(Ui::qs(['src' => $s['key']])) . '">' . $e($s['label']) . '</a>' ?></span><b class="mono"><?= $e(Fmt::num($s['n'])) ?></b><span class="mono muted"><?= $e(Fmt::num($s['pct'])) ?>%</span></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($src['insight']): ?><p class="insight"><?= Ui::icon('star', 'ic--star') ?><span><?= $e($src['insight']) ?></span></p><?php endif; ?>
      <?php else: ?>
        <div class="empty empty--sm"><svg class="empty__star" aria-hidden="true"><use href="#i-star"/></svg><p>Aún no hay leads en los últimos 90 días.</p></div>
      <?php endif; ?>
    </div>
  </section>
</div>

<section class="card" aria-labelledby="h-recent">
  <header class="card__h card__h--row">
    <h2 class="label" id="h-recent">LEADS RECIENTES</h2>
    <div class="card__tools">
      <form class="minisearch" method="get" action="/" role="search">
        <?php if ($period !== 'today'): ?><input type="hidden" name="p" value="<?= $e($period) ?>"><?php endif; ?>
        <?= Ui::icon('search') ?><input type="search" name="rq" value="<?= $e($rq) ?>" placeholder="Buscar en la tabla" aria-label="Buscar en leads recientes" maxlength="100">
      </form>
      <a class="btn btn--secondary btn--sm" href="/leads">Ver todos<?= Ui::icon('chevron-right') ?></a>
    </div>
  </header>
  <?php $rows = $recent['rows']; ?>
  <?php if (!$rows): ?>
    <div class="empty"><svg class="empty__star" aria-hidden="true"><use href="#i-star"/></svg>
      <?php if ($rq !== ''): ?><h3>Nada coincide con «<?= $e($rq) ?>»</h3><p>Prueba con otro nombre, teléfono o correo.</p><a class="btn btn--secondary" href="/<?= $e(Ui::qs(['p' => $period === 'today' ? null : $period])) ?>">Quitar búsqueda</a>
      <?php else: ?><h3>Todavía no hay leads</h3><p>Cuando alguien envíe el formulario de <code>/careers/apply/</code>, llegue por la API o se importe, aparecerá aquí con su puntuación y temperatura.</p><a class="btn btn--secondary" href="/leads">Ver cómo llegan los leads</a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="tw">
    <table class="tbl tbl--dash">
      <thead><tr><th scope="col" class="c-id">ID</th><th scope="col">Lead</th><th scope="col" class="c-prof">Perfil</th><th scope="col" class="c-st">Est.</th><th scope="col" class="c-eq">Equipo</th><th scope="col" class="c-n">Pts</th><th scope="col">Temp.</th><th scope="col" class="c-stage">Etapa</th><th scope="col" class="c-src">Fuente</th><th scope="col" class="c-when">Recibido</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $name = Fmt::name($r); ?>
        <tr>
          <td class="c-id mono" data-l="ID">#<?= $e(str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT)) ?></td>
          <th scope="row" class="c-name"><a class="who" href="/leads/<?= $e($r['id']) ?>"><?= Ui::avatar($name, 'sm') ?><span><?= $e($name) ?></span></a></th>
          <td class="c-prof" data-l="Perfil"><?= $e(Catalog::label(Catalog::PROFILES_SHORT, $r['profile'])) ?></td>
          <td class="c-st mono" data-l="Estado"><?= $e($r['state'] ?? '—') ?></td>
          <td class="c-eq" data-l="Equipo"><?= $e(Ui::equipment($r['equipment_types'], 1)) ?></td>
          <td class="c-n" data-l="Puntos"><?= Ui::score((int) $r['score'], $r['temperature']) ?></td>
          <td class="c-tmp" data-l="Temperatura"><?= Ui::temp($r['temperature']) ?></td>
          <td class="c-stage" data-l="Etapa"><?= Ui::stage($r['stage'], $r['pipeline']) ?></td>
          <td class="c-src" data-l="Fuente"><?= $e(Catalog::source($r['source'])) ?></td>
          <td class="c-when mono" data-l="Recibido" title="<?= $e(Fmt::dateTime($r['created_at'])) ?>"><?= $e(Fmt::ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <footer class="card__f">
    <p class="muted">Mostrando <?= $e(count($rows)) ?> de <?= $e(Fmt::num($recent['total'])) ?> lead<?= $recent['total'] === 1 ? '' : 's' ?><?= $rq !== '' ? ' para «' . $e($rq) . '»' : '' ?></p>
    <?= Ui::pager('/', ['rq' => $rq !== '' ? $rq : null, 'p' => $period === 'today' ? null : $period], 'rp', $recent['page'], $recent['pages']) ?>
  </footer>
  <?php endif; ?>
</section>

<div class="grid grid--3">
  <section class="card" aria-labelledby="h-heat">
    <header class="card__h"><h2 class="label" id="h-heat">ACTIVIDAD</h2><?= $tip('Leads recibidos por día de la semana en las últimas 13 semanas (hora del este). Más oscuro = más leads.') ?></header>
    <div class="card__b">
      <p class="muted">Leads en 13 semanas</p>
      <p class="chart__v"><?= $e(Fmt::num($heat['total'])) ?> <?= Ui::delta($heat['delta']) ?></p>
      <div class="heatwrap" data-heat>
        <div class="heat__months" aria-hidden="true"><?php foreach ($heat['months'] as $m): ?><span><?= $e($m) ?></span><?php endforeach; ?></div>
        <div class="heat__body">
          <div class="heat__days" aria-hidden="true"><span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span><span>D</span></div>
          <?= Charts::heat($heat['cells'], $heat['weeks']) ?>
        </div>
        <div class="heat__legend" aria-hidden="true"><span>Menos</span><i class="ht0"></i><i class="ht1"></i><i class="ht2"></i><i class="ht3"></i><i class="ht4"></i><span>Más</span><output class="heat__cap" data-heat-cap><?= $heat['total'] > 0 ? $e($heat['busiest']['label']) . ' · ' . $e($heat['busiest']['n']) . ' leads' : 'Sin actividad todavía' ?></output></div>
      </div>
      <dl class="duo">
        <div><dt>Día más activo</dt><dd><?= $heat['busiest_dow'] !== null ? $e(ucfirst($heat['busiest_dow'])) : '—' ?></dd></div>
        <div><dt>Promedio por día</dt><dd><?= $e(Fmt::num($heat['avg_day'], 1)) ?></dd></div>
      </dl>
    </div>
  </section>

  <section class="card" aria-labelledby="h-today">
    <header class="card__h"><h2 class="label" id="h-today">HOY</h2><?= $tip('Tareas vencidas o con vencimiento hoy y citas de hoy, en hora del este.') ?></header>
    <div class="card__b">
      <?php if (!$today['tasks'] && !$today['appointments']): ?>
        <div class="empty empty--sm"><svg class="empty__star" aria-hidden="true"><use href="#i-star"/></svg><h3>Nada pendiente para hoy</h3><p>Las tareas y citas con fecha de hoy aparecerán aquí. Crea una desde la ficha de un lead.</p></div>
      <?php else: ?>
        <ul class="today">
          <?php foreach ($today['appointments'] as $a): ?>
            <li><span class="today__ic today__ic--cal"><?= Ui::icon('calendar') ?></span><span class="today__t"><a href="/leads/<?= $e($a['lead_id']) ?>"><?= $e(Fmt::name($a)) ?></a><small>Llamada agendada</small></span><time class="mono"><?= $e(Fmt::et($a['starts_at'])->format('H:i')) ?> ET</time></li>
          <?php endforeach; ?>
          <?php foreach ($today['tasks'] as $t): $late = \Captacion\Clock::parse((string) $t['due_at']) < $today['start']; ?>
            <li><span class="today__ic<?= (int) $t['priority'] === 1 ? ' today__ic--hi' : '' ?>"><?= Ui::icon('check-circle') ?></span><span class="today__t"><?php if ($t['lead_id']): ?><a href="/leads/<?= $e($t['lead_id']) ?>"><?= $e($t['title']) ?></a><small><?= $e(Fmt::name($t)) ?></small><?php else: ?><?= $e($t['title']) ?><?php endif; ?></span><time class="mono<?= $late ? ' is-late' : '' ?>"><?= $late ? $e(Fmt::date($t['due_at'])) : $e(Fmt::et($t['due_at'])->format('H:i')) . ' ET' ?></time></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($today['more'] > 0): ?><p class="foot-note">y <?= $e($today['more']) ?> tarea<?= $today['more'] === 1 ? '' : 's' ?> más, en las fichas de sus leads.</p><?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" aria-labelledby="h-sys" id="cola">
    <header class="card__h"><h2 class="label" id="h-sys">ESTADO DEL SISTEMA</h2><?= $tip('Cola de trabajos (alertas, FMCSA, correos), tarea de cron e integraciones configuradas.') ?></header>
    <div class="card__b">
      <ul class="sysl">
        <li><i class="dot<?= $cronAgo !== null && !$cronLate ? ' is-ok' : ' is-bad' ?>"></i><span>Cron de la cola</span><b><?= $cronAgo !== null ? $e($cronAgo) : 'nunca' ?></b></li>
        <li><i class="dot<?= $queue['counts']['failed'] > 0 ? ' is-bad' : ' is-ok' ?>"></i><span>Trabajos fallidos</span><b><?= $e($queue['counts']['failed']) ?></b></li>
        <li><i class="dot is-ok"></i><span>Pendientes / en curso</span><b><?= $e($queue['counts']['pending']) ?> / <?= $e($queue['counts']['running']) ?></b></li>
        <li><i class="dot<?= $sys['telegram'] ? ' is-ok' : ' is-warn' ?>"></i><span>Telegram</span><b><?= $sys['telegram'] ? 'Configurado' : 'Sin configurar' ?></b></li>
        <li><i class="dot<?= $sys['fmcsa'] ? ' is-ok' : ' is-warn' ?>"></i><span>FMCSA</span><b><?= $sys['fmcsa'] ? 'Con llave' : 'Sin llave' ?></b></li>
        <li><i class="dot<?= $sys['smtp'] ? ' is-ok' : ' is-warn' ?>"></i><span>Correo (SMTP)</span><b><?= $sys['smtp'] ? 'Configurado' : 'Solo registro' ?></b></li>
      </ul>
      <?php if ($queue['recent_fail']): ?>
        <details class="fails"><summary>Últimos trabajos fallidos</summary>
          <ul><?php foreach ($queue['recent_fail'] as $f): ?><li><code><?= $e($f['type']) ?></code> #<?= $e($f['id']) ?> · <?= $e($f['attempts']) ?> intentos<br><small><?= $e($f['last_error']) ?></small></li><?php endforeach; ?></ul>
        </details>
      <?php endif; ?>
    </div>
  </section>
</div>
