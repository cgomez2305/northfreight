<?php
declare(strict_types=1);

use Captacion\Services\Flags;
use Captacion\Services\Scoring;
use Captacion\Services\Settings;
use CaptacionTests\T;

return static function (array $ctx): void {
    $S = Scoring::DEFAULTS;
    $owner = [
        'profile' => 'owner_operator', 'first_name' => 'Maria', 'last_name' => 'Perez', 'phone_e164' => '+18635550101', 'email' => 'm@example.com', 'state' => 'FL', 'language' => 'en',
        'cdl_type' => 'standard', 'english_level' => 'yes', 'years_experience' => 4, 'equipment_types' => ['dry-van'], 'truck_count' => 2, 'authority_status' => 'active',
        'authority_age_months' => null, 'referral_code' => null, 'fmcsa_data' => [], 'endorsements' => [],
    ];
    $fmOk = ['found' => true, 'authority_status' => 'active', 'insured' => true];
    $driver = [
        'profile' => 'company_driver', 'first_name' => 'Juan', 'last_name' => 'Lopez', 'phone_e164' => '+18635550102', 'email' => 'j@example.com', 'state' => 'TX', 'language' => 'en',
        'cdl_type' => 'standard', 'cdl_class' => 'A', 'english_level' => 'yes', 'years_experience' => 5, 'equipment_types' => [], 'truck_count' => null, 'authority_status' => null,
        'referral_code' => null, 'fmcsa_data' => [], 'endorsements' => [],
    ];

    // [descripcion, lead, esperado score, temperatura, motivo de descarte opcional]
    $cases = [
        ['owner base (CDL 20 + ingles 15 + equipo 10 + 2 camiones 10 + 4 anos 5 + completo 5)', $owner, 65, 'warm'],
        ['owner + FMCSA activa e insured + autoridad de 12 meses (15+10+10)', ['fmcsa_data' => $fmOk, 'authority_age_months' => 12] + $owner, 100, 'hot'],
        ['owner + FMCSA + autoridad de 3 meses (5)', ['fmcsa_data' => $fmOk, 'authority_age_months' => 3] + $owner, 95, 'hot'],
        ['owner con tope: 105 bruto -> 100', ['fmcsa_data' => $fmOk, 'authority_age_months' => 12, 'referral_code' => 'REF1'] + $owner, 100, 'hot'],
        ['owner + referido', ['referral_code' => 'REF1'] + $owner, 70, 'hot'],
        ['owner ingles basico (+5 en vez de +15)', ['english_level' => 'basic'] + $owner, 55, 'warm'],
        ['owner CDL "not sure": 0 puntos de CDL, no descarta', ['cdl_type' => 'unsure'] + $owner, 45, 'warm'],
        ['owner 1 camion (+5)', ['truck_count' => 1] + $owner, 60, 'warm'],
        ['owner 5 camiones (+10)', ['truck_count' => 5] + $owner, 65, 'warm'],
        ['owner 6 camiones (+15) = limite 70 caliente', ['truck_count' => 6] + $owner, 70, 'hot'],
        ['owner 12 camiones (+15)', ['truck_count' => 12] + $owner, 70, 'hot'],
        ['owner sin dato de camiones (0 y formulario incompleto)', ['truck_count' => null] + $owner, 50, 'warm'],
        ['owner con Amazon Relay (equipo no despachado: 0)', ['equipment_types' => ['amazon-relay']] + $owner, 55, 'warm'],
        ['owner con reefer + otro (equipo despachado: +10)', ['equipment_types' => ['other', 'reefer']] + $owner, 65, 'warm'],
        ['owner 2 anos exactos = +5 de experiencia; solo CDL+ingles+exp', ['first_name' => 'M', 'last_name' => '', 'phone_e164' => '+18635550101', 'email' => null, 'state' => null, 'truck_count' => null, 'equipment_types' => [], 'authority_status' => null, 'years_experience' => 2] + $owner, 40, 'warm'],
        ['owner 1 ano (<2): 35 = frio', ['first_name' => 'M', 'last_name' => '', 'email' => null, 'state' => null, 'truck_count' => null, 'equipment_types' => [], 'authority_status' => null, 'years_experience' => 1] + $owner, 35, 'cold'],
        ['owner sin experiencia declarada', ['first_name' => 'M', 'last_name' => '', 'email' => null, 'state' => null, 'truck_count' => null, 'equipment_types' => [], 'authority_status' => null, 'years_experience' => 0] + $owner, 35, 'cold'],
        ['owner autoridad 0 meses', ['authority_age_months' => 0] + $owner, 70, 'hot'],
        ['owner autoridad 5 meses', ['authority_age_months' => 5] + $owner, 70, 'hot'],
        ['owner autoridad 6 meses (+10)', ['authority_age_months' => 6] + $owner, 75, 'hot'],
        ['owner autoridad 24 meses (+10)', ['authority_age_months' => 24] + $owner, 75, 'hot'],
        ['owner autoridad 100 meses (+10)', ['authority_age_months' => 100] + $owner, 75, 'hot'],
        ['owner FMCSA encontrada pero inactiva y sin seguro: 0 puntos de FMCSA', ['fmcsa_data' => ['found' => true, 'authority_status' => 'inactive', 'insured' => false]] + $owner, 65, 'warm'],
        ['owner FMCSA activa pero sin dato de seguro', ['fmcsa_data' => ['found' => true, 'authority_status' => 'active', 'insured' => null]] + $owner, 80, 'hot'],
        ['small_carrier usa la tabla de owner', ['profile' => 'small_carrier', 'truck_count' => 8] + $owner, 70, 'hot'],
        ['descarte: CDL no domiciliada', ['cdl_type' => 'non_domiciled'] + $owner, 0, 'disqualified', 'cdl_non_domiciled'],
        ['descarte: ingles "No"', ['english_level' => 'no'] + $owner, 0, 'disqualified', 'english_no'],
        ['descarte: ambos motivos', ['cdl_type' => 'non_domiciled', 'english_level' => 'no'] + $owner, 0, 'disqualified', 'cdl_non_domiciled,english_no'],
        // Company drivers
        ['driver base (CDL-A 25 + ingles 15 + >2 anos 20 + completo 5; sin estados de contratacion)', $driver, 65, 'warm'],
        ['driver 3 anos = "mas de 2" (+20)', ['years_experience' => 3] + $driver, 65, 'warm'],
        ['driver 2 anos (+10)', ['years_experience' => 2] + $driver, 55, 'warm'],
        ['driver 1 ano (+10)', ['years_experience' => 1] + $driver, 55, 'warm'],
        ['driver menos de 1 ano (0)', ['years_experience' => 0] + $driver, 45, 'warm'],
        ['driver CDL clase B no suma CDL-A', ['cdl_class' => 'B'] + $driver, 40, 'warm'],
        ['driver sin clase declarada se asume CDL-A', ['cdl_class' => null] + $driver, 65, 'warm'],
        ['driver CDL "not sure"', ['cdl_type' => 'unsure'] + $driver, 40, 'warm'],
        ['driver ingles basico', ['english_level' => 'basic'] + $driver, 55, 'warm'],
        ['driver referido (+10)', ['referral_code' => 'R'] + $driver, 75, 'hot'],
        ['driver con endoso Hazmat (+5)', ['endorsements' => ['hazmat']] + $driver, 70, 'hot'],
        ['driver con endoso Tanker (+5)', ['endorsements' => ['Tanker']] + $driver, 70, 'hot'],
        ['driver con endoso irrelevante (passenger)', ['endorsements' => ['passenger']] + $driver, 65, 'warm'],
        ['driver sin correo: formulario incompleto', ['email' => null] + $driver, 60, 'warm'],
        ['driver maximo sin estado de contratacion: referido + endoso', ['referral_code' => 'R', 'endorsements' => ['hazmat']] + $driver, 80, 'hot'],
        ['driver descarte: ingles "No"', ['english_level' => 'no'] + $driver, 0, 'disqualified', 'english_no'],
        ['driver descarte: CDL no domiciliada', ['cdl_type' => 'non_domiciled'] + $driver, 0, 'disqualified', 'cdl_non_domiciled'],
    ];
    foreach ($cases as $c) {
        $r = Scoring::score($c[1], $S);
        $good = $r['score'] === $c[2] && $r['temperature'] === $c[3] && ($c[4] ?? null) === $r['disqualified_reason'];
        T::ok($good, $c[0] . ' -> ' . $c[2] . ' ' . $c[3] . ($good ? '' : ' [obtenido ' . $r['score'] . ' ' . $r['temperature'] . ']'));
    }

    // Estados de contratacion (ajuste provisional): vacio por defecto; con la lista, +15
    $withHiring = $S;
    $withHiring['hiring_states'] = ['TX', 'oh'];
    T::eq(Scoring::score($driver, $withHiring)['score'], 80, 'driver en estado de contratacion (TX): +15 = 80 caliente');
    T::eq(Scoring::score(['state' => 'OH'] + $driver, $withHiring)['score'], 80, 'estado de contratacion sin distinguir mayusculas');
    T::eq(Scoring::score(['state' => 'CA'] + $driver, $withHiring)['score'], 65, 'driver fuera de la lista: sin los +15');
    T::eq(Scoring::score($driver, $S)['score'], 65, 'lista de estados vacia (provisional): nadie recibe los +15');

    // Desglose guardado
    $r = Scoring::score($owner, $S);
    T::eq(array_sum(array_column($r['breakdown']['items'], 'points')), $r['breakdown']['raw_total'], 'desglose: la suma de items = total bruto');
    T::ok(isset($r['breakdown']['items'][0]['key'], $r['breakdown']['items'][0]['label']) && $r['breakdown']['version'] === Scoring::VERSION, 'desglose con key, label y version');
    T::eq(Scoring::score(['fmcsa_data' => $fmOk, 'authority_age_months' => 12, 'referral_code' => 'REF1'] + $owner, $S)['breakdown']['capped'], true, 'desglose marca el tope de 100');

    // Los pesos y umbrales se leen de la tabla settings
    Settings::set('scoring.owner', ['cdl_standard' => 30] + $S['scoring.owner']);
    Settings::set('scoring.temperature', ['hot_min' => 60, 'warm_min' => 40]);
    Settings::set('dispatched_equipment', ['amazon-relay']);
    $cfg = Scoring::settings();
    T::eq($cfg['scoring.owner']['cdl_standard'], 30, 'settings(): peso leido de la base');
    $r = Scoring::score($owner);
    T::eq([$r['score'], $r['temperature']], [75 - 10 + 0, 'hot'], 'puntuar con ajustes de la base (CDL 30, umbral caliente 60, equipo despachado = amazon-relay -> 75-10)');
    T::eq(Scoring::score(['equipment_types' => ['amazon-relay']] + $owner)['score'], 75, 'equipo despachado editado desde ajustes');
    // Restaurar
    Settings::set('scoring.owner', $S['scoring.owner']);
    Settings::set('scoring.temperature', $S['scoring.temperature']);
    Settings::set('dispatched_equipment', $S['dispatched_equipment']);
    Settings::clear();
    T::eq(Scoring::score($owner)['score'], 65, 'ajustes restaurados: vuelve a 65');

    // Banderas derivadas
    T::ok(in_array('cdl_unsure', Flags::derive([], ['cdl_type' => 'unsure']), true), 'bandera cdl_unsure');
    T::ok(!in_array('cdl_unsure', Flags::derive(['cdl_unsure'], ['cdl_type' => 'standard']), true), 'cdl_unsure se quita cuando deja de aplicar');
    T::ok(in_array('below_min_experience', Flags::derive([], ['profile' => 'company_driver', 'years_experience' => 0], $S), true), 'driver con 0 anos: bandera below_min_experience (minimo 1)');
    T::ok(!in_array('below_min_experience', Flags::derive([], ['profile' => 'company_driver', 'years_experience' => 1], $S), true), 'driver con 1 ano: sin bandera');
    $red = Flags::derive(['stop', 'phone_invalid'], ['fmcsa_data' => ['found' => true, 'red_flags' => ['authority_inactive', 'no_insurance']]], $S);
    T::ok(in_array('red:authority_inactive', $red, true) && in_array('red:no_insurance', $red, true) && in_array('stop', $red, true) && in_array('phone_invalid', $red, true), 'banderas rojas de FMCSA; las manuales (stop, phone_invalid) se conservan');
    T::ok(in_array('usdot_not_found', Flags::derive([], ['fmcsa_data' => ['found' => false]], $S), true), 'bandera usdot_not_found');
};
