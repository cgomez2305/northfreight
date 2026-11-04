<?php
declare(strict_types=1);

use Captacion\Db;
use CaptacionTests\Infra;
use CaptacionTests\T;

return static function (array $ctx): void {
    $work = $ctx['work'];
    $mkEnv = static function (string $name, array $over, array $drop = []) use ($ctx, $work): string {
        $vars = array_diff_key(array_merge($ctx['env'], $over), array_flip($drop));
        $file = "$work/$name.env";
        file_put_contents($file, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($vars), $vars)) . "\n");
        return $file;
    };
    $count = static fn(string $t, string $where = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $where");
    $docroot = $ctx['root'] . '/public';
    $tok = $ctx['env']['INTERNAL_API_TOKEN'];
    $payload = ['profile' => 'owner_operator', 'name' => 'Luis Ortega', 'phone' => '+1 (305) 234-5600', 'email' => 'luis@example.com', 'state' => 'FL', 'language' => 'es', 'cdl_type' => 'standard', 'english_level' => 'yes',
        'years_experience' => 5, 'equipment_types' => ['reefer'], 'truck_count' => 3, 'source' => 'n8n_whatsapp', 'utm' => ['utm_source' => 'wa'], 'landing_page' => 'whatsapp'];

    T::reset();
    $port = Infra::phpServer('api-a', $docroot, ['CAPTACION_ENV_FILE' => $mkEnv('api-a', ['API_RATE_LIMIT' => '500'])]);
    $U = "http://127.0.0.1:$port/api/lead.php";
    $auth = ["Authorization: Bearer $tok"];

    $r = Infra::http('GET', $U);
    T::ok($r['status'] === 405 && Infra::header($r, 'allow') === 'POST', 'GET: 405 con Allow: POST');
    T::eq(Infra::http('OPTIONS', $U)['status'], 405, 'OPTIONS (preflight CORS): 405, no hay CORS en el endpoint');
    $r = Infra::http('POST', $U, ['json' => $payload]);
    T::ok($r['status'] === 401 && Infra::header($r, 'www-authenticate') !== '' && $count('leads') === 0, 'sin token: 401');
    T::eq(Infra::http('POST', $U, ['json' => $payload, 'headers' => ['Authorization: Bearer ' . $tok . 'x']])['status'], 401, 'token incorrecto: 401');
    T::eq(Infra::http('POST', $U, ['json' => $payload, 'headers' => ['Authorization: Basic ' . base64_encode('a:b')]])['status'], 401, 'esquema Basic: 401');
    T::eq(Infra::http('POST', $U . '?token=' . $tok, ['json' => $payload])['status'], 401, 'token en la URL: no vale (401)');
    T::eq($count('leads'), 0, 'ninguna de esas peticiones creo un lead');

    $r = Infra::http('POST', $U, ['json' => $payload, 'headers' => $auth]);
    $j = json_decode($r['body'], true);
    T::ok($r['status'] === 201 && ($j['ok'] ?? false) === true && ($j['status'] ?? '') === 'created' && is_int($j['lead_id'] ?? null) && ($j['score'] ?? null) === 60, 'token valido + JSON: 201 created, lead_id y score (' . $r['body'] . ')');
    $lead = Db::one('SELECT * FROM leads WHERE id = ?', [$j['lead_id']]);
    T::ok($lead['source'] === 'n8n_whatsapp' && $lead['phone_e164'] === '+13052345600' && $lead['profile'] === 'owner_operator' && $lead['landing_page'] === 'whatsapp', 'el lead se guardo con la fuente enviada y el telefono normalizado');
    T::ok(!str_contains($r['body'], 'luis') && !str_contains($r['body'], '305') && !str_contains($r['body'], 'Ortega'), 'la respuesta no devuelve datos personales');
    T::ok(Infra::header($r, 'x-content-type-options') === 'nosniff' && str_contains(Infra::header($r, 'cache-control'), 'no-store') && Infra::header($r, 'access-control-allow-origin') === '' && str_contains(Infra::header($r, 'x-robots-tag'), 'noindex'), 'cabeceras: nosniff, no-store, noindex y sin CORS');

    $r = Infra::http('POST', $U, ['form' => ['profile' => 'small-fleet', 'name' => 'Rosa Diaz', 'phone' => '305 234 5601', 'cdl_standard' => 'yes', 'english' => 'yes', 'source' => 'manual'], 'headers' => $auth]);
    T::ok($r['status'] === 201 && ($j2 = json_decode($r['body'], true)) && $j2['status'] === 'created', 'formulario urlencoded tambien vale: 201');
    $r = Infra::http('POST', $U, ['json' => ['phone' => '3052345600'] + $payload, 'headers' => $auth]);
    T::ok($r['status'] === 200 && (json_decode($r['body'], true)['status'] ?? '') === 'reentry' && $count('leads') === 2, 'mismo telefono: 200 reentry (sin lead nuevo)');
    $r = Infra::http('POST', $U, ['raw' => '{no es json', 'headers' => array_merge($auth, ['Content-Type: application/json'])]);
    T::eq($r['status'], 400, 'JSON malformado: 400');
    $r = Infra::http('POST', $U, ['raw' => 'x', 'headers' => array_merge($auth, ['Content-Type: text/plain'])]);
    T::eq($r['status'], 415, 'Content-Type no soportado: 415');
    $r = Infra::http('POST', $U, ['raw' => json_encode(['blob' => str_repeat('x', 70000)]), 'headers' => array_merge($auth, ['Content-Type: application/json'])]);
    T::eq($r['status'], 413, 'cuerpo de mas de 64 KB: 413');
    $r = Infra::http('POST', $U, ['json' => ['profile' => 'x', 'phone' => '1'], 'headers' => $auth]);
    $j = json_decode($r['body'], true);
    T::ok($r['status'] === 422 && ($j['error'] ?? '') === 'invalid' && isset($j['fields']['profile']), 'datos invalidos: 422 con fields');
    $r = Infra::http('POST', $U, ['json' => ['email' => 'x@mailinator.com'] + $payload, 'headers' => $auth]);
    T::ok($r['status'] === 422 && (json_decode($r['body'], true)['error'] ?? '') === 'disposable_email', 'correo desechable: 422');
    $r = Infra::http('POST', $U, ['json' => ['website' => 'spam'] + $payload, 'headers' => $auth]);
    T::ok($r['status'] === 422 && (json_decode($r['body'], true)['error'] ?? '') === 'honeypot', 'honeypot: 422');
    T::eq($count('leads'), 2, 'los rechazos no guardaron nada');
    $r = Infra::http('POST', $U, ['json' => ['cdl_type' => 'non_domiciled', 'phone' => '3052345650', 'email' => 'dq@example.com'] + $payload, 'headers' => $auth]);
    T::ok($r['status'] === 201 && (json_decode($r['body'], true)['temperature'] ?? '') === 'disqualified', 'descartado por la API: se guarda (201) con temperatura disqualified');

    Infra::stop('api-a');

    // Limite por IP: API_RATE_LIMIT=5 (cuenta todas las peticiones, validas o no)
    T::reset();
    $port = Infra::phpServer('api-r', $docroot, ['CAPTACION_ENV_FILE' => $mkEnv('api-r', ['API_RATE_LIMIT' => '5'])]);
    $U = "http://127.0.0.1:$port/api/lead.php";
    $codes = [];
    for ($i = 0; $i < 7; $i++) {
        $codes[] = Infra::http('POST', $U, ['json' => ['phone' => '30523457' . (10 + $i)] + $payload, 'headers' => $auth])['status'];
    }
    T::eq($codes, [201, 200, 200, 200, 200, 429, 429], 'limite de tasa por IP: 5 peticiones por hora y despues 429 (la 2.a ya es reingreso: mismo correo)');
    $r = Infra::http('POST', $U, ['json' => $payload, 'headers' => $auth]);
    T::ok($r['status'] === 429 && Infra::header($r, 'retry-after') !== '', '  429 incluye Retry-After, incluso con token valido');
    Infra::stop('api-r');

    // Fallos de autenticacion repetidos: 10 y bloqueo, aunque despues llegue el token bueno
    T::reset();
    $port = Infra::phpServer('api-b', $docroot, ['CAPTACION_ENV_FILE' => $mkEnv('api-b', ['API_RATE_LIMIT' => '1000'])]);
    $U = "http://127.0.0.1:$port/api/lead.php";
    $st = [];
    for ($i = 0; $i < 12; $i++) {
        $st[] = Infra::http('POST', $U, ['json' => $payload, 'headers' => ['Authorization: Bearer mala']])['status'];
    }
    T::ok(array_slice($st, 0, 10) === array_fill(0, 10, 401) && $st[10] === 429, 'fuerza bruta del token: 10 intentos fallidos y despues 429 (' . implode(',', $st) . ')');
    T::eq(Infra::http('POST', $U, ['json' => $payload, 'headers' => $auth])['status'], 429, '  bloqueada la IP, tampoco entra con el token bueno hasta que pase la ventana');
    Infra::stop('api-b');

    // Sin INTERNAL_API_TOKEN: deshabilitada
    T::reset();
    $port = Infra::phpServer('api-c', $docroot, ['CAPTACION_ENV_FILE' => $mkEnv('api-c', [], ['INTERNAL_API_TOKEN'])]);
    $r = Infra::http('POST', "http://127.0.0.1:$port/api/lead.php", ['json' => $payload, 'headers' => $auth]);
    T::ok($r['status'] === 503 && (json_decode($r['body'], true)['error'] ?? '') === 'api_disabled', 'sin INTERNAL_API_TOKEN en el .env: 503 api_disabled (nunca acepta tokens vacios)');
    Infra::stop('api-c');

    // Base de datos caida: 503 sin detalles
    T::reset();
    $port = Infra::phpServer('api-d', $docroot, ['CAPTACION_ENV_FILE' => $mkEnv('api-d', ['DB_PORT' => '1'])]);
    $r = Infra::http('POST', "http://127.0.0.1:$port/api/lead.php", ['json' => $payload, 'headers' => $auth]);
    T::ok($r['status'] === 503 && (json_decode($r['body'], true)['error'] ?? '') === 'unavailable' && !str_contains($r['body'], 'captest') && Infra::header($r, 'retry-after') !== '', 'base caida: 503 unavailable + Retry-After, sin credenciales ni detalles');
    Infra::stop('api-d');
    T::reset();
};
