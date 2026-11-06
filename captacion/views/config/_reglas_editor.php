<?php
use Captacion\Services\Catalog;
use Captacion\Services\Fmt;
use Captacion\Services\Rules;
use Captacion\Ui;

/**
 * Editor de un conjunto de reglas (panel lateral / hoja inferior; en la pagina sin JS va en linea). Variables: $set, $kind, $rules (forma de formulario), $errors, $phrases, $draft, $pub, $history,
 * $diffDraft, $sim, $locked, $csrf, $meta (name, description, note), $impact, $impactErr, $inline, $impactScope, $simScope, $reauthTtl.
 */
$status = $pub !== null ? ($draft !== null ? 'published_draft' : 'published') : ($draft !== null ? 'draft' : (!empty($history) ? 'retired' : 'empty'));
$chip = ['published' => ['ok', 'Publicada'], 'published_draft' => ['warm', 'Publicada · con borrador'], 'draft' => ['plain', 'Borrador'], 'retired' => ['dq', 'Retirada'], 'empty' => ['plain', 'Sin versiones']][$status];
$nextVer = $draft !== null ? (int) $draft['version'] : ((int) ($history[0]['version'] ?? 0) + 1);
$sub = $pub !== null ? 'v' . (int) $pub['version'] . ' publicada · ' . Fmt::ago((string) $pub['published_at']) : ($draft !== null ? 'Borrador v' . (int) $draft['version'] . ' · ' . Fmt::ago((string) $draft['updated_at']) : 'Aún sin versiones');
$st = static fn(string $s): string => ['published' => 'Publicada', 'draft' => 'Borrador', 'archived' => 'Archivada'][$s] ?? $s;
$hasErr = (bool) $errors;
?>
<div class="rp__wrap" data-rp-id="<?= $e($set['id']) ?>" data-kind="<?= $e($kind) ?>">
<form class="rp__form" method="post" action="/config/rules/save" data-rp-form novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="set" value="<?= $e($set['id']) ?>">
  <button class="rp__enter" type="submit" name="op" value="save" tabindex="-1" aria-hidden="true"></button>

  <header class="rp__head">
    <span class="rp__ic" aria-hidden="true"><?= Ui::icon('sliders') ?></span>
    <div class="rp__title">
      <h2 class="rp__h" id="rp-title" tabindex="-1"><?= $e($meta['name']) ?></h2>
      <p class="rp__sub"><?= $e($sub) ?> · <?= $e(Rules::KINDS[$kind]) ?></p>
    </div>
    <span class="chip chip--<?= $e($chip[0]) ?> rp__chip"><i class="dot"></i><?= $e($chip[1]) ?></span>
    <a class="icon-btn" href="#rp-hist" data-hist aria-label="Ver el historial de versiones" title="Historial"><?= Ui::icon('history') ?></a>
    <?php if (!$inline): ?><button class="icon-btn" type="button" data-rp-close aria-label="Cerrar el editor (Esc)" title="Cerrar (Esc)"><?= Ui::icon('x') ?></button>
    <?php else: ?><a class="icon-btn" href="/config?tab=reglas" aria-label="Volver a la lista de conjuntos" title="Cerrar"><?= Ui::icon('x') ?></a><?php endif; ?>
  </header>

  <div class="rp__body">
    <div class="rp__main">
      <?php if ($hasErr): ?><p class="flash flash--bad" role="alert"><?= Ui::icon('alert') ?><span class="flash__t">Hay <?= $e(count($errors)) ?> campo<?= count($errors) === 1 ? '' : 's' ?> por corregir (marcados abajo). No se guardó nada.</span></p><?php endif; ?>
      <div class="rp__meta">
        <label class="field<?= isset($errors['meta.name']) ? ' has-err' : '' ?>"><span>Nombre del conjunto</span><input type="text" name="name" value="<?= $e($meta['name']) ?>" maxlength="80" autocomplete="off"><small class="rp-err"<?= isset($errors['meta.name']) ? '' : ' hidden' ?> data-err="meta.name"><?= $e($errors['meta.name'] ?? '') ?></small></label>
        <label class="field"><span>Descripción</span><input type="text" name="description" value="<?= $e($meta['description']) ?>" maxlength="300" autocomplete="off" placeholder="Para qué sirve este conjunto"></label>
      </div>
      <p class="rp__kind"><?= Ui::icon('info') ?><span><?= $e(Rules::KIND_HELP[$kind]) ?> Las reglas se evalúan de arriba abajo; la posición es la prioridad.</span></p>
      <button class="btn btn--ghost btn--sm rp-nojs" type="submit" name="op" value="refresh"><?= Ui::icon('refresh') ?>Actualizar los campos</button>

      <div class="rp__rules" data-rules>
        <?php foreach ($rules as $k => $r): echo \Captacion\View::render('config/_rule', ['k' => (string) $k, 'r' => (array) $r, 'errors' => $errors, 'kind' => $kind, 'phr' => $phrases[$k] ?? null, 'e' => $e], false); endforeach; ?>
      </div>
      <p class="rp__empty" data-rules-empty<?= $rules ? ' hidden' : '' ?>><?= Ui::icon('sliders') ?><span>Este conjunto todavía no tiene reglas. Añade la primera.</span></p>
      <small class="rp-err" data-err="rules"<?= isset($errors['rules']) ? '' : ' hidden' ?>><?= $e($errors['rules'] ?? '') ?></small>
      <button class="btn btn--secondary" type="submit" name="op" value="add_rule" data-add="rule"><?= Ui::icon('plus') ?>Añadir regla</button>

      <label class="field rp__note"><span>Nota de esta versión <small>(opcional, se ve en el historial)</small></span><input type="text" name="note" value="<?= $e($meta['note']) ?>" maxlength="200" autocomplete="off" placeholder="Qué cambió y por qué"></label>
      <?php if ($draft !== null): ?><button class="btn btn--ghost btn--sm rp__discard" type="submit" formaction="/config/rules/discard" data-confirm="¿Descartar el borrador? Se pierden los cambios sin publicar. La versión publicada no se toca."><?= Ui::icon('trash') ?>Descartar el borrador</button><?php endif; ?>

      <?php if ($diffDraft): ?>
      <details class="rp__diff" open><summary><?= Ui::icon('layers') ?>Cambios del borrador frente a la versión publicada <span class="rp__n"><?= $e(count($diffDraft)) ?></span></summary>
        <?= \Captacion\View::render('config/_rule_diff', ['diff' => $diffDraft, 'e' => $e], false) ?></details>
      <?php endif; ?>

      <section class="rp__hist" id="rp-hist" aria-label="Historial de versiones">
        <h3 class="label">HISTORIAL DE VERSIONES (<?= $e(count($history)) ?>)</h3>
        <?php if (!$history): ?><p class="muted">Todavía no hay versiones. Guarda un borrador para crear la primera.</p><?php endif; ?>
        <ol class="rp-hist">
          <?php foreach ($history as $h): ?>
          <li class="rp-hist__i rp-hist__i--<?= $e($h['status']) ?>">
            <div class="rp-hist__top"><b class="mono">v<?= $e($h['version']) ?></b><span class="chip chip--<?= $h['status'] === 'published' ? 'ok' : ($h['status'] === 'draft' ? 'plain' : 'dq') ?>"><i class="dot"></i><?= $e($st($h['status'])) ?></span>
              <span class="muted"><?= $e($h['by'] ?? 'Sistema') ?> · <?= $e(Fmt::dateTime($h['at'])) ?> · <?= $e($h['count']) ?> regla<?= $h['count'] === 1 ? '' : 's' ?></span></div>
            <?php if ($h['note'] !== ''): ?><p class="rp-hist__note"><?= $e($h['note']) ?></p><?php endif; ?>
            <?php if ($h['reverted_from'] !== null): ?><p class="muted">Vuelve a las reglas de la versión <?= $e($h['reverted_from']) ?>.</p><?php endif; ?>
            <?php if (!empty($h['diff'])): ?><details class="rp-hist__d"><summary>Ver cambios respecto a la versión anterior <span class="rp__n"><?= $e(count($h['diff'])) ?></span></summary><?= \Captacion\View::render('config/_rule_diff', ['diff' => $h['diff'], 'e' => $e], false) ?></details><?php endif; ?>
            <?php if ($h['status'] === 'archived'): ?>
              <button class="btn btn--secondary btn--sm" type="submit" formaction="/config/rules/revert" name="version" value="<?= $e($h['id']) ?>"<?= $locked ? ' disabled' : '' ?> data-confirm="¿Volver a las reglas de la versión <?= $e($h['version']) ?>? Se publica como una versión nueva y la historia se conserva. Se aplica a los leads nuevos y a los que cambien."><?= Ui::icon('undo') ?>Revertir a v<?= $e($h['version']) ?></button>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ol>
      </section>
    </div>

    <aside class="rp__side" aria-label="Impacto y simulación">
      <section class="rp-card" aria-labelledby="rp-imp-t">
        <header class="rp-card__h"><h3 class="label" id="rp-imp-t">VISTA PREVIA DE IMPACTO</h3>
          <label class="rp-scope"><span class="sr">Alcance de la vista previa</span><select name="impact_scope" data-impact-scope>
            <?php foreach (['last:100' => 'Últimos 100 leads', 'last:500' => 'Últimos 500 leads', 'last:1000' => 'Últimos 1.000 leads', 'days:7' => 'Últimos 7 días', 'days:30' => 'Últimos 30 días', 'days:90' => 'Últimos 90 días'] as $v => $l): ?><option value="<?= $e($v) ?>"<?= $impactScope === $v ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
          </select></label></header>
        <div class="rp-card__b" data-impact aria-live="polite"><?= \Captacion\View::render('config/_rule_impact', ['impact' => $impact, 'impactErr' => $impactErr, 'e' => $e], false) ?></div>
        <footer class="rp-card__f"><button class="btn btn--ghost btn--sm" type="submit" name="op" value="preview" data-impact-go><?= Ui::icon('refresh') ?>Calcular impacto</button></footer>
      </section>

      <section class="rp-card" aria-labelledby="rp-sim-t">
        <header class="rp-card__h"><h3 class="label" id="rp-sim-t">SIMULACIÓN</h3></header>
        <div class="rp-card__b">
          <div class="rp-simform">
            <label class="rp-scope"><span class="sr">Alcance de la simulación</span><select name="sim_scope" data-sim-scope>
              <?php foreach (['last:500' => 'Últimos 500 leads', 'last:1000' => 'Últimos 1.000 leads', 'last:5000' => 'Últimos 5.000 leads', 'last:20000' => 'Últimos 20.000 leads', 'days:30' => 'Últimos 30 días', 'days:90' => 'Últimos 90 días', 'days:365' => 'Últimos 365 días', 'range' => 'Rango de fechas…'] as $v => $l): ?><option value="<?= $e($v) ?>"<?= $simScope === $v ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
            </select></label>
            <span class="rp-range" data-sim-range<?= $simScope === 'range' ? '' : ' hidden' ?>><label><span class="sr">Desde</span><input type="date" name="sim_from" value="<?= $e($_POST['sim_from'] ?? '') ?>"></label><i aria-hidden="true">a</i><label><span class="sr">Hasta</span><input type="date" name="sim_to" value="<?= $e($_POST['sim_to'] ?? '') ?>"></label></span>
          </div>
          <div data-sim-out aria-live="polite"><?= \Captacion\View::render('config/_rule_sim', ['sim' => $sim, 'csrf' => $csrf, 'setId' => $set['id'], 'e' => $e], false) ?></div>
        </div>
      </section>
      <p class="rp-warn muted"><?= Ui::icon('info') ?>Los ejemplos y sus puntos son provisionales: no son cifras del negocio. Con todo retirado, la puntuación vuelve a ser exactamente la del documento.</p>
    </aside>
  </div>

  <footer class="rp__foot">
    <button class="btn btn--ghost" type="submit" name="op" value="simulate" data-sim-go><?= Ui::icon('flask') ?>Ejecutar simulación</button>
    <span class="rp__foot-sp"></span>
    <button class="btn btn--secondary" type="submit" name="op" value="save"><?= Ui::icon('check') ?>Guardar borrador</button>
    <button class="btn btn--primary" type="submit" formaction="/config/rules/publish"<?= $locked ? ' disabled title="Confirma tu identidad para publicar"' : '' ?> data-confirm="¿Publicar la versión <?= $e($nextVer) ?>? Se aplicará a los leads nuevos y a los que cambien; los existentes solo con «Recalcular». Quedará en el registro de actividad."><span class="btn__t">Publicar v<?= $e($nextVer) ?></span><span class="btn__cell"><?= Ui::icon('check') ?></span></button>
  </footer>
</form>

<?php if ($locked): ?>
<section class="rp__unlock" aria-labelledby="rp-ul-t">
  <h3 class="label" id="rp-ul-t">CONFIRMA TU IDENTIDAD PARA PUBLICAR O REVERTIR</h3>
  <form method="post" action="/config/unlock" autocomplete="off" class="rp__ulf">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tab" value="reglas"><input type="hidden" name="set" value="<?= $e($set['id']) ?>">
    <label class="field"><span>Contraseña</span><input type="password" name="password" required autocomplete="current-password"></label>
    <label class="field"><span>Código de 6 dígitos</span><input type="text" name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code"></label>
    <button class="btn btn--secondary" type="submit">Confirmar</button>
  </form>
  <p class="muted">Guardar borradores, la vista previa y las simulaciones no lo necesitan. Vale <?= $e(intdiv($reauthTtl, 60)) ?> minutos.</p>
</section>
<?php endif; ?>
</div>
