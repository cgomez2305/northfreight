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
ns_default('EMAIL', 'nfreightsolutionsllc@gmail.com');
ns_default('WA_BASE', 'https://wa.me/18636513322');
// PENDIENTE (cliente): dominio definitivo. Sin "https://" ni barra final.
// Elegir UNO (con o sin www) y reflejarlo en .htaccess.
ns_default('DOMAIN', 'northstarfreight.example');

// --- Entidad / SEO (todas OPCIONALES: vacías hasta que el cliente las confirme) -----------------------------------
// Solo se emiten en JSON-LD (Organization) y en el footer cuando están definidas. Ver app/config.local.example.php.
ns_default('LEGAL_NAME', '');      // nombre legal completo (p. ej. el de la LLC)
ns_default('ADDRESS', []);         // ['street'=>'', 'city'=>'', 'region'=>'', 'postal'=>'', 'country'=>'US']
ns_default('SAME_AS', []);         // URLs de perfiles oficiales (LinkedIn, Facebook, YouTube…)
ns_default('FOUNDING_DATE', '');   // 'YYYY' o 'YYYY-MM-DD'
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

// --- Flags de contenido (§10) -------------------------------------------
// Hero del home: 'B' = "Booked. Tracked. Documented." (actual)
//                'A' = "Incidents are handled with evidence. And operations, as a team." (PDF)
ns_default('HERO_VARIANT', 'B');
ns_default('HERO_HEADLINES', [
    'A' => 'Incidents are handled with evidence. And operations, as a team.',
    'B' => 'Booked. Tracked. Documented.',
]);
// Foto de fondo del CTA final (assets/img/<base>-{640,1024,1280}.webp + .jpg). '' = panel liso. Una ruta puede anularla con 'cta_image' => 'base' | '' | false.
ns_default('CTA_IMAGE', 'nf-lone-truck-rain-night');
ns_default('SHOW_RESOURCES', true);         // Resources y Blog en nav/footer (activos desde Fase 1)
ns_default('SHOW_ON_SHIFT', true);          // barra "On shift" + relojes (replica la maqueta; revisar al confirmar horario)
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
            'show_on_shift'        => (bool) SHOW_ON_SHIFT,
            'show_equipment_chips' => (bool) SHOW_EQUIPMENT_CHIPS,
            'wa_messages'          => WA_MESSAGES,
        ];
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

return cfg();
