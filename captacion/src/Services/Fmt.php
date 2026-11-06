<?php
/**
 * Formato para mostrar: fechas en America/New_York (la base guarda UTC), tiempos relativos, numeros, telefonos +1 e iniciales/avatares.
 * Los limites de dia/semana/mes se calculan en hora del este (ET) y se devuelven como UTC para consultar la base.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;

final class Fmt
{
    public const TZ = 'America/New_York';
    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    private const DAYS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

    /** @var array<string,\DateTimeZone> */
    private static array $tzs = [];

    /** Zona de visualizacion (Configuracion > Horario y zona; por defecto America/New_York). */
    public static function tz(): \DateTimeZone
    {
        $name = Display::timezone();
        return self::$tzs[$name] ??= new \DateTimeZone($name);
    }

    /** Abreviatura de la zona que se muestra junto a las horas ("ET"). */
    public static function zone(): string
    {
        return Display::zoneLabel();
    }

    /** DateTimeImmutable en ET a partir de un 'Y-m-d H:i:s' en UTC (o de un timestamp). */
    public static function et(string|int|null $utc): ?\DateTimeImmutable
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $ts = is_int($utc) ? $utc : Clock::parse($utc);
        return $ts === null ? null : (new \DateTimeImmutable('@' . $ts))->setTimezone(self::tz());
    }

    /** "3 nov, 14:32 ET" */
    public static function dateTime(?string $utc, bool $withZone = true): string
    {
        $d = self::et($utc);
        return $d === null ? '—' : (int) $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n') - 1] . ', ' . $d->format('H:i') . ($withZone ? ' ' . self::zone() : '');
    }

    /** "3 nov 2026" (con el anio solo si no es el actual). */
    public static function date(?string $utc): string
    {
        $d = self::et($utc);
        if ($d === null) {
            return '—';
        }
        $y = (int) $d->format('Y') === (int) self::et(Clock::time())->format('Y') ? '' : ' ' . $d->format('Y');
        return (int) $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n') - 1] . $y;
    }

    public static function dayLabel(\DateTimeImmutable $d): string
    {
        return (int) $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n') - 1];
    }

    public static function monthLabel(int $month): string
    {
        return self::MONTHS[$month - 1] ?? '';
    }

    public static function weekdayShort(int $isoDay): string
    {
        return self::DAYS[$isoDay - 1] ?? '';
    }

    /** "hace 12 min", "hace 3 h", "hace 2 d", "ahora". */
    public static function ago(?string $utc): string
    {
        $ts = $utc === null ? null : Clock::parse($utc);
        if ($ts === null) {
            return '—';
        }
        $s = max(0, Clock::time() - $ts);
        return match (true) {
            $s < 60       => 'ahora',
            $s < 3600     => 'hace ' . intdiv($s, 60) . ' min',
            $s < 86400    => 'hace ' . intdiv($s, 3600) . ' h',
            $s < 86400 * 30 => 'hace ' . intdiv($s, 86400) . ' d',
            $s < 86400 * 365 => 'hace ' . intdiv($s, 86400 * 30) . (intdiv($s, 86400 * 30) === 1 ? ' mes' : ' meses'),
            default       => 'hace ' . intdiv($s, 86400 * 365) . (intdiv($s, 86400 * 365) === 1 ? ' año' : ' años'),
        };
    }

    /** Duracion corta: "45 s", "12 min", "3 h 5 min", "2 d 4 h". */
    public static function dur(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min';
        }
        if ($seconds < 86400) {
            $m = intdiv($seconds % 3600, 60);
            return intdiv($seconds, 3600) . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
        }
        $h = intdiv($seconds % 86400, 3600);
        return intdiv($seconds, 86400) . ' d' . ($h > 0 ? ' ' . $h . ' h' : '');
    }

    public static function num(int|float $n, int $dec = 0): string
    {
        return number_format($n, $dec, '.', ',');
    }

    public static function pct(float $n, int $dec = 0): string
    {
        return number_format($n, $dec, '.', ',') . '%';
    }

    public static function name(array $lead): string
    {
        $n = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        return $n !== '' ? $n : 'Sin nombre';
    }

    /** Codigo corto del lead (LD-0142), el mismo que muestra la ficha. */
    public static function code(int $id): string
    {
        return 'LD-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $a = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1));
        $b = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';
        return ($a . $b) !== '' ? $a . $b : '?';
    }

    /** Clase de color del avatar (0 a 7), estable para un mismo nombre. */
    public static function avatar(string $seed): int
    {
        return crc32(mb_strtolower($seed)) % 8;
    }

    /** +1 (305) 234-5600 para E.164 de EE. UU.; otros se devuelven tal cual. */
    public static function phone(?string $e164): string
    {
        if ($e164 === null || $e164 === '') {
            return '—';
        }
        if (preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m) === 1) {
            return '+1 (' . $m[1] . ') ' . $m[2] . '-' . $m[3];
        }
        return $e164;
    }

    /** Numero de WhatsApp (solo digitos, con codigo de pais) o null. */
    public static function waNumber(?string $e164): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $e164);
        return $d !== '' && strlen((string) $d) >= 8 ? (string) $d : null;
    }

    /** Segundos entre dos fechas UTC (null si falta alguna). */
    public static function secondsBetween(?string $from, ?string $to): ?int
    {
        $a = $from === null ? null : Clock::parse($from);
        $b = $to === null ? null : Clock::parse($to);
        return $a === null || $b === null ? null : $b - $a;
    }

    // --- Ventanas de tiempo en ET -----------------------------------------------------------------------------------------

    /** Medianoche ET del dia de $ts (o de ahora), como timestamp. */
    public static function dayStart(?int $ts = null, int $offsetDays = 0): int
    {
        $d = self::et($ts ?? Clock::time())->setTime(0, 0, 0);
        if ($offsetDays !== 0) {
            $d = $d->modify(($offsetDays > 0 ? '+' : '') . $offsetDays . ' days')->setTime(0, 0, 0);
        }
        return $d->getTimestamp();
    }

    /** Lunes 00:00 ET de la semana de $ts, con $offsetWeeks semanas de desplazamiento. */
    public static function weekStart(?int $ts = null, int $offsetWeeks = 0): int
    {
        $d = self::et($ts ?? Clock::time())->setTime(0, 0, 0);
        $d = $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->setTime(0, 0, 0);
        if ($offsetWeeks !== 0) {
            $d = $d->modify(($offsetWeeks > 0 ? '+' : '') . ($offsetWeeks * 7) . ' days')->setTime(0, 0, 0);
        }
        return $d->getTimestamp();
    }

    /** Dia 1 a las 00:00 ET del mes de $ts, con desplazamiento en meses. */
    public static function monthStart(?int $ts = null, int $offsetMonths = 0): int
    {
        $d = self::et($ts ?? Clock::time())->modify('first day of this month')->setTime(0, 0, 0);
        if ($offsetMonths !== 0) {
            $d = $d->modify('first day of ' . ($offsetMonths > 0 ? '+' : '') . $offsetMonths . ' months')->setTime(0, 0, 0);
        }
        return $d->getTimestamp();
    }

    public static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /** Interpreta 'Y-m-d' o 'Y-m-d\TH:i' escritos por la persona en ET y devuelve el 'Y-m-d H:i:s' UTC (o null si no es valido). */
    public static function fromEtInput(string $v, bool $endOfDay = false): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $time = isset($m[4]) ? $m[4] . ':' . $m[5] . ':00' : ($endOfDay ? '23:59:59' : '00:00:00');
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $time, self::tz());
        return $d === false ? null : self::utc($d->getTimestamp());
    }

    /** Valor de un input datetime-local (ET) para un UTC. */
    public static function toEtInput(?string $utc): string
    {
        $d = self::et($utc);
        return $d === null ? '' : $d->format('Y-m-d\TH:i');
    }
}
