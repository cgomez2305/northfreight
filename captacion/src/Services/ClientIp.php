<?php
/**
 * IP del cliente para limites de intentos, registro de actividad y pruebas de consentimiento.
 * Por defecto REMOTE_ADDR (no se confia en cabeceras que el visitante puede falsear). Si el .env define TRUSTED_PROXY_HEADER (p. ej. HTTP_X_FORWARDED_FOR o HTTP_CF_CONNECTING_IP) se usa
 * el PRIMER valor de esa cabecera que sea una IP valida; sin cabecera o sin valor valido, REMOTE_ADDR. Activarlo SOLO si el servidor esta detras de un proxy que sobrescribe esa cabecera:
 * detras de un CDN con REMOTE_ADDR = IP del proxy, todos los visitantes compartirian el limite de intentos de /login (cinco fallos bloquearian a todos). Comprobarlo en el servidor real.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Env;

final class ClientIp
{
    public static function get(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $header = strtoupper(trim((string) Env::get('TRUSTED_PROXY_HEADER', '')));
        if ($header !== '' && preg_match('/^HTTP_[A-Z0-9_]{1,60}$/', $header) === 1) {
            foreach (explode(',', (string) ($_SERVER[$header] ?? '')) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return substr($candidate, 0, 45);
                }
            }
        }
        return substr($ip, 0, 45);
    }
}
