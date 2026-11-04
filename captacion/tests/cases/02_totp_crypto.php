<?php
declare(strict_types=1);

use Captacion\Services\Auth;
use Captacion\Services\Crypto;
use Captacion\Services\Totp;
use CaptacionTests\T;

return static function (array $ctx): void {
    // Vectores del RFC 6238 (Apendice B), SHA-1, secreto ASCII "12345678901234567890", 8 digitos
    $key = '12345678901234567890';
    foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $t => $code) {
        T::eq(Totp::codeForStep($key, intdiv($t, 30), 8), $code, "RFC 6238: T=$t -> $code");
    }
    // Los mismos con 6 digitos (los ultimos 6 de los codigos de 8)
    T::eq(Totp::codeForStep($key, intdiv(59, 30), 6), '287082', 'RFC 6238 a 6 digitos (T=59)');
    $b32 = Totp::base32Encode($key);
    T::eq($b32, 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32 del secreto del RFC');
    T::eq(Totp::base32Decode($b32), $key, 'base32 ida y vuelta');
    T::eq(Totp::base32Decode(strtolower('GEZD GNBV-GY3T QOJQ GEZD GNBV GY3T QOJQ')), $key, 'base32 tolera minusculas, espacios y guiones');

    // Verificacion con ventana +-1
    $secret = Totp::generateSecret();
    T::eq(strlen(Totp::base32Decode($secret)), 20, 'secreto generado: 20 bytes (160 bits)');
    $now = 1_800_000_015;
    $step = intdiv($now, 30);
    $codeAt = static fn(int $s) => Totp::codeForStep(Totp::base32Decode($secret), $s);
    T::eq(Totp::verify($secret, $codeAt($step), $now), $step, 'codigo del paso actual: valido');
    T::eq(Totp::verify($secret, $codeAt($step - 1), $now), $step - 1, 'codigo del paso anterior (-1): valido');
    T::eq(Totp::verify($secret, $codeAt($step + 1), $now), $step + 1, 'codigo del paso siguiente (+1): valido');
    T::eq(Totp::verify($secret, $codeAt($step - 2), $now), null, 'codigo de hace 2 pasos: invalido');
    T::eq(Totp::verify($secret, $codeAt($step + 2), $now), null, 'codigo de 2 pasos adelante: invalido');
    T::eq(Totp::verify($secret, '000000', $now) === null || $codeAt($step) === '000000' || $codeAt($step - 1) === '000000' || $codeAt($step + 1) === '000000', true, 'codigo 000000: invalido (salvo coincidencia)');
    T::eq(Totp::verify($secret, '12345', $now), null, 'codigo corto: invalido');
    T::eq(Totp::verify($secret, 'abcdef', $now), null, 'codigo no numerico: invalido');
    T::eq(Totp::verify($secret, substr($codeAt($step), 0, 3) . ' ' . substr($codeAt($step), 3), $now), $step, 'codigo con espacio en medio: valido');
    T::eq(Totp::verify($secret, $codeAt($step), $now, 1, $step), null, 'anti-reutilizacion: un paso ya usado se rechaza');
    T::eq(Totp::verify($secret, $codeAt($step + 1), $now, 1, $step), $step + 1, 'anti-reutilizacion: un paso posterior se acepta');
    T::ok(str_starts_with(Totp::uri('NorthStar Captacion', 'a@b.com', 'ABC234'), 'otpauth://totp/NorthStar%20Captacion%3Aa%40b.com?secret=ABC234&issuer=NorthStar%20Captacion'), 'URI otpauth://');
    T::eq(Totp::grouped('ABCDEFGHIJKLMNOP'), 'ABCD EFGH IJKL MNOP', 'clave en grupos de 4');

    // Crypto (sodium secretbox, APP_KEY)
    $blob = Crypto::encrypt('mi-secreto');
    T::ok($blob !== 'mi-secreto' && str_starts_with($blob, 'v1:') && !str_contains($blob, 'mi-secreto'), 'Crypto: el texto cifrado no contiene el original');
    T::eq(Crypto::decrypt($blob), 'mi-secreto', 'Crypto: descifra');
    T::ok(Crypto::encrypt('mi-secreto') !== $blob, 'Crypto: nonce aleatorio (dos cifrados distintos)');
    T::eq(Crypto::decrypt(substr($blob, 0, -4) . 'AAAA'), null, 'Crypto: texto manipulado -> null');
    T::eq(Crypto::decrypt('v1:no-es-base64!!'), null, 'Crypto: basura -> null');
    \Captacion\Env::set('APP_KEY', 'corta');
    T::throws(static fn() => Crypto::encrypt('x'), 'Crypto: APP_KEY invalida lanza error claro', 'APP_KEY invalida');
    \Captacion\Env::set('APP_KEY', $ctx['appKey']);

    // Contrasenas y codigos de recuperacion
    $h = Auth::hashPassword('una contrasena larga y buena');
    T::ok(password_verify('una contrasena larga y buena', $h) && !password_verify('otra', $h), 'hash de contrasena verifica');
    T::ok(str_starts_with($h, defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$12$'), 'algoritmo: ' . Auth::algo() . (defined('PASSWORD_ARGON2ID') ? '' : ' (este PHP no trae Argon2id: bcrypt coste 12)'));
    $codes = Auth::generateRecoveryCodes(10);
    T::eq(count($codes), 10, '10 codigos de recuperacion');
    T::eq(count(array_unique($codes)), 10, 'codigos de recuperacion distintos');
    T::ok(preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $codes[0]) === 1, 'formato xxxxx-xxxxx');
};
