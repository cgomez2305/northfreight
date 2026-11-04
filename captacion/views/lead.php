<?php
use Captacion\Ui;
use Captacion\Clock;
use Captacion\Services\Catalog;
use Captacion\Services\Charts;
use Captacion\Services\Fmt;

$id = (int) $lead['id'];
$name = Fmt::name($lead);
$pipeline = (string) $lead['pipeline'];
$stages = Catalog::STAGES[$pipeline] ?? Catalog::STAGES['A'];
$lost = $lead['stage'] === 'lost';
$closedKey = $pipeline === 'B' ? 'hired' : 'signed';
$flow = array_diff_key($stages, ['lost' => 1]);
$keys = array_keys($flow);
$curIdx = array_search($lead['stage'], $keys, true);
$fm = is_array($lead['fmcsa_data']) ? $lead['fmcsa_data'] : [];
$bd = is_array($lead['score_breakdown']) ? $lead['score_breakdown'] : [];
$items = $bd['items'] ?? [];
$maxPts = max(1, ...array_map(static fn($i) => (int) $i['points'], $items ?: [['points' => 1]]));
$phone = $lead['phone_e164'];
$wa = Fmt::waNumber($phone);
$waText = $lead['language'] === 'es' ? 'Hola ' . $lead['first_name'] . ', te escribe NorthStar Freight Solutions sobre tu solicitud.' : 'Hi ' . $lead['first_name'] . ', this is NorthStar Freight Solutions following up on your application.';
$flags = (array) $lead['flags'];
$activeConsents = array_filter($consents, static fn($c) => $c['revoked_at'] === null);
$stopped = in_array('stop', $flags, true);
$groups = ['all' => 'Todo', 'contact' => 'Contacto', 'stage' => 'Etapas', 'note' => 'Notas', 'task' => 'Tareas', 'system' => 'Sistema'];
$gc = array_fill_keys(array_keys($groups), 0);
foreach ($timeline as $t) {
    $gc['all']++;
    $gc[$t['group']] = ($gc[$t['group']] ?? 0) + 1;
}
$openTasks = array_filter($tasks, static fn($t) => $t['done_at'] === null);
$doneTasks = array_filter($tasks, static fn($t) => $t['done_at'] !== null);
$loc = trim(($lead['city'] ? $lead['city'] . ', ' : '') . ($lead['state'] ?? ''), ', ');
?>
<article class="lead" aria-labelledby="lead-name">
<header class="card lead__head">
  <div class="lead__id">
    <?= Ui::avatar($name, 'xl') ?>
    <div class="lead__who">
      <p class="label">LEAD #<?= $e(str_pad((string) $id, 4, '0', STR_PAD_LEFT)) ?> · RECIBIDO <?= $e(strtoupper(Fmt::ago($lead['created_at']))) ?> · <?= $e(strtoupper(Fmt::dateTime($lead['created_at']))) ?></p>
      <h1 class="h1 lead__n" id="lead-name"><?= $e($name) ?></h1>
      <div class="lead__chips">
        <span class="chip chip--plain"><?= Ui::icon($lead['profile'] === 'company_driver' ? 'user' : 'truck') ?><?= $e(Catalog::label(Catalog::PROFILES, $lead['profile'])) ?></span>
        <?= Ui::temp($lead['temperature']) ?>
        <?= Ui::stage($lead['stage'], $pipeline) ?>
        <?php foreach ($flags as $fl): $red = str_starts_with($fl, 'red:') || str_starts_with($fl, 'stop'); ?><span class="chip <?= $red ? 'chip--bad' : 'chip--plain' ?>"><?= Ui::icon($red ? 'alert' : 'flag') ?><?= $e(Catalog::label(Catalog::FLAGS, $fl)) ?></span><?php endforeach; ?>
      </div>
    </div>
    <div class="lead__score" role="img" aria-label="Puntuación <?= $e($lead['score']) ?> de 100, <?= $e(Catalog::label(Catalog::TEMPS, $lead['temperature'])) ?>">
      <div class="ring ring--<?= $e($lead['temperature'] === 'disqualified' ? 'dq' : $lead['temperature']) ?>"><?= Charts::donut((float) $lead['score'], 84) ?><span><b><?= $e($lead['score']) ?></b><small>/100</small></span></div>
    </div>
  </div>

  <div class="lead__acts">
    <?php if ($phone): ?><a class="btn btn--primary" href="tel:<?= $e($phone) ?>"><span class="btn__t">Llamar</span><span class="btn__cell"><?= Ui::icon('phone') ?></span></a><?php endif; ?>
    <?php if ($wa): ?><a class="btn btn--secondary" href="https://wa.me/<?= $e($wa) ?>?text=<?= $e(rawurlencode($waText)) ?>" rel="noopener noreferrer" target="_blank"><?= Ui::icon('chat') ?>WhatsApp</a><?php endif; ?>
    <?php if ($lead['email']): ?><a class="btn btn--secondary" href="mailto:<?= $e($lead['email']) ?>"><?= Ui::icon('mail') ?>Correo</a><?php endif; ?>
    <button class="btn btn--secondary" type="button" data-open="#call-dlg"><?= Ui::icon('history') ?>Registrar llamada</button>
    <?php if ($lost): ?>
      <form method="post" action="/leads/<?= $e($id) ?>/reopen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--secondary" type="submit"><?= Ui::icon('refresh') ?>Reabrir lead</button></form>
    <?php else: ?>
      <button class="btn btn--secondary" type="button" data-open="#stage-dlg"><?= Ui::icon('layers') ?>Cambiar etapa</button>
    <?php endif; ?>
  </div>

  <dl class="contact">
    <div><dt>Teléfono</dt><dd class="mono"><?= $phone ? '<a href="tel:' . $e($phone) . '">' . $e(Fmt::phone($phone)) . '</a>' : '<span class="muted">' . ($lead['phone_raw'] ? $e($lead['phone_raw']) . ' (inválido)' : '—') . '</span>' ?></dd></div>
    <div><dt>Correo</dt><dd><?= $lead['email'] ? '<a href="mailto:' . $e($lead['email']) . '">' . $e($lead['email']) . '</a>' : '—' ?></dd></div>
    <div><dt>Ubicación</dt><dd><?= $e($loc !== '' ? $loc : '—') ?></dd></div>
    <div><dt>Idioma</dt><dd><?= $e(Catalog::label(Catalog::LANGS, $lead['language'])) ?></dd></div>
    <div><dt>Prefiere</dt><dd><?= $e(['call' => 'Llamada', 'whatsapp' => 'WhatsApp', 'text' => 'Texto', 'email' => 'Correo'][$lead['contact_pref'] ?? ''] ?? '—') ?><?= $lead['best_time'] ? ' · ' . $e(['morning' => 'mañana', 'afternoon' => 'tarde', 'evening' => 'noche', 'any' => 'cualquier hora'][$lead['best_time']] ?? $lead['best_time']) : '' ?></dd></div>
    <div><dt>Primer contacto</dt><dd><?= $lead['first_contact_at'] ? $e(Fmt::dateTime($lead['first_contact_at'])) . ' <small class="muted">(' . $e(Fmt::dur(Fmt::secondsBetween($lead['created_at'], $lead['first_contact_at']))) . ' tras llegar)</small>' : '<span class="late">Sin contactar</span>' ?></dd></div>
  </dl>
</header>

<?php if ($lost): ?>
<p class="flash flash--bad" role="status"><?= Ui::icon('flag') ?><span><b><?= $e($stages['lost']) ?></b><?= $lead['lost_reason'] ? ' · ' . $e(Catalog::label(Catalog::LOSS_REASONS, $lead['lost_reason'])) : '' ?><?= $lead['lost_at'] ? ' · ' . $e(Fmt::dateTime($lead['lost_at'])) : '' ?>. Puedes reabrirlo si vuelve a mostrar interés.</span></p>
<?php elseif ($lead['temperature'] === 'disqualified'): ?>
<p class="flash flash--warn" role="status"><?= Ui::icon('info') ?><span><b>Descartado automáticamente:</b> <?= $e(implode(', ', array_map(static fn($r) => Catalog::label(Catalog::DISQUALIFIED, trim($r)), explode(',', (string) $lead['disqualified_reason'])))) ?>. Queda visible para revisión manual.</span></p>
<?php endif; ?>

<section class="card pipe" aria-label="Etapa del pipeline <?= $e($pipeline) ?>">
  <header class="card__h"><h2 class="label">PIPELINE <?= $pipeline === 'B' ? 'COMPANY DRIVERS' : 'OWNER-OPERATORS Y CARRIERS' ?></h2><span class="muted"><?= $curIdx !== false ? 'Etapa ' . ($curIdx + 1) . ' de ' . count($keys) : 'Fuera del pipeline' ?></span></header>
  <ol class="pipe__l">
    <?php foreach ($keys as $i => $k): $state = $curIdx === false ? 'todo' : ($i < $curIdx ? 'done' : ($i === $curIdx ? 'now' : 'todo')); ?>
      <li class="pipe__s pipe__s--<?= $e($state) ?><?= $k === $closedKey ? ' pipe__s--end' : '' ?>"<?= $state === 'now' ? ' aria-current="step"' : '' ?>><span class="pipe__d"><?= $state === 'done' ? Ui::icon('check') : $e($i + 1) ?></span><span class="pipe__t"><?= $e($flow[$k]) ?></span></li>
    <?php endforeach; ?>
  </ol>
</section>

<div class="lead__grid">
  <div class="lead__main">
    <section class="card" id="tareas" aria-labelledby="h-tasks">
      <header class="card__h"><h2 class="label" id="h-tasks">TAREAS</h2><span class="muted"><?= $e(count($openTasks)) ?> abierta<?= count($openTasks) === 1 ? '' : 's' ?></span></header>
      <div class="card__b">
        <?php if (!$tasks): ?><p class="muted pad-b">Sin tareas. Agenda el siguiente paso para no perder este lead.</p><?php endif; ?>
        <ul class="tasks">
          <?php foreach ($openTasks as $t): $late = $t['due_at'] !== null && Clock::parse((string) $t['due_at']) < Clock::time(); ?>
            <li class="task"><form method="post" action="/leads/<?= $e($id) ?>/task-done"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="task_id" value="<?= $e($t['id']) ?>"><button class="tick" type="submit" aria-label="Completar: <?= $e($t['title']) ?>"><?= Ui::icon('check') ?></button></form>
              <span class="task__t"><?= $e($t['title']) ?><small><i class="pri pri--<?= $e($t['priority']) ?>"></i><?= $e(Catalog::PRIORITIES[(int) $t['priority']] ?? '') ?><?= $t['due_at'] ? ' · <span class="' . ($late ? 'late' : '') . '">' . $e(Fmt::dateTime($t['due_at'])) . ($late ? ' · vencida' : '') . '</span>' : '' ?></small></span></li>
          <?php endforeach; ?>
          <?php foreach ($doneTasks as $t): ?>
            <li class="task is-done"><span class="tick is-on" aria-hidden="true"><?= Ui::icon('check') ?></span><span class="task__t"><s><?= $e($t['title']) ?></s><small>Completada <?= $e(Fmt::dateTime($t['done_at'])) ?></small></span></li>
          <?php endforeach; ?>
        </ul>
        <form class="addrow" method="post" action="/leads/<?= $e($id) ?>/task">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <label class="field field--grow"><span>Nueva tarea</span><input type="text" name="title" required maxlength="190" placeholder="Ej. Pedir copia del seguro"></label>
          <label class="field"><span>Para (hora <?= $e(Fmt::zone()) ?>)</span><input type="datetime-local" name="due"></label>
          <label class="field"><span>Prioridad</span><select name="priority"><?php foreach (Catalog::PRIORITIES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $k === 2 ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
          <button class="btn btn--secondary" type="submit"><?= Ui::icon('plus') ?>Añadir</button>
        </form>
      </div>
    </section>

    <section class="card" id="notas" aria-labelledby="h-notes">
      <header class="card__h"><h2 class="label" id="h-notes">NOTAS</h2><span class="muted"><?= $e(count($notes)) ?></span></header>
      <div class="card__b">
        <form class="notef" method="post" action="/leads/<?= $e($id) ?>/note">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <label class="sr" for="note-body">Nueva nota</label>
          <textarea id="note-body" name="body" rows="2" maxlength="4000" required placeholder="Escribe una nota: qué dijo, qué prometiste, qué falta…"></textarea>
          <button class="btn btn--secondary" type="submit">Guardar nota</button>
        </form>
        <?php if ($notes): ?>
        <ul class="notes-l">
          <?php foreach ($notes as $n): ?>
            <li class="nt">
              <header><span><b><?= $e($n['author'] ?? 'Sistema') ?></b> · <time title="<?= $e(Fmt::dateTime($n['created_at'])) ?>"><?= $e(Fmt::dateTime($n['created_at'])) ?></time><?= $n['updated_at'] !== $n['created_at'] && $n['updated_at'] > $n['created_at'] ? ' · editada' : '' ?></span>
                <span class="nt__a"><button class="icon-btn icon-btn--sm" type="button" data-toggle="#ne-<?= $e($n['id']) ?>" aria-expanded="false" aria-controls="ne-<?= $e($n['id']) ?>" aria-label="Editar nota"><?= Ui::icon('pencil') ?></button>
                <form method="post" action="/leads/<?= $e($id) ?>/note-delete"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="note_id" value="<?= $e($n['id']) ?>"><button class="icon-btn icon-btn--sm" type="submit" aria-label="Eliminar nota" data-confirm="Se quitará esta nota de la ficha. Queda en el registro de actividad."><?= Ui::icon('trash') ?></button></form></span></header>
              <p><?= nl2br($e($n['body'])) ?></p>
              <form class="nt__e" id="ne-<?= $e($n['id']) ?>" method="post" action="/leads/<?= $e($id) ?>/note-edit" hidden>
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="note_id" value="<?= $e($n['id']) ?>">
                <label class="sr" for="nb-<?= $e($n['id']) ?>">Editar nota</label><textarea id="nb-<?= $e($n['id']) ?>" name="body" rows="3" maxlength="4000" required><?= $e($n['body']) ?></textarea>
                <div class="nt__ea"><button class="btn btn--primary btn--sm" type="submit"><span class="btn__t">Guardar</span></button><button class="btn btn--ghost btn--sm" type="button" data-toggle="#ne-<?= $e($n['id']) ?>">Cancelar</button></div>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" id="linea" aria-labelledby="h-tl" data-tl>
      <header class="card__h"><h2 class="label" id="h-tl">LÍNEA DE TIEMPO</h2><span class="muted"><?= $e($gc['all']) ?> eventos</span></header>
      <div class="card__b">
        <div class="seg seg--sm seg--wrap" role="group" aria-label="Filtrar la línea de tiempo">
          <?php foreach ($groups as $g => $l): if ($g !== 'all' && $gc[$g] === 0) { continue; } ?><button class="seg__i<?= $g === 'all' ? ' is-on' : '' ?>" type="button" data-g="<?= $e($g) ?>" aria-pressed="<?= $g === 'all' ? 'true' : 'false' ?>"><?= $e($l) ?> <span class="seg__n"><?= $e($gc[$g]) ?></span></button><?php endforeach; ?>
        </div>
        <ol class="tl">
          <?php foreach ($timeline as $t): ?>
            <li class="tl__i tl__i--<?= $e($t['tone']) ?>" data-g="<?= $e($t['group']) ?>">
              <span class="tl__ic"><?= Ui::icon($t['icon']) ?></span>
              <div class="tl__b"><p class="tl__t"><?= $e($t['title']) ?></p><?php if ($t['detail'] !== ''): ?><p class="tl__d"><?= $e($t['detail']) ?></p><?php endif; ?></div>
              <time class="tl__w mono" title="<?= $e(Fmt::dateTime($t['at'])) ?>"><?= $e(Fmt::dateTime($t['at'], false)) ?><small><?= $e(Fmt::ago($t['at'])) ?><?= $t['by'] ? ' · ' . $e(explode(' ', $t['by'])[0]) : '' ?></small></time>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </section>
  </div>

  <div class="lead__side">
    <section class="card" aria-labelledby="h-score">
      <header class="card__h"><h2 class="label" id="h-score">PUNTUACIÓN DESGLOSADA</h2><b class="mono"><?= $e($lead['score']) ?>/100</b></header>
      <div class="card__b">
        <?php if (!$items): ?><p class="muted">Sin desglose guardado.</p><?php else: ?>
        <ul class="scorel">
          <?php foreach ($items as $it): $pts = (int) $it['points']; ?>
            <li class="<?= $pts > 0 ? 'is-on' : 'is-off' ?>"><span class="scorel__t"><?= $e(Catalog::SCORE_LABELS[$it['key'] ?? ''] ?? $it['label']) ?><?php if (!empty($it['note'])): ?><small><?= $e(Catalog::scoreNote((string) $it['note'])) ?></small><?php endif; ?></span><b class="mono"><?= $pts > 0 ? '+' . $e($pts) : '0' ?></b>
              <svg class="scorel__b" viewBox="0 0 100 4" preserveAspectRatio="none" aria-hidden="true"><rect class="scorel__bg" width="100" height="4" rx="2"/><?php if ($pts > 0): ?><rect class="scorel__fg" width="<?= $e(max(4, round($pts / $maxPts * 100))) ?>" height="4" rx="2"/><?php endif; ?></svg></li>
          <?php endforeach; ?>
        </ul>
        <p class="scorel__sum">Total <b><?= $e($bd['raw_total'] ?? $lead['score']) ?></b><?= !empty($bd['capped']) ? ' · limitado a 100' : '' ?> · Caliente desde 70, tibio desde 40.</p>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" aria-labelledby="h-op">
      <header class="card__h"><h2 class="label" id="h-op">ELEGIBILIDAD Y OPERACIÓN</h2></header>
      <div class="card__b"><dl class="kv">
        <div><dt>CDL</dt><dd><?= $e(Catalog::label(Catalog::CDL, $lead['cdl_type'])) ?><?= $lead['cdl_class'] ? ' · clase ' . $e($lead['cdl_class']) : '' ?></dd></div>
        <div><dt>Inglés</dt><dd><?= $e(Catalog::label(Catalog::ENGLISH, $lead['english_level'])) ?></dd></div>
        <div><dt>Experiencia</dt><dd><?= $lead['years_experience'] !== null ? $e($lead['years_experience']) . ' año' . ((int) $lead['years_experience'] === 1 ? '' : 's') : '—' ?></dd></div>
        <?php if ($lead['profile'] !== 'company_driver'): ?>
        <div><dt>Equipo</dt><dd><?= $e(Ui::equipment((array) $lead['equipment_types'], 6)) ?></dd></div>
        <div><dt>Camiones</dt><dd><?= $lead['truck_count'] !== null ? $e($lead['truck_count']) : '—' ?></dd></div>
        <div><dt>Autoridad</dt><dd><?= $e(Catalog::label(Catalog::AUTHORITY, $lead['authority_status'])) ?><?= $lead['authority_age_months'] !== null ? ' · ' . $e($lead['authority_age_months']) . ' meses' : '' ?></dd></div>
        <div><dt>USDOT</dt><dd class="mono"><?= $e($lead['usdot'] ?? '—') ?></dd></div>
        <?php else: ?>
        <div><dt>Endosos</dt><dd><?= $lead['endorsements'] ? $e(implode(', ', array_map('ucfirst', (array) $lead['endorsements']))) : '—' ?></dd></div>
        <?php if ($lead['job_slug']): ?><div><dt>Vacante</dt><dd><?= $e($lead['job_slug']) ?></dd></div><?php endif; ?>
        <?php endif; ?>
      </dl></div>
    </section>

    <section class="card" aria-labelledby="h-fm">
      <header class="card__h"><h2 class="label" id="h-fm">FMCSA</h2><?php if (!empty($fm['checked_at'])): ?><span class="muted">Consultado <?= $e(Fmt::ago($fm['checked_at'])) ?></span><?php endif; ?></header>
      <div class="card__b">
        <?php if (empty($fm)): ?>
          <p class="muted"><?= $lead['usdot'] ? (in_array('fmcsa_pending', $flags, true) ? 'Consulta pendiente: se reintenta desde la cola.' : 'Todavía sin consultar.') : 'El lead no dio USDOT, no hay nada que consultar.' ?></p>
        <?php elseif (empty($fm['found'])): ?>
          <p class="flash flash--warn"><?= Ui::icon('alert') ?>USDOT <?= $e($lead['usdot']) ?> no encontrado en FMCSA.</p>
        <?php else: ?>
          <?php foreach ((array) ($fm['red_flags'] ?? []) as $rf): ?><p class="flash flash--bad"><?= Ui::icon('alert') ?><b><?= $e(Catalog::label(Catalog::FLAGS, 'red:' . $rf)) ?></b></p><?php endforeach; ?>
          <dl class="kv">
            <div><dt>Razón social</dt><dd><?= $e($fm['legal_name'] ?? '—') ?></dd></div>
            <div><dt>Autoridad</dt><dd><?= $e(Catalog::label(Catalog::AUTHORITY, $fm['authority_status'] ?? null)) ?><?= isset($fm['authority_age_months']) ? ' · ' . $e($fm['authority_age_months']) . ' meses' : '' ?></dd></div>
            <div><dt>Seguro (BIPD)</dt><dd><?= ($fm['insured'] ?? null) === true ? 'Vigente' . (isset($fm['bipd_on_file']) ? ' · $' . $e(Fmt::num((int) $fm['bipd_on_file'])) . ' mil' : '') : (($fm['insured'] ?? null) === false ? 'Sin seguro registrado' : '—') ?></dd></div>
            <div><dt>Unidades / choferes</dt><dd><?= $e($fm['power_units'] ?? '—') ?> / <?= $e($fm['drivers'] ?? '—') ?></dd></div>
            <div><dt>Fuera de servicio</dt><dd>Chofer <?= isset($fm['driver_oos_rate']) ? $e($fm['driver_oos_rate']) . '%' : '—' ?> · Vehículo <?= isset($fm['vehicle_oos_rate']) ? $e($fm['vehicle_oos_rate']) . '%' : '—' ?></dd></div>
          </dl>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" aria-labelledby="h-at">
      <header class="card__h"><h2 class="label" id="h-at">ATRIBUCIÓN</h2><span class="muted">Intento <?= $e($lead['attempt']) ?></span></header>
      <div class="card__b"><dl class="kv">
        <div><dt>Fuente</dt><dd><?= $e(Catalog::source($lead['source'])) ?></dd></div>
        <div><dt>Página de llegada</dt><dd class="mono wrap"><?= $e($lead['landing_page'] ?? '—') ?></dd></div>
        <?php if ($lead['referrer']): ?><div><dt>Referente</dt><dd class="wrap"><?= $e($lead['referrer']) ?></dd></div><?php endif; ?>
        <?php foreach ((array) $lead['utm'] as $uk => $uv): ?><div><dt><?= $e(str_replace('_', ' ', $uk)) ?></dt><dd class="wrap"><?= $e(is_array($uv) ? implode(', ', $uv) : $uv) ?></dd></div><?php endforeach; ?>
        <?php if ($lead['referral_code']): ?><div><dt>Código de referido</dt><dd class="mono"><?= $e($lead['referral_code']) ?></dd></div><?php endif; ?>
        <?php if ($appts): ?><div><dt>Próxima llamada</dt><dd><?= $e(Fmt::dateTime($appts[0]['starts_at'])) ?></dd></div><?php endif; ?>
      </dl></div>
    </section>

    <section class="card" id="consentimientos" aria-labelledby="h-co">
      <header class="card__h"><h2 class="label" id="h-co">CONSENTIMIENTOS</h2><?php if ($stopped): ?><span class="chip chip--bad"><?= Ui::icon('alert') ?>STOP</span><?php endif; ?></header>
      <div class="card__b">
        <?php if (!$consents): ?><p class="muted">Este lead no dio consentimiento por escrito para llamadas, SMS ni correo. Solo contáctalo si él escribió primero.</p><?php else: ?>
        <ul class="cons">
          <?php foreach ($consents as $c): ?>
            <li class="<?= $c['revoked_at'] ? 'is-off' : '' ?>"><span class="chip chip--plain"><?= $e(['call' => 'Llamada', 'sms' => 'SMS', 'email' => 'Correo', 'whatsapp' => 'WhatsApp'][$c['channel']] ?? $c['channel']) ?></span>
              <span><?= $c['revoked_at'] ? 'Revocado ' . $e(Fmt::dateTime($c['revoked_at'])) . ($c['revoked_via'] ? ' · vía ' . $e($c['revoked_via']) : '') : 'Vigente desde ' . $e(Fmt::dateTime($c['granted_at'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <details class="fine"><summary>Texto aceptado<?= $consents[0]['consent_version'] ? ' (' . $e($consents[0]['consent_version']) . ')' : '' ?></summary><p><?= $e($consents[0]['consent_text']) ?></p></details>
        <?php if ($activeConsents): ?>
          <form method="post" action="/leads/<?= $e($id) ?>/revoke"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--danger-o btn--sm" type="submit" data-confirm="Se revocarán todos los consentimientos de este lead y quedará marcado como STOP: no se le debe llamar, escribir ni enviar SMS."><?= Ui::icon('shield') ?>Revocar consentimiento</button></form>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($dupes): ?>
    <section class="card" aria-labelledby="h-du">
      <header class="card__h"><h2 class="label" id="h-du">OTROS LEADS CON LOS MISMOS DATOS</h2></header>
      <div class="card__b"><ul class="dupes"><?php foreach ($dupes as $dp): ?><li><a href="/leads/<?= $e($dp['id']) ?>"><?= $e(Fmt::name($dp)) ?> <small class="mono">#<?= $e(str_pad((string) $dp['id'], 4, '0', STR_PAD_LEFT)) ?></small></a><?= Ui::stage($dp['stage']) ?></li><?php endforeach; ?></ul></div>
    </section>
    <?php endif; ?>
  </div>
</div>
</article>

<div class="leadbar" role="region" aria-label="Acciones rápidas">
  <?php if ($phone): ?><a class="leadbar__b leadbar__b--main" href="tel:<?= $e($phone) ?>"><?= Ui::icon('phone') ?><span>Llamar</span></a><?php endif; ?>
  <?php if ($wa): ?><a class="leadbar__b" href="https://wa.me/<?= $e($wa) ?>?text=<?= $e(rawurlencode($waText)) ?>" rel="noopener noreferrer" target="_blank"><?= Ui::icon('chat') ?><span>WhatsApp</span></a><?php endif; ?>
  <?php if ($lead['email']): ?><a class="leadbar__b" href="mailto:<?= $e($lead['email']) ?>"><?= Ui::icon('mail') ?><span>Correo</span></a><?php endif; ?>
  <?php if ($lost): ?>
    <form method="post" action="/leads/<?= $e($id) ?>/reopen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="leadbar__b" type="submit"><?= Ui::icon('refresh') ?><span>Reabrir</span></button></form>
  <?php else: ?>
    <button class="leadbar__b" type="button" data-open="#stage-dlg"><?= Ui::icon('layers') ?><span>Etapa</span></button>
  <?php endif; ?>
</div>

<?php if (!$lost): ?>
<dialog class="dlg" id="stage-dlg" aria-labelledby="stage-t">
  <form method="post" action="/leads/<?= $e($id) ?>/stage" data-stage-form data-override="<?= $e(json_encode($needsOverride)) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="stage-t">Cambiar etapa</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m">Ahora: <?= Ui::stage($lead['stage'], $pipeline) ?></p>
    <label class="field"><span>Nueva etapa</span>
      <select name="stage" required data-stage><?php foreach ($stageOptions as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field" data-reason hidden><span>Motivo (obligatorio)</span>
      <select name="reason"><option value="">Elige un motivo…</option><?php foreach (Catalog::LOSS_REASONS as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Nota (opcional; obligatoria con «Otro»)</span><textarea name="note" rows="2" maxlength="500"></textarea></label>
    <label class="check" data-override-box hidden><input type="checkbox" name="override" value="1"><span>Confirmo que quiero saltarme el orden de las etapas.</span></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Cambiar etapa</span><span class="btn__cell"><?= Ui::icon('arrow-up-right') ?></span></button></div>
  </form>
</dialog>
<?php endif; ?>
<dialog class="dlg" id="call-dlg" aria-labelledby="call-t">
  <form method="post" action="/leads/<?= $e($id) ?>/call">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="call-t">Registrar llamada</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <fieldset class="opts"><legend>Resultado</legend>
      <?php foreach (Catalog::CALL_OUTCOMES as $k => $l): ?><label class="opt"><input type="radio" name="outcome" value="<?= $e($k) ?>" required><span><?= $e($l) ?></span></label><?php endforeach; ?>
    </fieldset>
    <label class="field"><span>Duración (minutos, opcional)</span><input type="number" name="minutes" min="0" max="600" inputmode="numeric"></label>
    <label class="field"><span>Nota (opcional)</span><textarea name="note" rows="2" maxlength="500"></textarea></label>
    <p class="dlg__m small">Si el lead contestó y está en «Nuevo», pasa a «Contactado» y se cancela la segunda alerta.</p>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Guardar llamada</span><span class="btn__cell"><?= Ui::icon('arrow-up-right') ?></span></button></div>
  </form>
</dialog>
