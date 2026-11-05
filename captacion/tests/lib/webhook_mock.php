<?php
/** Webhook falso (router de php -S): guarda en MOCK_LOG cada peticion (ruta, cabeceras en minusculas y cuerpo crudo). El modo sale de MOCK_MODE_FILE: ok | fail (500) | slow (7 s). */

$log = getenv('MOCK_LOG') ?: '';
$modeFile = getenv('MOCK_MODE_FILE') ?: '';
$mode = $modeFile !== '' && is_file($modeFile) ? trim((string) file_get_contents($modeFile)) : 'ok';
$headers = [];
foreach (getallheaders() as $k => $v) {
    $headers[strtolower($k)] = $v;
}
$body = (string) file_get_contents('php://input');
if ($log !== '') {
    file_put_contents($log, json_encode(['path' => parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), 'method' => $_SERVER['REQUEST_METHOD'], 'headers' => $headers, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
if ($mode === 'slow') {
    sleep(7);
}
if ($mode === 'fail') {
    http_response_code(500);
    echo 'error';
    exit;
}
header('Content-Type: application/json');
echo '{"ok":true}';
