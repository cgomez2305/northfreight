<?php
/** Servidor falso de la Bot API de Telegram para las pruebas (php -S con este router). Registra cada peticion en MOCK_LOG; el modo sale de MOCK_MODE_FILE (ok | fail | error500). */

$log = getenv('MOCK_LOG') ?: '';
$modeFile = getenv('MOCK_MODE_FILE') ?: '';
$mode = $modeFile !== '' && is_file($modeFile) ? trim((string) file_get_contents($modeFile)) : 'ok';
$body = (string) file_get_contents('php://input');
if ($log !== '') {
    file_put_contents($log, json_encode(['path' => parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), 'method' => $_SERVER['REQUEST_METHOD'], 'body' => json_decode($body, true)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
header('Content-Type: application/json');
// FMCSA simulado: GET /carriers/{usdot} y /carriers/{usdot}/authority con las fixtures (1234567 activa; 9999999 no existe)
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/carriers/(\d+)(/authority)?$#', $path, $m) === 1) {
    $fx = dirname(__DIR__) . '/fixtures/fmcsa/';
    if ($mode === 'fail') {
        http_response_code(403);
        echo '{}';
        exit;
    }
    if ($m[1] !== '1234567') {
        http_response_code(404);
        echo '{}';
        exit;
    }
    echo isset($m[2]) ? str_replace('__GRANT__', gmdate('Y-m-d', strtotime('-12 months -2 days')), (string) file_get_contents($fx . 'authority_active.json')) : (string) file_get_contents($fx . 'carrier_active.json');
    exit;
}
if ($mode === 'fail') {
    http_response_code(401);
    echo '{"ok":false,"error_code":401,"description":"Unauthorized"}';
    exit;
}
if ($mode === 'slow') {
    sleep(3);
}
if ($mode === 'error500') {
    http_response_code(500);
    echo 'internal error';
    exit;
}
echo '{"ok":true,"result":{"message_id":1}}';
