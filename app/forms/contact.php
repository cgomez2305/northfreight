<?php
/**
 * POST /contact/ — formulario de contacto (secundario; el canal principal es WhatsApp, §8.2.4).
 *
 * Lo incluye index.php con $route y $cfg en el ambito. Responde por si mismo y termina:
 *   exito            -> 303 a /contact/?sent=1 (PRG; la plantilla muestra el mensaje con $flash['ok'])
 *   honeypot         -> mismo 303, sin enviar nada (exito falso)
 *   validacion       -> 422 con la misma pagina y $flash['errors'] / $flash['old']
 *   csrf / rapido    -> 403 / 422;  limite por IP -> 429;  fallo SMTP -> 503
 * Campos: name* company mc trucks equipment message*  (+ website=honeypot, form_ts, csrf).
 * La maqueta no tiene campo de correo/telefono de respuesta: el mensaje llega sin forma de contestar por escrito.
 */

declare(strict_types=1);

(static function (array $route, array $cfg): never {
    ns_form_boot();
    $form = 'contact';
    $in = $_POST;

    $guard = ns_form_guard($in, $form);
    if (!empty($guard['honeypot'])) {
        header('Location: ' . url((string) $route['path'] . '?sent=1'), true, 303);
        exit;
    }

    $v = [
        'name'      => ns_clean($in['name'] ?? '', 100),
        'company'   => ns_clean($in['company'] ?? '', 120),
        'mc'        => ns_clean($in['mc'] ?? '', 20),
        'trucks'    => ns_clean($in['trucks'] ?? '', 4),
        'equipment' => ns_clean($in['equipment'] ?? '', 60),
        'message'   => ns_clean($in['message'] ?? '', 3000, true),
    ];

    if (empty($guard['ok'])) {
        ns_render_page($route, ns_flash_error($form, [], $v, (string) $guard['error']), (int) $guard['status']);
    }

    $errors = [];
    if ($v['name'] === '') {
        $errors['name'] = 'Please enter your name.';
    }
    if ($v['message'] === '') {
        $errors['message'] = 'Please tell us a little about your trucks and what you need.';
    }
    if ($v['mc'] !== '' && !preg_match('/^[A-Za-z0-9#\- ]{1,20}$/', $v['mc'])) {
        $errors['mc'] = 'Use letters, numbers and dashes only.';
    }
    if ($v['trucks'] !== '' && (!ctype_digit($v['trucks']) || (int) $v['trucks'] < 1)) {
        $errors['trucks'] = 'Enter a whole number, 1 or more.';
    }
    if ($errors) {
        ns_render_page($route, ns_flash_error($form, $errors, $v), 422);
    }

    $body = "New message from the website contact form.\n\n"
        . 'Name: ' . $v['name'] . "\n"
        . 'Company: ' . ($v['company'] ?: '-') . "\n"
        . 'MC#: ' . ($v['mc'] ?: '-') . "\n"
        . 'Number of trucks: ' . ($v['trucks'] ?: '-') . "\n"
        . 'Equipment: ' . ($v['equipment'] ?: '-') . "\n"
        . "Reply address: none (the form has no email or phone field)\n\n"
        . "Message:\n" . $v['message'] . "\n"
        . ns_mail_footer();

    ns_rate_hit();
    if (!ns_send_mail('New dispatch inquiry: ' . $v['name'], $body)) {
        ns_render_page($route, ns_flash_error($form, [], $v, 'We could not send your message right now. Please message us on WhatsApp or email ' . ns_mail_to() . '.'), 503);
    }

    header('Location: ' . url((string) $route['path'] . '?sent=1'), true, 303);
    exit;
})($route, $cfg);
