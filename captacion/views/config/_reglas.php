<?php
use Captacion\Services\Fmt;
use Captacion\Services\Rules;
use Captacion\Ui;

/** Pestaña «Reglas». Variables: $sets (RuleSets::all), $editor (variables del editor si hay ?set=ID) o null, $rulesOn, $pubCount, $cssV, $csrf, $locked */
$byKind = [];
foreach ($sets as $s) {
    $byKind[$s['kind']][] = $s;
}
$chipOf = ['published' => ['ok', 'Publicada'], 'published_draft' => ['warm', 'Publicada · con borrador'], 'draft' => ['plain', 'Borrador'], 'retired' => ['dq', 'Retirada'], 'empty' => ['plain', 'Sin versiones']];
?>
<p class="cfg-intro">Reglas legibles que <b>ajustan</b> la puntuación del documento o disparan acciones: <i>cuando</i> pasa algo, <i>entonces</i> haz esto, <i>excepto</i> si… Se editan como borrador, se prueban con una vista previa y una simulación (sin tocar ningún lead) y se publican con versión. Sin ninguna regla publicada, todo funciona exactamente como en el documento.</p>

<section class="card rl-state<?= $rulesOn ? '' : ' is-off' ?>" aria-labelledby="rl-st-t">
  <div class="rl-state__t">
    <span class="rl-state__i" aria-hidden="true"><?= Ui::icon($rulesOn ? ($pubCount > 0 ? 'zap' : 'check-circle') : 'pause') ?></span>
    <div>
      <h2 id="rl-st-t"><?php if (!$rulesOn): ?>Las reglas están apagadas<?php elseif ($pubCount > 0): ?><?= $e($pubCount) ?> conjunto<?= $pubCount === 1 ? '' : 's' ?> publicado<?= $pubCount === 1 ? '' : 's' ?> en uso<?php else: ?>Se usa la puntuación del documento<?php endif; ?></h2>
      <p><?php if (!$rulesOn): ?>Mientras estén apagadas, ninguna regla publicada se aplica: la puntuación y las alertas son las del documento.<?php elseif ($pubCount > 0): ?>Se aplican después de la puntuación base, a los leads nuevos y a los que cambian. Los existentes se actualizan con «Recalcular» (Puntuación).<?php else: ?>No hay conjuntos publicados. Los borradores no se aplican a nadie.<?php endif; ?></p>
    </div>
  </div>
  <div class="rl-state__a">
    <form method="post" action="/config/rules/switch"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="on" value="<?= $rulesOn ? '0' : '1' ?>">
      <button class="btn btn--secondary btn--sm" type="submit"<?= $locked ? ' disabled' : '' ?><?= $rulesOn ? ' data-confirm="¿Apagar todas las reglas? Dejan de aplicarse a los leads nuevos y a los que cambien; la puntuación vuelve a ser la del documento. Lo publicado no se borra."' : '' ?>><?= Ui::icon($rulesOn ? 'pause' : 'play') ?><?= $rulesOn ? 'Apagar las reglas' : 'Encender las reglas' ?></button></form>
    <?php if ($pubCount > 0): ?>
    <form method="post" action="/config/rules/reset"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn--ghost btn--sm" type="submit"<?= $locked ? ' disabled' : '' ?> data-confirm="¿Restablecer a la puntuación del documento? Se retiran TODOS los conjuntos publicados (quedan archivados con su historial). Las puntuaciones ya guardadas no cambian hasta que recalcules."><?= Ui::icon('undo') ?>Restablecer a la puntuación del documento</button></form>
    <?php endif; ?>
  </div>
</section>

<?php
$tl = ['hot' => 'Calientes', 'warm' => 'Tibios', 'cold' => 'Fríos', 'disqualified' => 'Descartados'];
$run = $recalc !== null && ($recalc['status'] ?? '') === 'running';
?>
<?php if ($sets): ?>
<section class="card rl-recalc" id="aplicar" aria-labelledby="rl-rc-t">
  <div class="rl-recalc__t">
    <h2 class="label" id="rl-rc-t">Aplicar a leads existentes</h2>
    <p>Publicar una versión afecta a los leads nuevos y a los que cambian. Para que los que ya están en la base reciban puntos, temperatura, etiquetas y cola de revisión, recalcula: corre por la cola, en lotes, y no crea alertas ni tareas en masa.</p>
    <?php if ($recalc !== null): ?>
      <div class="rl-recalc__s<?= $run ? ' is-run' : '' ?>"<?= $run ? ' data-running' : '' ?>>
        <p><b><?= $run ? 'Recalculando…' : 'Último recálculo terminado' ?></b> <?= $e(Fmt::num((int) $recalc['processed'])) ?> de <?= $e(Fmt::num((int) $recalc['total'])) ?> leads · <?= $e(Fmt::num((int) $recalc['changed'])) ?> cambiaron · <?= $e(Fmt::dateTime((string) $recalc['started_at'])) ?></p>
        <?php if ($run): $pct = $recalc['total'] > 0 ? (int) round($recalc['processed'] / $recalc['total'] * 100) : 100; ?><progress class="rl-prog" max="100" value="<?= $e($pct) ?>"><?= $e($pct) ?> %</progress><p class="muted">Esta página se actualiza sola.</p>
        <?php elseif (is_array($recalc['after'] ?? null) && is_array($recalc['before'] ?? null)): ?>
          <ul class="rl-ba" aria-label="Recuento antes y después"><?php foreach ($tl as $k => $l): ?><li><span><?= $e($l) ?></span><b class="mono"><?= $e((int) ($recalc['before'][$k] ?? 0)) ?> → <?= $e((int) ($recalc['after'][$k] ?? 0)) ?></b></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <form method="post" action="/config/rules/apply" class="rl-recalc__f">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="check"><input type="checkbox" name="confirm" value="1" required><span>Entiendo que se vuelve a puntuar a todos los leads.</span></label>
    <button class="btn btn--secondary btn--sm" type="submit"<?= $locked || $run ? ' disabled' : '' ?>><?= Ui::icon('refresh') ?><?= $run ? 'Recálculo en marcha…' : 'Aplicar a leads existentes' ?></button>
  </form>
</section>
<?php endif; ?>

<div class="rl-bar">
  <h2 class="cfg-h3 rl-bar__t">Conjuntos de reglas <span class="rl-bar__n"><?= $e(count($sets)) ?></span></h2>
  <div class="rl-bar__a">
    <?php if (count($sets) > 0 && count($sets) < Rules::MAX_SETS): ?>
    <form method="post" action="/config/rules/starter"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= Ui::icon('layers') ?>Añadir ejemplos</button></form>
    <?php endif; ?>
    <button class="btn btn--primary btn--sm" type="button" data-open="#newset"<?= count($sets) >= Rules::MAX_SETS ? ' disabled' : '' ?>><span class="btn__t">Nuevo conjunto</span></button>
  </div>
</div>

<?php if (!$sets): ?>
<section class="card empty empty--lg rl-empty" aria-labelledby="rl-e-t">
  <?= Ui::icon('star', 'empty__star empty__star--lg') ?>
  <h3 id="rl-e-t">Todavía no hay reglas</h3>
  <p>La puntuación sigue exactamente la del documento. Crea un conjunto para ajustar puntos, avisar por Telegram o crear tareas, o empieza con cinco ejemplos desactivados que puedes editar.</p>
  <div class="rl-empty__a">
    <button class="btn btn--secondary" type="button" data-open="#newset"><span class="btn__t">Nuevo conjunto</span></button>
    <form method="post" action="/config/rules/starter"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn--secondary" type="submit">Empezar con ejemplos</button></form>
  </div>
</section>
<?php endif; ?>

<?php foreach (Rules::KINDS as $kind => $label): if (empty($byKind[$kind])) { continue; } ?>
<section class="rl-group" aria-labelledby="rl-g-<?= $e($kind) ?>">
  <header class="rl-group__h"><h3 class="label" id="rl-g-<?= $e($kind) ?>"><?= $e($label) ?></h3><span class="muted"><?= $e(Rules::KIND_HELP[$kind]) ?></span></header>
  <ul class="rl-list">
    <?php foreach ($byKind[$kind] as $s): [$cc, $cl] = $chipOf[$s['status']]; $p = $s['published']; $d = $s['draft']; ?>
    <li><a class="rl-card<?= $editor && $editor['set']['id'] === $s['id'] ? ' is-cur' : '' ?>" href="/config?tab=reglas&amp;set=<?= $e($s['id']) ?>" data-rl-open="<?= $e($s['id']) ?>">
      <span class="rl-card__top"><span class="rl-card__n"><?= $e($s['name']) ?></span><span class="chip chip--<?= $e($cc) ?>"><i class="dot"></i><?= $e($cl) ?></span></span>
      <?php if ($s['description'] !== ''): ?><span class="rl-card__d"><?= $e($s['description']) ?></span><?php endif; ?>
      <span class="rl-card__m">
        <?php if ($p): ?><span><?= Ui::icon('check-circle') ?>v<?= $e($p['version']) ?> · <?= $e(Fmt::ago($p['at'])) ?> · <?= $e($p['count']) ?> regla<?= $p['count'] === 1 ? '' : 's' ?> (<?= $e($p['enabled']) ?> activa<?= $p['enabled'] === 1 ? '' : 's' ?>)</span><?php endif; ?>
        <?php if ($d): ?><span><?= Ui::icon('pencil') ?>Borrador v<?= $e($d['version']) ?> · <?= $e($d['count']) ?> regla<?= $d['count'] === 1 ? '' : 's' ?> · <?= $e(Fmt::ago($d['at'])) ?></span><?php endif; ?>
        <?php if (!$p && !$d): ?><span class="muted">Sin reglas todavía</span><?php endif; ?>
      </span>
      <?php if ($p && $p['names']): ?><span class="rl-card__r"><?php foreach ($p['names'] as $nm): ?><span class="rl-tag<?= $nm['enabled'] ? '' : ' is-off' ?>"><?= $e($nm['name'] !== '' ? $nm['name'] : 'Sin nombre') ?></span><?php endforeach; ?></span><?php endif; ?>
      <span class="rl-card__go" aria-hidden="true"><?= Ui::icon('arrow-right') ?></span>
    </a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>

<p class="rl-note muted"><?= Ui::icon('info') ?><span>Las reglas de ejemplo y sus puntos son ilustrativos y provisionales: ajústalos a lo que NorthStar confirme. Cada publicación, reversión o retirada queda en la <a href="/actividad">actividad</a> con lo que cambió.</span></p>

<dialog class="dlg" id="newset" aria-labelledby="newset-t">
  <form method="post" action="/config/rules/newset">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="dlg__h"><h2 class="dlg__t" id="newset-t">Nuevo conjunto de reglas</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <fieldset class="rl-kinds"><legend class="label">Tipo</legend>
      <?php foreach (Rules::KINDS as $k => $l): ?><label class="rl-kind"><input type="radio" name="kind" value="<?= $e($k) ?>"<?= $k === 'scoring' ? ' checked' : '' ?>><span><b><?= $e($l) ?></b><small><?= $e(Rules::KIND_HELP[$k]) ?></small></span></label><?php endforeach; ?>
    </fieldset>
    <label class="field"><span>Nombre</span><input type="text" name="name" required maxlength="80" autocomplete="off" placeholder="Ajustes por fuente"></label>
    <label class="field"><span>Descripción <small>(opcional)</small></span><input type="text" name="description" maxlength="300" autocomplete="off"></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit"><span class="btn__t">Crear y editar</span></button></div>
  </form>
</dialog>

<div class="rpscrim" data-rp-scrim hidden></div>
<aside class="rp<?= $editor ? ' is-open rp--inline' : '' ?>" id="rp" role="dialog" aria-modal="false" aria-labelledby="rp-title" aria-label="Editor del conjunto de reglas" tabindex="-1"<?= $editor ? '' : ' hidden' ?> data-css-v="<?= $e($cssV) ?>" data-focus="<?= $e($focus) ?>">
  <?php if ($editor): ?><link rel="stylesheet" href="/assets/rules.css?v=<?= $e($cssV) ?>"><div class="rp__content" data-rp-body><?= \Captacion\View::render('config/_reglas_editor', $editor + ['inline' => true, 'e' => $e], false) ?></div>
  <?php else: ?><div class="rp__content" data-rp-body></div><?php endif; ?>
</aside>
