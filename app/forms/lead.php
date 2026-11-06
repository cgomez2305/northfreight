<?php
/**
 * POST /careers/apply/ — solicitud multipaso de conductores y owner-operators (leads, sin base de datos).
 *
 * Lo incluye index.php con $route y $cfg en el ambito. Responde por si mismo y termina:
 *   exito            -> guarda el lead (storage/leads/AAAA-MM.jsonl), 303 a /careers/thank-you/?t=<token firmado>, y DESPUES avisa por correo y por webhook
 *   honeypot         -> el mismo 303 (exito falso), sin guardar ni enviar nada
 *   validacion       -> 422 con la misma pagina, errores por campo y los valores previos ($flash['errors'] / $flash['old']): no se pierde nada
 *   csrf / rapido    -> 403 / 422;  limite por IP -> 429;  no se pudo guardar -> 503
 *
 * Reglas de producto:
 *  - Elegibilidad (CDL estandar, ingles FMCSA 391.11, anos de experiencia) NUNCA rechaza en pantalla: solo marca `qualified` true/false en el lead. No se pregunta nacionalidad ni estatus migratorio.
 *  - Consentimiento (TCPA) OPCIONAL: se guarda consent {given, version, text exacto, fecha UTC, IP (solo si lo dio), user agent}. No condiciona el envio.
 *  - Atribucion (utm_*, referrer, landing, first_seen y ref = codigo de referido) llega en un campo oculto `attr` (JSON) que rellena main.js; sin JS el lead entra sin atribucion (valido).
 *  - Correo (PHPMailer, mismo camino que contact.php; sin SMTP se registra en storage/mail.log) y webhook opcional (LEAD_WEBHOOK_URL, POST JSON con cURL, timeout 4 s) se ejecutan tras guardar y
 *    NUNCA rompen la respuesta: si fallan se registra en storage/error.log y el lead sigue guardado.
 *  - Mini-CRM de captacion (captacion/, fase Q1): con CAPTACION_ENABLED = 1 el lead tambien se entrega EN PROCESO al motor (Captacion\Services\LeadIngest::handle) antes de escribir el JSONL.
 *    El JSONL SIEMPRE se escribe (respaldo) y lleva `crm_ingested` true/false (+ `crm_reason`): si el CRM esta apagado, sin .env, con la base caida o lanza cualquier excepcion, el lead no se pierde
 *    y `php captacion/bin/import-jsonl.php` lo reimporta (idempotente por id). El visitante ve exactamente lo mismo con o sin CRM (mismo 303, mismos errores).
 */

declare(strict_types=1);

/** Escribe un lead como una linea JSON en storage/leads/AAAA-MM.jsonl (dir 0750, archivo 0600, bloqueo exclusivo). */
function ns_lead_store(array $lead): bool
{
    $dir = ns_storage('leads');
    if (!ns_ensure_dir(ns_storage()) || !ns_ensure_dir($dir)) {
        return false;
    }
    $file = $dir . '/' . gmdate('Y-m') . '.jsonl';
    $isNew = !is_file($file);
    $line = json_encode($lead, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line === false) {
        return false;
    }
    $fh = @fopen($file, 'ab');
    if ($fh === false) {
        return false;
    }
    $ok = false;
    if (flock($fh, LOCK_EX)) {
        $ok = fwrite($fh, $line . "\n") === strlen($line) + 1;
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    if ($isNew) {
        @chmod($file, 0600);
    }
    return $ok;
}

/** POST JSON del lead a LEAD_WEBHOOK_URL (cURL, timeout 4 s, sin seguir redirecciones). Nunca lanza: devuelve false y registra el motivo (sin la URL ni el lead). */
function ns_lead_webhook(array $lead): bool
{
    $url = defined('LEAD_WEBHOOK_URL') ? trim((string) LEAD_WEBHOOK_URL) : '';
    if ($url === '') {
        return true;
    }
    if (!preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
        ns_log_error('lead-webhook skipped', 'URL no valida o cURL no disponible');
        return false;
    }
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($lead, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'User-Agent: NorthStar-site/1.0', 'X-NorthStar-Event: lead.created'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : '';
        curl_close($ch);
        if ($err !== '' || $code < 200 || $code >= 300) {
            ns_log_error('lead-webhook failed', ($err !== '' ? $err : 'HTTP ' . $code) . ' lead=' . ($lead['id'] ?? '?'));
            return false;
        }
        return true;
    } catch (Throwable $t) {
        ns_log_error('lead-webhook ' . get_class($t), $t->getMessage());
        return false;
    }
}

/**
 * Entrega el lead al motor del mini-CRM (en proceso, sin HTTP). NUNCA lanza: cualquier fallo devuelve ok=false con un motivo corto y el lead sigue su camino al JSONL.
 * @return array{ok:bool,reason:?string,lead_id:?int}
 */
function ns_lead_crm_ingest(array $lead): array
{
    if (!defined('CAPTACION_ENABLED') || (int) CAPTACION_ENABLED !== 1) {
        return ['ok' => false, 'reason' => 'disabled', 'lead_id' => null];
    }
    try {
        $boot = NS_ROOT . '/captacion/src/bootstrap.php';
        if (!is_file($boot)) {
            return ['ok' => false, 'reason' => 'not_installed', 'lead_id' => null];
        }
        defined('CAPTACION_EMBEDDED') || define('CAPTACION_EMBEDDED', true);   // el arranque no comprueba extensiones ni sale: aqui un fallo cae al JSONL (try/catch de abajo)
        require_once $boot;
        $r = \Captacion\Services\LeadIngest::handle(\Captacion\Services\LeadIngest::fromSiteLead($lead), 'web_careers', ['ip' => ns_client_ip(), 'public' => true]);
        return ['ok' => (bool) $r['ok'], 'reason' => $r['ok'] ? null : (string) ($r['reason'] ?? 'rejected'), 'lead_id' => $r['lead_id'] ?? null];
    } catch (Throwable $t) {
        ns_log_error('lead-crm ' . get_class($t), $t->getMessage());   // sin datos del lead
        return ['ok' => false, 'reason' => 'error', 'lead_id' => null];
    }
}

/** Cierra la respuesta al navegador (si el SAPI lo permite) para que el correo y el webhook no hagan esperar al usuario. */
function ns_lead_flush_response(): void
{
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

/** Cuerpo de texto plano del aviso por correo (todos los valores ya saneados: ns_clean quita saltos de linea de los campos de una linea). */
function ns_lead_mail_body(array $l, array $opt): string
{
    $label = static fn(string $group, ?string $key): string => $key === null || $key === '' ? '-' : (string) ($opt[$group][$key] ?? $key);
    $att = $l['attribution'];
    $b = "New application from the website (/careers/apply/).\n\n"
        . 'Lead ID: ' . $l['id'] . "\n"
        . 'Profile: ' . $label('profile', $l['profile']) . "\n"
        . ($l['job'] ? 'Job: ' . $l['job'] . "\n" : '')
        . 'Qualified (internal): ' . ($l['qualified'] ? 'yes' : 'no') . "\n\n"
        . 'Name: ' . $l['name'] . "\n"
        . 'Phone: ' . $l['phone'] . "\n"
        . 'Email: ' . ($l['email'] ?: '-') . "\n"
        . 'State: ' . $label('state', $l['state']) . "\n"
        . 'Language: ' . $label('language', $l['language']) . "\n"
        . 'Reach first by: ' . $label('contact_pref', $l['contact_pref']) . "\n"
        . 'Best time: ' . $label('best_time', $l['best_time']) . "\n\n"
        . 'Trucks: ' . ($l['trucks'] ?? '-') . "\n"
        . 'Equipment: ' . ($l['equipment'] ? implode(', ', array_map(static fn($k) => $label('equipment', $k), $l['equipment'])) : '-') . "\n"
        . 'Years of experience: ' . $l['years'] . "\n"
        . 'Authority: ' . $label('authority', $l['authority']) . "\n"
        . 'Standard CDL from state of residence: ' . $label('cdl_standard', $l['cdl_standard']) . "\n"
        . 'English (FMCSA 391.11): ' . $label('english', $l['english']) . "\n\n"
        . 'Contact consent (calls/SMS/email): ' . ($l['consent']['given'] ? 'GIVEN (' . $l['consent']['version'] . ')' : 'not given') . "\n\n"
        . 'Attribution: ' . implode(' | ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($att), $att)) . "\n";
    return $b . ns_mail_footer();
}

(static function (array $route, array $cfg): never {
    ns_form_boot();
    $form = 'lead';
    $in = $_POST;
    $opt = data('lead-options');
    $thanks = static fn(string $profile): string => url('/careers/thank-you/') . '?t=' . ns_lead_thanks_token($profile);

    $guard = ns_form_guard($in, $form);
    if (!empty($guard['honeypot'])) {
        header('Location: ' . $thanks('owner-operator'), true, 303);   // exito falso: el bot no aprende nada
        exit;
    }

    // --- Lectura y saneado (todo en listas blancas o con limites) ---
    $pick = static fn(string $k, string $group): string => (is_string($in[$k] ?? null) && isset($opt[$group][$in[$k]])) ? $in[$k] : '';
    $profileOptions = $opt['profile'];
    if (!jobs_active()) {
        unset($profileOptions['company-driver']);   // sin vacantes publicadas no se ofrece (ni se acepta) el perfil de company driver
    }
    $profile = (is_string($in['profile'] ?? null) && isset($profileOptions[$in['profile']])) ? $in['profile'] : '';
    $equipment = [];
    foreach (is_array($in['equipment'] ?? null) ? $in['equipment'] : [] as $e) {
        if (is_string($e) && isset($opt['equipment'][$e]) && !in_array($e, $equipment, true)) {
            $equipment[] = $e;
        }
    }
    $jobSlug = ns_clean($in['job'] ?? '', 80);
    $jobSlug = isset(jobs_active()[$jobSlug]) ? $jobSlug : '';
    $phoneRaw = ns_clean($in['phone'] ?? '', 30);
    $trucksRaw = ns_clean($in['trucks'] ?? '', 4);
    $yearsRaw = ns_clean($in['years'] ?? '', 3);

    // Valores previos para volver a pintar el formulario si hay errores (los campos vienen ya saneados).
    $old = [
        'profile' => $profile, 'trucks' => $trucksRaw, 'equipment' => $equipment, 'years' => $yearsRaw, 'authority' => $pick('authority', 'authority'),
        'cdl_standard' => $pick('cdl_standard', 'cdl_standard'), 'english' => $pick('english', 'english'),
        'name' => ns_clean($in['name'] ?? '', 100), 'phone' => $phoneRaw, 'email' => ns_clean($in['email'] ?? '', 120),
        'state' => $pick('state', 'state'), 'language' => $pick('language', 'language'), 'contact_pref' => $pick('contact_pref', 'contact_pref'),
        'best_time' => $pick('best_time', 'best_time'), 'consent' => !empty($in['consent']) ? '1' : '', 'job' => $jobSlug,
    ];

    if (empty($guard['ok'])) {
        ns_render_page($route, ns_flash_error($form, [], $old, (string) $guard['error']), (int) $guard['status']);
    }

    // --- Validacion de servidor ---
    $errors = [];
    if ($profile === '') {
        $errors['profile'] = 'Choose the option that describes you.';
    }
    if ($trucksRaw !== '' && (!ctype_digit($trucksRaw) || (int) $trucksRaw < 1 || (int) $trucksRaw > 999)) {
        $errors['trucks'] = 'Enter a whole number from 1 to 999, or leave it empty.';
    }
    if ($yearsRaw === '' || !ctype_digit($yearsRaw) || (int) $yearsRaw > 60) {
        $errors['years'] = 'Enter your years of driving experience as a whole number (0 if none).';
    }
    if ($old['cdl_standard'] === '') {
        $errors['cdl_standard'] = 'Choose an answer. "Not sure" is fine.';
    }
    if ($old['english'] === '') {
        $errors['english'] = 'Choose an answer.';
    }
    if ($old['name'] === '') {
        $errors['name'] = 'Please enter your name.';
    }
    $digits = (string) preg_replace('/\D+/', '', $phoneRaw);
    $phoneOk = $phoneRaw !== '' && preg_match('/^[+\d\s().\-]{7,30}$/', $phoneRaw) === 1 && strlen($digits) >= 10 && strlen($digits) <= 15;
    if (!$phoneOk) {
        $errors['phone'] = 'Enter a phone number we can reach, with area code.';
    }
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'That email does not look right. You can also leave it empty.';
    }
    if ($old['state'] === '') {
        $errors['state'] = 'Choose your state.';
    }
    if ($old['language'] === '') {
        $errors['language'] = 'Choose a language.';
    }
    if ($old['contact_pref'] === '') {
        $errors['contact_pref'] = 'Choose how you want us to reach you first.';
    }
    if ($errors) {
        ns_render_page($route, ns_flash_error($form, $errors, $old), 422);
    }

    // --- Atribucion (campo oculto `attr`, JSON de main.js): solo claves conocidas y con largo limitado ---
    $attr = ['utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => '', 'referrer' => '', 'landing' => '', 'first_seen' => '', 'ref' => ''];
    $decoded = is_string($in['attr'] ?? null) && strlen($in['attr']) <= 2000 ? json_decode($in['attr'], true) : null;
    if (is_array($decoded)) {
        foreach ($attr as $k => $_) {
            $attr[$k] = ns_clean($decoded[$k] ?? '', $k === 'referrer' || $k === 'landing' ? 300 : 100);
        }
        // Codigo de referido (?ref=): solo letras, numeros, guion y guion bajo (3 a 40); cualquier otra cosa se ignora sin error. El CRM comprueba que el codigo exista.
        $attr['ref'] = preg_match('/^[A-Za-z0-9_-]{3,40}$/', $attr['ref']) === 1 ? $attr['ref'] : '';
    }

    // --- Elegibilidad interna: nunca se muestra ni rechaza en pantalla ---
    $minYears = defined('LEAD_MIN_YEARS') ? (int) LEAD_MIN_YEARS : 1;
    $qualified = $old['cdl_standard'] === 'yes' && in_array($old['english'], ['yes', 'basic'], true) && (int) $yearsRaw >= $minYears;

    $consentGiven = $old['consent'] === '1';
    $now = gmdate('c');
    $ip = ns_client_ip();
    $phoneE164 = ((!str_starts_with(ltrim($phoneRaw), '+') && strlen($digits) === 10) ? '+1' : '+') . $digits;   // 10 digitos sin + = EE. UU.
    $lead = [
        'id'           => 'ld_' . bin2hex(random_bytes(6)),
        'created_at'   => $now,
        'form_version' => 'apply-v1',
        'profile'      => $profile,
        'job'          => $jobSlug !== '' ? $jobSlug : null,
        'qualified'    => $qualified,
        'qualified_rule' => ['cdl_standard' => 'yes', 'english' => ['yes', 'basic'], 'min_years' => $minYears],
        'name'         => $old['name'],
        'phone'        => $phoneE164,
        'email'        => $old['email'] !== '' ? $old['email'] : null,
        'state'        => $old['state'],
        'language'     => $old['language'],
        'contact_pref' => $old['contact_pref'],
        'best_time'    => $old['best_time'] !== '' ? $old['best_time'] : null,
        'trucks'       => $trucksRaw !== '' ? (int) $trucksRaw : null,
        'equipment'    => $equipment,
        'years'        => (int) $yearsRaw,
        'authority'    => $old['authority'] !== '' ? $old['authority'] : null,
        'cdl_standard' => $old['cdl_standard'],
        'english'      => $old['english'],
        'consent'      => [
            'given'   => $consentGiven,
            'version' => defined('CONSENT_VERSION') ? (string) CONSENT_VERSION : 'v1',
            'text'    => defined('CONSENT_TEXT_V1') ? (string) CONSENT_TEXT_V1 : '',
            'at'      => $consentGiven ? $now : null,
            'ip'      => $consentGiven ? $ip : null,   // la IP se guarda solo como prueba de un consentimiento dado
            'user_agent' => $consentGiven ? ns_user_agent() : null,
        ],
        'attribution'  => $attr,
        'meta'         => ['ip_hash' => ns_ip_hash(), 'user_agent' => ns_user_agent(), 'page' => (string) $route['path']],
    ];

    ns_rate_hit();
    $crm = ns_lead_crm_ingest($lead);
    $lead['crm_ingested'] = $crm['ok'];
    if (!$crm['ok'] && $crm['reason'] !== 'disabled') {
        $lead['crm_reason'] = $crm['reason'];
    }
    if (!ns_lead_store($lead) && !$crm['ok']) {   // solo es un error si NINGUN destino lo guardo
        ns_log_error('lead-store failed', 'no se pudo escribir storage/leads/');
        ns_render_page($route, ns_flash_error($form, [], $old, 'We could not save your application right now. Please message us on WhatsApp or email ' . ns_mail_to() . '.'), 503);
    }

    // Respuesta al usuario primero; el aviso por correo y el webhook van despues y nunca la rompen.
    header('Location: ' . $thanks($profile), true, 303);
    ns_lead_flush_response();
    try {
        $replyTo = $lead['email'] !== null ? [$lead['email'], $lead['name']] : null;
        ns_send_mail('New driver lead: ' . $lead['name'] . ' (' . $profile . ', ' . $lead['state'] . ')', ns_lead_mail_body($lead, $opt), $replyTo);
    } catch (Throwable $t) {
        ns_log_error('lead-mail ' . get_class($t), $t->getMessage());
    }
    ns_lead_webhook($lead);
    exit;
})($route, $cfg);
