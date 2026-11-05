<?php
/**
 * Horario permitido para llamar en frio (doc §16): de 8:00 a. m. a 9:00 p. m. HORA LOCAL del prospecto. Aqui solo se conoce el estado (y la ciudad como texto), no el
 * condado, asi que en un estado con DOS zonas horarias la llamada solo se permite cuando es hora valida en TODAS sus zonas (la interseccion de las ventanas: en
 * Florida, por ejemplo, de 9:00 a. m. a 9:00 p. m. hora del Este). Es conservador a proposito: nunca llama antes de las 8 a. m. ni despues de las 9 p. m. de nadie.
 * Los estados de varias zonas son los que el propio FMCSA y el IANA marcan como divididos (AK, AZ por la Nacion Navajo, FL, ID, IN, KS, KY, MI, NE, NV por West Wendover,
 * ND, OR, SD, TN, TX). Cambio de hora incluido (DateTimeZone). Solo 50 estados + DC: territorios y provincias no se listan.
 */

declare(strict_types=1);

namespace Captacion\Services;

use DateTimeImmutable;
use DateTimeZone;

final class UsTimeZones
{
    public const START_HOUR = 8;    // 8:00 a. m. local
    public const END_HOUR = 21;     // 9:00 p. m. local (la llamada debe empezar antes de esa hora)

    private const E = 'America/New_York';
    private const C = 'America/Chicago';
    private const M = 'America/Denver';
    private const P = 'America/Los_Angeles';

    /** @var array<string,list<string>> */
    public const ZONES = [
        'AL' => [self::C], 'AK' => ['America/Anchorage', 'America/Adak'], 'AZ' => ['America/Phoenix', self::M], 'AR' => [self::C], 'CA' => [self::P], 'CO' => [self::M],
        'CT' => [self::E], 'DE' => [self::E], 'DC' => [self::E], 'FL' => [self::E, self::C], 'GA' => [self::E], 'HI' => ['Pacific/Honolulu'], 'ID' => [self::M, self::P],
        'IL' => [self::C], 'IN' => [self::E, self::C], 'IA' => [self::C], 'KS' => [self::C, self::M], 'KY' => [self::E, self::C], 'LA' => [self::C], 'ME' => [self::E],
        'MD' => [self::E], 'MA' => [self::E], 'MI' => [self::E, self::C], 'MN' => [self::C], 'MS' => [self::C], 'MO' => [self::C], 'MT' => [self::M], 'NE' => [self::C, self::M],
        'NV' => [self::P, self::M], 'NH' => [self::E], 'NJ' => [self::E], 'NM' => [self::M], 'NY' => [self::E], 'NC' => [self::E], 'ND' => [self::C, self::M], 'OH' => [self::E],
        'OK' => [self::C], 'OR' => [self::P, self::M], 'PA' => [self::E], 'RI' => [self::E], 'SC' => [self::E], 'SD' => [self::C, self::M], 'TN' => [self::C, self::E],
        'TX' => [self::C, self::M], 'UT' => [self::M], 'VT' => [self::E], 'VA' => [self::E], 'WA' => [self::P], 'WV' => [self::E], 'WI' => [self::C], 'WY' => [self::M],
    ];

    /** @return list<string> zonas IANA del estado ([] si no se conoce) */
    public static function zones(?string $state): array
    {
        return self::ZONES[strtoupper((string) $state)] ?? [];
    }

    public static function isMultiZone(?string $state): bool
    {
        return count(self::zones($state)) > 1;
    }

    /**
     * ¿Se puede llamar a un prospecto de ese estado en este instante?
     * @return array{allowed:bool,known:bool,local:string,local_hm:string,zone_label:string,reason:string,opens_in:?int,multi:bool}
     *   local = hora local mostrada (la de la zona principal, la primera); opens_in = segundos hasta que abre la ventana (null si ya esta abierta o no se sabe).
     */
    public static function check(?string $state, int $ts): array
    {
        $zones = self::zones($state);
        if (!$zones) {
            return ['allowed' => false, 'known' => false, 'local' => '', 'local_hm' => '', 'zone_label' => '', 'reason' => 'Sin estado: no se puede saber la hora local.', 'opens_in' => null, 'multi' => false];
        }
        $allowed = true;
        $worstOpen = 0;
        foreach ($zones as $z) {
            $d = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($z));
            $mins = (int) $d->format('G') * 60 + (int) $d->format('i');
            if ($mins < self::START_HOUR * 60 || $mins >= self::END_HOUR * 60) {
                $allowed = false;
            }
            $worstOpen = max($worstOpen, self::secondsToOpen($d));
        }
        $main = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($zones[0]));
        $multi = count($zones) > 1;
        $abbr = $main->format('T');
        $hm = $main->format('g:i') . ' ' . ($main->format('A') === 'AM' ? 'a. m.' : 'p. m.');
        $reason = $allowed ? '' : ($multi ? 'El estado tiene dos zonas horarias: solo se llama cuando es de 8 a. m. a 9 p. m. en ambas.' : 'Fuera del horario permitido (8 a. m. a 9 p. m. hora local).');
        return ['allowed' => $allowed, 'known' => true, 'local' => $main->format('Y-m-d H:i'), 'local_hm' => $hm, 'zone_label' => $abbr, 'reason' => $reason, 'opens_in' => $allowed ? null : $worstOpen, 'multi' => $multi];
    }

    /** Segundos hasta las 8:00 locales (hoy o manana) si ahora esta cerrado; 0 si esta abierto. */
    private static function secondsToOpen(DateTimeImmutable $local): int
    {
        $mins = (int) $local->format('G') * 60 + (int) $local->format('i');
        if ($mins >= self::START_HOUR * 60 && $mins < self::END_HOUR * 60) {
            return 0;
        }
        $open = $local->setTime(self::START_HOUR, 0);
        if ($mins >= self::END_HOUR * 60) {
            $open = $open->modify('+1 day');
        }
        return max(0, $open->getTimestamp() - $local->getTimestamp());
    }
}
