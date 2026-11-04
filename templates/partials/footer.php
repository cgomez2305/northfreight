<?php
/**
 * Footer: logo + descripción · Services · Company · Talk to the desk; fila inferior © año, MC#/USDOT (ph), Privacy, Terms.
 * WhatsApp, teléfono y correo siempre visibles como texto, con icono (bloque de contacto). "Back to top" apunta a #site-header (la cabecera).
 * Ancla #footer-nav (alternativa sin JS al menú móvil).
 * Dirección (NAP) opcional: solo si ADDRESS está definida (org_address()).
 * Params: wa(string|null).
 */
require_once __DIR__ . '/kit.php';
$wa = $wa ?? null;
$m = ns_menu();
$services = array_merge($m['dispatch'], $m['ops']);
$company = $m['main'];
$extra = [];
if (cfg('show_resources')) {
    foreach ([['Resources', '/resources/'], ['Blog', '/blog/']] as $x) {
        if (route_is_live($x[1])) {
            $extra[] = $x;
        }
    }
}
$email = (string) cfg('email');
$legalId = ph('MC# pending · USDOT pending');
$addr = org_address();   // NAP: solo se imprime si ADDRESS está definida en config.local.php
?>
<footer class="footer" id="footer-nav">
  <div class="footer__grid g4">
    <div class="footer__brand">
      <?= ns_logo() ?>
      <p>Remote truck dispatch and back-office desk for owner-operators and small fleets.</p>
    </div>
    <nav class="footer__col" aria-label="Services">
      <span class="footer__head t-meta">Services</span>
      <?php foreach ($services as $i): ?><a class="foot__link" href="<?= e(url($i[2])) ?>"><?= e($i[4] ?? $i[0]) ?></a><?php endforeach; ?>
    </nav>
    <nav class="footer__col" aria-label="Company">
      <span class="footer__head t-meta">Company</span>
      <?php
      $links = [];
      foreach ($company as $i) { $links[$i[2]] = $i[0]; }
      $order = [['/how-it-works/', 'How it works'], ['/our-desk/', 'Our desk']];
      foreach ($order as $o) { if (isset($links[$o[0]])) { echo '<a class="foot__link" href="' . e(url($o[0])) . '">' . e($o[1]) . '</a>'; } }
      foreach ($extra as $x) { echo '<a class="foot__link" href="' . e(url($x[1])) . '">' . e($x[0]) . '</a>'; }
      if (isset($links['/contact/'])) { echo '<a class="foot__link" href="' . e(url('/contact/')) . '">Contact</a>'; }
      ?>
    </nav>
    <div class="footer__col">
      <span class="footer__head t-meta">Talk to the desk</span>
      <a class="foot__contact" href="<?= e(wa($wa)) ?>" rel="noopener">
        <span class="foot__ico"><?= ns_icon('whatsapp', 18) ?></span>
        <span class="foot__ctext"><span class="t-meta">WhatsApp</span><span class="foot__cval"><?= e(cfg('phone')) ?></span></span>
      </a>
      <a class="foot__contact" href="mailto:<?= e($email) ?>">
        <span class="foot__ico"><?= ns_icon('mail', 18) ?></span>
        <span class="foot__ctext"><span class="t-meta">Email</span><span class="foot__cval foot__cval--mail"><?= e($email) ?></span></span>
      </a>
      <?php if ($addr): ?>
      <address class="foot__addr">
        <?php if (isset($addr['street'])): ?><span><?= e($addr['street']) ?></span><?php endif; ?>
        <?php $cityLine = trim(($addr['city'] ?? '') . (isset($addr['city'], $addr['region']) ? ', ' : '') . ($addr['region'] ?? '') . (isset($addr['postal']) ? ' ' . $addr['postal'] : '')); ?>
        <?php if ($cityLine !== ''): ?><span><?= e($cityLine) ?></span><?php endif; ?>
        <?php if (isset($addr['country'])): ?><span><?= e($addr['country']) ?></span><?php endif; ?>
      </address>
      <?php endif; ?>
    </div>
  </div>
  <div class="footer__bar">
    <span>© <?= date('Y') ?> <?= e(cfg('brand')) ?> LLC</span>
    <span class="footer__legal">
      <?php if ($legalId !== ''): ?><span><?= $legalId ?></span><?php endif; ?>
      <?php if (route_is_live('/privacy-policy/')): ?><a class="foot__link" href="<?= e(url('/privacy-policy/')) ?>">Privacy</a><?php endif; ?>
      <?php if (route_is_live('/terms/')): ?><a class="foot__link" href="<?= e(url('/terms/')) ?>">Terms</a><?php endif; ?>
      <a class="foot__top" href="#site-header">Back to top <span class="arrow" aria-hidden="true">→</span></a>
    </span>
  </div>
</footer>
