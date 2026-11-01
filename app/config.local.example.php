<?php
/**
 * Plantilla de configuración local.
 *
 * Copia este archivo a app/config.local.php (está en .gitignore) y rellénalo
 * en cada servidor. NUNCA subas credenciales reales al repositorio.
 *
 * Se carga antes que app/config.php: cada define() de aquí gana al valor
 * por defecto.
 */

// Entorno: 'staging' (noindex + robots Disallow: /) o 'production'.
define('ENV', 'staging');

// Dominio canónico sin "https://" ni barra final (el mismo que fija .htaccess).
define('DOMAIN', 'staging.example.com');

// Token para ver rutas draft con ?preview=TOKEN. Genera uno largo y aleatorio
// (p. ej. `php -r "echo bin2hex(random_bytes(16));"`). Vacío = desactivado.
define('PREVIEW_TOKEN', '');

// SMTP de Hostinger para el formulario de contacto (PHPMailer).
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);          // 465 con 'ssl'; 587 con 'tls'
define('SMTP_USER', 'forms@example.com');
define('SMTP_PASS', 'CHANGE-ME');
define('SMTP_SECURE', 'ssl');

// Destino de los mensajes del formulario.
define('MAIL_TO', 'nfreightsolutionsllc@gmail.com');

// Opcional: flags de contenido (ver app/config.php).
// define('HERO_VARIANT', 'B');
// define('SHOW_RESOURCES', true);
// define('SHOW_ON_SHIFT', true);
// define('SHOW_EQUIPMENT_CHIPS', false);
