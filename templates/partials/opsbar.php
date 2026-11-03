<?php
/**
 * Barra superior: punto "live" + "Dispatch desk · On shift" (flag SHOW_ON_SHIFT) + relojes ET/CT/MT/PT + correo.
 * Los relojes los rellena JS (span[data-t="et|ct|mt|pt"]); sin JS muestran --:--.
 * Params: ninguno.
 */
require_once __DIR__ . '/kit.php';
$show = (bool) cfg('show_on_shift');
$email = (string) cfg('email');
?>
<div class="opsbar" id="opsbar">
  <div class="opsbar__in">
    <?php if ($show): ?>
    <div class="opsbar__status"><span class="opsbar__dot" aria-hidden="true"></span><span><span class="hide-m">Dispatch desk · </span>On shift</span></div>
    <?php endif; ?>
    <div class="opsbar__clocks" role="group" aria-label="Current time in US time zones">
      <span>ET <strong><span data-t="et">--:--</span></strong></span>
      <span class="hide-m">CT <strong><span data-t="ct">--:--</span></strong></span>
      <span class="hide-m">MT <strong><span data-t="mt">--:--</span></strong></span>
      <span>PT <strong><span data-t="pt">--:--</span></strong></span>
    </div>
    <a class="opsbar__mail hide-tab" href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
  </div>
</div>
