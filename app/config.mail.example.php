<?php
/**
 * Buzón de correo del sitio (plantilla). En el servidor se copia como app/config.mail.php (fuera de git, bloqueado en la web).
 * Devuelve un array; solo se aceptan SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_SECURE, MAIL_TO, MAIL_FROM y EMAIL,
 * y estas claves GANAN a config.local.php. Si se cambia la contraseña del buzón en hPanel, hay que cambiarla aquí también
 * (y en captacion/config/mail.env, que usa el mismo buzón).
 */
return [
    'SMTP_HOST'   => 'smtp.hostinger.com',
    'SMTP_PORT'   => 465,
    'SMTP_SECURE' => 'ssl',
    'SMTP_USER'   => 'contact@northstarfreightsolutions.us',
    'SMTP_PASS'   => 'CHANGE-ME',
    'MAIL_FROM'   => 'contact@northstarfreightsolutions.us',
    'MAIL_TO'     => 'contact@northstarfreightsolutions.us',
];
