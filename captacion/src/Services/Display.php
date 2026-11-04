<?php
/**
 * Zona horaria de visualizacion y horario comercial (Configuracion > Horario y zona). La base siempre guarda UTC; esto solo cambia como se muestra y como se
 * interpretan las fechas escritas en el panel. Valores en settings: display.timezone (cadena IANA de ZONES) y display.hours {start, end, days[1..7]}.
 * El horario comercial lo usan "Posponer a mañana" (mañana a la hora de inicio) y las tareas sin hora (a la hora de inicio).
 */

declare(strict_types=1);

namespace Captacion\Services;

final class Display
{
    public const DEFAULT_TZ = 'America/New_York';

    /** zona IANA => [nombre, abreviatura que se muestra] */
    public const ZONES = [
        'America/New_York'    => ['Este de EE. UU. (Nueva York)', 'ET'],
        'America/Chicago'     => ['Centro de EE. UU. (Chicago)', 'CT'],
        'America/Denver'      => ['Montaña de EE. UU. (Denver)', 'MT'],
        'America/Phoenix'     => ['Arizona (sin horario de verano)', 'MST'],
        'America/Los_Angeles' => ['Pacífico de EE. UU. (Los Ángeles)', 'PT'],
        'America/Anchorage'   => ['Alaska', 'AKT'],
        'Pacific/Honolulu'    => ['Hawái', 'HST'],
        'America/Caracas'     => ['Venezuela (Caracas)', 'VET'],
    ];

    public const DEFAULT_HOURS = ['start' => '09:00', 'end' => '18:00', 'days' => [1, 2, 3, 4, 5]];

    public static function timezone(): string
    {
        try {
            $tz = Settings::get('display.timezone', self::DEFAULT_TZ);
        } catch (\Throwable) {
            return self::DEFAULT_TZ;
        }
        return is_string($tz) && isset(self::ZONES[$tz]) ? $tz : self::DEFAULT_TZ;
    }

    public static function zoneLabel(): string
    {
        return self::ZONES[self::timezone()][1];
    }

    /** @return array{start:string,end:string,days:list<int>} */
    public static function hours(): array
    {
        try {
            $h = Settings::get('display.hours', self::DEFAULT_HOURS);
        } catch (\Throwable) {
            return self::DEFAULT_HOURS;
        }
        $h = is_array($h) ? $h : [];
        $ok = static fn($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($h['days'] ?? [])), static fn($d) => $d >= 1 && $d <= 7)));
        return [
            'start' => $ok($h['start'] ?? null) ? $h['start'] : self::DEFAULT_HOURS['start'],
            'end'   => $ok($h['end'] ?? null) ? $h['end'] : self::DEFAULT_HOURS['end'],
            'days'  => $days ?: self::DEFAULT_HOURS['days'],
        ];
    }

    /**
     * Valida y guarda zona y horario. @return array{ok:bool,msg:string,changes:array}
     */
    public static function validate(array $in): array
    {
        $tz = is_string($in['timezone'] ?? null) ? $in['timezone'] : '';
        if (!isset(self::ZONES[$tz])) {
            return ['ok' => false, 'msg' => 'Elige una zona horaria de la lista.', 'tz' => null, 'hours' => null];
        }
        $start = is_string($in['start'] ?? null) ? $in['start'] : '';
        $end = is_string($in['end'] ?? null) ? $in['end'] : '';
        $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (preg_match($re, $start) !== 1 || preg_match($re, $end) !== 1) {
            return ['ok' => false, 'msg' => 'Las horas deben tener el formato HH:MM.', 'tz' => null, 'hours' => null];
        }
        if (strcmp($start, $end) >= 0) {
            return ['ok' => false, 'msg' => 'La hora de inicio debe ser anterior a la de cierre.', 'tz' => null, 'hours' => null];
        }
        $days = array_values(array_unique(array_filter(array_map('intval', is_array($in['days'] ?? null) ? $in['days'] : []), static fn($d) => $d >= 1 && $d <= 7)));
        sort($days);
        if (!$days) {
            return ['ok' => false, 'msg' => 'Marca al menos un día laborable.', 'tz' => null, 'hours' => null];
        }
        return ['ok' => true, 'msg' => '', 'tz' => $tz, 'hours' => ['start' => $start, 'end' => $end, 'days' => $days]];
    }
}
