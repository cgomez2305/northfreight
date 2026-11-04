<?php
/** Dominios de correo desechables (config/disposable-domains.txt, uno por linea, # comentarios). Coincide el dominio o cualquier subdominio. */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Env;

final class Disposable
{
    /** @var array<string,true>|null */
    private static ?array $set = null;

    private static function path(): string
    {
        return Env::get('DISPOSABLE_FILE') ?? CAPTACION_ROOT . '/config/disposable-domains.txt';
    }

    public static function reload(): void
    {
        self::$set = null;
    }

    public static function isDisposable(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(substr($email, $at + 1));
        if (self::$set === null) {
            self::$set = [];
            $lines = is_file(self::path()) ? (file(self::path(), FILE_IGNORE_NEW_LINES) ?: []) : [];
            foreach ($lines as $l) {
                $l = strtolower(trim($l));
                if ($l !== '' && $l[0] !== '#') {
                    self::$set[$l] = true;
                }
            }
        }
        $parts = explode('.', $domain);
        for ($i = 0, $n = count($parts); $i < $n - 1; $i++) {
            if (isset(self::$set[implode('.', array_slice($parts, $i))])) {
                return true;
            }
        }
        return false;
    }
}
