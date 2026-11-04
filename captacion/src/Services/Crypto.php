<?php
/** Cifrado simetrico de secretos en reposo (secreto TOTP) con sodium secretbox. Clave: APP_KEY (64 hex = 32 bytes, o "base64:..."). */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Env;
use RuntimeException;

final class Crypto
{
    private const PREFIX = 'v1:';

    public static function key(): string
    {
        $raw = Env::get('APP_KEY');
        if ($raw === null) {
            throw new RuntimeException('APP_KEY sin configurar.');
        }
        if (str_starts_with($raw, 'base64:')) {
            $key = base64_decode(substr($raw, 7), true);
        } elseif (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
            $key = hex2bin($raw);
        } else {
            $key = false;
        }
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('APP_KEY invalida: usa 64 caracteres hexadecimales (php -r "echo bin2hex(random_bytes(32));").');
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $blob): ?string
    {
        if (!str_starts_with($blob, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($blob, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? null : $plain;
    }

    /** HMAC con la clave de la app (codigos de recuperacion: aleatorios de alta entropia, se guardan solo como hash). */
    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }
}
