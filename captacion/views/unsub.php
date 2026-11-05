<?php
use Captacion\Ui;

/** Pagina publica /u/{token}. Variables: $state (done|invalid|limited), $lang (en|es), $first_name, $already */
$es = ($lang ?? 'en') === 'es';
require __DIR__ . '/_pub_open.php';
?>
<?php if ($state === 'done'): ?>
      <div class="pub__card">
        <svg class="auth__mini" aria-hidden="true"><use href="#i-star"/></svg>
        <h1 class="pub__t"><?= $es ? 'Listo, te diste de baja' : 'You are unsubscribed' ?></h1>
        <p class="pub__s"><?= $es ? 'No volverás a recibir correos de seguimiento de NorthStar Freight Solutions. Puede pasar un momento hasta que deje de salir alguno que ya estuviera en camino.' : 'You will no longer receive follow-up emails from NorthStar Freight Solutions. A message already on its way may still arrive in the next few minutes.' ?></p>
        <p class="pub__n"><?= $es ? 'Si fue un error, escríbenos por WhatsApp y retomamos la conversación.' : 'If this was a mistake, message us on WhatsApp and we will pick the conversation back up.' ?></p>
      </div>
<?php elseif ($state === 'limited'): ?>
      <div class="pub__card"><h1 class="pub__t">Too many requests / Demasiadas visitas</h1><p class="pub__s">Try again later. / Inténtalo más tarde.</p></div>
<?php else: ?>
      <div class="pub__card">
        <h1 class="pub__t">This link is not valid / Enlace no válido</h1>
        <p class="pub__s">We could not process this unsubscribe link. Reply to the email you received and we will remove you by hand. / No pudimos procesar este enlace. Responde al correo que recibiste y te damos de baja a mano.</p>
      </div>
<?php endif; ?>
<?php require __DIR__ . '/_pub_close.php'; ?>
