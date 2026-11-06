<?php
/**
 * /careers/apply/ — solicitud multipaso (4 pasos) de owner-operators, flotas pequeñas y, si hay vacantes reales, company drivers (brief P).
 * Disponibles: $route, $cfg, helpers y, tras un POST con errores, $flash (['errors'=>[campo=>texto], 'old'=>[campo=>valor], 'message'=>…]).
 *
 * SIN JS: los cuatro pasos son <fieldset> apilados y se envía de una vez (principio del sitio). CON JS (assets/js/apply.js): un paso a la vez, barra de progreso, validación por paso,
 * foco gestionado, `aria-live` para el progreso y sin pérdida de datos al volver atrás (los campos no salen del DOM).
 * Backend: POST /careers/apply/ -> app/forms/lead.php (CSRF sin sesión, honeypot `website`, tiempo mínimo, límite por IP). Opciones: app/data/lead-options.php (también las valida el servidor).
 * Precarga (sin datos personales) por GET: ?type=owner-operator|small-fleet|company-driver, ?equipment=dry-van, ?trucks=2, ?job=slug (vacante real).
 * Elegibilidad (CDL estándar, inglés, años) NUNCA rechaza en pantalla: solo marca `qualified` en el lead. No se pregunta nacionalidad ni estatus migratorio.
 * Consentimiento (TCPA): casilla SIN marcar y OPCIONAL, con CONSENT_TEXT_V1 (pendiente de abogado). Atribución: main.js rellena el campo oculto `attr`.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$flash  = isset($flash) && is_array($flash) ? $flash : [];
$errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
$old    = is_array($flash['old'] ?? null) ? $flash['old'] : [];
$opt    = data('lead-options');
$jobs   = jobs_active();

// Perfiles ofrecidos: el de company driver solo existe si hay vacantes reales.
$profiles = $opt['profile'];
if (!$jobs) {
    unset($profiles['company-driver']);
}

// Precarga: valores previos del POST con errores; si no hay, parámetros GET validados contra las listas (nunca se pinta nada sin comprobar).
$g = static fn(string $k): string => is_string($_GET[$k] ?? null) ? trim((string) $_GET[$k]) : '';
if (!$old) {
    $gType = $g('type');
    $gEq = $g('equipment');
    $gTrucks = $g('trucks');
    $gJob = $g('job');
    $old = [
        'profile'   => isset($profiles[$gType]) ? $gType : '',
        'equipment' => isset($opt['equipment'][$gEq]) ? [$gEq] : [],
        'trucks'    => ctype_digit($gTrucks) && (int) $gTrucks >= 1 && (int) $gTrucks <= 999 ? $gTrucks : '',
        'job'       => isset($jobs[$gJob]) ? $gJob : '',
    ];
    if ($old['job'] !== '' && $old['profile'] === '' && isset($profiles['company-driver'])) {
        $old['profile'] = 'company-driver';
    }
}
$v    = static fn(string $k): string => e((string) ($old[$k] ?? ''));
$err  = static fn(string $k): string => isset($errors[$k]) && is_string($errors[$k]) ? $errors[$k] : '';
$desc = static fn(string $k): string => $err($k) !== '' ? ' aria-describedby="ap-err-' . $k . '"' : '';
$inv  = static fn(string $k): string => $err($k) !== '' ? ' aria-invalid="true"' : '';
$isEq = static fn(string $k): bool => in_array($k, (array) ($old['equipment'] ?? []), true);
$contactPref = (string) ($old['contact_pref'] ?? 'whatsapp');
$contactPref = isset($opt['contact_pref'][$contactPref]) ? $contactPref : 'whatsapp';
$errLine = static fn(string $k): string => $err($k) !== '' ? '<span class="ap__err" id="ap-err-' . $k . '">' . e($err($k)) . '</span>' : '';
$labels = ['profile' => 'Who you are', 'trucks' => 'Number of trucks', 'years' => 'Years of experience', 'cdl_standard' => 'Standard CDL', 'english' => 'English', 'name' => 'Name', 'phone' => 'Phone',
           'email' => 'Email', 'state' => 'State', 'language' => 'Language', 'contact_pref' => 'How to reach you'];
$general = $err('form');
$stepNames = ['Who you are', 'Your truck and experience', 'Eligibility', 'How to reach you'];
$eeo = 'NorthStar Freight Solutions LLC is an equal opportunity employer. We consider all qualified applicants without regard to race, color, religion, sex, sexual orientation, gender identity, national origin, age, disability, veteran status or any other protected status.';

partial('hero', [
    'route' => $route,
    'cls'   => 'cr-hero cr-hero--apply',
    'h1'    => 'Start your {hl}application{/hl}',
    'lead'  => 'Four short steps, no phone call first. Prefer to talk? Open WhatsApp and message a dispatcher.',
    'ctas'  => [['label' => 'Message the desk on WhatsApp', 'href' => wa($route['wa']), 'variant' => 'ghost', 'wa' => true, 'arrow' => true]],
    'stack' => true,
]);

/** Grupo de opciones tipo botón (radio): legend, opciones y error. $name = campo, $items = [valor => etiqueta], $req = required. */
$radioGroup = static function (string $name, string $legend, array $items, string $current, bool $req, string $msg, string $hint = '') use ($err, $errLine, $desc): void {
    ?>
      <fieldset class="ap__group" data-ap-group="<?= e($name) ?>"<?= $req ? ' data-ap-req data-msg="' . e($msg) . '"' : '' ?><?= $desc($name) ?>>
        <legend class="ap__label"><?= e($legend) ?></legend>
        <?php if ($hint !== ''): ?><p class="ap__hint" id="ap-hint-<?= e($name) ?>"><?= e($hint) ?></p><?php endif; ?>
        <div class="ap__opts">
          <?php foreach ($items as $val => $label): ?>
          <label class="ap__opt"><input type="radio" name="<?= e($name) ?>" value="<?= e((string) $val) ?>"<?= (string) $val === $current ? ' checked' : '' ?><?= $req ? ' required' : '' ?>><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?= $errLine($name) ?>
      </fieldset>
    <?php
};
?>
<section class="section ap-sec" aria-label="Application form">
  <div class="container container--narrow">
    <?php if ($err('form') !== '' || $errors): ?>
    <div class="ap__alert" role="alert" tabindex="-1" id="ap-alert">
      <p class="ap__alert-title"><?= $general !== '' ? e($general) : 'Please check the highlighted answers and send it again.' ?></p>
      <?php $links = array_filter(array_keys($errors), static fn($k) => isset($labels[$k])); if ($links): ?>
      <ul><?php foreach ($links as $k): ?><li><a href="#ap-f-<?= e($k) ?>"><?= e($labels[$k]) ?>: <?= e($err($k)) ?></a></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php /* Evita el CLS de la mejora con JS: esta clase ya deja el formulario con el aspecto del asistente (paso 1, progreso visible) antes de que apply.js se ejecute; apply.js la quita al iniciar y, si no iniciara en 4 s (script bloqueado), se quita aquí y queda el formulario apilado completo. */ ?>
    <script>document.documentElement.classList.add('js-apply');setTimeout(function(){if(!document.querySelector('.ap--js'))document.documentElement.classList.remove('js-apply')},4000)</script>
    <form class="ap" id="apply-form" method="post" action="<?= e(url('/careers/apply/')) ?>" data-apply data-attr-form>
      <input type="hidden" name="form_ts" value="<?= time() ?>">
      <input type="hidden" name="csrf" value="<?= function_exists('csrf_token') ? e(csrf_token()) : '' ?>">
      <input type="hidden" name="attr" value="" data-attr-field>
      <input type="hidden" name="job" value="<?= $v('job') ?>">
      <div class="sr-only" aria-hidden="true">
        <label for="ap-website">Leave this empty</label>
        <input type="text" id="ap-website" name="website" value="" tabindex="-1" autocomplete="off">
      </div>

      <div class="ap__prog ap__js-only" data-ap-prog>
        <p class="ap__count t-meta" data-ap-count aria-live="polite">Step 1 of 4: <?= e($stepNames[0]) ?></p>
        <div class="ap__bar" aria-hidden="true"><i data-ap-bar></i></div>
        <ol class="ap__dots" aria-hidden="true"><?php foreach ($stepNames as $i => $n): ?><li data-ap-dot="<?= $i + 1 ?>"><span><?= $i + 1 ?></span><b><?= e($n) ?></b></li><?php endforeach; ?></ol>
      </div>

      <!-- PASO 1 · quién eres -->
      <fieldset class="ap__step" data-ap-step="1">
        <legend class="ap__legend head" tabindex="-1"><span class="t-meta">Step 1 of 4</span><?= e($stepNames[0]) ?></legend>
        <div id="ap-f-profile"><?php $radioGroup('profile', 'Which describes you?', $profiles, (string) ($old['profile'] ?? ''), true, 'Choose the option that describes you.'); ?></div>
        <label class="field" for="ap-f-trucks" data-ap-only="owner-operator small-fleet">How many trucks do you run?
          <input class="input" type="number" id="ap-f-trucks" name="trucks" min="1" max="999" inputmode="numeric" autocomplete="off" value="<?= $v('trucks') ?>" data-msg="Enter a whole number from 1 to 999, or leave it empty."<?= $inv('trucks') . $desc('trucks') ?>>
          <?= $errLine('trucks') ?>
        </label>
      </fieldset>

      <!-- PASO 2 · equipo y experiencia -->
      <fieldset class="ap__step" data-ap-step="2">
        <legend class="ap__legend head" tabindex="-1"><span class="t-meta">Step 2 of 4</span><?= e($stepNames[1]) ?></legend>
        <fieldset class="ap__group" data-ap-group="equipment">
          <legend class="ap__label">What do you run? <span class="ap__opt-tag">Choose all that apply</span></legend>
          <div class="ap__opts ap__opts--wrap">
            <?php foreach ($opt['equipment'] as $k => $label): ?>
            <label class="ap__opt"><input type="checkbox" name="equipment[]" value="<?= e($k) ?>"<?= $isEq($k) ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <label class="field" for="ap-f-years">Years of driving experience
          <input class="input" type="number" id="ap-f-years" name="years" min="0" max="60" inputmode="numeric" autocomplete="off" required value="<?= $v('years') ?>" data-ap-req data-msg="Enter your years of driving experience as a whole number (0 if none)."<?= $inv('years') . $desc('years') ?>>
          <?= $errLine('years') ?>
        </label>
        <label class="field" for="ap-f-authority" data-ap-only="owner-operator small-fleet"><span>Your authority <span class="ap__opt-tag">Optional</span></span>
          <select class="input" id="ap-f-authority" name="authority">
            <option value="">Choose one</option>
            <?php foreach ($opt['authority'] as $k => $label): ?><option value="<?= e($k) ?>"<?= ($old['authority'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
      </fieldset>

      <!-- PASO 3 · elegibilidad (nunca rechaza en pantalla) -->
      <fieldset class="ap__step" data-ap-step="3">
        <legend class="ap__legend head" tabindex="-1"><span class="t-meta">Step 3 of 4</span><?= e($stepNames[2]) ?></legend>
        <p class="ap__note">We ask because federal rules apply to drivers. Your answers do not stop you from applying.</p>
        <div id="ap-f-cdl_standard"><?php $radioGroup('cdl_standard', 'Do you hold a standard Commercial Driver\'s License (CDL) issued by your state of residence?', $opt['cdl_standard'], (string) ($old['cdl_standard'] ?? ''), true, 'Choose an answer. "Not sure" is fine.'); ?></div>
        <div id="ap-f-english"><?php $radioGroup('english', 'Can you read and speak English well enough to talk with the public, understand road signs and answer official inquiries, as FMCSA requires of drivers (49 CFR 391.11)?', $opt['english'], (string) ($old['english'] ?? ''), true, 'Choose an answer.'); ?></div>
      </fieldset>

      <!-- PASO 4 · cómo contactarte -->
      <fieldset class="ap__step" data-ap-step="4">
        <legend class="ap__legend head" tabindex="-1"><span class="t-meta">Step 4 of 4</span><?= e($stepNames[3]) ?></legend>
        <div class="ap__grid">
          <label class="field" for="ap-f-name">Full name
            <input class="input" type="text" id="ap-f-name" name="name" autocomplete="name" required maxlength="100" value="<?= $v('name') ?>" data-ap-req data-msg="Please enter your name."<?= $inv('name') . $desc('name') ?>>
            <?= $errLine('name') ?>
          </label>
          <label class="field" for="ap-f-phone">Mobile or WhatsApp number
            <input class="input" type="tel" id="ap-f-phone" name="phone" autocomplete="tel" inputmode="tel" required maxlength="30" value="<?= $v('phone') ?>" data-ap-req data-ap-kind="phone" data-msg="Enter a phone number we can reach, with area code."<?= $inv('phone') . $desc('phone') ?>>
            <?= $errLine('phone') ?>
          </label>
          <label class="field" for="ap-f-email"><span>Email <span class="ap__opt-tag">Optional</span></span>
            <input class="input" type="email" id="ap-f-email" name="email" autocomplete="email" maxlength="120" value="<?= $v('email') ?>" data-ap-kind="email" data-msg="That email does not look right. You can also leave it empty."<?= $inv('email') . $desc('email') ?>>
            <?= $errLine('email') ?>
          </label>
          <label class="field" for="ap-f-state">State where you live
            <select class="input" id="ap-f-state" name="state" required data-ap-req data-msg="Choose your state."<?= $inv('state') . $desc('state') ?>>
              <option value="">Choose your state</option>
              <?php foreach ($opt['state'] as $k => $label): ?><option value="<?= e($k) ?>"<?= ($old['state'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <?= $errLine('state') ?>
          </label>
        </div>
        <div id="ap-f-language"><?php $radioGroup('language', 'Preferred language', $opt['language'], (string) ($old['language'] ?? ''), true, 'Choose a language.'); ?></div>
        <div id="ap-f-contact_pref"><?php $radioGroup('contact_pref', 'Do you prefer WhatsApp? How should we reach you first?', $opt['contact_pref'], $contactPref, true, 'Choose how you want us to reach you first.'); ?></div>
        <label class="field" for="ap-f-best_time"><span>Best time to reach you <span class="ap__opt-tag">Optional, your local time</span></span>
          <select class="input" id="ap-f-best_time" name="best_time">
            <option value="">No preference</option>
            <?php foreach ($opt['best_time'] as $k => $label): ?><option value="<?= e($k) ?>"<?= ($old['best_time'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <div class="ap__consent">
          <label class="ap__check"><input type="checkbox" name="consent" value="1"<?= ($old['consent'] ?? '') === '1' ? ' checked' : '' ?> aria-describedby="ap-consent-text"><span id="ap-consent-text"><?= e((string) CONSENT_TEXT_V1) ?></span></label>
          <p class="ap__hint">Optional: you can apply without checking this box. See our <a href="<?= e(url('/privacy-policy/')) ?>">Privacy Policy</a> and <a href="<?= e(url('/terms/')) ?>">Terms</a>.</p>
        </div>
        <?php if ($jobs): ?>
        <p class="ap__hint" data-ap-only="company-driver"><?= e($eeo) ?></p>
        <?php endif; ?>
      </fieldset>

      <div class="ap__nav">
        <button type="button" class="btn btn--ghost ap__back ap__js-only" data-ap-back>Back</button>
        <button type="button" class="btn btn--navy ap__next ap__js-only" data-ap-next>Next</button>
        <button type="submit" class="btn btn--navy ap__submit" data-ap-submit>Send my application</button>
      </div>
      <p class="ap__live sr-only" role="status" aria-live="polite" data-ap-live></p>
    </form>
  </div>
</section>
