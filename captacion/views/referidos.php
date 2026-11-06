<?php
use Captacion\Ui;
use Captacion\Services\Fmt;
use Captacion\Services\Referrals;

/** Variables: $ov (codes, referrals, totals), $filter, $confirmed, $siteUrl, $csrf */
$t = $ov['totals'];
$money = static fn($v): string => $v === null ? 'Sin monto' : '$' . number_format((float) $v, 2);
$tabs = ['' => 'Todos', 'signed' => 'Firmados', 'pending' => 'Bono pendiente', 'approved' => 'Aprobados', 'paid' => 'Pagados', 'cancelled' => 'Cancelados'];
$empty = !$ov['codes'] && !$ov['referrals'];
?>
<div class="page-head">
  <div>
    <h1 class="h1">Referidos</h1>
    <p class="sub">Códigos de referido, los leads que llegan con ellos y los bonos por pagar.</p>
  </div>
  <div class="page-head__a">
    <button class="btn btn--primary" type="button" data-open="#code-new"><span class="btn__t">Nuevo código</span></button>
  </div>
</div>

<section class="rbanner<?= $confirmed ? ' is-ok' : '' ?>" aria-label="Estado del monto de los bonos">
  <?= Ui::icon($confirmed ? 'check-circle' : 'alert') ?>
  <p><?php if ($confirmed): ?><b>Montos confirmados con NorthStar.</b> Los bonos se aprueban con el monto de cada código.<?php else: ?><b>Monto del bono provisional: pendiente de confirmar con NorthStar.</b> Mientras tanto puedes crear códigos y aprobar bonos con el monto que definas, pero no es una cifra acordada.<?php endif; ?></p>
  <form method="post" action="/referidos/confirm"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="confirmed" value="<?= $confirmed ? '0' : '1' ?>">
    <button class="btn btn--secondary btn--sm" type="submit"<?= $confirmed ? '' : ' data-confirm="¿NorthStar ya confirmó por escrito los montos de los bonos?"' ?>><?= $confirmed ? 'Volver a provisional' : 'NorthStar ya confirmó' ?></button></form>
</section>

<?php if ($empty): ?>
<section class="card empty empty--lg">
  <svg class="empty__star empty__star--lg" aria-hidden="true"><use href="#i-star"/></svg>
  <h2 class="h2">Todavía no hay códigos de referido</h2>
  <p>Un código es un enlace personal. Quien llegue por él a <code>/careers/apply/</code> queda ligado a su referidor, suma puntos al lead y, si firma, genera un bono por aprobar.</p>
  <ol class="steps">
    <li><span class="steps__n">1</span><div><b>Crea un código</b><p>Para un conductor, un owner-operator o un lead firmado. Puedes elegir el código o dejar que se genere.</p></div></li>
    <li><span class="steps__n">2</span><div><b>Comparte el enlace</b><p><code><?= $e($siteUrl) ?>/careers/?ref=CODIGO</code>. El sitio lo recuerda 60 días y lo manda con la solicitud, sin cookies.</p></div></li>
    <li><span class="steps__n">3</span><div><b>Aprueba el bono</b><p>Cuando el referido firma, el bono pasa a «pendiente de aprobar» y te avisamos.</p></div></li>
  </ol>
  <button class="btn btn--primary" type="button" data-open="#code-new"><span class="btn__t">Crear el primer código</span></button>
</section>
<?php else: ?>

<ul class="rkpi" aria-label="Resumen">
  <li><span class="label">Referidos</span><b><?= $e($t['n']) ?></b></li>
  <li><span class="label">Firmados</span><b><?= $e($t['signed']) ?></b></li>
  <li class="<?= $t['pending'] > 0 ? 'is-warn' : '' ?>"><span class="label">Bonos por aprobar</span><b><?= $e($t['pending']) ?></b></li>
  <li><span class="label">Aprobados sin pagar</span><b><?= $e($t['approved']) ?></b></li>
  <li><span class="label">Pagados</span><b><?= $e($t['paid']) ?></b></li>
</ul>

<nav class="ptabs" aria-label="Filtro de referidos">
  <?php foreach ($tabs as $k => $label): $on = $filter === $k; ?>
    <a class="ptab<?= $on ? ' is-on' : '' ?>" href="/referidos<?= $k !== '' ? '?f=' . $e($k) : '' ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= $e($label) ?></a>
  <?php endforeach; ?>
</nav>

<section class="card" aria-labelledby="refs-t">
  <header class="card__h"><h2 id="refs-t" class="h2">Leads referidos</h2></header>
  <?php if (!$ov['referrals']): ?>
    <div class="empty empty--sm"><h3>Nadie coincide con este filtro</h3><p>Los leads que llegan con un código aparecen aquí, con el estado de su bono.</p></div>
  <?php else: ?>
  <ul class="rrows">
    <?php foreach ($ov['referrals'] as $r): $name = Fmt::name($r); $b = $r['bonus_status']; ?>
    <li class="rrow">
      <div class="rrow__w"><a class="rrow__n" href="/leads/<?= $e($r['lead_id']) ?>"><?= $e($name) ?></a><small class="mono">Código <?= $e($r['code']) ?> · de <?= $e($r['owner_name']) ?> · <?= $e(Fmt::ago((string) $r['created_at'])) ?></small></div>
      <div class="rrow__s"><span class="chip chip--<?= $r['status'] === 'signed' ? 'ok' : ($r['status'] === 'qualified' ? 'warm' : 'plain') ?>"><?php if ($r['status'] !== 'referred'): ?><i class="dot"></i><?php endif; ?><?= $e(Referrals::STATUSES[$r['status']] ?? $r['status']) ?></span>
        <span class="chip chip--<?= ['pending' => 'hot', 'approved' => 'warm', 'paid' => 'ok', 'cancelled' => 'dq', 'none' => 'plain'][$b] ?? 'plain' ?>"><?= $e(Referrals::BONUS[$b] ?? $b) ?></span>
        <span class="rrow__m mono"><?= $e($money($r['bonus_amount'])) ?></span></div>
      <div class="rrow__a">
        <?php if ($b === 'pending'): ?>
          <form class="rrow__f" method="post" action="/referidos/<?= $e($r['id']) ?>/bonus"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="approve">
            <label class="field rrow__amt"><span class="sr">Monto del bono en dólares</span><input type="text" name="bonus_amount" inputmode="decimal" placeholder="Monto $" value="<?= $e($r['bonus_amount'] !== null ? number_format((float) $r['bonus_amount'], 2, '.', '') : '') ?>" autocomplete="off"></label>
            <button class="btn btn--primary btn--sm" type="submit">Aprobar</button></form>
        <?php elseif ($b === 'approved'): ?>
          <form method="post" action="/referidos/<?= $e($r['id']) ?>/bonus"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="pay"><button class="btn btn--primary btn--sm" type="submit" data-confirm="¿Marcar el bono de <?= $e($money($r['bonus_amount'])) ?> como pagado?">Marcar pagado</button></form>
        <?php elseif ($b === 'cancelled' && $r['status'] === 'signed'): ?>
          <form method="post" action="/referidos/<?= $e($r['id']) ?>/bonus"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="reopen"><button class="btn btn--ghost btn--sm" type="submit">Reabrir</button></form>
        <?php endif; ?>
        <?php if (in_array($b, ['pending', 'approved'], true)): ?>
          <form method="post" action="/referidos/<?= $e($r['id']) ?>/bonus"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="cancel"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="¿Cancelar este bono?">Cancelar</button></form>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="card" id="codigos" aria-labelledby="codes-t">
  <header class="card__h"><h2 id="codes-t" class="h2">Códigos</h2></header>
  <?php if (!$ov['codes']): ?>
    <div class="empty empty--sm"><h3>No hay códigos</h3><p>Crea uno con «Nuevo código».</p></div>
  <?php else: ?>
  <ul class="rrows">
    <?php foreach ($ov['codes'] as $c): $link = Referrals::link((string) $c['code']); ?>
    <li class="rrow rrow--code<?= (int) $c['active'] === 1 ? '' : ' is-off' ?>">
      <div class="rrow__w"><b class="rrow__code mono"><?= $e($c['code']) ?></b><small><?= $e($c['owner_name']) ?><?php if ($c['owner_lead_id']): ?> · <a href="/leads/<?= $e($c['owner_lead_id']) ?>">lead #<?= $e($c['owner_lead_id']) ?></a><?php endif; ?><?= $c['note'] ? ' · ' . $e($c['note']) : '' ?></small>
        <span class="rlink"><input class="mono" type="text" readonly value="<?= $e($link) ?>" aria-label="Enlace del código <?= $e($c['code']) ?>"><button class="btn btn--secondary btn--sm" type="button" data-copy="<?= $e($link) ?>"><?= Ui::icon('link') ?>Copiar</button></span></div>
      <div class="rrow__s"><span class="chip chip--plain"><?= $e($c['n_ref']) ?> <?= (int) $c['n_ref'] === 1 ? 'referido' : 'referidos' ?></span><span class="chip chip--plain"><?= $e($c['n_signed']) ?> <?= (int) $c['n_signed'] === 1 ? 'firmado' : 'firmados' ?></span>
        <span class="chip chip--<?= (int) $c['active'] === 1 ? 'ok' : 'dq' ?>"><?= (int) $c['active'] === 1 ? 'Activo' : 'Desactivado' ?></span></div>
      <div class="rrow__a">
        <form class="rrow__f" method="post" action="/referidos/<?= $e($c['id']) ?>/code"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="amount">
          <label class="field rrow__amt"><span class="sr">Monto del bono del código <?= $e($c['code']) ?></span><input type="text" name="bonus_amount" inputmode="decimal" placeholder="Bono $" value="<?= $e($c['bonus_amount'] !== null ? number_format((float) $c['bonus_amount'], 2, '.', '') : '') ?>" autocomplete="off"></label>
          <button class="btn btn--secondary btn--sm" type="submit">Guardar</button></form>
        <form method="post" action="/referidos/<?= $e($c['id']) ?>/code"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="<?= (int) $c['active'] === 1 ? 'disable' : 'enable' ?>">
          <button class="btn btn--ghost btn--sm" type="submit"><?= (int) $c['active'] === 1 ? 'Desactivar' : 'Activar' ?></button></form>
      </div>
      <?php if (!$confirmed && $c['bonus_amount'] !== null): ?><p class="rrow__prov">Monto provisional: pendiente de confirmar con NorthStar.</p><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<dialog class="dlg" id="code-new" aria-labelledby="code-new-t">
  <form method="post" action="/referidos/new">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="code-new-t">Nuevo código de referido</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <label class="field"><span>Nombre del referidor</span><input type="text" name="owner_name" required maxlength="120" autocomplete="off"></label>
    <div class="dlg__row">
      <label class="field"><span>Código (opcional)</span><input type="text" name="code" maxlength="40" autocomplete="off" placeholder="Se genera solo"><small>Letras, números, guion y guion bajo.</small></label>
      <label class="field"><span>Lead asociado (opcional)</span><input type="text" name="lead_id" inputmode="numeric" maxlength="12" autocomplete="off" placeholder="Número del lead"></label>
    </div>
    <label class="field"><span>Bono en dólares (provisional)</span><input type="text" name="bonus_amount" inputmode="decimal" maxlength="9" autocomplete="off" placeholder="Pendiente de confirmar con NorthStar"></label>
    <label class="field"><span>Nota (opcional)</span><input type="text" name="note" maxlength="255" autocomplete="off"></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Crear código</span></button></div>
  </form>
</dialog>
