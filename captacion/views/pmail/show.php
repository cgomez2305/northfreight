<?php
use Captacion\Services\Fmt;
use Captacion\Services\ProspectMail;
use Captacion\Ui;

/** Campaña lanzada. Variables: $c, $stats, $ver, $list, $settings, $csrf */
$total = max(1, (int) $c['total']);
$done = $stats['sent'] + $stats['failed'] + $stats['skipped'];
$pct = (int) floor($done * 100 / $total);
$openPct = $stats['sent'] > 0 ? (int) round($stats['opened'] * 100 / $stats['sent']) : null;
$chip = ['sending' => 'ok', 'paused' => 'warm', 'done' => 'plain', 'cancelled' => 'dq'];
$tabs = ['' => ['Todos', (int) $c['total']], 'sent' => ['Enviados', $stats['sent']], 'opened' => ['Abiertos', $stats['opened']], 'queued' => ['En cola', $stats['queued']], 'skipped' => ['Omitidos', $stats['skipped']], 'failed' => ['Fallidos', $stats['failed']]];
$rChip = ['queued' => 'plain', 'sent' => 'ok', 'failed' => 'bad', 'skipped' => 'dq'];
$base = '/prospectos/correos/' . (int) $c['id'];
?>
<div class="page-head">
  <div>
    <h1 class="h1"><?= $e($c['name']) ?></h1>
    <p class="sub"><span class="chip chip--<?= $e($chip[$c['status']] ?? 'plain') ?>"><?php if ($c['status'] === 'sending'): ?><i class="dot is-ok"></i><?php endif; ?><?= $e(ProspectMail::STATUS[$c['status']] ?? $c['status']) ?></span> <?= $e(ProspectMail::segmentLabel($c['segment'])) ?> · lanzada <?= $e(Fmt::dateTime((string) $c['launched_at'])) ?></p>
  </div>
  <div class="page-head__a">
    <a class="btn btn--ghost" href="/prospectos/correos">Volver a las campañas</a>
    <?php if ($c['status'] === 'sending'): ?>
      <form method="post" action="<?= $e($base) ?>/pausar"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--secondary" type="submit"><?= Ui::icon('pause') ?><span class="btn__t">Pausar</span></button></form>
    <?php elseif ($c['status'] === 'paused'): ?>
      <form method="post" action="<?= $e($base) ?>/reanudar"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--primary" type="submit"><?= Ui::icon('play') ?><span class="btn__t">Reanudar</span></button></form>
    <?php endif; ?>
    <?php if (in_array($c['status'], ['sending', 'paused'], true)): ?>
      <form method="post" action="<?= $e($base) ?>/cancelar"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--ghost" type="submit"<?= Ui::confirm(['title' => '¿Cancelar la campaña?', 'text' => 'Los ' . Fmt::num($stats['queued']) . ' correos que faltan ya no se enviarán. Los enviados no se pueden recuperar.', 'label' => 'Cancelar campaña', 'danger' => true]) ?>><span class="btn__t">Cancelar</span></button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($c['status'] === 'paused' && $c['paused_reason']): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><span><?= $e($c['paused_reason']) ?></span></p><?php endif; ?>

<section class="card pm-kpis" aria-label="Resultado de la campaña">
  <div class="pm-prog">
    <p><b><?= $e(Fmt::num($done)) ?></b> de <?= $e(Fmt::num((int) $c['total'])) ?> procesados<?= $c['status'] === 'sending' ? ' · ' . $e(Fmt::num($stats['queued'])) . ' en cola, a ' . $e($settings['cap_hour']) . ' por hora' : '' ?></p>
    <progress max="100" value="<?= $e($pct) ?>" aria-label="Progreso del envío"><?= $e($pct) ?> %</progress>
  </div>
  <dl class="pm-k">
    <div><dt>Enviados</dt><dd><?= $e(Fmt::num($stats['sent'])) ?></dd></div>
    <div><dt>Abiertos</dt><dd><?= $e(Fmt::num($stats['opened'])) ?><?php if ($openPct !== null): ?> <small><?= $e($openPct) ?> %</small><?php endif; ?></dd></div>
    <div><dt>Omitidos</dt><dd><?= $e(Fmt::num($stats['skipped'])) ?></dd></div>
    <div><dt>Fallidos</dt><dd><?= $e(Fmt::num($stats['failed'])) ?></dd></div>
  </dl>
  <p class="muted pm-help">Las aperturas son orientativas (algunos clientes de correo cargan la imagen solos y otros la bloquean). Las respuestas llegan a tu buzón; las bajas se aplican solas.</p>
</section>

<details class="card pm-set">
  <summary class="card__h"><span class="label">Correo enviado</span><span class="muted"><?= $e($c['subject']) ?></span></summary>
  <div class="card__b"><div class="cm-body"><?= nl2br($e($c['body'])) ?></div></div>
</details>

<nav class="ptabs" aria-label="Destinatarios por resultado">
  <?php foreach ($tabs as $k => [$label, $n]): $on = $ver === $k; ?><a class="ptab<?= $on ? ' is-on' : '' ?>" href="<?= $e($base . ($k !== '' ? '?ver=' . $k : '')) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= $e($label) ?><span class="ptab__n"><?= $e(Fmt::num($n)) ?></span></a><?php endforeach; ?>
</nav>

<section class="card">
  <?php if (!$list['rows']): ?>
    <div class="empty empty--sm"><svg class="empty__star" aria-hidden="true"><use href="#i-star"/></svg><p>Nadie en esta pestaña.</p></div>
  <?php else: ?>
  <div class="pm-tw">
    <table class="tbl tbl--pm">
      <caption class="sr">Destinatarios de la campaña</caption>
      <thead><tr><th scope="col">Transportista</th><th scope="col">Correo</th><th scope="col">Resultado</th><th scope="col">Enviado</th><th scope="col">Abierto</th></tr></thead>
      <tbody>
      <?php foreach ($list['rows'] as $r): $name = Fmt::company((string) ($r['legal_name'] ?: ($r['dba'] ?: 'USDOT ' . $r['usdot']))); ?>
        <tr>
          <td class="c-n"><b><?= $e($name) ?></b><small class="muted"><?= $e(trim(($r['city'] ? ucwords(strtolower((string) $r['city'])) . ', ' : '') . ($r['state'] ?? ''), ' ,')) ?><?= $r['fleet_size'] !== null ? ' · ' . $e($r['fleet_size']) . ((int) $r['fleet_size'] === 1 ? ' unidad' : ' unidades') : '' ?></small></td>
          <td class="pm-mail"><?= $e($r['email']) ?></td>
          <td><span class="chip chip--<?= $e($rChip[$r['status']] ?? 'plain') ?>"><?= $e(ProspectMail::R_STATUS[$r['status']] ?? $r['status']) ?></span><?php if ($r['skip_reason'] || $r['error']): ?><small class="muted pm-why"><?= $e($r['skip_reason'] ?: mb_substr((string) $r['error'], 0, 90)) ?></small><?php endif; ?></td>
          <td><?= $r['sent_at'] ? $e(Fmt::dateTime((string) $r['sent_at'])) : '—' ?></td>
          <td><?= $r['opened_at'] ? $e(Fmt::dateTime((string) $r['opened_at'])) . ((int) $r['open_count'] > 1 ? ' <small class="muted">· ' . $e($r['open_count']) . ' veces</small>' : '') : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <footer class="card__f"><span class="muted">Mostrando <?= $e(Fmt::num(count($list['rows']))) ?> de <?= $e(Fmt::num($list['total'])) ?></span><?= Ui::pager($base, $ver !== '' ? ['ver' => $ver] : [], 'page', $list['page'], $list['pages']) ?></footer>
  <?php endif; ?>
</section>
