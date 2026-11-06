<?php
use Captacion\Clock;
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\Kanban;
use Captacion\Ui;

/** Contenido del vistazo rapido (fragmento, ya escapado). Variables: $lead, $tasks, $timeline, $stages, $openTasks, $now. */
$id = (int) $lead['id'];
$name = Fmt::name($lead);
$phone = $lead['phone_e164'];
$wa = Fmt::waNumber($phone);
$waText = $lead['language'] === 'es' ? 'Hola ' . $lead['first_name'] . ', te escribe NorthStar Freight Solutions sobre tu solicitud.' : 'Hi ' . $lead['first_name'] . ', this is NorthStar Freight Solutions following up on your application.';
$fm = is_array($lead['fmcsa_data']) ? $lead['fmcsa_data'] : [];
$flags = (array) $lead['flags'];
[$pos, $tot] = Kanban::progress((string) $lead['pipeline'], (string) $lead['stage']);
$loc = trim(($lead['city'] ? $lead['city'] . ', ' : '') . ($lead['state'] ?? ''), ', ');
$lost = $lead['stage'] === 'lost';
?>
<div class="pk__top">
  <div class="pk__id">
    <?= Ui::avatar($name, 'lg') ?>
    <div class="pk__who">
      <p class="label"><?= $e(Fmt::code($id)) ?> · <?= $e(strtoupper(Fmt::ago($lead['created_at']))) ?></p>
      <h2 class="pk__n" id="pk-title" tabindex="-1"><?= $e($name) ?></h2>
    </div>
  </div>
  <div class="pk__chips">
    <?= Ui::temp((string) $lead['temperature']) ?>
    <?= Ui::stage((string) $lead['stage'], (string) $lead['pipeline']) ?>
    <?= Ui::tags($lead, 1) ?>
    <span class="pk__score"><?= Ui::scoreBar((int) $lead['score'], (string) $lead['temperature']) ?><b><?= $e($lead['score']) ?></b><small>/100</small></span>
  </div>
  <div class="pk__acts">
    <?php if ($phone): ?><a class="btn btn--primary btn--sm" href="tel:<?= $e($phone) ?>"><span class="btn__t">Llamar</span><span class="btn__cell"><?= Ui::icon('phone') ?></span></a><?php endif; ?>
    <?php if ($wa): ?><a class="btn btn--secondary btn--sm" href="https://wa.me/<?= $e($wa) ?>?text=<?= $e(rawurlencode($waText)) ?>" rel="noopener noreferrer" target="_blank"><?= Ui::icon('chat') ?>WhatsApp</a><?php endif; ?>
    <?php if ($lead['email']): ?><a class="btn btn--secondary btn--sm" href="mailto:<?= $e($lead['email']) ?>"><?= Ui::icon('mail') ?>Correo</a><?php endif; ?>
  </div>
</div>

<div class="pk__body">
  <section class="pk__sec" aria-label="Etapa">
    <div class="pk__row">
      <span class="label">ETAPA</span>
      <span class="pk__prog" title="Avance en el pipeline"><?= $pos > 0 ? $e($pos . '/' . $tot) : '—' ?><i class="prog" role="img" aria-label="<?= $pos > 0 ? $e('Etapa ' . $pos . ' de ' . $tot) : 'Fuera del pipeline' ?>"><?php for ($i = 1; $i <= $tot; $i++): ?><b<?= $i <= $pos ? ' class="on"' : '' ?>></b><?php endfor; ?></i></span>
    </div>
    <?php if ($lost): ?>
      <p class="muted"><?= $e(Catalog::stageAny('lost')) ?> · <?= $e(Catalog::label(Catalog::LOSS_REASONS, $lead['lost_reason'], 'sin motivo')) ?>. Reábrelo desde la ficha.</p>
    <?php else: ?>
      <div class="pk__move">
        <label class="sr" for="pk-stage">Cambiar etapa</label>
        <select id="pk-stage" data-pk-stage data-lead="<?= $e($id) ?>">
          <?php foreach ($stages as $k => $l): ?><option value="<?= $e($k) ?>"<?= $k === $lead['stage'] ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn--secondary btn--sm" type="button" data-pk-move><?= Ui::icon('layers') ?>Cambiar etapa</button>
      </div>
    <?php endif; ?>
  </section>

  <section class="pk__sec" aria-label="Datos clave">
    <h3 class="label">DATOS CLAVE</h3>
    <dl class="pk__dl">
      <div><dt>Teléfono</dt><dd class="mono"><?= $phone ? $e(Fmt::phone($phone)) : '<span class="muted">' . ($lead['phone_raw'] ? $e($lead['phone_raw']) . ' (inválido)' : '—') . '</span>' ?></dd></div>
      <div><dt>Correo</dt><dd><?= $lead['email'] ? $e($lead['email']) : '—' ?></dd></div>
      <div><dt>Ubicación</dt><dd><?= $e($loc !== '' ? $loc : '—') ?></dd></div>
      <div><dt>Equipo</dt><dd><?= $e(Ui::equipment((array) $lead['equipment_types'], 3)) ?></dd></div>
      <div><dt>Camiones</dt><dd><?= $lead['truck_count'] !== null ? $e((int) $lead['truck_count']) : '—' ?></dd></div>
      <div><dt>USDOT</dt><dd class="mono"><?= $lead['usdot'] ? $e($lead['usdot']) : '—' ?></dd></div>
      <div><dt>Fuente</dt><dd><?= $e(Catalog::source($lead['source'])) ?></dd></div>
      <div><dt>Primer contacto</dt><dd><?= $lead['first_contact_at'] ? $e(Fmt::ago($lead['first_contact_at'])) : '<span class="late">Sin contactar</span>' ?></dd></div>
    </dl>
    <?php if ($flags || $fm): ?>
      <p class="pk__flags">
        <?php if ($fm && !empty($fm['found'])): ?><span class="chip chip--plain"><?= Ui::icon('shield') ?>FMCSA: <?= $e(Catalog::label(Catalog::AUTHORITY, (string) ($fm['authority_status'] ?? ''), 'sin dato')) ?></span><?php endif; ?>
        <?php foreach ($flags as $fl): $red = str_starts_with((string) $fl, 'red:') || str_starts_with((string) $fl, 'stop'); ?><span class="chip <?= $red ? 'chip--bad' : 'chip--plain' ?>"><?= Ui::icon($red ? 'alert' : 'flag') ?><?= $e(Catalog::label(Catalog::FLAGS, (string) $fl)) ?></span><?php endforeach; ?>
      </p>
    <?php endif; ?>
  </section>

  <?php $pkRules = is_array($lead['score_breakdown'] ?? null) ? (array) ($lead['score_breakdown']['rules'] ?? []) : []; if ($pkRules): ?>
  <section class="pk__sec" aria-label="Reglas que influyeron">
    <h3 class="label">REGLAS QUE INFLUYERON</h3>
    <ul class="pk__rules">
      <?php foreach ($pkRules as $ru): $fx = array_values(array_filter(array_map([\Captacion\Services\RulePhrase::class, 'effect'], (array) ($ru['effects'] ?? [])))); ?>
        <li><span><?= $e($ru['name'] ?? 'Regla') ?><small><?= $e($ru['set'] ?? '') ?></small></span><b class="mono"><?= $e(implode(' · ', $fx)) ?></b></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="pk__sec" aria-label="Próximas tareas">
    <h3 class="label">PRÓXIMAS TAREAS <span class="muted">(<?= $e($openTasks) ?>)</span></h3>
    <?php if (!$tasks): ?><p class="muted">Sin tareas abiertas. Agenda el siguiente paso abajo.</p><?php endif; ?>
    <ul class="pk__tasks">
      <?php foreach ($tasks as $t): $late = $t['due_at'] !== null && (int) Clock::parse((string) $t['due_at']) < $now; ?>
        <li><button class="tick" type="button" data-pk-done="<?= $e($t['id']) ?>" aria-label="Completar: <?= $e($t['title']) ?>"><?= Ui::icon('check') ?></button>
          <span><?= $e($t['title']) ?><small class="<?= $late ? 'late' : '' ?>"><?= $t['due_at'] !== null ? $e(Fmt::dateTime((string) $t['due_at'])) : 'Sin fecha' ?></small></span></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="pk__sec" aria-label="Actividad reciente">
    <h3 class="label">ACTIVIDAD RECIENTE</h3>
    <?php if (!$timeline): ?><p class="muted">Sin actividad todavía.</p><?php endif; ?>
    <ol class="pk__tl">
      <?php foreach ($timeline as $t): ?>
        <li><i class="dot dot--<?= $e($t['tone'] === 'bad' ? 'bad' : ($t['tone'] === 'good' ? 'ok' : 'info')) ?>"></i><span><b><?= $e($t['title']) ?></b><?= $t['detail'] !== '' ? '<small>' . $e(mb_substr($t['detail'], 0, 90)) . '</small>' : '' ?></span><time title="<?= $e(Fmt::dateTime($t['at'])) ?>"><?= $e(Fmt::ago($t['at'])) ?></time></li>
      <?php endforeach; ?>
    </ol>
  </section>

  <section class="pk__sec pk__forms" aria-label="Acciones rápidas">
    <details class="pk__f" data-pk-form="note">
      <summary><?= Ui::icon('note') ?>Nueva nota</summary>
      <label class="sr" for="pk-note">Nota</label>
      <textarea id="pk-note" rows="3" maxlength="4000" placeholder="Qué dijo, qué prometiste, qué falta…"></textarea>
      <button class="btn btn--primary btn--sm" type="button" data-pk-save="note"><span class="btn__t">Guardar nota</span></button>
    </details>
    <details class="pk__f" data-pk-form="task">
      <summary><?= Ui::icon('calendar') ?>Nueva tarea</summary>
      <label class="field"><span>Qué hay que hacer</span><input type="text" id="pk-task-t" maxlength="190" placeholder="Ej. Pedir copia del seguro"></label>
      <label class="field"><span>Para (hora <?= $e(Fmt::zone()) ?>)</span><input type="datetime-local" id="pk-task-d"></label>
      <button class="btn btn--primary btn--sm" type="button" data-pk-save="task"><span class="btn__t">Crear tarea</span></button>
    </details>
    <details class="pk__f" data-pk-form="call">
      <summary><?= Ui::icon('history') ?>Registrar llamada</summary>
      <label class="field"><span>Resultado</span><select id="pk-call-o"><?php foreach (Catalog::CALL_OUTCOMES as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Minutos (opcional)</span><input type="number" id="pk-call-m" min="0" max="600" inputmode="numeric"></label>
      <label class="field"><span>Nota (opcional)</span><input type="text" id="pk-call-n" maxlength="500"></label>
      <button class="btn btn--primary btn--sm" type="button" data-pk-save="call"><span class="btn__t">Registrar</span></button>
    </details>
  </section>
</div>
<footer class="pk__foot"><a class="btn btn--secondary" href="/leads/<?= $e($id) ?>">Abrir ficha completa<?= Ui::icon('arrow-up-right') ?></a></footer>
