<?php
/** Banderas del lead (leads.flags = lista JSON de cadenas): cdl_unsure, fmcsa_pending, red:authority_inactive, red:no_insurance, phone_invalid, stop... */

declare(strict_types=1);

namespace Captacion\Services;

final class Flags
{
    /** @param list<string> $flags */
    public static function add(array $flags, string ...$new): array
    {
        return array_values(array_unique([...$flags, ...$new]));
    }

    /** @param list<string> $flags */
    public static function remove(array $flags, string ...$gone): array
    {
        return array_values(array_diff($flags, $gone));
    }

    /**
     * Banderas derivadas de los datos (se recalculan al reevaluar): se quitan todas las derivadas y se vuelven a poner las que apliquen.
     * Las manuales (stop, phone_invalid...) no se tocan.
     */
    public static function derive(array $flags, array $lead, ?array $settings = null): array
    {
        $derived = ['cdl_unsure', 'below_min_experience', 'red:authority_inactive', 'red:no_insurance', 'usdot_not_found'];
        $flags = self::remove($flags, ...$derived);
        if (($lead['cdl_type'] ?? null) === 'unsure') {
            $flags[] = 'cdl_unsure';
        }
        if (($lead['profile'] ?? '') === 'company_driver' && isset($lead['years_experience']) && $lead['years_experience'] !== null) {
            $min = (int) (($settings ?? Scoring::settings())['driver_min_years'] ?? 1);
            if ((int) $lead['years_experience'] < $min) {
                $flags[] = 'below_min_experience';
            }
        }
        $fm = is_array($lead['fmcsa_data'] ?? null) ? $lead['fmcsa_data'] : [];
        if (($fm['found'] ?? null) === false) {
            $flags[] = 'usdot_not_found';
        }
        foreach ((array) ($fm['red_flags'] ?? []) as $r) {
            $flags[] = 'red:' . $r;
        }
        return array_values(array_unique($flags));
    }
}
