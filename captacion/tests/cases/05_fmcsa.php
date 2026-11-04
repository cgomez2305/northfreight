<?php
declare(strict_types=1);

use Captacion\Services\Fmcsa;
use Captacion\Services\FmcsaUnavailable;
use CaptacionTests\T;

return static function (array $ctx): void {
    $fx = static fn(string $n, string $grant = '2025-01-01') => str_replace('__GRANT__', $grant, (string) file_get_contents($ctx['root'] . '/tests/fixtures/fmcsa/' . $n . '.json'));
    /** Cliente falso: mapea el final de la ruta a [status, body]. Registra las URLs pedidas. */
    $mk = static function (array $routes, array &$seen) {
        return static function (string $url) use ($routes, &$seen): array {
            $seen[] = $url;
            $path = (string) parse_url($url, PHP_URL_PATH);
            foreach ($routes as $suffix => $resp) {
                if (str_ends_with($path, $suffix)) {
                    if ($resp instanceof Throwable) {
                        throw $resp;
                    }
                    return ['status' => $resp[0], 'body' => $resp[1]];
                }
            }
            return ['status' => 404, 'body' => ''];
        };
    };
    $grant12 = gmdate('Y-m-d', strtotime('-12 months -2 days'));
    $seen = [];

    // Activa, con seguro
    $f = new Fmcsa($mk(['/1234567' => [200, $fx('carrier_active')], '/1234567/authority' => [200, $fx('authority_active', $grant12)]], $seen), 'KEY123456789');
    $r = $f->lookup('1234567');
    T::ok($r['found'] === true && $r['authority_status'] === 'active' && $r['insured'] === true && $r['red_flags'] === [], 'FMCSA activa con seguro: sin banderas rojas');
    T::eq([$r['legal_name'], $r['state'], $r['power_units'], $r['drivers']], ['PEREZ TRANSPORT LLC', 'FL', 2, 2], 'FMCSA: nombre, estado, unidades y conductores');
    T::eq([$r['driver_oos_rate'], $r['vehicle_oos_rate'], $r['bipd_on_file']], [4.5, 16.7, 1000], 'FMCSA: indices fuera de servicio y seguro registrado');
    T::eq($r['authority_granted_at'], $grant12, 'FMCSA: fecha de concesion de la autoridad');
    T::eq($r['authority_age_months'], 12, 'FMCSA: antiguedad en meses (12)');
    T::ok(count($seen) === 2 && str_contains($seen[0], '/carriers/1234567?webKey=') && str_contains($seen[1], '/carriers/1234567/authority?webKey='), 'FMCSA: pide carrier y authority con webKey');

    // Inactiva
    $seen = [];
    $r = (new Fmcsa($mk(['/2345678' => [200, $fx('carrier_inactive')], '/authority' => [200, '{"content":[]}']], $seen), 'K'))->lookup('2345678');
    T::ok($r['authority_status'] === 'inactive' && in_array('authority_inactive', $r['red_flags'], true) && $r['allowed_to_operate'] === false, 'FMCSA inactiva: bandera roja authority_inactive');
    T::eq($r['authority_age_months'], null, 'FMCSA sin fecha de concesion: antiguedad desconocida (null), no se inventa');

    // Sin seguro
    $seen = [];
    $r = (new Fmcsa($mk(['/3456789' => [200, $fx('carrier_no_insurance')], '/authority' => [200, $fx('authority_active', '2020-05-01')]], $seen), 'K'))->lookup('3456789');
    T::ok($r['authority_status'] === 'active' && $r['insured'] === false && in_array('no_insurance', $r['red_flags'], true) && !in_array('authority_inactive', $r['red_flags'], true), 'FMCSA sin seguro: bandera roja no_insurance');
    T::ok($r['authority_age_months'] > 60, 'FMCSA: autoridad antigua (>60 meses)');

    // No existe
    $seen = [];
    T::eq((new Fmcsa($mk(['/9999999' => [200, $fx('not_found')]], $seen), 'K'))->lookup('9999999')['found'], false, 'FMCSA: content null -> found=false (no reintenta)');
    T::eq((new Fmcsa($mk([], $seen), 'K'))->lookup('8888888')['found'], false, 'FMCSA: HTTP 404 -> found=false');
    T::eq((new Fmcsa($mk([], $seen), 'K'))->lookup('abc')['found'], false, 'FMCSA: USDOT no numerico -> found=false sin llamar a la API');

    // API caida / llave
    $seen = [];
    T::throws(static fn() => (new Fmcsa($mk(['/1234567' => new RuntimeException('connection reset for https://x/carriers/1?webKey=SECRETKEY123456')], $seen), 'SECRETKEY123456'))->lookup('1234567'), 'FMCSA caida (excepcion de red) -> FmcsaUnavailable', 'FMCSA no responde');
    try {
        (new Fmcsa($mk(['/1234567' => new RuntimeException('fail https://x/carriers/1?webKey=SECRETKEY123456&a=1')], $seen), 'SECRETKEY123456'))->lookup('1234567');
    } catch (FmcsaUnavailable $e) {
        T::ok(!str_contains($e->getMessage(), 'SECRETKEY123456'), 'FMCSA: la webKey no aparece en el mensaje de error');
    }
    foreach ([500, 502, 503, 429] as $code) {
        T::throws(static fn() => (new Fmcsa($mk(['/1234567' => [$code, 'x']], $seen), 'K'))->lookup('1234567'), "FMCSA HTTP $code -> FmcsaUnavailable (reintentable)", (string) $code);
    }
    T::throws(static fn() => (new Fmcsa($mk(['/1234567' => [401, '']], $seen), 'K'))->lookup('1234567'), 'FMCSA HTTP 401 -> webKey rechazada', 'webKey');
    T::throws(static fn() => (new Fmcsa($mk(['/1234567' => [200, '<html>mantenimiento</html>']], $seen), 'K'))->lookup('1234567'), 'FMCSA respuesta que no es JSON -> FmcsaUnavailable', 'JSON');
    T::throws(static fn() => (new Fmcsa($mk([], $seen), ''))->lookup('1234567'), 'FMCSA sin webKey -> FmcsaUnavailable (el lead se encola, no se bloquea)', 'FMCSA_WEBKEY');
    T::eq((new Fmcsa(null, ''))->configured(), false, 'configured() = false sin llave');

    // El endpoint de authority caido no pierde los datos del carrier
    $seen = [];
    $r = (new Fmcsa($mk(['/1234567' => [200, $fx('carrier_active')], '/authority' => [503, '']], $seen), 'K'))->lookup('1234567');
    T::ok($r['found'] && $r['authority_status'] === 'active' && $r['authority_age_months'] === null, 'FMCSA: si falla /authority se conserva lo del carrier (sin antiguedad)');

    // Fechas
    T::eq(Fmcsa::parseDate('2024-05-12'), '2024-05-12', 'fecha ISO');
    T::eq(Fmcsa::parseDate('05/12/2024'), '2024-05-12', 'fecha MM/DD/AAAA');
    T::eq(Fmcsa::parseDate('2024-05-12T00:00:00.000+0000'), '2024-05-12', 'fecha ISO con hora');
    T::eq(Fmcsa::parseDate(1715472000000), '2024-05-12', 'fecha epoch en milisegundos');
    T::eq(Fmcsa::parseDate('13/45/2024'), null, 'fecha imposible -> null');
    T::eq(Fmcsa::parseDate('ayer'), null, 'fecha no reconocida -> null');
    // La webKey jamas se guarda en el resultado normalizado
    T::ok(!str_contains(json_encode(Fmcsa::normalize('1234567', json_decode($fx('carrier_active'), true))), 'webKey'), 'el resultado normalizado no contiene la webKey');
};
