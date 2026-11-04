<?php
/**
 * Normalizacion de telefonos a E.164 con reglas de EE. UU. (NANP: +1 NXX NXX XXXX).
 *
 *  - 10 digitos, o 11 empezando por 1, o "+1" + 10: se valida el plan NANP (codigo de area 2-9XX, sin N11 ni N9X; central 2-9XX sin N11; no todos los digitos iguales).
 *  - "+" con otro codigo de pais: se acepta como internacional (8 a 15 digitos, sin 0 inicial) y se devuelve 'intl' => true. No se valida el plan de ese pais.
 *  - Se ignoran separadores " ()-." y una extension final ("x12", "ext 12", "#12"). Cualquier otro caracter = invalido.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class Phone
{
    /** @return array{ok:bool,e164:?string,intl:bool,reason:?string} */
    public static function normalize(mixed $raw): array
    {
        $fail = static fn(string $reason): array => ['ok' => false, 'e164' => null, 'intl' => false, 'reason' => $reason];
        if (!is_string($raw) || trim($raw) === '') {
            return $fail('empty');
        }
        $s = trim($raw);
        if (strlen($s) > 40) {
            return $fail('too_long');
        }
        $s = (string) preg_replace('/\s*(?:ext\.?|extension|x|#)\s*\d{1,6}$/i', '', $s);
        if (preg_match('/^\+?[\d\s().\-]+$/', $s) !== 1 || substr_count($s, '+') > 1 || (str_contains($s, '+') && $s[0] !== '+')) {
            return $fail('chars');
        }
        $plus = $s[0] === '+';
        $d = (string) preg_replace('/\D+/', '', $s);

        if ($plus) {
            if ($d !== '' && $d[0] === '1') {
                return strlen($d) === 11 ? self::nanp(substr($d, 1)) : $fail('length');
            }
            if ($d === '' || $d[0] === '0' || strlen($d) < 8 || strlen($d) > 15) {
                return $fail('length');
            }
            return ['ok' => true, 'e164' => '+' . $d, 'intl' => true, 'reason' => null];
        }
        if (str_starts_with($d, '011') && strlen($d) >= 11 && strlen($d) <= 18) {
            $d = substr($d, 3);   // prefijo de marcacion internacional de EE. UU.
            if ($d !== '' && $d[0] !== '0' && strlen($d) >= 8 && strlen($d) <= 15 && $d[0] !== '1') {
                return ['ok' => true, 'e164' => '+' . $d, 'intl' => true, 'reason' => null];
            }
            return $fail('length');
        }
        if (strlen($d) === 11 && $d[0] === '1') {
            $d = substr($d, 1);
        }
        return strlen($d) === 10 ? self::nanp($d) : $fail('length');
    }

    /** @return array{ok:bool,e164:?string,intl:bool,reason:?string} */
    private static function nanp(string $d): array
    {
        $fail = static fn(string $reason): array => ['ok' => false, 'e164' => null, 'intl' => false, 'reason' => $reason];
        $area = substr($d, 0, 3);
        $exch = substr($d, 3, 3);
        if ($area[0] < '2' || $exch[0] < '2') {
            return $fail('nanp_leading_digit');
        }
        if (substr($area, 1) === '11' || $area[1] === '9') {
            return $fail('nanp_area_code');
        }
        if (substr($exch, 1) === '11') {
            return $fail('nanp_exchange');
        }
        if (count(array_unique(str_split($d))) === 1) {
            return $fail('repeated_digits');
        }
        return ['ok' => true, 'e164' => '+1' . $d, 'intl' => false, 'reason' => null];
    }
}
