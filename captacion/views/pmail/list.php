<?php
use Captacion\Services\Fmt;
use Captacion\Services\ProspectMail;
use Captacion\Ui;

/** /prospectos/correos. Variables: $campaigns, $settings, $blockers, $inbox, $inboxReady, $csrf */
$chip = ['draft' => 'plain', 'sending' => 'ok', 'paused' => 'warm', 'done' => 'plain', 'cancelled' => 'dq'];
?>
<div class="page-head">
  <div>
    <h1 class="h1">Correos a prospectos</h1>
    <p class="sub">Correos en lote a transportistas de FMCSA, por segmentos de flota, estado y antigüedad de la autoridad. Cada correo lleva la dirección física y una baja de un clic.</p>
  </div>
  <div class="page-head__a">
    <a class="btn btn--primary" href="/prospectos/correos/nueva"><span class="btn__t">Nueva campaña</span></a>
  </div>
</div>

<?php $stabs = [['/prospectos', 'Llamadas', false], ['/prospectos/correos', 'Correos en lote', true]]; $stabsLabel = 'Secciones de prospectos'; require __DIR__ . '/../_stabs.php'; ?>

<?php foreach ($blockers as $b): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><span><?= $e($b) ?></span></p><?php endforeach; ?>

<?php if (!$campaigns): ?>
<section class="card empty empty--lg">
  <svg class="empty__star empty__star--lg" aria-hidden="true"><use href="#i-star"/></svg>
  <h2 class="h2">Todavía no hay campañas</h2>
  <p>Empieza con uno de estos textos y ajústalo: el segmento ya viene elegido. Antes de lanzar te pediremos una prueba a tu correo.</p>
  <ul class="pm-start">
    <?php foreach (ProspectMail::STARTERS as $k => $s): ?>
      <li><a class="pm-start__i" href="/prospectos/correos/nueva?inicio=<?= $e($k) ?>"><b><?= $e($s['label']) ?></b><span><?= $e($s['subject']) ?></span></a></li>
    <?php endforeach; ?>
    <li><a class="pm-start__i" href="/prospectos/correos/nueva"><b>Desde cero</b><span>Elige tú el segmento y escribe el texto.</span></a></li>
  </ul>
</section>
<?php else: ?>
<section class="card">
  <div class="pm-tw">
    <table class="tbl tbl--pm">
      <caption class="sr">Campañas de correo a prospectos</caption>
      <thead><tr><th scope="col">Campaña</th><th scope="col">Estado</th><th scope="col" class="pm-n">Enviados</th><th scope="col" class="pm-n">Abiertos</th><th scope="col" class="pm-n">Clics</th><th scope="col" class="pm-n">Respuestas</th><th scope="col">Fecha</th></tr></thead>
      <tbody>
      <?php foreach ($campaigns as $c): $k = $c['counts']; $sent = $k['sent']; ?>
        <tr>
          <td class="c-n"><a href="/prospectos/correos/<?= $e($c['id']) ?>"><b><?= $e($c['name']) ?></b></a><small class="muted"><?= $e(ProspectMail::segmentLabel($c['segment'])) ?></small></td>
          <td><span class="chip chip--<?= $e($chip[$c['status']] ?? 'plain') ?>"><?php if ($c['status'] === 'sending'): ?><i class="dot is-ok"></i><?php endif; ?><?= $e(ProspectMail::STATUS[$c['status']] ?? $c['status']) ?></span><?php if ($c['status'] === 'paused' && $c['paused_reason']): ?><small class="muted pm-why"><?= $e($c['paused_reason']) ?></small><?php endif; ?></td>
          <td class="pm-n"><?= $c['status'] === 'draft' ? '—' : $e(Fmt::num($sent)) . ' <span class="muted">de ' . $e(Fmt::num((int) $c['total'])) . '</span>' ?></td>
          <td class="pm-n"><?= $sent > 0 ? $e(Fmt::num($k['opened'])) . ' <span class="muted">(' . $e(Fmt::num(round($k['opened'] * 100 / $sent))) . ' %)</span>' : '—' ?></td>
          <td class="pm-n"><?= $sent > 0 ? $e(Fmt::num($k['clicked'])) : '—' ?></td>
          <td class="pm-n"><?= $sent > 0 ? '<b>' . $e(Fmt::num($k['replied'])) . '</b>' : '—' ?></td>
          <td><?= $e(Fmt::date((string) ($c['launched_at'] ?? $c['created_at']))) ?><small class="muted"><?= $c['launched_at'] ? 'lanzada' : 'creada' ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<details class="card pm-set">
  <summary class="card__h"><span class="label">Ajustes de envío</span><span class="muted">Hasta <?= $e($settings['cap_hour']) ?> correos por hora · <?= $settings['cooldown_days'] > 0 ? 'no se repite a un prospecto en ' . $e($settings['cooldown_days']) . ' días' : 'sin periodo de espera' ?> · respuestas <?= $settings['read_replies'] ? 'detectadas' : 'sin detectar' ?></span></summary>
  <form class="card__b pm-set__f" method="post" action="/prospectos/correos/ajustes">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Correos por hora (todas las campañas)</span><input type="number" name="cap_hour" min="1" max="200" value="<?= $e($settings['cap_hour']) ?>" required><small class="muted">Ir despacio cuida la reputación del dominio: con un buzón nuevo, mejor no pasar de 30 a 50 por hora.</small></label>
    <label class="field"><span>Días sin repetir a un mismo prospecto</span><input type="number" name="cooldown_days" min="0" max="365" value="<?= $e($settings['cooldown_days']) ?>" required><small class="muted">Quien recibió un correo de cualquier campaña en ese periodo no entra en la siguiente.</small></label>
    <label class="check pm-rr"><input type="checkbox" name="read_replies" value="1"<?= $settings['read_replies'] ? ' checked' : '' ?><?= $inboxReady ? '' : ' disabled' ?>><span><b>Detectar respuestas en el buzón</b><small>Cada 10 minutos lee, solo para consultar (no marca nada como leído ni mueve correos), el remitente de los correos nuevos del buzón <?= $e(\Captacion\Services\ProspectReplies::config()['user'] ?: 'del remitente') ?>. Si responde un prospecto: no recibe el segundo correo, sale el primero en la Lista de hoy y te avisa la campana.<?php if (!$inboxReady): ?> Falta configurar el acceso al buzón (IMAP o SMTP en el servidor).<?php elseif ($inbox['at']): ?> Última lectura <?= $e(Fmt::ago((string) $inbox['at'])) ?><?= $inbox['error'] ? ': ' . $e($inbox['error']) : ' · ' . $e(Fmt::num($inbox['found'])) . ' respuestas encontradas en total' ?>.<?php endif; ?></small></span></label>
    <div><button class="btn btn--secondary" type="submit"><span class="btn__t">Guardar ajustes</span></button></div>
  </form>
</details>

<details class="card pm-set">
  <summary class="card__h"><span class="label">A quién nunca se le escribe</span></summary>
  <ul class="card__b pm-rules">
    <li>Prospectos en «No llamar más» o «No interesado», y los que ya son lead.</li>
    <li>Quien se dio de baja de estos correos (la baja es permanente y queda en la lista de supresión).</li>
    <li>Correos inválidos, direcciones repetidas (un correo por dirección y campaña) y quien recibió otro correo dentro del periodo de espera.</li>
    <li>Las aperturas son orientativas: algunos clientes de correo cargan la imagen solos (cuentan de más) y otros la bloquean (cuentan de menos).</li>
  </ul>
</details>
