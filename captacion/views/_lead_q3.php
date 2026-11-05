<?php
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\Sequences;
use Captacion\Ui;

/** Fase Q3 en la ficha: entrega a NorthStar (A9) y secuencias de correo (A6/A7/A12). Variables: $lead, $id, $csrf, $handoff, $seqs */
$hl = $handoff['latest'];
$tn = ['email' => 'Correo', 'webhook' => 'Webhook'];
$ds = ['pending' => 'en cola', 'retrying' => 'reintentando', 'sent' => 'enviada', 'failed' => 'falló', 'skipped' => 'omitida'];
$confTxt = $hl === null ? '' : ['signed' => 'Firmó', 'rejected' => 'Rechazado', 'superseded' => 'Reemplazada por una versión nueva'][$hl['confirm_status'] ?? ''] ?? 'Esperando respuesta';
$presented = $lead['stage'] === 'presented';
$post = static fn(string $action, string $label, string $icon, string $cls = 'btn--secondary', string $confirm = '', array $extra = []) => '<form method="post" action="/leads/' . (int) $id . '/' . $action . '" class="q3f">'
    . '<input type="hidden" name="_csrf" value="' . htmlspecialchars((string) $csrf, ENT_QUOTES) . '">'
    . implode('', array_map(static fn($k, $v) => '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES) . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES) . '">', array_keys($extra), $extra))
    . '<button class="btn btn--sm ' . $cls . '" type="submit"' . ($confirm !== '' ? ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"' : '') . '>' . Ui::icon($icon) . htmlspecialchars($label, ENT_QUOTES) . '</button></form>';
$activeSeq = array_filter($seqs, static fn($s) => $s['status'] === 'active');
?>
<?php if ($hl !== null || $presented): ?>
<section class="card" id="entrega" aria-labelledby="h-ho">
  <header class="card__h"><h2 class="label" id="h-ho">ENTREGA A NORTHSTAR</h2><?php if ($hl !== null): ?><span class="muted">Versión <?= $e($hl['version']) ?></span><?php endif; ?></header>
  <div class="card__b">
    <?php if ($presented && !$handoff['configured']): ?><p class="flash flash--warn" role="status"><?= Ui::icon('alert') ?><span><b>Destino de entrega sin configurar.</b> No se envió nada a NorthStar. <a href="/config?tab=entrega">Configurar destino</a>.</span></p><?php endif; ?>
    <?php if ($hl === null): ?><p class="muted">Este lead está en «Presentado a NorthStar» pero no tiene una entrega registrada. Usa «Reenviar entrega» para crearla.</p>
    <?php else: ?>
    <dl class="kv">
      <div><dt>Entregada</dt><dd><?= $e(Fmt::dateTime($hl['created_at'])) ?></dd></div>
      <?php foreach ($handoff['deliveries'] as $d): ?>
        <div><dt><?= $e($tn[$d['target']] ?? $d['target']) ?></dt><dd><?= $e($ds[$d['status']] ?? $d['status']) ?><?= $d['sent_at'] ? ' · ' . $e(Fmt::dateTime($d['sent_at'])) : '' ?><?= $d['error'] && $d['status'] !== 'sent' ? '<br><small class="late">' . $e($d['error']) . '</small>' : '' ?></dd></div>
      <?php endforeach; ?>
      <?php if (!$handoff['deliveries']): ?><div><dt>Destinos</dt><dd class="muted">Ninguno configurado al presentar</dd></div><?php endif; ?>
      <div><dt>NorthStar</dt><dd><b><?= $e($confTxt) ?></b><?= $hl['confirmed_at'] ? ' · ' . $e(Fmt::dateTime($hl['confirmed_at'])) . ($hl['confirmed_via'] === 'link' ? ' · por enlace' : ' · en el panel') : '' ?><?= $hl['reject_reason'] ? '<br><small>Motivo: ' . $e(Catalog::label(Catalog::LOSS_REASONS, $hl['reject_reason'])) . ($hl['reject_note'] ? ' · ' . $e($hl['reject_note']) : '') . '</small>' : '' ?></dd></div>
    </dl>
    <?php endif; ?>
    <?php if ($presented): ?>
    <div class="q3a">
      <?php if ($handoff['open']): ?>
        <?= $post('handoff-signed', 'Marcar firmó', 'check', 'btn--primary', 'Esto mueve el lead a «' . ($lead['pipeline'] === 'B' ? 'Contratado' : 'Firmado') . '», registra la conversión y programa el pedido de referidos. ¿Confirmas que NorthStar lo firmó?') ?>
        <button class="btn btn--sm btn--secondary" type="button" data-open="#reject-dlg"><?= Ui::icon('flag') ?>Marcar rechazado</button>
      <?php endif; ?>
      <?= $post('handoff-resend', 'Reenviar entrega', 'send', 'btn--ghost', 'Se crea una versión nueva de la entrega y los enlaces de la anterior dejan de valer. ¿Reenviar?') ?>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php if ($presented && $handoff['open']): ?>
<dialog class="dlg" id="reject-dlg" aria-labelledby="reject-t">
  <form method="post" action="/leads/<?= $e($id) ?>/handoff-rejected">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="reject-t">Marcar rechazado por NorthStar</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m">El lead pasa a «<?= $e($lead['pipeline'] === 'B' ? 'Descartado' : 'Perdido') ?>» con este motivo y queda una tarea para revisarlo.</p>
    <label class="field"><span>Motivo</span><select name="reason" required><?php foreach (Catalog::LOSS_REASONS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $k === 'rejected' ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Nota (opcional; obligatoria con «Otro»)</span><textarea name="note" rows="2" maxlength="500"></textarea></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Marcar rechazado</span><span class="btn__cell"><?= Ui::icon('arrow-up-right') ?></span></button></div>
  </form>
</dialog>
<?php endif; ?>
<?php endif; ?>

<?php if ($seqs): ?>
<section class="card" id="secuencias" aria-labelledby="h-sq">
  <header class="card__h"><h2 class="label" id="h-sq">SECUENCIAS DE CORREO</h2><span class="muted"><?= $e(count($activeSeq)) ?> activa<?= count($activeSeq) === 1 ? '' : 's' ?></span></header>
  <div class="card__b">
    <ul class="q3l">
      <?php foreach ($seqs as $s): $def = Sequences::DEFS[$s['sequence_key']] ?? ['name' => $s['sequence_key']]; ?>
        <li><b><?= $e($def['name']) ?></b>
          <small class="muted"><?= $e(Sequences::STATUS[$s['status']] ?? $s['status']) ?><?= $s['status'] === 'active' || $s['status'] === 'paused' ? ' · siguiente: ' . $e(Sequences::stepLabel($s['sequence_key'], (int) $s['step'])) : '' ?><?= $s['status'] === 'active' && $s['next_run_at'] ? ' · ' . $e(Fmt::dateTime($s['next_run_at'])) : '' ?></small>
          <?php if ($s['paused_reason']): ?><small class="muted"><?= $e(Sequences::REASONS[$s['paused_reason']] ?? $s['paused_reason']) ?></small><?php endif; ?>
          <?php if ($s['blocked_reason'] && $s['status'] === 'active'): ?><small class="late">Detenida: <?= $e($s['blocked_reason']) ?></small><?php endif; ?>
          <?php if (in_array($s['status'], ['active', 'paused'], true)): ?><span class="q3a">
            <?= $s['status'] === 'active' ? $post('seq-pause', 'Pausar', 'pause', 'btn--ghost', '', ['enrollment_id' => $s['id']]) : $post('seq-resume', 'Reanudar', 'refresh', 'btn--secondary', '', ['enrollment_id' => $s['id']]) ?>
            <?= $post('seq-remove', 'Sacar', 'x', 'btn--ghost', '¿Sacar a este lead de la secuencia?', ['enrollment_id' => $s['id']]) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($activeSeq): ?><div class="q3a"><?= $post('responded', 'El lead respondió', 'chat', 'btn--secondary', '', []) ?></div><?php endif; ?>
  </div>
</section>
<?php endif; ?>
