<?php
use Captacion\Ui;
use Captacion\Services\Fmt;
use Captacion\Services\ProspectConfig;
use Captacion\Services\ProspectScript;
use Captacion\Services\Prospects;

/**
 * Detalle de un prospecto: llamar, guion, registrar el resultado (con calificación rápida y hora de rellamada) y, al lado, datos, prioridad, correos e historial.
 * Lo usan la fila de /prospectos y el Modo llamada (/prospectos/llamar). Necesita las variables de _prospect_vars.php y $csrf, $back, $todayEt, $resultIcon,
 * $dayFmt, $mailed, $autoMails, $script, $agent; $mode = 'list' | 'call'.
 */
$mode ??= 'list';
$q = is_array($r['qual'] ?? null) ? $r['qual'] : [];
$sv = ProspectScript::vars($r, (string) ($agent ?? ''));
$am = $autoMails[$id] ?? null;
ob_start();
?>
          <details class="pc__script" data-script<?= $mode === 'call' ? ' open' : '' ?>>
            <summary><?= Ui::icon('file') ?>Guion de la llamada</summary>
            <div class="pc__sl seg seg--tight" role="group" aria-label="Idioma del guion" data-script-lang hidden>
              <?php foreach (ProspectScript::LANGS as $l => $ll): ?><button class="seg__i<?= $script['lang'] === $l ? ' is-on' : '' ?>" type="button" data-lang="<?= $e($l) ?>" aria-pressed="<?= $script['lang'] === $l ? 'true' : 'false' ?>"><?= $e($ll) ?></button><?php endforeach; ?>
            </div>
            <?php foreach (ProspectScript::LANGS as $l => $ll): ?>
              <div class="pc__sc<?= $script['lang'] === $l ? ' is-on' : '' ?>" data-sc="<?= $e($l) ?>" lang="<?= $e($l) ?>"><?= ProspectScript::html($script[$l], $sv) ?></div>
            <?php endforeach; ?>
          </details>
<?php $scriptHtml = (string) ob_get_clean(); if (!$open) { $scriptHtml = ''; } ?>
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
              <p class="pc__att"><?= (int) $r['attempts'] > 0 ? 'Intentos sin respuesta: <b>' . $e($r['attempts']) . ' de ' . Prospects::MAX_ATTEMPTS . '</b>' : '' ?><?= $nextLabel !== null ? ((int) $r['attempts'] > 0 ? ' · ' : '') . ((int) ($r['callback_exact'] ?? 0) === 1 ? 'Pidió que lo llamaran: ' : 'Siguiente intento: ') . '<b>' . $e($nextLabel) . '</b>' : '' ?></p>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($open): ?>
          <?php if ($mode !== 'call') { echo $scriptHtml; } ?>

          <form class="pc__res" method="post" action="/prospectos/<?= $e($id) ?>/result" data-pr-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="back" value="<?= $e($back) ?>">
            <div class="pc__fields">
              <label class="field"><span>Nota (opcional)</span><input type="text" name="note" maxlength="500" autocomplete="off" placeholder="Qué dijo, a qué hora volver a llamar…"></label>
              <div class="field pc__next"><label for="nx-<?= $e($id) ?>">Siguiente intento (opcional)</label>
                <span class="pc__nx"><input id="nx-<?= $e($id) ?>" type="date" lang="es" name="next" min="<?= $e($todayEt) ?>"><input type="time" lang="es" name="next_time" step="900" min="08:00" max="20:45" aria-label="Hora de allá (opcional)" title="Hora de allá: la que pidió el prospecto (opcional)"><span class="qd" data-qd hidden><button type="button" data-days="1">Mañana</button><button type="button" data-days="3">En 3 días</button><button type="button" data-days="7">En 1 semana</button></span></span>
                <small class="pc__nxh">Con hora: es la hora local del prospecto. Sin hora: 9:00 a. m. ET.</small></div>
            </div>
            <details class="pc__q"<?= $q || $mode === 'call' ? ' open' : '' ?>>
              <summary>Datos de la llamada<?= $q ? ' <span class="muted">(' . $e(count($q)) . ')</span>' : '' ?></summary>
              <input type="hidden" name="q[_on]" value="1">
              <div class="pc__qg">
                <label class="field"><span>Contacto</span><input type="text" name="q[contact_name]" maxlength="80" autocomplete="off" value="<?= $e($q['contact_name'] ?? '') ?>" placeholder="Nombre de quien atendió"></label>
                <label class="field"><span>Correo que dio por teléfono</span><input type="email" name="q[email]" maxlength="190" autocomplete="off" value="<?= $e($q['email'] ?? '') ?>" placeholder="<?= $e($r['email'] ?? 'correo@ejemplo.com') ?>"></label>
                <label class="field"><span>Cuenta de Amazon Relay</span><select name="q[relay]"><option value="">Sin dato</option><?php foreach (Prospects::YESNO as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($q['relay'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Ya tiene dispatcher</span><select name="q[dispatcher]"><option value="">Sin dato</option><?php foreach (Prospects::YESNO as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($q['dispatcher'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Mejor horario</span><select name="q[best_time]"><option value="">Sin dato</option><?php foreach (Prospects::BEST as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($q['best_time'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
                <fieldset class="pc__eq"><legend>Equipo</legend><?php foreach (Prospects::QUAL_EQUIPMENT as $k => $l): ?><label class="check check--box"><input type="checkbox" name="q[equipment][]" value="<?= $e($k) ?>"<?= in_array($k, (array) ($q['equipment'] ?? []), true) ? ' checked' : '' ?>><span><?= $e($l) ?></span></label><?php endforeach; ?></fieldset>
              </div>
            </details>
            <div class="pc__btns" role="group" aria-label="Registrar el resultado de la llamada a <?= $e($name) ?>">
              <?php foreach (Prospects::RESULTS as $k => $label): ?>
                <button class="rbtn rbtn--<?= $e($k) ?>" type="submit" name="result" value="<?= $e($k) ?>"<?= $k === 'dnc' ? ' data-confirm-title="¿No llamar más?" data-confirm-label="Marcar como no llamar" data-confirm-danger data-confirm="¿Marcar «' . $e($name) . '» como No llamar más? Es permanente: su teléfono no volverá a listarse."' : '' ?><?= $k === 'interested' && ProspectConfig::get()['auto_convert'] ? ' title="Lo convierte en lead en el mismo clic"' : '' ?>><?= Ui::icon($resultIcon[$k]) ?><?= $e($label) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
          <?php endif; ?>
        </div>

        <aside class="pr__side" aria-label="Datos de <?= $e($name) ?>">
          <?php if ($mode === 'call') { echo $scriptHtml; } ?>
          <?php if ($open && $prio['why']): ?>
          <div class="pc__why"><p class="pc__wt"><i class="dot dot--<?= $e($prio['level']) ?>"></i><?= $e($prio['label']) ?></p><p class="muted"><?= $e(implode(' · ', $prio['why'])) ?></p></div>
          <?php endif; ?>
          <dl class="pc__d">
            <?php if ($r['dba'] && $r['dba'] !== $r['legal_name']): ?><div><dt>Nombre comercial</dt><dd><?= $e(Fmt::company((string) $r['dba'])) ?></dd></div><?php endif; ?>
            <div><dt>Autoridad concedida</dt><dd><?= $r['authority_granted_at'] ? $e(Fmt::et($r['authority_granted_at'] . ' 12:00:00')->format('j M Y')) : 'Sin fecha' ?></dd></div>
            <div><dt>Conductores</dt><dd><?= $r['drivers'] !== null && (int) $r['drivers'] > 0 && (int) $r['drivers'] < 500 ? $e($r['drivers']) : '—' ?></dd></div>
            <div><dt>Carga declarada</dt><dd><?= $cats ? $e(implode(' · ', array_map(static fn($c) => ProspectConfig::CARGO[$c][0], array_slice($cats, 0, 3)))) : '—' ?></dd></div>
            <?php if (($r['source'] ?? 'socrata') !== 'socrata'): ?><div><dt>Origen</dt><dd><?= $e(Prospects::SOURCES[$r['source']] ?? $r['source']) ?></dd></div><?php endif; ?>
            <?php foreach (Prospects::qualRows($q) as [$k, $v]): ?><div><dt><?= $e($k) ?></dt><dd><?= $e($v) ?></dd></div><?php endforeach; ?>
            <?php if ($r['email']): ?><div class="pc__mail"><dt>Correo en FMCSA</dt><dd class="mono"><?= $e($r['email']) ?></dd></div><?php endif; ?>
            <?php if (!empty($r['email_opt_out_at'])): ?><div class="pc__mail"><dt>Correos</dt><dd>Se dio de baja el <?= $e(Fmt::date((string) $r['email_opt_out_at'])) ?></dd></div>
            <?php elseif (isset($mailed[$id])): ?><div class="pc__mail"><dt>Último correo en lote</dt><dd><?= $e(Fmt::date($mailed[$id]['sent_at'])) ?> · <?= $e($mailed[$id]['name']) ?><small><?= !empty($mailed[$id]['replied']) ? 'Respondió' : (!empty($mailed[$id]['clicked']) ? 'Hizo clic en un enlace' : ($mailed[$id]['opened'] ? 'Lo abrió' : 'Sin abrir todavía')) ?></small></dd></div><?php endif; ?>
            <?php if ($am !== null): ?><div class="pc__mail"><dt>Correo tras no contestar</dt><dd><?= $am['status'] === 'sent' ? 'Enviado el ' . $e(Fmt::date((string) $am['sent_at'])) : ($am['status'] === 'queued' ? 'Sale en unos minutos' : ($am['status'] === 'failed' ? 'Falló el envío' : 'No se envió: ' . $e((string) $am['skip_reason']))) ?></dd></div><?php endif; ?>
          </dl>
          <?php if (!$dnc): ?>
          <div class="pc__f">
            <?php if ($conv): ?>
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
                <li><time class="mono"><?= $e($dayFmt((string) $h['created_at'])) ?></time><b><?= $e(Prospects::RESULTS[$h['result']] ?? ['reopened' => 'Reabierto', 'converted' => 'Convertido en lead', 'added' => 'Añadido a mano'][$h['result']] ?? $h['result']) ?></b><?= (int) $h['attempt_no'] > 0 ? ' <span class="muted">intento ' . $e($h['attempt_no']) . '</span>' : '' ?><?= $h['user_name'] ? ' <span class="muted">· ' . $e($h['user_name']) . '</span>' : '' ?><?= $h['note'] ? '<p>' . $e($h['note']) . '</p>' : '' ?></li>
              <?php endforeach; ?>
            </ol>
          </details>
          <?php endif; ?>
        </aside>
