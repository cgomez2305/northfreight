<?php
/**
 * Cuerpo compartido de doc-bol y doc-agreement (tras el hero): What's inside + formulario de descarga,
 * How to use it (4 pasos), FAQ en líneas + tarjetas relacionadas.
 * Espera $doc = [inside[[strong,resto]], form_title, formats[[valor,etiqueta]], submit, notice,
 *                steps_title, steps[[título,texto]], (FAQ: route_faq_items($route), de app/data/faq.php), related[rc…]] y $route. Datos del cliente: nada inventado.
 */
$action = url((string) $route['path']);
$flashMsg = '';
$flashErr = false;
if (isset($flash)) {
    if (is_array($flash)) {
        $flashMsg = (string) ($flash['message'] ?? $flash['msg'] ?? '');
        $flashErr = in_array($flash['type'] ?? $flash['status'] ?? '', ['error', 'err', 'fail'], true) || !empty($flash['error']);
    } else {
        $flashMsg = (string) $flash;
    }
}
$csrf = function_exists('csrf_token') ? (string) csrf_token() : '';
?>
<section class="section" aria-labelledby="doc-inside">
  <div class="container stack">
    <div class="co-doc">
      <div class="co-doc__main">
        <?php partial('section-head', [ 'title' => (string) ($doc['inside_title'] ?? 'Every field, already in place'), 'id' => 'doc-inside']); ?>
        <ul class="co-list">
          <?php foreach ($doc['inside'] as $it): ?>
          <li><?= ns_star(16) ?><span><strong><?= e($it[0]) ?></strong>: <?= e($it[1]) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="co-doc__side">
        <form class="co-form" method="post" action="<?= e($action) ?>" aria-labelledby="doc-form-title">
          <span class="co-form__eyebrow t-meta">Free download</span>
          <p class="co-form__title head" id="doc-form-title">Get the template</p>
          <div class="co-flash<?= $flashErr ? ' co-flash--error' : '' ?>" role="status" aria-live="polite"><?= e($flashMsg) ?></div>
          <div class="g2">
            <label class="field">Name<input class="input" type="text" name="name" autocomplete="name" required></label>
            <label class="field">Email<input class="input" type="email" name="email" autocomplete="email" required></label>
          </div>
          <label class="field">Format<select class="input" name="format">
            <?php foreach ($doc['formats'] as $f): ?><option value="<?= e($f[0]) ?>"><?= e($f[1]) ?></option><?php endforeach; ?>
          </select></label>
          <div class="co-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <input type="hidden" name="form_ts" value="<?= time() ?>">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <button type="submit" class="btn btn--navy"><?= e($doc['submit']) ?></button>
          <p class="co-form__note">We only use your email to send the file. See our <a href="<?= e(url('/privacy-policy/')) ?>">privacy policy</a>.</p>
          <p class="co-form__note"><strong>Please note:</strong> <?= e($doc['notice']) ?></p>
        </form>
      </div>
    </div>
  </div>
</section>

<section class="section section--surface" aria-labelledby="doc-steps">
  <div class="container stack">
    <?php partial('section-head', [ 'title' => $doc['steps_title'], 'id' => 'doc-steps']); ?>
    <ol class="co-steps">
      <?php foreach ($doc['steps'] as $n => $st): ?>
      <li><strong class="co-step__t"><?= e($st[0]) ?></strong><span class="co-step__x"><?= e($st[1]) ?></span></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section class="section" aria-labelledby="doc-faq">
  <div class="container stack">
    <div class="co-faqwrap">
      <div class="co-faqhead">
        <h2 class="t-h2 c-navy" id="doc-faq">Before you print it</h2>
      </div>
      <div class="co-faqlist">
        <?php foreach (route_faq_items($route) as $q): ?>
        <details class="co-faq">
          <summary><?= phtext($q['q']) ?><span class="co-faq__plus" aria-hidden="true"><svg class="icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg></span></summary>
          <p><?= phtext($q['a']) ?></p>
        </details>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="g3 co-related">
      <?php foreach ($doc['related'] as $rc) { include __DIR__ . '/res-card.php'; } ?>
    </div>
  </div>
</section>
