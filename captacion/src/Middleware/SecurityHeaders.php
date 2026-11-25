<?php
/** Cabeceras de toda respuesta del panel y de la API: CSP estricta (sin inline; solo nonce para scripts), sin marcos, sin cache, fuera de buscadores. */

declare(strict_types=1);

namespace Captacion\Middleware;

final class SecurityHeaders
{
    private static string $nonce = '';
    /** Fase AV: pantallas de Prospectos con Vonage configurado: SDK de Vonage desde jsDelivr (versión fija con SRI), conexiones a Vonage y micrófono propio. */
    public static bool $voice = false;

    public static function nonce(): string
    {
        return self::$nonce !== '' ? self::$nonce : (self::$nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='));
    }

    public static function send(bool $html = true): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=' . (self::$voice ? '(self)' : '()') . ', geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        if ($html) {
            $v = self::$voice;
            header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self' 'nonce-" . self::nonce() . "'" . ($v ? ' https://cdn.jsdelivr.net' : '') . "; img-src 'self' data:; font-src 'self'; connect-src 'self'"
                . ($v ? ' https://*.nexmo.com wss://*.nexmo.com https://*.vonage.com wss://*.vonage.com; media-src \'self\' blob: data:; worker-src \'self\' blob:' : '') . "; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        } else {
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        }
    }
}
