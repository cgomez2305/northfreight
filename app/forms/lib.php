<?php
/**
 * Utilidades compartidas de los formularios (contacto y descargas). Sin sesiones, sin base de datos.
 *
 * Lo incluye index.php SIEMPRE (despues de helpers.php y antes de renderizar), para que las plantillas
 * encuentren csrf_token() con function_exists().
 *
 *  - csrf_token()      token sin sesion "ts.hmac" (HMAC-SHA256, valido 2 h). Clave: APP_SECRET o storage/app.key.
 *  - ns_form_guard()   honeypot, CSRF, tiempo minimo, limite por IP.
 *  - ns_clean()        saneado UTF-8 / sin CRLF.
 *  - ns_send_mail()    PHPMailer por SMTP; sin credenciales: staging -> storage/mail.log como exito; produccion -> mail.log y false.
 *  - ns_render_page()  re-render de la misma pagina con $flash (el closure de render_route() no ve variables globales).
 *
 * Todo lo que se escribe va a storage/ (denegado por web): app.key, ratelimit/, *.log.
 * Compatible con PHP 8.2.
 */

declare(strict_types=1);

if (defined('NS_FORMS_LIB')) {
    return;
}
define('NS_FORMS_LIB', true);

const NS_CSRF_TTL = 7200;        // 2 h
const NS_MIN_FILL_SECONDS = 3;   // tiempo minimo entre pintar el formulario y enviarlo
const NS_MAX_POST_BYTES = 65536; // 64 KB: sobra para estos formularios

// --- Rutas / registro --------------------------------------------------------------------------

function ns_storage(string $sub = ''): string
{
    $dir = NS_ROOT . '/storage';
    return $sub === '' ? $dir : $dir . '/' . ltrim($sub, '/');
}

/** Crea un directorio bajo storage/ si hace falta (0750). */
function ns_ensure_dir(string $dir): bool
{
    return is_dir($dir) || @mkdir($dir, 0750, true) || is_dir($dir);
}

/** Anade una linea/bloque a storage/<name>.log con rotacion simple a 1 MB (<name>.log.1). */
function ns_log(string $name, string $text): void
{
    if (!preg_match('/^[a-z]{3,12}$/', $name) || !ns_ensure_dir(ns_storage())) {
        return;
    }
    $file = ns_storage($name . '.log');
    clearstatcache(true, $file);
    if (is_file($file) && (int) @filesize($file) > 1048576) {
        @rename($file, $file . '.1');
    }
    @file_put_contents($file, $text . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** Linea de error sin datos sensibles (nunca credenciales). */
function ns_log_error(string $context, string $detail = ''): void
{
    ns_log('error', gmdate('c') . ' ' . $context . ($detail !== '' ? ' :: ' . ns_redact($detail) : ''));
}

/** Quita credenciales SMTP y rutas del servidor de un texto antes de registrarlo. */
function ns_redact(string $s): string
{
    foreach ([defined('SMTP_PASS') ? SMTP_PASS : '', defined('SMTP_USER') ? SMTP_USER : ''] as $secret) {
        if (is_string($secret) && $secret !== '') {
            $s = str_replace($secret, '[redacted]', $s);
        }
    }
    $s = str_replace(NS_ROOT, '[root]', $s);
    return (string) preg_replace('/[\r\n]+/', ' ', $s);
}

/** Prepara el entorno de un handler: sin errores en pantalla, errores a storage/error.log. */
function ns_form_boot(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    if (ns_ensure_dir(ns_storage())) {
        ini_set('error_log', ns_storage('error.log'));
    }
}

/** Cabeceras de toda respuesta a un POST: nada en cache, fuera del indice. */
function ns_post_headers(): void
{
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// --- Clave y CSRF (sin sesion) -------------------------------------------------------------------

/** Clave HMAC: APP_SECRET de config.local.php o storage/app.key (se genera una vez, chmod 600). */
function ns_secret(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    if (defined('APP_SECRET') && is_string(APP_SECRET) && strlen(APP_SECRET) >= 32) {
        return $key = APP_SECRET;
    }
    $file = ns_storage('app.key');
    for ($i = 0; $i < 3; $i++) {
        if (is_file($file)) {
            $k = trim((string) @file_get_contents($file));
            if (strlen($k) >= 32) {
                return $key = $k;
            }
        }
        if (!ns_ensure_dir(ns_storage())) {
            break;
        }
        $fh = @fopen($file, 'x');   // creacion exclusiva: dos peticiones simultaneas no se pisan
        if ($fh !== false) {
            fwrite($fh, bin2hex(random_bytes(32)));
            fclose($fh);
            @chmod($file, 0600);
        } else {
            usleep(50000);
        }
    }
    // Ultimo recurso (storage/ no escribible): clave derivada y estable, mas debil. Se avisa en el log.
    ns_log_error('app.key no disponible; usando clave derivada. Revisa permisos de storage/');
    return $key = hash('sha256', 'ns-fallback|' . NS_ROOT . '|' . (defined('SMTP_PASS') ? SMTP_PASS : '') . '|' . php_uname('n'));
}

/** Token CSRF "ts.hmac". Sin cookies: apto para paginas cacheadas por LiteSpeed. */
function csrf_token(?int $ts = null): string
{
    $ts ??= time();
    return $ts . '.' . hash_hmac('sha256', 'csrf|' . $ts, ns_secret());
}

/** Edad en segundos de un token valido, o null si es invalido/caducado (2 h) o viene del futuro. */
function ns_csrf_age(mixed $token): ?int
{
    if (!is_string($token) || !preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) {
        return null;
    }
    $ts = (int) $m[1];
    if (!hash_equals(hash_hmac('sha256', 'csrf|' . $ts, ns_secret()), $m[2])) {
        return null;
    }
    $age = time() - $ts;
    return ($age >= -60 && $age <= NS_CSRF_TTL) ? max(0, $age) : null;
}

// --- Pagina de gracias de las solicitudes (sin sesion): parametro firmado "ts.perfil.hmac", valido 7 dias ----------------------------------

const NS_THANKS_TTL = 604800;   // 7 dias: el usuario puede recargar o volver a abrirla

/** Token para /careers/thank-you/?t=… (perfil = owner-operator | small-fleet | company-driver). No lleva datos personales. */
function ns_lead_thanks_token(string $profile, ?int $ts = null): string
{
    $ts ??= time();
    return $ts . '.' . $profile . '.' . substr(hash_hmac('sha256', 'thanks|' . $ts . '|' . $profile, ns_secret()), 0, 32);
}

/** Perfil de un token valido y vigente, o null (token ausente, manipulado, caducado o de un perfil desconocido). */
function ns_lead_thanks_profile(mixed $token): ?string
{
    if (!is_string($token) || !preg_match('/^(\d{9,11})\.(owner-operator|small-fleet|company-driver)\.([a-f0-9]{32})$/', $token, $m)) {
        return null;
    }
    $age = time() - (int) $m[1];
    if ($age < -60 || $age > NS_THANKS_TTL) {
        return null;
    }
    return hash_equals(substr(hash_hmac('sha256', 'thanks|' . $m[1] . '|' . $m[2], ns_secret()), 0, 32), $m[3]) ? $m[2] : null;
}

// --- Cliente / limite por IP -----------------------------------------------------------------------

/**
 * IP del visitante. Por defecto REMOTE_ADDR (no se confia en cabeceras que el visitante puede falsear).
 * Si app/config.local.php define TRUSTED_PROXY_HEADER (p. ej. 'HTTP_X_FORWARDED_FOR' o 'HTTP_CF_CONNECTING_IP'), se lee esa cabecera y se usa el PRIMER valor que sea una IP valida;
 * sin cabecera o sin valor valido, vuelve a REMOTE_ADDR. Activarlo SOLO si el servidor esta detras de un proxy/CDN que sobrescribe esa cabecera (si el proxy la anade al final
 * en vez de reemplazarla, un visitante podria falsear la IP y saltarse el limite): comprobarlo en el servidor real antes de activarlo (ver config.local.example.php).
 */
function ns_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $header = defined('TRUSTED_PROXY_HEADER') ? strtoupper(trim((string) TRUSTED_PROXY_HEADER)) : '';
    if ($header !== '' && preg_match('/^HTTP_[A-Z0-9_]{1,60}$/', $header) === 1) {
        foreach (explode(',', (string) ($_SERVER[$header] ?? '')) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
    }
    return $ip;
}

/** Hash corto de la IP con sal (nunca se guarda la IP en claro). Ver ns_client_ip(). */
function ns_ip_hash(): string
{
    return substr(hash_hmac('sha256', 'ip|' . ns_client_ip(), ns_secret()), 0, 20);
}

function ns_rate_limit(): int
{
    return defined('RATE_LIMIT') && (int) RATE_LIMIT > 0 ? (int) RATE_LIMIT : 5;
}

function ns_rate_window(): int
{
    return defined('RATE_WINDOW') && (int) RATE_WINDOW > 0 ? (int) RATE_WINDOW : 3600;
}

/** Marcas de tiempo vigentes de esta IP (archivo storage/ratelimit/<hash>.json). */
function ns_rate_hits(): array
{
    $file = ns_storage('ratelimit/' . ns_ip_hash() . '.json');
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents($file), true);
    $min = time() - ns_rate_window();
    return is_array($data) ? array_values(array_filter($data, static fn($t) => is_int($t) && $t > $min)) : [];
}

function ns_rate_exceeded(): bool
{
    return count(ns_rate_hits()) >= ns_rate_limit();
}

/** Registra un envio de esta IP. */
function ns_rate_hit(): void
{
    $dir = ns_storage('ratelimit');
    if (!ns_ensure_dir($dir)) {
        return;
    }
    $fh = @fopen($dir . '/' . ns_ip_hash() . '.json', 'c+');
    if ($fh === false) {
        return;
    }
    if (flock($fh, LOCK_EX)) {
        $min = time() - ns_rate_window();
        $data = json_decode((string) stream_get_contents($fh), true);
        $data = is_array($data) ? array_values(array_filter($data, static fn($t) => is_int($t) && $t > $min)) : [];
        $data[] = time();
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    // Limpieza ocasional de archivos vencidos.
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (@filemtime($f) < time() - ns_rate_window() - 60) {
                @unlink($f);
            }
        }
    }
}

// --- Entrada ---------------------------------------------------------------------------------------

/**
 * Sanea un valor de formulario: solo strings, UTF-8 valido, sin caracteres de control. En una linea
 * (por defecto) se eliminan \r y \n (inyeccion de cabeceras); en multilinea se normaliza a \n.
 */
function ns_clean(mixed $v, int $max, bool $multiline = false): string
{
    if (!is_string($v)) {
        return '';
    }
    $v = mb_scrub($v);
    $v = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $v);
    if ($multiline) {
        $v = (string) preg_replace('/(?![\n\t])\p{Cc}/u', '', $v);
        $v = (string) preg_replace("/\n{3,}/", "\n\n", $v);
    } else {
        $v = (string) preg_replace('/\p{Cc}+/u', ' ', $v);
        $v = (string) preg_replace('/ {2,}/', ' ', $v);
    }
    return mb_substr(trim($v), 0, $max);
}

function ns_user_agent(): string
{
    return ns_clean($_SERVER['HTTP_USER_AGENT'] ?? '', 200);
}

// --- Guardia comun de formularios -------------------------------------------------------------------------

/**
 * Honeypot, CSRF, tiempo minimo y limite por IP. Devuelve:
 *   ['ok' => true]
 *   ['ok' => false, 'honeypot' => true]                       -> responder con exito falso
 *   ['ok' => false, 'status' => 403|422|429, 'error' => '…']  -> re-renderizar con ese estado
 */
function ns_form_guard(array $in, string $form): array
{
    $ip = ns_ip_hash();

    // 1) Honeypot: cualquier valor (o array) = bot.
    $hp = $in['website'] ?? '';
    if ($hp !== '' && $hp !== null) {
        if (!ns_rate_exceeded()) {   // evita inundar el log
            ns_log('forms', gmdate('c') . " honeypot form=$form ip=$ip");
            ns_rate_hit();
        }
        return ['ok' => false, 'honeypot' => true];
    }

    // 2) CSRF (token sin sesion; mismo token para todos, caduca a las 2 h).
    $csrfAge = ns_csrf_age($in['csrf'] ?? null);
    if ($csrfAge === null) {
        ns_log('forms', gmdate('c') . " csrf-rejected form=$form ip=$ip");
        return ['ok' => false, 'status' => 403, 'error' => 'This form has expired. Please send it again.'];
    }

    // 3) Tiempo minimo / maximo desde que se pinto el formulario.
    $ts = (isset($in['form_ts']) && is_string($in['form_ts']) && ctype_digit($in['form_ts'])) ? (int) $in['form_ts'] : 0;
    $age = time() - $ts;
    if ($ts === 0 || $age > NS_CSRF_TTL || $age < -60) {
        ns_log('forms', gmdate('c') . " bad-form_ts form=$form ip=$ip");
        return ['ok' => false, 'status' => 422, 'error' => 'This form has expired. Please send it again.'];
    }
    if ($age < NS_MIN_FILL_SECONDS || $csrfAge < NS_MIN_FILL_SECONDS) {
        ns_log('forms', gmdate('c') . " too-fast form=$form ip=$ip age=$age");
        return ['ok' => false, 'status' => 422, 'error' => 'That was quick. Wait a few seconds and send it again.'];
    }

    // 4) Limite por IP.
    if (ns_rate_exceeded()) {
        ns_log('forms', gmdate('c') . " rate-limited form=$form ip=$ip");
        return ['ok' => false, 'status' => 429, 'error' => 'We got a lot of messages from your connection. Please try again in a while, or message us on WhatsApp.'];
    }

    return ['ok' => true];
}

// --- $flash y render ---------------------------------------------------------------------------------------

/** Campo de la plantilla donde mostrar un error general del formulario. */
function ns_general_field(string $form): string
{
    return match ($form) {
        'download' => 'format',
        'lead', 'contact' => 'form',
        default    => 'message',
    };
}

/**
 * Flash de exito. 'ok' lo lee contact.php; 'message'/'type' los lee company/doc-body.php.
 */
function ns_flash_ok(string $form): array
{
    $msg = $form === 'download'
        ? 'Thanks. If your download did not start, message us and we will send the file.'
        : 'Thanks, we received your message. If it is urgent, message us on WhatsApp.';
    return ['ok' => $msg, 'message' => $msg, 'type' => 'ok'];
}

/** Flash de error con errores por campo, valores previos y mensaje general opcional. */
function ns_flash_error(string $form, array $errors, array $old = [], string $general = ''): array
{
    if ($general !== '') {
        $errors[ns_general_field($form)] ??= $general;
    }
    $first = $general !== '' ? $general : (string) (reset($errors) ?: 'Please check the form and try again.');
    return ['errors' => $errors, 'old' => $old, 'message' => $first, 'type' => 'error'];
}

/**
 * Pinta la pagina de la ruta con $flash visible para la plantilla. render_route() de helpers.php ejecuta la
 * plantilla en un closure que no ve variables globales, asi que se replica aqui pasando $flash.
 */
function ns_render_page(array $route, ?array $flash = null, int $status = 200): never
{
    http_response_code($status);
    $template = (string) ($route['template'] ?? '');
    $page = NS_ROOT . '/templates/pages/' . $template . '.php';
    if (!preg_match('/^[a-z0-9-]+$/', $template) || !is_file($page)) {
        http_response_code(500);
        echo 'Template error.';
        exit;
    }
    $cfg = cfg();
    $flash ??= [];
    $content = (static function (string $__page, array $route, array $cfg, array $flash): string {
        ob_start();
        try {
            include $__page;
        } catch (Throwable $t) {
            ob_end_clean();
            throw $t;
        }
        return (string) ob_get_clean();
    })($page, $route, $cfg, $flash);

    ob_start();
    (static function (array $route, array $cfg, string $content, array $flash): void {
        include NS_ROOT . '/templates/layout.php';
    })($route, $cfg, $content, $flash);
    $html = (string) ob_get_clean();
    ns_send_html(ns_is_es() ? ns_translate_html($html) : $html);
    exit;
}

// --- Correo --------------------------------------------------------------------------------------------------

function ns_smtp_configured(): bool
{
    $user = defined('SMTP_USER') ? (string) SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? (string) SMTP_PASS : '';
    $host = defined('SMTP_HOST') ? (string) SMTP_HOST : '';
    return $host !== '' && $user !== '' && $pass !== '' && $pass !== 'CHANGE-ME';
}

function ns_mail_to(): string
{
    $to = defined('MAIL_TO') ? trim((string) MAIL_TO) : '';
    return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : (string) (defined('EMAIL') ? EMAIL : '');
}

/** Bloque legible para storage/mail.log (cada linea del cuerpo con prefijo: no se pueden falsear entradas). */
function ns_mail_log_block(string $status, string $subject, string $body): string
{
    $lines = array_map(static fn($l) => '  | ' . $l, preg_split('/\n/', $body) ?: []);
    return '=== ' . gmdate('c') . ' [' . $status . '] to=' . ns_mail_to() . "\n  subject: " . $subject . "\n" . implode("\n", $lines) . "\n";
}

/**
 * Envia un correo de texto plano al destino del sitio.
 *   Sin SMTP configurado: staging => lo escribe completo en storage/mail.log y devuelve true (como exito); produccion => lo escribe en mail.log y devuelve false (el formulario no finge exito).
 *   Con SMTP: PHPMailer; si falla registra el error (sin credenciales) y el mensaje en mail.log; devuelve false.
 * $replyTo = [email, nombre] opcional (email ya validado con filter_var).
 */
function ns_send_mail(string $subject, string $body, ?array $replyTo = null): bool
{
    $subject = ns_clean($subject, 200);
    if (!ns_smtp_configured()) {
        if (function_exists('is_production') && is_production()) {
            // Produccion sin SMTP valido: NO se simula exito. El mensaje se conserva en mail.log y el visitante recibe la alternativa (WhatsApp / correo).
            ns_log('mail', ns_mail_log_block('SMTP NOT CONFIGURED - not delivered', $subject, $body));
            ns_log_error('mail-not-configured', 'ENV=production sin SMTP_USER/SMTP_PASS validos; mensaje guardado en storage/mail.log');
            return false;
        }
        ns_log('mail', ns_mail_log_block('SMTP not configured - logged only', $subject, $body));
        return true;
    }
    try {
        require_once NS_ROOT . '/vendor/autoload.php';
        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string) SMTP_HOST;
        $m->Port = (int) (defined('SMTP_PORT') ? SMTP_PORT : 465);
        $m->SMTPAuth = true;
        $m->Username = (string) SMTP_USER;
        $m->Password = (string) SMTP_PASS;
        $secure = strtolower((string) (defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl'));
        $m->SMTPSecure = $secure === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : ($secure === 'none' ? '' : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS);
        $m->SMTPAutoTLS = $secure !== 'none';
        $m->Timeout = 12;
        $m->CharSet = 'UTF-8';
        $m->Encoding = '8bit';
        $m->SMTPDebug = 0;
        $mailFrom = defined('MAIL_FROM') ? trim((string) MAIL_FROM) : '';
        $from = filter_var($mailFrom, FILTER_VALIDATE_EMAIL) ? $mailFrom : (filter_var((string) SMTP_USER, FILTER_VALIDATE_EMAIL) ? (string) SMTP_USER : ns_mail_to());
        $m->setFrom($from, 'NorthStar website');
        $m->addAddress(ns_mail_to());
        if ($replyTo !== null && filter_var($replyTo[0] ?? '', FILTER_VALIDATE_EMAIL)) {
            $m->addReplyTo((string) $replyTo[0], ns_clean($replyTo[1] ?? '', 100));
        }
        $m->isHTML(false);
        $m->Subject = $subject;
        $m->Body = $body;
        $m->send();
        ns_log('mail', gmdate('c') . ' [sent] to=' . ns_mail_to() . ' subject=' . $subject);
        return true;
    } catch (\Throwable $t) {
        ns_log_error('mail-failed', get_class($t) . ': ' . $t->getMessage());
        ns_log('mail', ns_mail_log_block('SEND FAILED - not delivered', $subject, $body));
        return false;
    }
}

/**
 * Clasifica un dato de contacto de respuesta: 'email' (FILTER_VALIDATE_EMAIL), 'phone' (solo digitos y + ( ) . - espacios, de 7 a 15 digitos) o null si no vale.
 */
function ns_contact_kind(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (str_contains($v, '@')) {
        return filter_var($v, FILTER_VALIDATE_EMAIL) ? 'email' : null;
    }
    if (preg_match('/^\+?[0-9()\s.\-]{7,30}$/', $v) !== 1) {
        return null;
    }
    $digits = strlen((string) preg_replace('/\D/', '', $v));
    return ($digits >= 7 && $digits <= 15) ? 'phone' : null;
}

/** Texto comun al pie de los correos: huella de IP, user agent y fecha UTC. */
function ns_mail_footer(): string
{
    return "\n--\nIP hash: " . ns_ip_hash() . "\nUser agent: " . (ns_user_agent() ?: '-') . "\nDate (UTC): " . gmdate('Y-m-d H:i:s') . "\n";
}
