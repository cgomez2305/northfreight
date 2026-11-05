<?php
/** Router de php -S que sirve las fixtures como si fuera data.transportation.gov (pruebas del cron por CLI). */

declare(strict_types=1);

require __DIR__ . '/SocrataFixture.php';

$fx = new CaptacionTests\SocrataFixture();
$dir = getenv('SOCRATA_MOCK_LOG') ?: '';
$r = $fx->respond('http://x' . $_SERVER['REQUEST_URI']);
if ($dir !== '') {
    file_put_contents($dir, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'token' => $_SERVER['HTTP_X_APP_TOKEN'] ?? null]) . "\n", FILE_APPEND | LOCK_EX);
}
http_response_code($r['status']);
header('Content-Type: application/json');
echo $r['body'];
