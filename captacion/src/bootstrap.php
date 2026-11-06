<?php
/**
 * Arranque del mini-CRM de captación. Lo incluyen: public/index.php, public/setup.php, public/api/lead.php, cron/*.php, bin/*.php,
 * los tests y (en proceso) app/forms/lead.php del sitio. NO imprime ni define funciones globales: solo constantes y el autoloader.
 *
 * Seguro de incluir desde el sitio: si no hay .env no lanza nada (Env::get devuelve el valor por defecto) y el sitio decide con CAPTACION_ENABLED.
 */

declare(strict_types=1);

if (defined('CAPTACION_ROOT')) {
    return;
}

define('CAPTACION_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Captacion\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Extensiones obligatorias. Sin ellas el panel y los cron fallarian a medias (sodium cifra el TOTP, pdo_mysql es la base, mbstring/openssl/curl los textos, el correo y los avisos).
// Panel y API web: 503 plano y amable (sin detalles). Cron y bin: mensaje claro y codigo 1. Embebido en el sitio (CAPTACION_EMBEDDED, app/forms/lead.php): no hace nada; el sitio ya cae a su JSONL.
if (!defined('CAPTACION_EMBEDDED')) {
    $missing = array_values(array_filter(['pdo_mysql', 'sodium', 'mbstring', 'openssl', 'curl'], static fn(string $e): bool => !extension_loaded($e)));
    if ($missing) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Faltan extensiones de PHP: ' . implode(', ', $missing) . ". Actívalas en hPanel (Avanzado > Configuración de PHP) y vuelve a ejecutar.\n");
            exit(1);
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 300');
        error_log('captacion: faltan extensiones de PHP: ' . implode(', ', $missing));
        echo 'Service temporarily unavailable.';
        exit;
    }
}

\Captacion\Env::load();
