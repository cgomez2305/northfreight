<?php
/** Graph API falsa de Meta (router de php -S). GET /{id} o /{version}/{id}: devuelve GRAPH_DIR/{id}.json. Registra cada peticion en MOCK_LOG. Modo (MOCK_MODE_FILE): ok | expired (400 codigo 190) | gone (404) | error500 | forbidden (403 codigo 10). */

$log = getenv('MOCK_LOG') ?: '';
$modeFile = getenv('MOCK_MODE_FILE') ?: '';
$mode = $modeFile !== '' && is_file($modeFile) ? trim((string) file_get_contents($modeFile)) : 'ok';
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($log !== '') {
    file_put_contents($log, json_encode(['path' => $path, 'query' => $_GET, 'method' => $_SERVER['REQUEST_METHOD']], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
header('Content-Type: application/json');
if ($mode === 'expired') {
    http_response_code(400);
    echo '{"error":{"message":"Error validating access token: Session has expired","type":"OAuthException","code":190}}';
    exit;
}
if ($mode === 'forbidden') {
    http_response_code(403);
    echo '{"error":{"message":"(#10) Application does not have permission","type":"OAuthException","code":10}}';
    exit;
}
if ($mode === 'error500') {
    http_response_code(500);
    echo '{"error":{"message":"Unknown error","code":2}}';
    exit;
}
$id = basename($path);
$file = (getenv('GRAPH_DIR') ?: '') . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $id) . '.json';
if ($mode === 'gone' || !is_file($file)) {
    http_response_code(404);
    echo '{"error":{"message":"Unsupported get request. Object with ID does not exist","type":"GraphMethodException","code":100}}';
    exit;
}
echo (string) file_get_contents($file);
