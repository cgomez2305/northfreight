<?php
/**
 * Configuración del sitio NorthStar Freight Solutions.
 *
 * Define constantes y devuelve el array $cfg (mismo contenido, para plantillas).
 * app/config.local.php (fuera de git) se carga PRIMERO y puede sobreescribir
 * cualquier constante con define(): aquí solo se define lo que no exista aún.
 *
 * Compatible con PHP 8.2 (sin constantes tipadas ni json_validate).
 */

if (defined('NS_CONFIG_LOADED')) {
    return cfg();
}
define('NS_CONFIG_LOADED', true);

// 1) Local primero: así sus define() ganan a los valores por defecto de abajo.
$ns_local = __DIR__ . '/config.local.php';
if (is_file($ns_local)) {
    require $ns_local;
}
unset($ns_local);

/** Define una constante solo si no la fijó config.local.php. */
function ns_default(string $name, mixed $value): void
{
    if (!defined($name)) {
        define($name, $value);
    }
}

// --- Entorno -----------------------------------------------------------
// 'staging' (noindex, robots Disallow: /) | 'production'. Por defecto staging
// para que un despliegue sin config.local.php nunca se indexe por error.
ns_default('ENV', 'staging');

// --- Marca y contacto (§8.3) --------------------------------------------
ns_default('BRAND', 'NorthStar Freight Solutions');
ns_default('BRAND_SHORT', 'NorthStar');
ns_default('PHONE', '+1 863-651-3322');          // visible como texto
ns_default('TEL', '+18636513322');                // para href="tel:"
// Correo visible y de contacto. Se imprime en footer, CTA final, /contact/, legales y pop-up móvil, y llega a JSON-LD y llms.txt: cambiarlo AQUÍ (o con define() en config.local.php)
// basta para pasar al correo con dominio propio. Literal aparte: la meta de /contact/ en app/routes.php (no se toca sin decisión de SEO).
ns_default('EMAIL', 'nfreightsolutionsllc@gmail.com');
// Remitente (From) de los correos del sitio. Vacío = el buzón SMTP_USER. Con SMTP de Hostinger debe ser el propio buzón autenticado.
ns_default('MAIL_FROM', '');
ns_default('WA_BASE', 'https://wa.me/18636513322');
// PENDIENTE (cliente): dominio definitivo. Sin "https://" ni barra final.
// Elegir UNO (con o sin www) y reflejarlo en .htaccess.
ns_default('DOMAIN', 'northstarfreight.example');

// --- Entidad / SEO (todas OPCIONALES: vacías hasta que el cliente las confirme) -----------------------------------
// Solo se emiten en JSON-LD (Organization) y en el footer cuando están definidas. Ver app/config.local.example.php.
ns_default('LEGAL_NAME', 'NorthStar Freight Solutions LLC');   // nombre legal (confirmado por el cliente, fase S). Llega a JSON-LD legalName. No es secreto.
ns_default('ADDRESS', []);         // OMITIDA a propósito (decisión del cliente, fase S: no se publica dirección). ['street'=>'', 'city'=>'', 'region'=>'', 'postal'=>'', 'country'=>'US']
ns_default('SAME_AS', []);         // URLs de perfiles oficiales (LinkedIn, Facebook, YouTube…)
ns_default('FOUNDING_DATE', '');   // PENDIENTE (fecha exacta). 'YYYY' o 'YYYY-MM-DD'. Solo se sabe que opera desde el 1 de junio de 2026: no se publica sin confirmar.
// robots.txt de producción: bots de ENTRENAMIENTO de IA (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended, CCBot).
// 'allow' (defecto) | 'block'. Los bots de búsqueda/respuesta de IA (OAI-SearchBot, PerplexityBot…) siempre quedan permitidos.
ns_default('AI_TRAINING_BOTS', 'allow');

// Token de ?preview=TOKEN para ver rutas draft. Vacío = vista previa desactivada.
ns_default('PREVIEW_TOKEN', '');

// --- Correo del formulario (§9). Credenciales reales SOLO en config.local.php
ns_default('SMTP_HOST', 'smtp.hostinger.com');
ns_default('SMTP_PORT', 465);
ns_default('SMTP_USER', '');
ns_default('SMTP_PASS', '');
ns_default('SMTP_SECURE', 'ssl');                 // 'ssl' (465) | 'tls' (587)
ns_default('MAIL_TO', EMAIL);                     // destino de los envíos del formulario

// --- Captación de conductores / leads (/careers/*) -------------------------------------------------------------------
// Cada solicitud se guarda como una línea JSON en storage/leads/AAAA-MM.jsonl (sin base de datos) y avisa por correo.
// LEAD_WEBHOOK_URL (opcional, en config.local.php): si existe, cada lead se envía también por POST JSON (cURL, timeout 4 s; un fallo nunca rompe la respuesta al usuario).
ns_default('LEAD_WEBHOOK_URL', '');
// Años mínimos de experiencia para marcar un lead como `qualified` (junto con CDL estándar = Sí e inglés Sí/Básico). PENDIENTE (cliente): confirmar el número.
ns_default('LEAD_MIN_YEARS', 1);
// Analítica: reservados para cuando Carlos elija herramienta (§10). HOY NO SE CARGA NINGÚN SCRIPT DE TERCEROS: los eventos (generate_lead / Lead) solo se emiten si ya existen gtag/fbq en la página.
// Mini-CRM de captacion (captacion/): 1 = ademas del JSONL, cada lead se entrega al motor en proceso (requiere captacion/config/.env y la base migrada). 0 = apagado (comportamiento de la fase P).
// Por defecto se enciende solo donde exista captacion/config/.env (el servidor); en local y en pruebas queda apagado. Un define('CAPTACION_ENABLED', 0|1) en config.local.php siempre manda.
ns_default('CAPTACION_ENABLED', is_file(__DIR__ . '/../captacion/config/.env') ? 1 : 0);
ns_default('GA_ID', '');
ns_default('META_PIXEL_ID', '');
// Consentimiento (TCPA) del formulario de solicitud. OPCIONAL para el usuario (la casilla va SIN marcar y no condiciona el envío). Cada lead guarda el texto exacto, la versión, la fecha UTC y la IP.
// Si se cambia el texto, crea CONSENT_TEXT_V2 y sube CONSENT_VERSION: no reescribas el V1 (los leads antiguos lo citan). TEXTO PENDIENTE DE REVISIÓN DEL ABOGADO.
ns_default('CONSENT_VERSION', 'v1');
ns_default('CONSENT_TEXT_V1', 'I agree that NorthStar Freight Solutions LLC may contact me about my application and its services at the phone number and email I provided, by phone call, text message (SMS) and email, including messages sent with an automated system. Message and data rates may apply. Consent is not a condition of applying or of buying any service. I can opt out at any time by replying STOP or by telling the dispatch desk.');

// --- Flags de contenido (§10) -------------------------------------------
// Hero del home: 'B' = "Booked. Tracked. Documented." (actual)
//                'A' = "Incidents are handled with evidence. And operations, as a team." (PDF)
ns_default('HERO_VARIANT', 'B');
ns_default('HERO_HEADLINES', [
    'A' => 'Incidents are handled with evidence. And operations, as a team.',
    'B' => 'Booked. Tracked. Documented.',
]);
// Foto de fondo del CTA final (assets/img/<base>-{640,1024,1280}.webp + .jpg). '' = panel liso. Una ruta puede anularla con 'cta_image' => 'base' | '' | false.
ns_default('CTA_IMAGE', 'nf-cta-night-truck');
ns_default('SHOW_RESOURCES', true);         // Resources y Blog en nav/footer (activos desde Fase 1)
// Chip "On shift · ET hh:mm" de la isla de acciones del header. Afirma que el desk trabaja AHORA, así que solo se muestra en producción si config.local.php lo activa
// de forma explícita con SHOW_ON_SHIFT_PRODUCTION = true (tras confirmar el horario con el cliente). En staging se ve para revisar el diseño.
ns_default('SHOW_ON_SHIFT_PRODUCTION', false);
ns_default('SHOW_ON_SHIFT', false);   // fase S: retirado en TODOS los entornos (el cliente anuncia cobertura 24/7 como texto, ver COVERAGE_TEXT). El mecanismo sigue disponible.

// Cobertura del desk (fase S, decisión del cliente: 24/7). UN solo sitio para cambiarla o vaciarla: con '' desaparece de todas las páginas.
// Aparece solo donde había un hueco natural: la línea de horario de /contact/ (rótulo "Desk hours") y el cierre de /our-desk/. Sin promesas de tiempo de respuesta.
// Sin openingHours en JSON-LD a propósito. PENDIENTE de validar por Carlos con el cliente.
ns_default('COVERAGE_TEXT', 'Dispatch desk coverage: 24/7');
// Idiomas de atención (fase S: el equipo atiende en inglés y español). '' = no se muestra. Aparece en /contact/ y en el cierre de /our-desk/.
ns_default('LANGUAGES_TEXT', 'English and Spanish');
// Descargables (BOL y agreement): el cliente pidió NO ofrecerlos hasta que estén aprobados. false (defecto) = en ENV=production las rutas /resources/bol-template/ y
// /resources/dispatcher-carrier-agreement/ dan 404 y desaparecen de sitemap, llms, menús, listados, footer y enlaces; en staging se ven para revisar. true = se publican
// (siempre que además existan los archivos de assets/downloads/ que declara cada ruta en needs_files).
ns_default('DOWNLOADS_APPROVED', false);
// URL del video del equipo (YouTube, Vimeo o .mp4). Vacía = no hay video: en producción se omiten la fachada, el play, la duración y los capítulos (en staging se ven para revisar el diseño).
ns_default('DESK_VIDEO_URL', '');
ns_default('SHOW_EQUIPMENT_CHIPS', false);  // chips "By equipment" del mega menú

// --- Mensajes de WhatsApp por página -------------------------------------
// Clave = valor de $route['wa']. wa('clave') devuelve la URL wa.me completa.
ns_default('WA_MESSAGES', [
    'default'     => "Hi, I'd like to talk about dispatch for my fleet with NorthStar.",
    'owner'       => "Hi, I'm an owner-operator and I'd like to talk about dispatch.",
    'relay'       => "Hi, I'd like help with Amazon Relay dispatch.",
    'dryvan'      => "Hi, I'd like to talk about dry van dispatch.",
    'tracking'    => "Hi, I'd like help tracking my loads.",
    'backoffice'  => "Hi, I'd like help with my trucking back office.",
    'post'        => "Hi, I read your article and want to talk about dispatch.",
    'careers'       => "Hi, I'd like to know how I can work with NorthStar.",
    'careers-owner' => "Hi, I'm an owner-operator and I'd like to talk about partnering with NorthStar.",
    'driver'        => "Hi, I'm a CDL-A driver and I'd like to ask about openings with NorthStar.",
    'thanks-owner'  => "Hi, I'm an owner-operator and I just sent my application on the NorthStar website.",
    'thanks-fleet'  => "Hi, I run a small fleet and I just sent my application on the NorthStar website.",
    'thanks-driver' => "Hi, I'm a CDL-A driver and I just sent my application on the NorthStar website.",
    'equip-box'        => 'Hi, I run a box truck and I would like to talk about dispatch.',
    'equip-hotshot'    => 'Hi, I run a hotshot and I would like to talk about dispatch.',
    'equip-flatbed'    => 'Hi, I run a flatbed and I would like to talk about dispatch.',
    'equip-power-only' => 'Hi, I run a power only and I would like to talk about dispatch.',
    'equip-reefer'     => 'Hi, I run a reefer and I would like to talk about dispatch.',
]);

/** Devuelve el array de configuración (cacheado). cfg('env') para una clave. */
function cfg(?string $key = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'brand'                => BRAND,
            'brand_short'          => BRAND_SHORT,
            'phone'                => PHONE,
            'tel'                  => TEL,
            'email'                => EMAIL,
            'domain'               => DOMAIN,
            'legal_name'           => LEGAL_NAME,
            'address'              => ADDRESS,
            'same_as'              => SAME_AS,
            'founding_date'        => FOUNDING_DATE,
            'wa_base'              => WA_BASE,
            'env'                  => ENV,
            'is_production'        => ENV === 'production',
            'hero_variant'         => HERO_VARIANT,
            'hero_headline'        => HERO_HEADLINES[HERO_VARIANT] ?? HERO_HEADLINES['B'],
            'show_resources'       => (bool) SHOW_RESOURCES,
            'show_on_shift'        => (bool) SHOW_ON_SHIFT && (ENV !== 'production' || (bool) SHOW_ON_SHIFT_PRODUCTION),   // en producción solo con SHOW_ON_SHIFT_PRODUCTION = true
            'coverage_text'        => trim((string) COVERAGE_TEXT),
            'languages_text'       => trim((string) LANGUAGES_TEXT),
            'downloads_approved'   => (bool) DOWNLOADS_APPROVED,
            'show_equipment_chips' => (bool) SHOW_EQUIPMENT_CHIPS,
            'wa_messages'          => WA_MESSAGES,
        ];
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

return cfg();
