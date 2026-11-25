<?php
/** API de Vonage simulada (fase AV) para php -S: POST /v1/users (201; 409 si el nombre ya existe) y GET /v1/users (200). Exige un JWT RS256 válido con la clave pública de VONAGE_MOCK_PUB. */

declare(strict_types=1);

$log = (string) getenv('VONAGE_MOCK_LOG');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$body = (string) file_get_contents('php://input');
$ok = false;
if (preg_match('/^Bearer ([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $auth, $m) === 1) {
    $sig = base64_decode(strtr($m[3], '-_', '+/') . str_repeat('=', (4 - strlen($m[3]) % 4) % 4));
    $ok = openssl_verify($m[1] . '.' . $m[2], (string) $sig, (string) file_get_contents((string) getenv('VONAGE_MOCK_PUB')), OPENSSL_ALGO_SHA256) === 1;
}
file_put_contents($log, json_encode(['method' => $method, 'path' => $path, 'jwt_ok' => $ok, 'body' => $body]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
if (!$ok) {
    http_response_code(401);
    echo '{"title":"Unauthorized"}';
    return true;
}
if ($path === '/v1/users' && $method === 'POST') {
    $name = (string) (json_decode($body, true)['name'] ?? '');
    $seen = $log . '.users';
    $all = is_file($seen) ? (array) json_decode((string) file_get_contents($seen), true) : [];
    if (in_array($name, $all, true)) {
        http_response_code(409);
        echo '{"code":"user:error:duplicate-name"}';
        return true;
    }
    $all[] = $name;
    file_put_contents($seen, json_encode($all));
    http_response_code(201);
    echo json_encode(['id' => 'USR-' . md5($name), 'name' => $name]);
    return true;
}
if ($path === '/v1/users' && $method === 'GET') {
    echo '{"page_size":1,"_embedded":{"users":[]}}';
    return true;
}
http_response_code(404);
echo '{}';
return true;
