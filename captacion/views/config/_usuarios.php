<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

/** Variables: $viewers, $once, $inviteForm, $errors, $locked, $csrf */
$fe = static fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="eu-' . $k . '"' : '';
$em = static fn(string $k) => isset($errors[$k]) ? '<small class="num__e" id="eu-' . $k . '">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</small>' : '';
$act = static function (string $action, int $id, string $label, string $cls, string $icon, string $confirm = '') use ($csrf, $e, $locked): string {
    return '<form class="inl" method="post" action="/config/users/' . $action . '"><input type="hidden" name="_csrf" value="' . $e($csrf) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<button class="btn btn--sm ' . $cls . '" type="submit"' . ($locked ? ' disabled' : '') . ($confirm !== '' ? ' data-confirm="' . $e($confirm) . '"' : '') . '><span class="btn__t">' . $e($label) . '</span></button></form>';
};
?>
<p class="cfg-intro">Cuentas de <b>solo lectura</b> para NorthStar. Un lector ve únicamente <b>Resultados</b> (totales, embudo, firmados, costo por firmado, tendencia y motivos de pérdida) e <b>Informes</b> mensuales: nunca nombres, teléfonos, correos, USDOT ni exportaciones. Se invita con un enlace de un solo uso (48 h) para fijar contraseña y activar el 2FA.</p>
<?php if (!empty($once) && $once['kind'] === 'link'): ?>
  <section class="card cfg-card en-once" aria-labelledby="us-once"><header class="card__h"><h2 class="label" id="us-once">Enlace de invitación · se muestra una sola vez</h2></header>
    <div class="card__b"><p>Para <b><?= $e($once['name']) ?></b>. Vale <?= $e($once['ttl']) ?> y se usa una sola vez. Envíaselo por un canal privado.</p><p class="secret"><code><?= $e($once['value']) ?></code></p></div></section>
<?php endif; ?>
<section class="card cfg-card" aria-labelledby="us-i">
  <header class="card__h"><h2 class="label" id="us-i">Invitar a un lector</h2></header>
  <div class="card__b">
    <form method="post" action="/config/users/invite" class="cfg-form us-form" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <fieldset class="cfg-fs"<?= $locked ? ' disabled' : '' ?>>
        <div class="us-grid">
          <label class="field<?= isset($errors['name']) ? ' has-err' : '' ?>"><span>Nombre</span><input type="text" name="name" value="<?= $e($inviteForm['name']) ?>" maxlength="120" autocomplete="off" required<?= $fe('name') ?>><?= $em('name') ?></label>
          <label class="field<?= isset($errors['email']) ? ' has-err' : '' ?>"><span>Correo</span><input type="email" name="email" value="<?= $e($inviteForm['email']) ?>" maxlength="190" autocomplete="off" required<?= $fe('email') ?>><?= $em('email') ?></label>
          <button class="btn btn--primary us-go" type="submit"><span class="btn__t">Crear invitación</span></button>
        </div>
      </fieldset>
    </form>
  </div>
</section>
<section class="card cfg-card" aria-labelledby="us-l">
  <header class="card__h"><h2 class="label" id="us-l">Lectores</h2><span class="muted"><?= $e(count($viewers)) ?> cuenta<?= count($viewers) === 1 ? '' : 's' ?></span></header>
  <?php if (!$viewers): ?>
    <div class="empty empty--sm"><?= Ui::icon('star', 'empty__star ic--star') ?><h3>Aún no hay lectores</h3><p>Invita a la persona de NorthStar que verá los resultados y los informes.</p></div>
  <?php else: ?>
    <div class="tw"><table class="tbl tbl--users"><thead><tr><th scope="col">Persona</th><th scope="col">Estado</th><th scope="col">Último acceso</th><th scope="col"><span class="sr">Acciones</span></th></tr></thead><tbody>
      <?php foreach ($viewers as $v):
        $pending = $v['totp_confirmed_at'] === null;
        $state = (int) $v['is_active'] !== 1 ? ['Desactivada', 'hot'] : ($pending ? ['Invitación pendiente', 'plain'] : ['Activa', 'warm']);
      ?>
        <tr><th scope="row" class="c-n"><span class="who"><?= Ui::avatar((string) $v['name']) ?><span><b><?= $e($v['name']) ?></b><small><?= $e($v['email']) ?></small></span></span></th>
          <td data-l="Estado"><span class="chip chip--<?= $e($state[1]) ?>"><i class="dot"></i><?= $e($state[0]) ?></span><?php if ($pending && $v['invite_until'] !== null && (int) $v['is_active'] === 1): ?><small class="muted"> · vale hasta <?= $e(Fmt::dateTime($v['invite_until'])) ?></small><?php endif; ?></td>
          <td class="c-when" data-l="Último acceso"><?= $v['last_login_at'] !== null ? $e(Fmt::dateTime($v['last_login_at'])) . ' · ' . $e(Fmt::ago($v['last_login_at'])) : 'Nunca' ?></td>
          <td class="c-act">
            <?php if ((int) $v['is_active'] === 1): ?>
              <?= $pending ? $act('relink', (int) $v['id'], 'Enlace nuevo', 'btn--secondary', 'link') : $act('reset', (int) $v['id'], 'Reiniciar 2FA', 'btn--secondary', 'key', 'Se borra su contraseña y su 2FA, sus sesiones dejan de valer y recibirá un enlace nuevo para repetir el alta. ¿Seguir?') ?>
              <?= $act('deactivate', (int) $v['id'], 'Desactivar', 'btn--ghost', 'x', 'La cuenta deja de poder entrar y sus sesiones abiertas dejan de valer. ¿Desactivar?') ?>
            <?php else: ?><?= $act('activate', (int) $v['id'], 'Activar', 'btn--secondary', 'check') ?><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
