<?php
/** Reloj UTC con desplazamiento para pruebas. Todo el CRM guarda fechas en UTC ('Y-m-d H:i:s'). */

declare(strict_types=1);

namespace Captacion;

final class Clock
{
    private static int $offset = 0;

    public static function time(): int
    {
        return time() + self::$offset;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s', self::time());
    }

    public static function at(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /** Fecha en UTC, $seconds segundos desde ahora (negativo = pasado). */
    public static function shift(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', self::time() + $seconds);
    }

    public static function parse(?string $dt): ?int
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        $ts = strtotime($dt . ' UTC');
        return $ts === false ? null : $ts;
    }

    /** Solo pruebas. */
    public static function travel(int $seconds): void
    {
        self::$offset = $seconds;
    }
}
