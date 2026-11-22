<?php
use Captacion\Ui;
use Captacion\Services\Fmt;

/** Fase AM · «Fusionar duplicados» (solo administradores). Usa $dupes (los leads con el mismo teléfono o correo) de LeadsController::show. */
if (empty($isAdmin) || !empty($lead['anonymized_at']) || empty($dupes)) {
    return;
}
$mgId = (int) $lead['id'];
$mgName = Fmt::name($lead);
?>
<section class="card" id="fusion" aria-labelledby="h-merge">
  <header class="card__h"><h2 class="label" id="h-merge">Fusionar duplicados</h2></header>
  <div class="card__b">
    <p class="muted pad-b">Junta dos fichas de la misma persona en una: pasan al principal las notas, tareas, mensajes, consentimientos, citas y secuencias, y se completan sus campos vacíos. La otra ficha desaparece de las listas.</p>
    <?php foreach ($dupes as $dp): $dpId = (int) $dp['id']; $dpName = Fmt::name($dp); ?>
    <details class="fine"><summary>Fusionar con <?= $e($dpName) ?> · <?= $e(Fmt::code($dpId)) ?></summary>
      <form class="priv__form" method="post" action="/leads/<?= $e($mgId) ?>/fusionar">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="other" value="<?= $e($dpId) ?>">
        <fieldset class="opts opts--stack"><legend>¿Cuál es el lead principal?</legend>
          <label class="check"><input type="radio" name="primary" value="<?= $e($mgId) ?>" required><span>Este: <?= $e($mgName) ?> (<?= $e(Fmt::code($mgId)) ?>)</span></label>
          <label class="check"><input type="radio" name="primary" value="<?= $e($dpId) ?>"><span>El otro: <?= $e($dpName) ?> (<?= $e(Fmt::code($dpId)) ?>)</span></label>
        </fieldset>
        <p class="foot-note">Se conservan la etapa, el perfil, la fuente y la fecha de alta del principal. Si uno ya está firmado, tiene que ser el principal.</p>
        <label class="check"><input type="checkbox" name="confirm" value="1" required><span>Entiendo que la fusión no se puede deshacer.</span></label>
        <button class="btn btn--secondary" type="submit" data-confirm="Se fusionarán los dos leads. No se puede deshacer." data-confirm-title="¿Fusionar los leads?" data-confirm-label="Fusionar" data-confirm-danger><?= Ui::icon('users') ?>Fusionar</button>
      </form>
    </details>
    <?php endforeach; ?>
  </div>
</section>
