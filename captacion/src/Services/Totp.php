<?php
/** TOTP (RFC 6238): HMAC-SHA1, paso de 30 s, 6 digitos, ventana de verificacion +-1 paso. Base32 (RFC 4648) para el secreto. */

declare(strict_types=1);

namespace Captacion\Services;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public const PERIOD = 30;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper((string) preg_replace('/[\s=-]+/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) {
                return '';
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $out;
    }

    /** Codigo para el paso $step con el secreto binario $key. */
    public static function codeForStep(string $key, int $step, int $digits = 6): string
    {
        $hmac = hash_hmac('sha1', pack('J', $step), $key, true);
        $off = ord($hmac[19]) & 0x0F;
        $bin = ((ord($hmac[$off]) & 0x7F) << 24) | (ord($hmac[$off + 1]) << 16) | (ord($hmac[$off + 2]) << 8) | ord($hmac[$off + 3]);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function stepAt(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    public static function code(string $secretB32, ?int $time = null): string
    {
        return self::codeForStep(self::base32Decode($secretB32), self::stepAt($time ?? time()));
    }

    /**
     * Verifica un codigo de 6 digitos contra los pasos [-window, +window]. Devuelve el paso que coincide o null.
     * $afterStep: si se da, solo se aceptan pasos MAYORES (evita reutilizar un codigo ya usado).
     */
    public static function verify(string $secretB32, string $code, ?int $time = null, int $window = 1, ?int $afterStep = null): ?int
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($secretB32);
        if ($key === '') {
            return null;
        }
        $now = self::stepAt($time ?? time());
        $found = null;
        for ($i = -$window; $i <= $window; $i++) {
            $step = $now + $i;
            if (hash_equals(self::codeForStep($key, $step), $code) && $found === null) {
                $found = $step;   // sin cortar el bucle: tiempo constante
            }
        }
        if ($found !== null && $afterStep !== null && $found <= $afterStep) {
            return null;
        }
        return $found;
    }

    public static function uri(string $issuer, string $account, string $secretB32): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /** Clave en grupos de 4 para leerla/teclearla. */
    public static function grouped(string $secretB32): string
    {
        return trim(chunk_split($secretB32, 4, ' '));
    }
}
