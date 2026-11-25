<?php
use Captacion\Ui;
use Captacion\Services\Fmt;
use Captacion\Services\ProspectConfig;
use Captacion\Services\Prospects;
use Captacion\Services\UsStates;
use Captacion\Services\UsTimeZones;

/** Variables: $f, $per, $list, $counts, $callable, $history, $now, $summary, $cfg, $run, $cron, $totalAll, $back, $csrf */
$rows = $list['rows'];
$nFilters = (int) ($f['q'] !== '') + (int) ($f['us'] !== '') + (int) ($f['fl'] !== '') + (int) ($f['ag'] > 0) + (int) ($f['ph'] !== '');
$qsBase = array_filter(['tab' => $f['tab'] !== 'today' ? $f['tab'] : null, 'q' => $f['q'] ?: null, 'us' => $f['us'] ?: null, 'fl' => $f['fl'] ?: null, 'ag' => $f['ag'] ?: null, 'ph' => $f['ph'] ?: null, 'now' => $f['now'] ?: null, 'per' => $per !== 25 ? $per : null], static fn($v) => $v !== null);
$tabHref = static fn(string $tab): string => '/prospectos' . Ui::qs(array_merge(array_diff_key($qsBase, ['tab' => 1]), $tab !== 'today' ? ['tab' => $tab] : []));
$tabHint = ['today' => 'Por llamar y reintentos que ya tocan', 'to_call' => 'Nunca llamados', 'no_answer' => 'Hasta 3 intentos sin respuesta', 'talked' => 'Hubo conversación', 'interested' => 'Quieren seguir; conviértelos en lead', 'not_interested' => 'Dijeron que no', 'dnc' => 'Permanente: nunca se vuelven a listar'];
$todayEt = \Captacion\Services\Prospects::etDay($now);   // el dia de las llamadas es por ET, no por la zona de visualizacion
$ageLabel = static function (?string $granted) use ($now): string {
    if ($granted === null) {
        return 'Sin fecha';
    }
    $days = (int) floor(($now - (int) strtotime($granted . ' 00:00:00 UTC')) / 86400);
    if ($days <= 0) {
        return 'Concedida hoy';
    }
    if ($days < 31) {
        return 'Hace ' . $days . ($days === 1 ? ' día' : ' días');
    }
    $m = (int) floor($days / 30.4);
    return 'Hace ' . $m . ($m === 1 ? ' mes' : ' meses');
};
$dayFmt = static fn(?string $utc): string => $utc === null ? '—' : Fmt::et($utc)->format('j M, g:i') . ' ' . (Fmt::et($utc)->format('A') === 'AM' ? 'a. m.' : 'p. m.');
$resultIcon = ['no_answer' => 'phone', 'talked' => 'chat', 'interested' => 'star', 'not_interested' => 'x', 'dnc' => 'shield'];
$runAt = is_array($run) ? ($run['finished_at'] ?? $run['updated_at'] ?? null) : null;
$cronAt = is_array($cron) ? ($cron['at'] ?? null) : null;
?>
<div class="page-head page-head--p">
  <div>
    <h1 class="h1">Prospectos FMCSA</h1>
    <p class="sub"><?= $totalAll === 0 ? 'Autoridades nuevas de transportistas para llamar en frío, una por una y a mano.' : $e(Fmt::num($counts['today'])) . ' para llamar hoy · ' . $e(Fmt::num($summary['new_today'])) . ' nuevos hoy · ' . $e(Fmt::num($summary['total'])) . ' en total' ?></p>
    <?php if ($totalAll > 0): ?>
    <p class="pstat" role="status">
      <span class="dot<?= is_array($run) && !empty($run['error']) ? ' dot--bad' : (!$cfg['enabled'] ? ' dot--warn' : ' is-ok') ?>"></span>
      <?php if (!$cfg['enabled']): ?>Búsqueda diaria apagada.
      <?php elseif ($runAt === null): ?>Aún no ha corrido la búsqueda diaria.
      <?php else: ?>Última búsqueda <?= $e(Fmt::ago((string) $runAt)) ?>: <?= $e((int) ($run['counters']['new'] ?? 0)) ?> nuevos de <?= $e((int) ($run['counters']['read'] ?? 0)) ?> leídos<?= !empty($run['capped']) ? ' (tope diario alcanzado)' : '' ?><?= empty($run['finished']) ? ' · sigue en la próxima hora' : '' ?><?php if (!empty($run['error'])): ?> · error: <?= $e($run['error']) ?><?php endif; ?>.
      <?php endif; ?>
      <a class="link" href="/config?tab=prospectos">Ajustes de la búsqueda</a>
    </p>
    <?php endif; ?>
  </div>
  <div class="page-head__a">
    <details class="rules" data-rules>
      <summary><?= Ui::icon('shield') ?><span class="rules__t">Reglas de las llamadas en frío</span></summary>
      <div class="rules__p">
        <p class="rules__s">Solo a mano · 8 a. m. a 9 p. m. hora local · lista de no llamar</p>
        <ul>
          <li><b>Solo llamadas manuales.</b> Nada de marcadores automáticos, SMS ni correos en frío desde esta pantalla.</li>
          <li><b>Horario permitido: 8 a. m. a 9 p. m. hora local del prospecto.</b> Fuera de ese horario el botón Llamar se bloquea; en estados con dos zonas horarias se exige que sea hora válida en ambas.</li>
          <li><b>Lista interna de no llamar.</b> Quien diga que no, o pida que no lo llamen, se marca «No llamar más»: es permanente y su teléfono no vuelve a entrar aunque FMCSA lo entregue de nuevo.</li>
          <li class="rules__todo"><b>Pendiente:</b> confirma con tu abogado el cumplimiento de las llamadas en frío (TCPA y listas estatales de no llamar) antes de operarlas.</li>
        </ul>
      </div>
    </details>
    <?php if ($totalAll > 0): ?>
    <form method="post" action="/prospectos/export">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="qs" value="<?= $e(ltrim(Ui::qs($qsBase), '?')) ?>">
      <button class="btn btn--secondary" type="submit"><?= Ui::icon('download') ?><span class="btn__t">Exportar CSV</span></button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($totalAll === 0): ?>
<section class="card empty empty--lg">
  <svg class="empty__star empty__star--lg" aria-hidden="true"><use href="#i-star"/></svg>
  <h2 class="h2">Todavía no hay prospectos</h2>
  <p><?= $cfg['enabled'] ? 'Cada día, a las 6:00 a. m. de Nueva York, el sistema lee las autoridades de carga recién concedidas y arma esta lista.' : 'La búsqueda diaria está apagada en Configuración. Enciéndela para que el sistema arme la lista.' ?></p>
  <ol class="steps">
    <li><span class="steps__n">1</span><div><b>Elige los filtros</b><p>Antigüedad máxima de la autoridad, estados, tamaño de flota y tope diario, en <a href="/config?tab=prospectos">Configuración &gt; Prospectos</a>.</p></div></li>
    <li><span class="steps__n">2</span><div><b>Programa el cron</b><p><code>cron/fmcsa_daily.php</code> cada hora (minuto 20). Decide solo la hora y corre una vez al día. Los pasos están en el README §19.</p></div></li>
    <li><span class="steps__n">3</span><div><b>Primera corrida</b><p>Por SSH puedes probar ya con <code>php cron/fmcsa_daily.php --force</code>.</p></div></li>
  </ol>
  <?php if ($cronAt !== null || $runAt !== null): ?><p class="muted">Última pasada del cron: <?= $e(Fmt::dateTime((string) ($cronAt ?? $runAt))) ?><?= is_array($run) && !empty($run['error']) ? ' · error: ' . $e($run['error']) : '' ?></p><?php endif; ?>
</section>
<?php else: ?>

<nav class="ptabs" aria-label="Estado del prospecto">
  <?php foreach (Prospects::TABS as $k => $label): $on = $f['tab'] === $k; ?>
    <a class="ptab<?= $on ? ' is-on' : '' ?>" href="<?= $e($tabHref($k)) ?>"<?= $on ? ' aria-current="true"' : '' ?> title="<?= $e($tabHint[$k]) ?>"><?= $e($label) ?><span class="ptab__n"><?= $e(Fmt::num($counts[$k])) ?></span></a>
  <?php endforeach; ?>
</nav>

<?php
$chips = [];
if ($f['us'] !== '') { $chips[] = ['Estado: ' . $f['us'] . ' · ' . (UsStates::NAMES[$f['us']] ?? ''), 'us']; }
if ($f['fl'] !== '') { $chips[] = ['Flota: ' . (Prospects::FLEETS[$f['fl']] ?? $f['fl']), 'fl']; }
if ($f['ag'] > 0) { $chips[] = ['Autoridad: ' . (Prospects::AGES[$f['ag']] ?? $f['ag']), 'ag']; }
if ($f['q'] !== '') { $chips[] = ['Búsqueda: «' . $f['q'] . '»', 'q']; }
$nPanel = (int) ($f['us'] !== '') + (int) ($f['fl'] !== '') + (int) ($f['ag'] > 0);
$drop = static fn(string $k): string => '/prospectos' . Ui::qs(array_diff_key($qsBase, [$k => 1, 'page' => 1]));
?>
<form class="pbar" id="filters" method="get" action="/prospectos" aria-label="Buscar y filtrar prospectos">
  <?php if ($f['tab'] !== 'today'): ?><input type="hidden" name="tab" value="<?= $e($f['tab']) ?>"><?php endif; ?>
  <?php if ($f['now'] === '1'): ?><input type="hidden" name="now" value="1"><?php endif; ?>
  <?php if ($per !== 25): ?><input type="hidden" name="per" value="<?= $e($per) ?>"><?php endif; ?>
  <label class="pbar__q"><span class="sr">Buscar prospecto</span><div class="inp"><?= Ui::icon('search') ?><input type="search" name="q" value="<?= $e($f['q']) ?>" placeholder="Buscar por razón social, USDOT, MC, teléfono o ciudad" maxlength="100"></div></label>
  <div class="fpop<?= $nPanel > 0 ? ' has-on' : '' ?>" data-fpop>
    <button class="btn btn--secondary fpop__b" type="button" aria-expanded="false" aria-controls="fpop-p"><?= Ui::icon('filter') ?><span class="btn__t">Filtros</span><?= $nPanel > 0 ? '<b class="badge">' . $e($nPanel) . '</b>' : '' ?></button>
    <div class="fpop__p" id="fpop-p" role="group" aria-label="Filtros de la lista">
      <label class="field"><span>Estado (EE. UU.)</span><select name="us"><option value="">Todos</option><?php foreach (UsStates::NAMES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f['us'] === $k ? ' selected' : '' ?>><?= $e($k) ?> · <?= $e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Flota</span><select name="fl"><option value="">Cualquiera</option><?php foreach (Prospects::FLEETS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f['fl'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Autoridad</span><select name="ag"><option value="">Cualquier antigüedad</option><?php foreach (Prospects::AGES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $f['ag'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
      <div class="filters__a"><button class="btn btn--secondary btn--sm" type="submit"><span class="btn__t">Aplicar</span></button><?php if ($nFilters > 0): ?><a class="btn btn--ghost btn--sm" href="<?= $e($tabHref($f['tab'])) ?>">Quitar filtros</a><?php endif; ?></div>
    </div>
  </div>
  <?php if ($callable !== null && $rows): ?><a class="seg__i pbar__now<?= $f['now'] === '1' ? ' is-on' : '' ?>" href="<?= $e('/prospectos' . Ui::qs(array_merge(array_diff_key($qsBase, ['now' => 1, 'page' => 1]), $f['now'] === '1' ? [] : ['now' => '1']))) ?>" title="Solo quienes están en horario permitido (8 a. m. a 9 p. m. hora local) y tienen teléfono"<?= $f['now'] === '1' ? ' aria-current="page"' : '' ?>>Solo llamables ahora<span class="ptab__n"><?= $e(Fmt::num($callable)) ?></span></a><?php endif; ?>
  <?php if ($rows): ?><label class="perpage"><span>Por página</span><select data-per aria-label="Prospectos por página"><?php foreach (Prospects::PER as $n): ?><option value="<?= $e($n) ?>"<?= $per === $n ? ' selected' : '' ?>><?= $e($n) ?></option><?php endforeach; ?></select></label><?php endif; ?>
</form>
<?php if ($chips): ?>
<ul class="fchips" aria-label="Filtros activos">
  <?php foreach ($chips as [$label, $k]): ?><li><a class="fchip" href="<?= $e($drop($k)) ?>" aria-label="Quitar filtro <?= $e($label) ?>"><?= $e($label) ?><?= Ui::icon('x') ?></a></li><?php endforeach; ?>
  <?php if (count($chips) > 1): ?><li><a class="link" href="<?= $e($tabHref($f['tab'])) ?>">Quitar todos</a></li><?php endif; ?>
</ul>
<?php endif; ?>

<?php if (!$rows): ?>
<section class="card empty">
  <svg class="empty__star" aria-hidden="true"><use href="#i-star"/></svg>
  <?php if ($nFilters > 0): ?>
    <h3>Ningún prospecto coincide con estos filtros</h3><p>Prueba quitando algún filtro o buscando por otro dato.</p><a class="btn btn--secondary" href="<?= $e($tabHref($f['tab'])) ?>">Quitar filtros</a>
  <?php elseif ($f['tab'] === 'today'): ?>
    <h3>La lista de hoy está vacía</h3><p>Ya atendiste a todos los que tocaban. Los reintentos vuelven a la lista en su fecha y mañana a las 6:00 a. m. (Nueva York) llegan prospectos nuevos.</p>
  <?php else: ?>
    <h3>Aquí no hay nadie todavía</h3><p>Los prospectos pasan a «<?= $e(Prospects::TABS[$f['tab']]) ?>» cuando registras ese resultado en una llamada.</p>
  <?php endif; ?>
</section>
<?php else: ?>
<div class="pr-head" aria-hidden="true"><span>Estado</span><span>Transportista</span><span>Ubicación y hora local</span><span>Flota</span><span>Autoridad</span><span>Intentos</span></div>
<ol class="plist" aria-label="Prospectos">
<?php foreach ($rows as $r):
    $id = (int) $r['id'];
    $name = Fmt::company((string) ($r['legal_name'] ?: ($r['dba'] ?: 'USDOT ' . $r['usdot'])));
    $tz = UsTimeZones::check($r['state'], $now);
    $dnc = (int) $r['do_not_call'] === 1 || $r['status'] === 'dnc';
    $conv = $r['converted_lead_id'] !== null;
    $open = !$dnc && !$conv;
    $cats = ProspectConfig::categoriesOf($r['cargo']);
    $hist = $history[$id] ?? [];
    $closed = in_array($r['status'], ['no_answer_closed', 'not_interested'], true);
    $statusChip = ['to_call' => 'warm', 'no_answer' => 'plain', 'no_answer_closed' => 'dq', 'talked' => 'plain', 'interested' => 'ok', 'not_interested' => 'dq', 'dnc' => 'bad'][$r['status']] ?? 'plain';
    $nextLabel = $r['next_call_at'] !== null && $open ? $dayFmt((string) $r['next_call_at']) : null;
    $callNow = $open && $r['phone'] !== null && $tz['allowed'];
    $loc = trim(($r['city'] ? ucwords(strtolower((string) $r['city'])) . ', ' : '') . ($r['state'] ?? ''), ' ,') ?: '—';
    ?>
  <li class="pc<?= $dnc ? ' is-dnc' : '' ?><?= $conv ? ' is-conv' : '' ?><?= $callNow ? ' is-now' : '' ?>" id="p-<?= $e($id) ?>">
    <details class="pr" data-pr>
      <summary class="pr__s" aria-describedby="pd-<?= $e($id) ?>">
        <span class="pr__st"><span class="chip chip--<?= $e($statusChip) ?>"><?php if ($statusChip !== 'plain'): ?><i class="dot"></i><?php endif; ?><?= $e(Prospects::STATUSES[$r['status']] ?? $r['status']) ?></span></span>
        <span class="pr__who"><b class="pr__n" id="pn-<?= $e($id) ?>"><?= $e($name) ?></b><span class="pr__ids mono">USDOT <?= $e($r['usdot']) ?><?= $r['mc_number'] ? ' · ' . $e($r['mc_number']) : '' ?></span></span>
        <span class="pr__loc"><?= $e($loc) ?><small class="pr__tz<?= $callNow ? ' is-ok' : '' ?>"><?php if (!$tz['known']): ?>Sin hora local<?php elseif ($tz['allowed']): ?><i class="dot is-ok"></i><?= $e($tz['local_hm']) ?> <?= $e($tz['zone_label']) ?> · en horario<?php else: ?><?= $e($tz['local_hm']) ?> <?= $e($tz['zone_label']) ?><?= $tz['opens_in'] ? ' · abre en ' . $e(Fmt::dur((int) $tz['opens_in'])) : ' · fuera de horario' ?><?php endif; ?></small></span>
        <span class="pr__fl"><span class="pr__k">Flota </span><?= $r['fleet_size'] !== null ? $e($r['fleet_size']) . ($r['fleet_size'] == 1 ? ' unidad' : ' unidades') : '—' ?></span>
        <span class="pr__age"><span class="pr__k">Autoridad </span><?= $e($ageLabel($r['authority_granted_at'])) ?></span>
        <span class="pr__att"><?= $dnc ? 'No llamar' : ($conv ? 'Lead #' . $e($r['converted_lead_id']) : ((int) $r['attempts'] > 0 ? $e($r['attempts']) . ' de ' . Prospects::MAX_ATTEMPTS : 'Sin llamar')) ?><?php if ($nextLabel !== null): ?><small>Próximo: <?= $e($nextLabel) ?></small><?php endif; ?></span>
        <span class="pr__chev" aria-hidden="true"><?= Ui::icon('chevron-down') ?></span>
      </summary>
      <div class="pr__b" id="pd-<?= $e($id) ?>">
        <div class="pr__main">
          <?php if ($open || $dnc || $conv): ?>
          <div class="pc__call">
            <?php if ($dnc): ?>
              <p class="pc__note"><?= Ui::icon('shield') ?>Marcado «No llamar más»: permanente. No volverá a listarse.</p>
            <?php elseif ($r['phone'] === null): ?>
              <span class="btn btn--secondary is-off" aria-disabled="true"><span class="btn__t">Llamar</span></span><span class="pc__local pc__local--off">Sin teléfono en FMCSA</span>
            <?php elseif ($tz['allowed']): ?>
              <a class="btn btn--primary" href="tel:<?= $e($r['phone']) ?>" aria-label="Llamar a <?= $e($name) ?>, <?= $e(Fmt::phone($r['phone'])) ?>"><?= Ui::icon('phone') ?><span class="btn__t">Llamar</span></a>
              <span class="pc__num mono"><?= $e(Fmt::phone($r['phone'])) ?></span>
              <span class="pc__local">Allá son las <?= $e($tz['local_hm']) ?> <?= $e($tz['zone_label']) ?><?= $tz['multi'] ? ' · dos zonas horarias' : '' ?></span>
            <?php else: ?>
              <span class="btn btn--secondary is-off" role="link" aria-disabled="true" title="<?= $e($tz['reason']) ?>"><span class="btn__t">Llamar</span></span>
              <span class="pc__local pc__local--off"><?= $tz['known'] ? 'Fuera de horario · allá son las ' . $e($tz['local_hm']) . ' ' . $e($tz['zone_label']) . ($tz['opens_in'] ? ' · abre en ' . $e(Fmt::dur((int) $tz['opens_in'])) : '') : 'Sin estado: no se sabe la hora local' ?></span>
              <details class="pc__ph"><summary>Ver teléfono</summary><span class="mono"><?= $e(Fmt::phone($r['phone'])) ?></span></details>
            <?php endif; ?>
            <?php if (!$dnc && ($r['attempts'] > 0 || $nextLabel !== null)): ?>
              <p class="pc__att"><?= (int) $r['attempts'] > 0 ? 'Intentos sin respuesta: <b>' . $e($r['attempts']) . ' de ' . Prospects::MAX_ATTEMPTS . '</b>' : '' ?><?= $nextLabel !== null ? ' · Siguiente intento: <b>' . $e($nextLabel) . '</b>' : '' ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($open): ?>
          <form class="pc__res" method="post" action="/prospectos/<?= $e($id) ?>/result" data-pr-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>">
            <div class="pc__fields">
              <label class="field"><span>Nota (opcional)</span><input type="text" name="note" maxlength="500" autocomplete="off" placeholder="Qué dijo, a qué hora volver a llamar…"></label>
              <div class="field pc__next"><label for="nx-<?= $e($id) ?>">Siguiente intento (ET, opcional)</label><input id="nx-<?= $e($id) ?>" type="date" lang="es" name="next" min="<?= $e($todayEt) ?>"><span class="qd" data-qd hidden><button type="button" data-days="1">Mañana</button><button type="button" data-days="3">En 3 días</button><button type="button" data-days="7">En 1 semana</button></span></div>
            </div>
            <div class="pc__btns" role="group" aria-label="Registrar el resultado de la llamada a <?= $e($name) ?>">
              <?php foreach (Prospects::RESULTS as $k => $label): ?>
                <button class="rbtn rbtn--<?= $e($k) ?>" type="submit" name="result" value="<?= $e($k) ?>"<?= $k === 'dnc' ? ' data-confirm-title="¿No llamar más?" data-confirm-label="Marcar como no llamar" data-confirm-danger data-confirm="¿Marcar «' . $e($name) . '» como No llamar más? Es permanente: su teléfono no volverá a listarse."' : '' ?>><?= Ui::icon($resultIcon[$k]) ?><?= $e($label) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
          <?php endif; ?>
        </div>

        <aside class="pr__side" aria-label="Datos de <?= $e($name) ?>">
          <dl class="pc__d">
            <?php if ($r['dba'] && $r['dba'] !== $r['legal_name']): ?><div><dt>Nombre comercial</dt><dd><?= $e(Fmt::company((string) $r['dba'])) ?></dd></div><?php endif; ?>
            <div><dt>Autoridad concedida</dt><dd><?= $r['authority_granted_at'] ? $e(Fmt::et($r['authority_granted_at'] . ' 12:00:00')->format('j M Y')) : 'Sin fecha' ?></dd></div>
            <div><dt>Conductores</dt><dd><?= $r['drivers'] !== null && (int) $r['drivers'] > 0 && (int) $r['drivers'] < 500 ? $e($r['drivers']) : '—' ?></dd></div>
            <div><dt>Carga declarada</dt><dd><?= $cats ? $e(implode(' · ', array_map(static fn($c) => ProspectConfig::CARGO[$c][0], array_slice($cats, 0, 3)))) : '—' ?></dd></div>
            <?php if ($r['email']): ?><div class="pc__mail"><dt>Correo en FMCSA</dt><dd class="mono"><?= $e($r['email']) ?></dd></div><?php endif; ?>
          </dl>
          <?php if (!$dnc): ?>
          <div class="pc__f">
            <?php if ($conv): ?>
              <a class="chip chip--stage" href="/leads/<?= $e($r['converted_lead_id']) ?>">Lead #<?= $e($r['converted_lead_id']) ?></a>
              <a class="btn btn--secondary btn--sm" href="/leads/<?= $e($r['converted_lead_id']) ?>">Ver lead #<?= $e($r['converted_lead_id']) ?></a>
            <?php else: ?>
              <form method="post" action="/prospectos/<?= $e($id) ?>/convert"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>">
                <button class="btn btn--secondary btn--sm" type="submit"<?= $r['status'] !== 'interested' ? ' data-confirm-title="¿Convertir en lead?" data-confirm-label="Convertir" data-confirm="¿Convertir a «' . $e($name) . '» en lead? Se crea en «Contactado», sin consentimiento de mensajes y sin alertas."' : '' ?>><?= Ui::icon('user-plus') ?>Convertir en lead</button></form>
            <?php endif; ?>
            <?php if ($closed && !$conv): ?>
              <form method="post" action="/prospectos/<?= $e($id) ?>/reopen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= Ui::icon('refresh') ?>Reabrir</button></form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($hist): ?>
          <details class="pc__hist"<?= count($hist) <= 3 ? ' open' : '' ?>>
            <summary>Historial (<?= $e(count($hist)) ?>)</summary>
            <ol>
              <?php foreach ($hist as $h): ?>
                <li><time class="mono"><?= $e($dayFmt((string) $h['created_at'])) ?></time><b><?= $e(Prospects::RESULTS[$h['result']] ?? ['reopened' => 'Reabierto', 'converted' => 'Convertido en lead'][$h['result']] ?? $h['result']) ?></b><?= (int) $h['attempt_no'] > 0 ? ' <span class="muted">intento ' . $e($h['attempt_no']) . '</span>' : '' ?><?= $h['user_name'] ? ' <span class="muted">· ' . $e($h['user_name']) . '</span>' : '' ?><?= $h['note'] ? '<p>' . $e($h['note']) . '</p>' : '' ?></li>
              <?php endforeach; ?>
            </ol>
          </details>
          <?php endif; ?>
        </aside>
      </div>
    </details>
  </li>
<?php endforeach; ?>
</ol>
<footer class="plist__f">
  <p class="muted">Mostrando <?= $e(Fmt::num(($list['page'] - 1) * $per + 1)) ?> a <?= $e(Fmt::num(($list['page'] - 1) * $per + count($rows))) ?> de <?= $e(Fmt::num($list['total'])) ?></p>
  <?= Ui::pager('/prospectos', $qsBase, 'page', $list['page'], $list['pages']) ?>
</footer>
<?php endif; ?>
<?php endif; ?>
