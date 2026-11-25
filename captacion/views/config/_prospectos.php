<?php
use Captacion\Services\Fmt;
use Captacion\Services\ProspectConfig;
use Captacion\Services\ProspectImport;
use Captacion\Services\UsStates;
use Captacion\Ui;

/** Variables: $pc (filtros vigentes), $pForm (valores enviados con error o null), $pRun, $pComplete, $pCron, $pSocrata, $pToken, $errors, $locked, $csrf */
$cur = $pForm !== null ? [
    'enabled' => ($pForm['enabled'] ?? '0') === '1', 'require_phone' => ($pForm['require_phone'] ?? '0') === '1', 'max_age_months' => (string) ($pForm['max_age_months'] ?? ''), 'min_age_months' => (string) ($pForm['min_age_months'] ?? ''), 'max_fleet' => (string) ($pForm['max_fleet'] ?? ''),
    'daily_cap' => (string) ($pForm['daily_cap'] ?? ''), 'states' => array_map('strval', (array) ($pForm['states'] ?? [])), 'cargo' => array_map('strval', (array) ($pForm['cargo'] ?? [])),
] : $pc;
$em = static fn(string $k): string => isset($errors[$k]) ? '<small class="num__e" role="alert">' . $e($errors[$k]) . '</small>' : '';
$c = is_array($pRun) ? ($pRun['counters'] ?? []) : [];
?>
<p class="cfg-intro">Filtros de la búsqueda diaria de autoridades nuevas de FMCSA (<code><?= $e(ProspectImport::GRANTS_DATASET) ?></code> + censo <code><?= $e(ProspectImport::CENSUS_DATASET) ?></code> de data.transportation.gov). Se aplican a los prospectos nuevos; los que ya están en la lista no se tocan. Si cambias un filtro, la próxima corrida revisa de nuevo toda la ventana.</p>
<form method="post" action="/config/prospects/save" class="cfg-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="pf-t">
      <header class="card__h"><h2 class="label" id="pf-t">Qué prospectos entran</h2></header>
      <div class="card__b pf-grid">
        <label class="tgl pf-all"><input type="checkbox" name="enabled" value="1"<?= $cur['enabled'] ? ' checked' : '' ?>><span class="tgl__t"><b>Buscar prospectos nuevos cada día</b><small>A las 6:00 a. m. de Nueva York. Apagarlo no borra nada de la lista.</small></span></label>
        <label class="tgl pf-all"><input type="checkbox" name="require_phone" value="1"<?= $cur['require_phone'] ? ' checked' : '' ?>><span class="tgl__t"><b>Solo carriers con teléfono válido</b><small>Sin teléfono no hay llamada. Si lo apagas, entran también los que solo tienen correo.</small></span></label>
        <label class="field"><span>Antigüedad mínima de la autoridad (meses)</span><input type="text" name="min_age_months" value="<?= $e($cur['min_age_months'] ?? '0') ?>" inputmode="numeric" maxlength="2" autocomplete="off"><small>0 = desde el primer día. Con 3 y 24 entran carriers que ya pasaron sus primeros meses y reciben menos llamadas.</small><?= $em('min_age_months') ?></label>
        <label class="field"><span>Antigüedad máxima de la autoridad (meses)</span><input type="text" name="max_age_months" value="<?= $e($cur['max_age_months']) ?>" inputmode="numeric" maxlength="2" autocomplete="off"><small>De 1 a 24. Por defecto 12.</small><?= $em('max_age_months') ?></label>
        <label class="field"><span>Flota máxima (unidades motrices)</span><input type="text" name="max_fleet" value="<?= $e($cur['max_fleet']) ?>" inputmode="numeric" maxlength="3" autocomplete="off"><small>Si FMCSA no trae el dato, no se descarta.</small><?= $em('max_fleet') ?></label>
        <label class="field"><span>Tope diario de prospectos nuevos</span><input type="text" name="daily_cap" value="<?= $e($cur['daily_cap']) ?>" inputmode="numeric" maxlength="3" autocomplete="off"><small>Por día (hora de Nueva York). Entran primero los más recientes.</small><?= $em('daily_cap') ?></label>
        <label class="field"><span>«No interesado» vuelve a la lista</span><select name="relist_months"><?php foreach (\Captacion\Services\ProspectConfig::RELIST as $k => $l): ?><option value="<?= $e($k) ?>"<?= (int) ($pForm['relist_months'] ?? $cur['relist_months']) === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select><small>Pasado ese tiempo desde que dijo que no, vuelve a «Por llamar» con 0 intentos (la situación de un transportista cambia). «No llamar más» nunca vuelve.</small><?= $em('relist_months') ?></label>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="pc-t">
      <header class="card__h"><h2 class="label" id="pc-t">Carga declarada (opcional)</h2></header>
      <div class="card__b">
        <p class="muted pf-hint">Sin marcar = cualquier carga. FMCSA publica la carga que el carrier declara, <b>no</b> el tipo de equipo (dry van, reefer, flatbed no existen como dato). Con un filtro activo, un carrier que no declara carga se descarta.</p>
        <div class="eqgrid"><?php foreach (ProspectConfig::CARGO as $k => [$label]): ?><label class="check check--box"><input type="checkbox" name="cargo[]" value="<?= $e($k) ?>"<?= in_array($k, $cur['cargo'], true) ? ' checked' : '' ?>><span><?= $e($label) ?></span></label><?php endforeach; ?></div>
        <?= $em('cargo') ?>
      </div>
    </section>
    <section class="card cfg-card" aria-labelledby="ps-t">
      <header class="card__h"><h2 class="label" id="ps-t">Estados (vacío = todo EE. UU.)</h2></header>
      <div class="card__b">
        <div class="pf-states"><?php foreach (UsStates::NAMES as $k => $l): ?><label class="check check--box check--st" title="<?= $e($l) ?>"><input type="checkbox" name="states[]" value="<?= $e($k) ?>"<?= in_array($k, $cur['states'], true) ? ' checked' : '' ?>><span><?= $e($k) ?></span></label><?php endforeach; ?></div>
        <?= $em('states') ?>
      </div>
      <footer class="card__f"><span class="muted">Territorios, Canadá y México quedan fuera siempre.</span>
        <button class="btn btn--primary" type="submit"><span class="btn__t">Guardar filtros</span></button></footer>
    </section>
  </fieldset>
</form>

<section class="card cfg-card" aria-labelledby="pr-t">
  <header class="card__h"><h2 class="label" id="pr-t">Última corrida</h2></header>
  <div class="card__b">
    <?php if (!is_array($pRun)): ?>
      <p class="muted">Todavía no ha corrido. Programa <code>cron/fmcsa_daily.php</code> (cada hora, minuto 20) o prueba por SSH con <code>--force</code>. Pasos en el README §19.</p>
    <?php else: ?>
      <p><b><?= $e($pRun['day'] ?? '—') ?></b> · <?= !empty($pRun['finished']) ? 'terminada' . (!empty($pRun['capped']) ? ' (tope diario alcanzado)' : '') : 'a medias: sigue a la próxima hora' ?> · <?= $e((int) ($pRun['pages'] ?? 0)) ?> páginas, <?= $e((int) ($pRun['requests'] ?? 0)) ?> consultas<?= !empty($pRun['resumed']) ? ', reanudada ' . $e((int) $pRun['resumed']) . ' ' . ((int) $pRun['resumed'] === 1 ? 'vez' : 'veces') : '' ?>.
        Ventana desde <span class="mono"><?= $e(substr((string) ($pRun['since'] ?? ''), 0, 4) . '-' . substr((string) ($pRun['since'] ?? ''), 4, 2) . '-' . substr((string) ($pRun['since'] ?? ''), 6, 2)) ?></span>.</p>
      <?php if (!empty($pRun['error'])): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?>Error: <?= $e($pRun['error']) ?></p><?php endif; ?>
      <ul class="pf-counts">
        <li class="is-new"><b><?= $e((int) ($c['new'] ?? 0)) ?></b><span>Nuevos en la lista</span></li>
        <li><b><?= $e((int) ($c['read'] ?? 0)) ?></b><span>Concesiones leídas</span></li>
        <?php foreach (ProspectImport::REASONS as $k => $label): if ((int) ($c[$k] ?? 0) === 0) { continue; } ?><li><b><?= $e((int) $c[$k]) ?></b><span><?= $e($label) ?></span></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <dl class="pf-meta">
      <div><dt>Ventana completa hasta</dt><dd><?= $pComplete !== null ? $e($pComplete) : 'ninguna todavía: la próxima corrida mira toda la ventana' ?></dd></div>
      <div><dt>Última pasada del cron</dt><dd><?= is_array($pCron) && isset($pCron['at']) ? $e(Fmt::dateTime((string) $pCron['at'])) . ' · ' . $e(Fmt::ago((string) $pCron['at'])) : 'nunca' ?></dd></div>
      <div><dt>Socrata</dt><dd><?= $pToken ? 'con token (SOCRATA_APP_TOKEN)' : 'sin token: límite compartido, suficiente para una corrida diaria' ?></dd></div>
    </dl>
  </div>
  <footer class="card__f"><span class="muted">Lo ya listado no se duplica al repasar la ventana.</span>
    <form method="post" action="/config/prospects/window"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--secondary" type="submit"<?= $locked ? ' disabled' : '' ?>><?= Ui::icon('refresh') ?>Revisar toda la ventana otra vez</button></form></footer>
</section>

<?php /* Fase AU: llamadas, guion y correos automáticos (cadencia, fase AW) */ ?>
<form method="post" action="/config/prospects/calls" class="cfg-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="pl-t">
      <header class="card__h"><h2 class="label" id="pl-t">Llamadas</h2></header>
      <div class="card__b pf-grid">
        <label class="field"><span>Meta de llamadas por día (equipo)</span><input type="text" name="daily_goal" value="<?= $e($pc['daily_goal']) ?>" inputmode="numeric" maxlength="3" autocomplete="off"><small>Se ve arriba de la lista y en el Modo llamada. 0 = sin meta.</small></label>
        <label class="field"><span>Intentos sin respuesta antes de cerrar</span><select name="max_attempts"><?php for ($n = 3; $n <= 6; $n++): ?><option value="<?= $n ?>"<?= (int) $pc['max_attempts'] === $n ? ' selected' : '' ?>><?= $n ?> intentos<?= $n === 3 ? ' (por defecto)' : '' ?></option><?php endfor; ?></select><small>Al agotarlos pasa a «Sin respuesta (cerrada)» y, si está encendido, sale el último correo.</small></label>
        <label class="field"><span>Cuándo vuelve a la lista tras no contestar</span><select name="retry_spacing"><?php foreach (\Captacion\Services\Prospects::SPACING as $k => [$l]): ?><option value="<?= $e($k) ?>"<?= $pc['retry_spacing'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select><small>Repartir los intentos en dos semanas contesta más que tres días seguidos. Una hora pedida por el prospecto siempre manda.</small></label>
        <label class="tgl pf-all"><input type="checkbox" name="auto_convert" value="1"<?= $pc['auto_convert'] ? ' checked' : '' ?>><span class="tgl__t"><b>«Interesado» lo convierte en lead en el mismo clic</b><small>Pasa a Leads en «Contactado» con lo anotado en la llamada (contacto, correo, equipo, Relay) y la última nota. Sin mensajes ni alertas.</small></span></label>
      </div>
      <footer class="card__f"><span class="muted">El orden de la lista lo explica cada fila.</span><button class="btn btn--primary" type="submit"><span class="btn__t">Guardar</span></button></footer>
    </section>
  </fieldset>
</form>

<form method="post" action="/config/prospects/script" class="cfg-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
    <section class="card cfg-card" aria-labelledby="pg-t">
      <header class="card__h"><h2 class="label" id="pg-t">Guion de la llamada</h2></header>
      <div class="card__b">
        <p class="muted pf-hint">Se abre dentro de cada prospecto y en el Modo llamada. «# Título» abre una sección y «- » hace una lista. Variables: <?php foreach (\Captacion\Services\ProspectScript::VARS as $k => $l): ?><code title="<?= $e($l) ?>"><?= $e($k) ?></code> <?php endforeach; ?>. Afirma solo lo que NorthStar hace hoy; los precios, como estimación.</p>
        <div class="pg-grid">
          <label class="field"><span>English</span><textarea name="script_en" rows="14" maxlength="6000"><?= $e($pScript['en']) ?></textarea></label>
          <label class="field"><span>Español</span><textarea name="script_es" rows="14" maxlength="6000"><?= $e($pScript['es']) ?></textarea></label>
        </div>
        <label class="field pg-lang"><span>Se abre primero en</span><select name="lang"><?php foreach (\Captacion\Services\ProspectScript::LANGS as $k => $l): ?><option value="<?= $e($k) ?>"<?= $pScript['lang'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
      </div>
      <footer class="card__f"><button class="btn btn--ghost" type="submit" name="restore" value="1" data-confirm-title="¿Restaurar el guion de fábrica?" data-confirm-label="Restaurar" data-confirm="Se pierden los cambios hechos a los dos guiones.">Restaurar el de fábrica</button><button class="btn btn--primary" type="submit"><span class="btn__t">Guardar guion</span></button></footer>
    </section>
  </fieldset>
</form>

<section class="card cfg-card" aria-labelledby="pa-t">
  <header class="card__h"><h2 class="label" id="pa-t">Correos automáticos (cadencia)</h2></header>
  <div class="card__b">
    <p class="muted pf-hint">Tres correos que acompañan a las llamadas, cada uno con su interruptor y su versión en inglés y en español (sale en el idioma que prefiere el prospecto; sin dato, en inglés). Uno de cada tipo por prospecto, al menos 2 días entre dos de ellos. Mismas reglas que los correos en lote: dirección física, baja de un clic, tope por hora compartido y el periodo de espera de las campañas. Nunca a «No llamar más», «No interesado», convertidos ni bajas.</p>
    <?php if ($pBlockers): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?><?= $e($pBlockers[0]) ?></p><?php endif; ?>
    <?php foreach (\Captacion\Services\ProspectAutoMail::KINDS as $ak => [$alabel, , $awhen]): $pa = $pAuto[$ak]; $ps = $pAutoStats[$ak]; ?>
    <details class="pa-k" id="pa-<?= $e($ak) ?>"<?= $pa['enabled'] ? ' open' : '' ?>>
      <summary><b><?= $e($alabel) ?></b> <span class="chip chip--<?= $pa['enabled'] ? 'ok' : 'plain' ?>"><?php if ($pa['enabled']): ?><i class="dot"></i><?php endif; ?><?= $pa['enabled'] ? 'Encendido' : 'Apagado' ?></span><span class="pa-more" aria-hidden="true"><?= Ui::icon('chevron-down') ?></span></summary>
      <form method="post" action="/config/prospects/automail" class="cfg-form pa-f" novalidate>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="kind" value="<?= $e($ak) ?>">
        <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
          <p class="muted"><?= $e($awhen) ?></p>
          <label class="tgl"><input type="checkbox" name="enabled" value="1"<?= $pa['enabled'] ? ' checked' : '' ?>><span class="tgl__t"><b>Encendido</b><small><?= $e($ps['sent7']) ?> enviados en los últimos 7 días · <?= $e($ps['sent']) ?> en total<?= $ps['skipped'] ? ' · ' . $e($ps['skipped']) . ' omitidos' : '' ?><?= $ps['failed'] ? ' · ' . $e($ps['failed']) . ' fallidos' : '' ?></small></span></label>
          <div class="pg-grid">
            <div class="pa-col"><label class="field"><span>Asunto (inglés)</span><input type="text" name="subject" maxlength="190" value="<?= $e($pa['subject']) ?>" autocomplete="off" lang="en"></label>
              <label class="field"><span>Texto (inglés)</span><textarea name="body" rows="9" maxlength="8000" lang="en"><?= $e($pa['body']) ?></textarea></label></div>
            <div class="pa-col"><label class="field"><span>Asunto (español)</span><input type="text" name="subject_es" maxlength="190" value="<?= $e($pa['subject_es']) ?>" autocomplete="off" lang="es"></label>
              <label class="field"><span>Texto (español)</span><textarea name="body_es" rows="9" maxlength="8000" lang="es"><?= $e($pa['body_es']) ?></textarea></label></div>
          </div>
          <div class="pa-foot"><button class="btn btn--ghost" type="submit" formaction="/config/prospects/automailtest">Enviarme una prueba (lo guardado)</button><button class="btn btn--secondary" type="submit"><span class="btn__t">Guardar</span></button></div>
        </fieldset>
      </form>
    </details>
    <?php endforeach; ?>
    <p class="muted pa-vars">Variables: <?php foreach (\Captacion\Services\ProspectMail::VARS as $k => $l): ?><code title="<?= $e($l) ?>"><?= $e($k) ?></code> <?php endforeach; ?></p>
  </div>
</section>
