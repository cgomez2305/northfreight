<?php
/** Utilidades de respuesta compartidas por los controladores. */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Middleware\SecurityHeaders;
use Captacion\View;

abstract class Responder
{
    protected static function redirect(string $path, int $status = 303): never
    {
        SecurityHeaders::send();
        header('Location: ' . $path, true, $status);
        exit;
    }

    protected static function html(string $view, array $vars = [], int $status = 200): never
    {
        http_response_code($status);
        SecurityHeaders::send();
        header('Content-Type: text/html; charset=utf-8');
        echo View::render($view, $vars);
        exit;
    }

    protected static function notFound(): never
    {
        self::html('message', ['title' => 'No encontrado', 'heading' => 'No encontrado', 'text' => 'Esta página no existe.'], 404);
    }

    protected static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    protected static function json(array $body, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        SecurityHeaders::send(false);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $h) {
            header($h);
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
