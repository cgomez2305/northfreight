<?php
use Captacion\Services\ActivityLog;
use Captacion\Services\ActivityView;
use Captacion\Services\Fmt;
use Captacion\Ui;

$rows = $res['rows'];
$nFilters = count($f);
$qs = static fn(array $over = []): string => Ui::qs(array_merge($f, $over));
$chips = ['ty' => static fn($v) => 'Tipo: ' . ActivityView::GROUPS[$v][0], 'us' => static function ($v) use ($users) { foreach ($users as $u) { if ((string) $u['id'] === $v) { return 'Usuario: ' . $u['name']; } } return 'Usuario #' . $v; }, 'from' => static fn($v) => 'Desde: ' . $v, 'to' => static fn($v) => 'Hasta: ' . $v, 'q' => static fn($v) => 'Texto: «' . $v . '»'];
?>
<div class="page-head">
  <div>
    <h1 class="h1">Actividad</h1>
    <p class="sub">Quién hizo qué y cuándo: accesos, exportaciones, cambios de configuración, etapas y borrados. Horas en <?= $e(Fmt::zone()) ?>.</p>
  </div>
  <div class="page-head__a">
    <form method="post" action="/actividad/export"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="qs" value="<?= $e(http_build_query($f)) ?>">
      <button class="btn btn--secondary" type="submit"><span class="btn__t">Exportar CSV</span></button></form>
  </div>
</div>

<form class="act-f card" method="get" action="/actividad">
  <label class="field"><span>Tipo</span><select name="ty"><option value="">Todos</option><?php foreach (ActivityView::GROUPS as $k => [$l]): ?><option value="<?= $e($k) ?>"<?= ($f['ty'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Usuario</span><select name="us"><option value="">Todos</option><?php foreach ($users as $u): ?><option value="<?= $e($u['id']) ?>"<?= ($f['us'] ?? '') === (string) $u['id'] ? ' selected' : '' ?>><?= $e($u['name']) ?></option><?php endforeach; ?></select></label>
  <label class="field"><span>Desde (<?= $e(Fmt::zone()) ?>)</span><input type="date" name="from" value="<?= $e($f['from'] ?? '') ?>"></label>
  <label class="field"><span>Hasta (<?= $e(Fmt::zone()) ?>)</span><input type="date" name="to" value="<?= $e($f['to'] ?? '') ?>"></label>
  <label class="field act-f__q"><span>Buscar</span><span class="inp"><?= Ui::icon('search') ?><input type="search" name="q" value="<?= $e($f['q'] ?? '') ?>" placeholder="Acción, IP, lead #, usuario…" maxlength="100"></span></label>
  <div class="act-f__a"><button class="btn btn--secondary" type="submit"><?= Ui::icon('filter') ?>Filtrar</button><?php if ($nFilters): ?><a class="btn btn--ghost" href="/actividad"><?= Ui::icon('x') ?>Quitar</a><?php endif; ?></div>
</form>
<?php if ($nFilters): ?><div class="chips" aria-label="Filtros activos"><?php foreach ($f as $k => $v): ?><a class="fchip" href="/actividad<?= $e(Ui::qs(array_diff_key($f, [$k => 1]))) ?>" aria-label="Quitar filtro: <?= $e($chips[$k]($v)) ?>"><?= $e($chips[$k]($v)) ?><?= Ui::icon('x') ?></a><?php endforeach; ?></div><?php endif; ?>

<section class="card card--table" aria-label="Registro de actividad">
  <header class="card__h card__h--row"><h2 class="label">Registro</h2><span class="muted"><?= $e(Fmt::num($res['total'])) ?> evento<?= $res['total'] === 1 ? '' : 's' ?><?= $nFilters ? ' con estos filtros' : '' ?></span></header>
  <?php if (!$rows): ?>
    <div class="empty empty--sm"><?= Ui::icon('star', 'empty__star ic--star') ?><h3><?= $total === 0 ? 'Aún no hay actividad' : 'Nada coincide con esos filtros' ?></h3><p><?= $total === 0 ? 'Los inicios de sesión, cambios y exportaciones irán apareciendo aquí.' : 'Prueba con otro tipo, otras fechas o menos texto.' ?></p></div>
  <?php else: ?>
    <div class="tw"><table class="tbl tbl--act">
      <thead><tr><th scope="col">Cuándo</th><th scope="col">Quién</th><th scope="col">Qué</th><th scope="col">Detalle</th><th scope="col">Desde</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): $g = ActivityView::groupOf((string) $r['action']); $bad = in_array($r['action'], ['login_failed', 'login_2fa_failed', 'login_blocked', 'reauth_failed', 'reauth_blocked', 'setup_failed'], true); ?>
          <tr>
            <th scope="row" class="c-when"><time datetime="<?= $e(str_replace(' ', 'T', (string) $r['created_at'])) ?>Z"><?= $e(Fmt::dateTime((string) $r['created_at'], false)) ?></time><small><?= $e(Fmt::et((string) $r['created_at'])->format('H:i:s')) ?></small></th>
            <td class="c-who"><?= $r['user_name'] !== null ? '<b>' . $e($r['user_name']) . '</b>' : '<span class="muted">' . ($r['user_id'] === null ? 'Sistema o sin sesión' : 'Usuario #' . $e($r['user_id'])) . '</span>' ?></td>
            <td class="c-act2"><span class="act act--<?= $e($g) ?><?= $bad ? ' act--bad' : '' ?>"><?= $e(ActivityView::label((string) $r['action'])) ?></span></td>
            <td class="c-det"><?= $e(ActivityView::describe($r)) ?><?php if ($r['entity_type'] === 'lead' && $r['entity_id'] !== null && ctype_digit((string) $r['entity_id'])): ?> <a href="/leads/<?= $e($r['entity_id']) ?>">Ver lead</a><?php endif; ?></td>
            <td class="c-ip"><span class="mono"><?= $e(ActivityLog::shortIp($r['ip'])) ?></span><small><?= $e($r['agent'] ?? '—') ?></small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <footer class="card__f"><span class="muted">Página <?= $e($res['page']) ?> de <?= $e($res['pages']) ?></span><?= Ui::pager('/actividad', $f, 'page', $res['page'], $res['pages']) ?></footer>
  <?php endif; ?>
</section>

<section class="card cfg-card" id="retencion" aria-labelledby="ret-h">
  <header class="card__h"><h2 class="label" id="ret-h">Retención</h2></header>
  <div class="card__b">
    <p class="muted">Se guarda el registro de los últimos <b><?= $e($retention) ?></b> días<?= $oldest !== null ? ' (el más antiguo es del ' . $e(Fmt::date((string) $oldest)) . ')' : '' ?>. Lo más viejo lo borra el cron diario (siempre queda una fila que cuenta cuántas se borraron).</p>
    <?php if ($locked): ?>
      <form class="cfg-unlock__f act-unlock" method="post" action="/config/unlock" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tab" value="actividad">
        <label class="field"><span>Contraseña</span><input type="password" name="password" required autocomplete="current-password"></label>
        <label class="field"><span>Código de 6 dígitos</span><input type="text" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>
        <button class="btn btn--secondary" type="submit"><?= Ui::icon('lock') ?>Confirmar para editar</button>
      </form>
    <?php endif; ?>
    <form class="cfg-form act-ret" method="post" action="/actividad/retention">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
        <label class="field"><span>Días de retención (30 a 3650)</span><input type="text" name="days" value="<?= $e($retention) ?>" inputmode="numeric" maxlength="4" autocomplete="off" required></label>
        <button class="btn btn--primary" type="submit"><span class="btn__t">Guardar retención</span></button>
      </fieldset>
    </form>
  </div>
</section>
