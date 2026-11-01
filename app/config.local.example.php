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

// Clave para firmar los tokens CSRF de los formularios (sin sesiones ni cookies).
// Vacío = se genera sola en storage/app.key la primera vez (chmod 600). Para fijarla:
// `php -r "echo bin2hex(random_bytes(32));"` (mínimo 32 caracteres). Cambiarla invalida los formularios abiertos.
define('APP_SECRET', '');

// SMTP de Hostinger para los formularios (contacto y descargas, vía PHPMailer).
// Si SMTP_USER o SMTP_PASS están vacíos (o es "CHANGE-ME"), el sitio NO falla: cada mensaje se escribe
// completo en storage/mail.log y el visitante ve el mensaje de éxito. Útil en local y staging.
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);          // 465 con 'ssl'; 587 con 'tls'
define('SMTP_USER', 'forms@example.com');   // también es el remitente (From)
define('SMTP_PASS', 'CHANGE-ME');
define('SMTP_SECURE', 'ssl');      // 'ssl' | 'tls'

// Destino de los mensajes y avisos de descarga.
define('MAIL_TO', 'nfreightsolutionsllc@gmail.com');

// Límite de envíos por IP y ventana (segundos). Defecto: 5 por hora; el siguiente recibe 429.
define('RATE_LIMIT', 5);
// define('RATE_WINDOW', 3600);

// Opcional: flags de contenido (ver app/config.php).
// define('HERO_VARIANT', 'B');
// define('SHOW_RESOURCES', true);
// define('SHOW_ON_SHIFT', true);
// define('SHOW_EQUIPMENT_CHIPS', false);
