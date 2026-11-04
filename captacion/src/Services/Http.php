<?php
/** Cliente HTTP minimo con cURL (sin redirecciones, con tiempos limite). Devuelve el error de transporte en 'error'; nunca lanza ni registra URLs con claves. */

declare(strict_types=1);

namespace Captacion\Services;

final class Http
{
    /**
     * @param list<string> $headers
     * @return array{status:int,body:string,error:string}
     */
    public static function request(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 8, int $connectTimeout = 3): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'cURL no disponible'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => array_merge(['User-Agent: NorthStar-Captacion/1.0', 'Accept: application/json'], $headers),
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch) !== 0 ? Storage::redact(curl_error($ch)) : '';
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($resp) ? $resp : '', 'error' => $err];
    }
}
