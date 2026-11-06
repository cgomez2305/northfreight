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

// Destino de los mensajes de contacto, los avisos de descarga y las solicitudes de /careers/ (contact.php, download.php y lead.php leen MAIL_TO vía ns_mail_to()).
// Valor propuesto por el cliente (fase S): el mismo correo de contacto. Cuando exista el correo con dominio propio (se crea en hPanel), cambiar AQUÍ MAIL_TO
// y, en config.php o con define(), EMAIL (el correo visible) y MAIL_FROM (remitente; vacío = el buzón SMTP_USER).
define('MAIL_TO', 'nfreightsolutionsllc@gmail.com');
// define('MAIL_FROM', 'desk@tu-dominio.us');

// IP real detrás de un proxy/CDN: por defecto el límite por IP usa REMOTE_ADDR. Si en el servidor REMOTE_ADDR llega con la IP del proxy (todos los visitantes compartirían el mismo límite),
// indica aquí la cabecera que el proxy SOBRESCRIBE con la IP del visitante, p. ej. 'HTTP_X_FORWARDED_FOR' o 'HTTP_CF_CONNECTING_IP' (se usa el primer valor que sea una IP válida).
// COMPROBAR primero en el servidor real (var_dump de $_SERVER['REMOTE_ADDR'] y de la cabecera desde una petición de prueba); activarlo mal permite falsear la IP.
// define('TRUSTED_PROXY_HEADER', 'HTTP_X_FORWARDED_FOR');

// Límite de envíos por IP y ventana (segundos). Defecto: 5 por hora; el siguiente recibe 429.
define('RATE_LIMIT', 5);
// define('RATE_WINDOW', 3600);

// Opcional: flags de contenido (ver app/config.php).
// define('HERO_VARIANT', 'B');
// define('SHOW_RESOURCES', true);
// Chip "On shift · ET hh:mm" del header: RETIRADO en todos los entornos (SHOW_ON_SHIFT = false por defecto desde la fase S). Para volver a verlo: SHOW_ON_SHIFT = true (staging)
// y, además, SHOW_ON_SHIFT_PRODUCTION = true en producción (solo con el horario confirmado por el cliente).
// define('SHOW_ON_SHIFT', true);
// define('SHOW_ON_SHIFT_PRODUCTION', true);
// Cobertura e idiomas (texto visible en /contact/ y /our-desk/). Vacío = desaparece de todo el sitio.
// define('COVERAGE_TEXT', 'Dispatch desk coverage: 24/7');
// define('LANGUAGES_TEXT', 'English and Spanish');
// Descargables (BOL y agreement): false (defecto) = en producción dan 404 y no aparecen en ningún sitio; true solo cuando el cliente apruebe los archivos de assets/downloads/.
// define('DOWNLOADS_APPROVED', true);
// Video del equipo (YouTube, Vimeo o .mp4). Vacío = en producción no se muestran la fachada del video, el play ni los capítulos.
// define('DESK_VIDEO_URL', 'https://www.youtube.com/watch?v=XXXXXXXXXXX');
// define('SHOW_EQUIPMENT_CHIPS', false);

// --- Entidad y SEO (opcionales; no inventes datos: déjalos comentados hasta que el cliente los confirme) ---
// Con valor, se emiten en el JSON-LD de Organization / ProfessionalService; ADDRESS además se imprime (NAP) en el footer.
// LEGAL_NAME ya viene por defecto en app/config.php ('NorthStar Freight Solutions LLC'); aquí solo para cambiarlo. ADDRESS: omitida por decisión del cliente. FOUNDING_DATE y SAME_AS: pendientes.
// define('LEGAL_NAME', 'NorthStar Freight Solutions LLC');
// define('FOUNDING_DATE', '2026-06-01');   // solo cuando el cliente confirme la fecha exacta   // 'YYYY' o 'YYYY-MM-DD'
// define('ADDRESS', [
//     'street'  => '123 Main St, Suite 4',
//     'city'    => 'Lakeland',
//     'region'  => 'FL',
//     'postal'  => '33801',
//     'country' => 'US',
// ]);
// define('SAME_AS', [
//     'https://www.linkedin.com/company/example',
//     'https://www.facebook.com/example',
//     'https://www.youtube.com/@example',
// ]);

// robots.txt de producción: bots de entrenamiento de IA (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, CCBot).
// 'allow' (defecto) | 'block'. Los bots de búsqueda/respuesta de IA siempre se permiten.
// define('AI_TRAINING_BOTS', 'allow');

// --- Captación de conductores (/careers/*) ---
// Los leads se guardan en storage/leads/AAAA-MM.jsonl (fuera de web, 0600) y se avisa por correo (mismo SMTP de arriba; sin SMTP queda en storage/mail.log).
// Para convertirlos a CSV: `php bin/leads-export.php --out=leads.csv` (ver el encabezado del script).
// Webhook opcional: cada lead se envía también por POST JSON (timeout 4 s; si falla se registra en storage/error.log y el usuario no lo nota).
// define('LEAD_WEBHOOK_URL', 'https://hooks.example.com/northstar-leads');
// Años mínimos de experiencia para marcar `qualified` (CDL estándar Sí + inglés Sí o Básico + años >= este valor). El usuario nunca ve el resultado.
// Defecto 0 = SIN criterio de años (el cliente pidió no usarlos hasta aprobar el requisito; en el CRM se ajusta `driver_min_years` desde Configuración).
// define('LEAD_MIN_YEARS', 0);
// Mini-CRM de captación (captacion/, fase Q1): 1 = además del JSONL, cada lead se entrega al motor del CRM en proceso (necesita captacion/config/.env con la base de datos y haber corrido
// `php captacion/bin/migrate.php`). 0 (defecto) = apagado: el sitio se comporta exactamente como en la fase P. Si el CRM falla, el lead sigue guardándose en el JSONL con crm_ingested=false.
// define('CAPTACION_ENABLED', 1);
// Reservados para la analítica (§10). NADA los carga todavía: los eventos generate_lead / Lead solo salen si la página ya trae gtag/fbq.
// define('GA_ID', '');
// define('META_PIXEL_ID', '');
