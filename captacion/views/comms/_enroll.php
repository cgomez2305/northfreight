<?php
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\Sequences;
use Captacion\Ui;

/** Variables: $rows, $counts, $st, $sq, $caps, $sentHour, $csrf */
$btn = static fn(int $id, string $a, string $label, string $cls, string $confirm = ''): string => '<form class="inl" method="post" action="/comunicaciones/inscripciones/' . $id . '/' . $a . '"><input type="hidden" name="_csrf" value="' . htmlspecialchars((string) $csrf, ENT_QUOTES) . '"><button class="btn btn--sm ' . $cls . '" type="submit"' . ($confirm !== '' ? ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"' : '') . '>' . htmlspecialchars($label, ENT_QUOTES) . '</button></form>';
?>
<div class="cm-seqs">
  <?php foreach (Sequences::DEFS as $key => $def): $c = $counts[$key] ?? []; ?>
    <section class="card" aria-labelledby="sq-<?= $e($key) ?>">
      <header class="card__h"><h2 class="label" id="sq-<?= $e($key) ?>"><?= $e($def['name']) ?></h2><span class="muted"><?= $e($c['active'] ?? 0) ?> activa<?= ($c['active'] ?? 0) === 1 ? '' : 's' ?></span></header>
      <div class="card__b">
        <ol class="cm-steps"><?php foreach ($def['steps'] as $s): ?><li><b><?= $e($s['label']) ?></b><?php if (!empty($s['needs_source'])): ?><small class="muted"><?= $caps['rates_source'] === '' ? 'Se omite: no hay fuente real de tarifas' : 'Fuente: ' . $e($caps['rates_source']) ?></small><?php endif; ?></li><?php endforeach; ?></ol>
        <p class="muted"><?= $def['pause_on_progress'] ? 'Se pausa sola cuando el lead pasa a Contactado, Calificado, Perdido, Firmado… o con «El lead respondió» en su ficha.' : 'Se programa al firmar el lead.' ?></p>
        <p class="muted">En pausa: <?= $e($c['paused'] ?? 0) ?> · Completadas: <?= $e($c['completed'] ?? 0) ?> · Detenidas: <?= $e($c['stopped'] ?? 0) ?></p>
      </div>
    </section>
  <?php endforeach; ?>
  <section class="card" aria-labelledby="sq-cap">
    <header class="card__h"><h2 class="label" id="sq-cap">Topes de envío</h2></header>
    <div class="card__b"><p>Esta hora: <b><?= $e($sentHour) ?></b> de <b><?= $e($caps['cap_hour']) ?></b> correos de secuencias.</p><p class="muted">Máximo <?= $e($caps['cap_lead']) ?> por lead cada 24 horas. Se cambian en <a href="/config?tab=correo">Configuración &gt; Correo</a>.</p></div>
  </section>
</div>

<section class="card card--table" aria-labelledby="en-l">
  <header class="card__h"><h2 class="label" id="en-l">Inscripciones</h2>
    <form class="cm-filter" method="get" action="/comunicaciones/inscripciones">
      <label class="sr" for="f-sq">Secuencia</label><select id="f-sq" name="sq"><option value="">Todas las secuencias</option><?php foreach (Sequences::DEFS as $k => $d): ?><option value="<?= $e($k) ?>"<?= $sq === $k ? ' selected' : '' ?>><?= $e($d['name']) ?></option><?php endforeach; ?></select>
      <label class="sr" for="f-st">Estado</label><select id="f-st" name="st"><option value="">Todos los estados</option><?php foreach (Sequences::STATUS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $st === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
      <button class="btn btn--sm btn--secondary" type="submit">Filtrar</button>
    </form></header>
  <?php if (!$rows): ?>
    <div class="empty empty--sm"><?= Ui::icon('star', 'empty__star ic--star') ?><h3>Nadie está inscrito<?= $st !== '' || $sq !== '' ? ' con ese filtro' : ' todavía' ?></h3><p>Los leads tibios con consentimiento de correo entran a A6 solos cuando las secuencias están encendidas; al firmar, cada lead entra a A12.</p></div>
  <?php else: ?>
    <div class="tw"><table class="tbl tbl--cm">
      <thead><tr><th scope="col">Lead</th><th scope="col">Secuencia</th><th scope="col">Paso siguiente</th><th scope="col">Estado</th><th scope="col">Próximo envío</th><th scope="col"><span class="sr">Acciones</span></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><th scope="row" class="c-n"><a href="/leads/<?= $e($r['lead_id']) ?>"><?= $e(Fmt::name($r)) ?></a><small class="muted"><?= $e(Catalog::label(Catalog::LANGS, $r['language'])) ?></small></th>
            <td data-l="Secuencia"><?= $e(Sequences::DEFS[$r['sequence_key']]['name'] ?? $r['sequence_key']) ?></td>
            <td data-l="Paso siguiente"><?= in_array($r['status'], ['active', 'paused'], true) ? $e(Sequences::stepLabel($r['sequence_key'], (int) $r['step'])) : '—' ?></td>
            <td data-l="Estado"><span class="chip chip--<?= $r['status'] === 'active' ? 'warm' : 'plain' ?>"><i class="dot"></i><?= $e(Sequences::STATUS[$r['status']] ?? $r['status']) ?></span><?php if ($r['paused_reason']): ?><small class="muted"><?= $e(Sequences::REASONS[$r['paused_reason']] ?? $r['paused_reason']) ?></small><?php endif; ?><?php if ($r['blocked_reason'] && $r['status'] === 'active'): ?><small class="late"><?= $e($r['blocked_reason']) ?></small><?php endif; ?></td>
            <td class="c-when" data-l="Próximo envío"><?= $r['status'] === 'active' && $r['next_run_at'] ? $e(Fmt::dateTime($r['next_run_at'])) : '—' ?></td>
            <td class="c-act"><?php if ($r['status'] === 'active'): ?><?= $btn((int) $r['id'], 'pause', 'Pausar', 'btn--ghost') ?><?php elseif ($r['status'] === 'paused'): ?><?= $btn((int) $r['id'], 'resume', 'Reanudar', 'btn--secondary') ?><?php endif; ?>
              <?php if (in_array($r['status'], ['active', 'paused'], true)): ?><?= $btn((int) $r['id'], 'remove', 'Sacar', 'btn--ghost', '¿Sacar a este lead de la secuencia?') ?><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
