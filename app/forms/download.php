<?php
/**
 * POST /resources/bol-template/ y /resources/dispatcher-carrier-agreement/ — descarga de plantillas.
 *
 * Lo incluye index.php con $route y $cfg en el ambito. Campos: name*, email*, format* (segun el documento),
 * website=honeypot, form_ts, csrf.
 *   archivo existe   -> se entrega (attachment) y se avisa por correo a MAIL_TO tras cerrar la respuesta
 *   archivo no existe-> 422 con $flash['errors']['format']; la solicitud se avisa por correo y se anota en
 *                       storage/downloads.log como UNAVAILABLE (no cuenta como entrega)
 *   honeypot         -> 303 a ?sent=1 sin entregar ni avisar
 * Los nombres de archivo salen SIEMPRE de la lista blanca de abajo, nunca de la entrada del usuario.
 */

declare(strict_types=1);

(static function (array $route, array $cfg): never {
    ns_form_boot();
    $form = 'download';

    // Lista blanca: ruta -> documento y archivos por formato (en assets/downloads/).
    $docs = [
        '/resources/bol-template/' => [
            'label' => 'BOL template',
            'files' => ['pdf' => 'bol-template.pdf', 'docx' => 'bol-template.docx'],
        ],
        '/resources/dispatcher-carrier-agreement/' => [
            'label' => 'Dispatcher-carrier agreement',
            'files' => ['docx' => 'dispatcher-carrier-agreement.docx'],
        ],
    ];
    $mimes = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    $doc = $docs[(string) ($route['path'] ?? '')] ?? null;
    if ($doc === null) {
        ns_plain(405, 'Method not allowed', ['Allow: GET, HEAD']);
    }

    $in = $_POST;
    $guard = ns_form_guard($in, $form);
    if (!empty($guard['honeypot'])) {
        header('Location: ' . url((string) $route['path'] . '?sent=1'), true, 303);
        exit;
    }

    $v = [
        'name'   => ns_clean($in['name'] ?? '', 100),
        'email'  => ns_clean($in['email'] ?? '', 254),
        'format' => strtolower(ns_clean($in['format'] ?? '', 8)),
    ];

    if (empty($guard['ok'])) {
        ns_render_page($route, ns_flash_error($form, [], $v, (string) $guard['error']), (int) $guard['status']);
    }

    $errors = [];
    if ($v['name'] === '') {
        $errors['name'] = 'Please enter your name.';
    }
    if ($v['email'] === '' || !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (!isset($doc['files'][$v['format']])) {
        $errors['format'] = 'Choose a format.';
    }
    if ($errors) {
        ns_render_page($route, ns_flash_error($form, $errors, $v), 422);
    }

    $fileName = $doc['files'][$v['format']];
    $fullPath = NS_ROOT . '/assets/downloads/' . $fileName;
    $exists = is_file($fullPath) && is_readable($fullPath);
    $ip = ns_ip_hash();

    ns_rate_hit();
    ns_log('downloads', gmdate('c') . ' ' . ($exists ? 'DELIVERED' : 'UNAVAILABLE')
        . ' doc="' . $doc['label'] . '" format=' . $v['format']
        . ' email=' . $v['email'] . ' name="' . $v['name'] . '" ip=' . $ip);

    $subject = 'Template download: ' . $doc['label'] . ' (' . $v['email'] . ')';
    $body = "Someone requested a template from the website.\n\n"
        . 'Document: ' . $doc['label'] . "\n"
        . 'Format: ' . strtoupper($v['format']) . "\n"
        . 'Name: ' . $v['name'] . "\n"
        . 'Email: ' . $v['email'] . "\n"
        . 'Status: ' . ($exists ? 'file delivered' : 'FILE NOT AVAILABLE YET - the visitor was told to message us; please send it manually') . "\n"
        . ns_mail_footer();
    $replyTo = [$v['email'], $v['name']];

    if (!$exists) {
        ns_send_mail($subject, $body, $replyTo);
        ns_render_page(
            $route,
            ns_flash_error($form, ['format' => 'This file isn\'t available yet. Message us and we\'ll send it.'], $v),
            422
        );
    }

    // Entrega: primero el archivo, el aviso por correo despues (SMTP puede tardar).
    $size = (int) filesize($fullPath);
    ignore_user_abort(true);
    http_response_code(200);
    header('Content-Type: ' . $mimes[$v['format']]);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($fullPath);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    ns_send_mail($subject, $body, $replyTo);
    exit;
})($route, $cfg);
