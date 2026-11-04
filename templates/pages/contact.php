<?php
/**
 * /contact/ — maqueta Contact.html.
 * Orden: hero (texto + WhatsApp / correo / horario) · triada WhatsApp / Email / Short form (iconos sin tarjeta, cada columna es un enlace) · formulario SECUNDARIO "Tell us about your trucks" · CTA final (layout).
 * Formulario (CLAUDE.md §9): POST /contact/ -> app/forms/contact.php (lote D), PRG.
 * Campos EXACTOS: name, company, mc, trucks, equipment, message; honeypot "website"; form_ts; csrf.
 * Flash opcional ($flash): ['ok'=>bool|string, 'errors'=>[campo=>texto], 'old'=>[campo=>valor]]. Sin $flash no imprime mensajes.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$flash  = isset($flash) && is_array($flash) ? $flash : [];
$errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
$old    = is_array($flash['old'] ?? null) ? $flash['old'] : [];
$val    = static fn (string $k): string => e((string) ($old[$k] ?? ''));
$err    = static fn (string $k): string => isset($errors[$k]) && $errors[$k] !== '' ? (string) $errors[$k] : '';
$aria   = static fn (string $k): string => $err($k) !== '' ? ' aria-invalid="true" aria-describedby="ct-err-' . $k . '"' : '';
$email  = (string) $cfg['email'];
$hours  = ph('hours · time zone');
$equip  = (string) ($old['equipment'] ?? '');

ob_start(); ?>
<a class="btn btn--primary btn--contact" href="<?= e(wa($route['wa'])) ?>" rel="noopener">
  <span class="btn__stack"><span class="btn__label t-meta">WhatsApp</span><span class="btn__num"><?= e($cfg['phone']) ?></span></span>
</a>
<a class="btn btn--mail" href="mailto:<?= e($email) ?>">
  <span class="btn__stack"><span class="btn__label t-meta">Email</span><span class="btn__mail"><?= e($email) ?></span></span>
</a>
<?php if ($hours !== ''): ?>
<p class="sb-ct-hours"><span class="t-meta">Desk hours</span><span><?= $hours ?></span></p>
<?php endif; ?>
<?php $aside = ob_get_clean();

partial('hero', [
    'route' => $route,
    'cls'   => 'sb-ct-hero',
    'h1'    => 'Talk to a {hl}dispatcher{/hl}',
    'lead'  => 'No forms first. Open WhatsApp with a ready message and a dispatcher replies. Tell us about your trucks, your lanes and what you need help with.',
    'aside' => $aside,
]);

// Triada bajo el hero: los tres caminos para escribir, con copy ya afirmado en el sitio (hero de esta página y FAQ). Sin H2 nuevos: los títulos son <p>.
partial('trio', ['label' => 'Ways to reach the desk', 'cls' => 'sb-ct-trio', 'items' => [
    ['icon' => 'whatsapp', 'title' => 'WhatsApp', 'text' => 'No forms first. Open WhatsApp with a ready message and a dispatcher replies.', 'href' => wa($route['wa']), 'cta' => 'Open WhatsApp'],
    ['icon' => 'mail',     'title' => 'Email',    'text' => 'Tell us about your trucks, your lanes and what you need help with.', 'href' => 'mailto:' . $email, 'cta' => 'Write to the desk'],
    ['icon' => 'form',     'title' => 'Short form', 'text' => 'Prefer to write? Tell us about your trucks and we will tell you honestly if we are a fit.', 'href' => '#write', 'cta' => 'Go to the form'],
]]);
?>

<section class="section pre-cta" aria-labelledby="ct-title" id="write">
  <div class="container container--narrow stack sb-ct-stack">
    <?php partial('section-head', ['eyebrow' => 'Prefer to write?', 'title' => 'Tell us about your trucks', 'id' => 'ct-title']); ?>

    <div class="sb-ct-status" role="status" aria-live="polite"><?php if (!empty($flash['ok'])): ?><p class="sb-ct-msg"><?= e(is_string($flash['ok']) ? $flash['ok'] : 'Thanks, we received your message.') ?></p><?php elseif ($errors): ?><p class="sb-ct-msg sb-ct-msg--error">Please check the highlighted fields and send it again.</p><?php endif; ?></div>

    <form class="sb-ct-form g2" method="post" action="<?= e(url('/contact/')) ?>">
      <input type="hidden" name="form_ts" value="<?= time() ?>">
      <input type="hidden" name="csrf" value="<?= function_exists('csrf_token') ? e(csrf_token()) : '' ?>">
      <div class="sr-only" aria-hidden="true">
        <label for="ct-website">Leave this empty</label>
        <input type="text" id="ct-website" name="website" value="" tabindex="-1" autocomplete="off">
      </div>

      <label class="field" for="ct-name">Name
        <input class="input" type="text" id="ct-name" name="name" autocomplete="name" required value="<?= $val('name') ?>"<?= $aria('name') ?>>
        <?php if ($err('name') !== ''): ?><span class="sb-ct-err" id="ct-err-name"><?= e($err('name')) ?></span><?php endif; ?>
      </label>
      <label class="field" for="ct-company">Company
        <input class="input" type="text" id="ct-company" name="company" autocomplete="organization" value="<?= $val('company') ?>"<?= $aria('company') ?>>
        <?php if ($err('company') !== ''): ?><span class="sb-ct-err" id="ct-err-company"><?= e($err('company')) ?></span><?php endif; ?>
      </label>
      <label class="field" for="ct-mc">MC#
        <input class="input" type="text" id="ct-mc" name="mc" autocomplete="off" value="<?= $val('mc') ?>"<?= $aria('mc') ?>>
        <?php if ($err('mc') !== ''): ?><span class="sb-ct-err" id="ct-err-mc"><?= e($err('mc')) ?></span><?php endif; ?>
      </label>
      <label class="field" for="ct-trucks">Number of trucks
        <input class="input" type="number" id="ct-trucks" name="trucks" min="1" inputmode="numeric" autocomplete="off" value="<?= $val('trucks') ?>"<?= $aria('trucks') ?>>
        <?php if ($err('trucks') !== ''): ?><span class="sb-ct-err" id="ct-err-trucks"><?= e($err('trucks')) ?></span><?php endif; ?>
      </label>
      <label class="field sb-ct-full" for="ct-equipment">Equipment
        <select class="input" id="ct-equipment" name="equipment"<?= $aria('equipment') ?>>
          <option value="Dry van"<?= $equip === 'Dry van' ? ' selected' : '' ?>>Dry van</option>
          <option value="Amazon Relay"<?= $equip === 'Amazon Relay' ? ' selected' : '' ?>>Amazon Relay</option>
          <?php if (!is_production()): ?><option value="Other"<?= $equip === 'Other' ? ' selected' : '' ?>>[other confirmed types]</option><?php endif; ?>
        </select>
        <?php if ($err('equipment') !== ''): ?><span class="sb-ct-err" id="ct-err-equipment"><?= e($err('equipment')) ?></span><?php endif; ?>
      </label>
      <label class="field sb-ct-full" for="ct-message">Message
        <textarea class="input" id="ct-message" name="message" rows="4" required<?= $aria('message') ?>><?= $val('message') ?></textarea>
        <?php if ($err('message') !== ''): ?><span class="sb-ct-err" id="ct-err-message"><?= e($err('message')) ?></span><?php endif; ?>
      </label>
      <button type="submit" class="btn btn--navy btn--block-m sb-ct-submit">Send message</button>
    </form>
  </div>
</section>
