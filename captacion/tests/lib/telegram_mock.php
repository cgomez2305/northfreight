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
