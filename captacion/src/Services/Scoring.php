<?php
/**
 * Puntuacion de leads (doc §6), 0 a 100. Funcion pura: score($lead, $settings) no toca la base; settings() lee `settings` y completa con DEFAULTS.
 *
 * Los DEFAULTS son los valores del documento y deben coincidir con db/migrations/003_settings_seed.sql (lo comprueba tests/run.php).
 * PROVISIONAL hasta que el cliente confirme ([CONFIRMAR CON CLIENTE]): dispatched_equipment, hiring_states, driver_min_years y los tramos de experiencia de company drivers.
 *
 * Tramos: [{"lt": N, "points": P}, ..., {"lt": null, "points": P}] = el primero cuyo "lt" sea mayor que el valor; "lt": null = el resto.
 *
 * Descarte automatico (temperatura 'disqualified', puntuacion 0, motivo guardado): cdl_type = non_domiciled, o ingles = no. "Not sure" en la CDL NO descarta (0 puntos y bandera cdl_unsure).
 * Owner-operators y carriers suman hasta 110 sin tope: se limita a 100.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class Scoring
{
    public const VERSION = 1;

    public const DEFAULTS = [
        'scoring.owner' => [
            'cdl_standard' => 20, 'english_yes' => 15, 'english_basic' => 5,
            'fmcsa_authority_active' => 15, 'fmcsa_insured' => 10,
            'authority_age' => [['lt' => 6, 'points' => 5], ['lt' => 24, 'points' => 10], ['lt' => null, 'points' => 10]],
            'dispatched_equipment' => 10,
            'trucks' => [['lt' => 2, 'points' => 5], ['lt' => 6, 'points' => 10], ['lt' => null, 'points' => 15]],
            'experience_min_years' => 2, 'experience' => 5, 'referral' => 5, 'complete' => 5,
            'complete_fields' => ['name', 'phone', 'email', 'state', 'language', 'truck_count', 'equipment_types', 'years_experience', 'authority_status', 'cdl_type', 'english_level'],
        ],
        'scoring.driver' => [
            'cdl_a_standard' => 25, 'english_yes' => 15, 'english_basic' => 5,
            'experience' => [['lt' => 1, 'points' => 0], ['lt' => 3, 'points' => 10], ['lt' => null, 'points' => 20]],
            'hiring_state' => 15, 'endorsement' => 5, 'referral' => 10, 'complete' => 5,
            'complete_fields' => ['name', 'phone', 'email', 'state', 'language', 'years_experience', 'cdl_type', 'english_level'],
        ],
        'scoring.temperature' => ['hot_min' => 70, 'warm_min' => 40],
        'dispatched_equipment' => ['dry-van', 'reefer', 'flatbed', 'box-truck', 'hotshot', 'power-only'],
        'hiring_states' => [],
        'driver_min_years' => 1,
    ];

    /** Ajustes vigentes: lo guardado en la tabla settings sobre los DEFAULTS. */
    public static function settings(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $v = Settings::get($key, $default);
            $out[$key] = is_array($default) && is_array($v) && self::isAssoc($default) ? array_replace($default, $v) : ($v ?? $default);
        }
        return $out;
    }

    private static function isAssoc(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * @param array $lead campos de la fila leads con los JSON ya decodificados: profile, first_name, phone_e164, email, state, language, cdl_type, cdl_class, english_level,
     *                    years_experience, equipment_types[], truck_count, authority_status, authority_age_months, endorsements[], referral_code, fmcsa_data[]
     * @return array{score:int,temperature:string,disqualified_reason:?string,breakdown:array}
     */
    public static function score(array $lead, ?array $settings = null): array
    {
        $cfg = $settings ?? self::settings();
        $temp = $cfg['scoring.temperature'];
        $isDriver = ($lead['profile'] ?? '') === 'company_driver';
        $w = $isDriver ? $cfg['scoring.driver'] : $cfg['scoring.owner'];

        $items = [];
        $add = static function (string $key, string $label, int $points, string $note = '') use (&$items): void {
            $items[] = ['key' => $key, 'label' => $label, 'points' => $points] + ($note !== '' ? ['note' => $note] : []);
        };

        // Descarte automatico
        $dq = [];
        if (($lead['cdl_type'] ?? null) === 'non_domiciled') {
            $dq[] = 'cdl_non_domiciled';
        }
        if (($lead['english_level'] ?? null) === 'no') {
            $dq[] = 'english_no';
        }

        $fm = is_array($lead['fmcsa_data'] ?? null) ? $lead['fmcsa_data'] : [];
        $fmFound = !empty($fm['found']);
        $cdl = $lead['cdl_type'] ?? null;
        $english = $lead['english_level'] ?? null;
        $years = isset($lead['years_experience']) && $lead['years_experience'] !== null ? (int) $lead['years_experience'] : null;
        $referral = trim((string) ($lead['referral_code'] ?? '')) !== '';

        if ($isDriver) {
            $classOk = in_array($lead['cdl_class'] ?? null, [null, '', 'A'], true);
            $add('cdl_a_standard', 'CDL-A estandar', ($cdl === 'standard' && $classOk) ? (int) $w['cdl_a_standard'] : 0, $cdl === 'unsure' ? 'cdl_unsure' : '');
            $add('english', 'Ingles', $english === 'yes' ? (int) $w['english_yes'] : ($english === 'basic' ? (int) $w['english_basic'] : 0), (string) $english);
            $add('experience', 'Experiencia', $years === null ? 0 : self::tier($w['experience'], $years), $years === null ? 'sin dato' : $years . ' anos');
            $inHiring = in_array(strtoupper((string) ($lead['state'] ?? '')), array_map('strtoupper', (array) $cfg['hiring_states']), true) && ($lead['state'] ?? '') !== '';
            $add('hiring_state', 'Estado de contratacion', $inHiring ? (int) $w['hiring_state'] : 0, (string) ($lead['state'] ?? ''));
            $endo = array_intersect(array_map('strtolower', (array) ($lead['endorsements'] ?? [])), ['hazmat', 'tanker', 'tank']);
            $add('endorsement', 'Endosos (Hazmat, Tanker)', $endo ? (int) $w['endorsement'] : 0);
            $add('referral', 'Referido', $referral ? (int) $w['referral'] : 0);
        } else {
            $add('cdl_standard', 'CDL estandar', $cdl === 'standard' ? (int) $w['cdl_standard'] : 0, $cdl === 'unsure' ? 'cdl_unsure' : '');
            $add('english', 'Ingles', $english === 'yes' ? (int) $w['english_yes'] : ($english === 'basic' ? (int) $w['english_basic'] : 0), (string) $english);
            $add('fmcsa_authority_active', 'Autoridad activa verificada (FMCSA)', ($fmFound && ($fm['authority_status'] ?? '') === 'active') ? (int) $w['fmcsa_authority_active'] : 0);
            $add('fmcsa_insured', 'Seguro vigente registrado (FMCSA)', ($fmFound && ($fm['insured'] ?? null) === true) ? (int) $w['fmcsa_insured'] : 0);
            $age = isset($lead['authority_age_months']) && $lead['authority_age_months'] !== null ? (int) $lead['authority_age_months'] : null;
            $add('authority_age', 'Antiguedad de la autoridad', $age === null ? 0 : self::tier($w['authority_age'], $age), $age === null ? 'sin dato' : $age . ' meses');
            $equipment = array_map('strtolower', (array) ($lead['equipment_types'] ?? []));
            $dispatched = array_map('strtolower', (array) $cfg['dispatched_equipment']);
            $add('dispatched_equipment', 'Equipo que NorthStar despacha', array_intersect($equipment, $dispatched) ? (int) $w['dispatched_equipment'] : 0);
            $trucks = isset($lead['truck_count']) && $lead['truck_count'] !== null ? (int) $lead['truck_count'] : null;
            $add('trucks', 'Numero de camiones', $trucks === null || $trucks < 1 ? 0 : self::tier($w['trucks'], $trucks), $trucks === null ? 'sin dato' : (string) $trucks);
            $add('experience', 'Experiencia con CDL de ' . (int) $w['experience_min_years'] . ' anos o mas', ($years !== null && $years >= (int) $w['experience_min_years']) ? (int) $w['experience'] : 0, $years === null ? 'sin dato' : $years . ' anos');
            $add('referral', 'Referido', $referral ? (int) $w['referral'] : 0);
        }

        $complete = true;
        $missing = [];
        foreach ((array) $w['complete_fields'] as $f) {
            if (!self::filled($lead, (string) $f)) {
                $complete = false;
                $missing[] = $f;
            }
        }
        $add('complete', 'Formulario completo', $complete ? (int) $w['complete'] : 0, $missing ? 'falta: ' . implode(', ', $missing) : '');

        $raw = array_sum(array_column($items, 'points'));
        $capped = $raw > 100;
        $score = $dq ? 0 : min(100, $raw);
        $temperature = $dq ? 'disqualified' : ($score >= (int) $temp['hot_min'] ? 'hot' : ($score >= (int) $temp['warm_min'] ? 'warm' : 'cold'));

        return [
            'score'               => $score,
            'temperature'         => $temperature,
            'disqualified_reason' => $dq ? implode(',', $dq) : null,
            'breakdown'           => [
                'version'      => self::VERSION,
                'profile'      => $isDriver ? 'company_driver' : (string) ($lead['profile'] ?? ''),
                'items'        => $items,
                'raw_total'    => $raw,
                'capped'       => $capped,
                'score'        => $score,
                'temperature'  => $temperature,
                'disqualified' => $dq,
            ],
        ];
    }

    /** Puntos del primer tramo cuyo "lt" supere el valor. */
    private static function tier(array $tiers, int $value): int
    {
        foreach ($tiers as $t) {
            if (!array_key_exists('lt', $t) || $t['lt'] === null || $value < (int) $t['lt']) {
                return (int) $t['points'];
            }
        }
        return 0;
    }

    private static function filled(array $lead, string $field): bool
    {
        $map = ['phone' => 'phone_e164'];
        if ($field === 'name') {
            return trim((string) ($lead['first_name'] ?? '')) !== '' && trim((string) ($lead['last_name'] ?? '')) !== '';
        }
        $v = $lead[$map[$field] ?? $field] ?? null;
        if (is_array($v)) {
            return $v !== [];
        }
        return $v !== null && trim((string) $v) !== '';
    }
}
