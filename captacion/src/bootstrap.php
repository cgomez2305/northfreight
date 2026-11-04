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

\Captacion\Env::load();
