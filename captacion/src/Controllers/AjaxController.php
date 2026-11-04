<?php
/** Endpoints JSON del panel (POST, cuerpo JSON, cabecera X-CSRF-Token): buscador global y avisos leidos. Limite de uso por usuario. */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Services\GlobalSearch;
use Captacion\Services\Notifications;
use Captacion\Services\RateLimiter;
use Captacion\Services\Session;

final class AjaxController extends Responder
{
    public const SEARCH_LIMIT = 90;   // peticiones por minuto y usuario

    /** @return array{0:array,1:array} [usuario, cuerpo JSON] tras comprobar metodo, sesion y CSRF. */
    private static function guard(): array
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::json(['ok' => false, 'error' => 'method'], 405, ['Allow: POST']);
        }
        $user = self::userJson();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!Session::checkCsrf($token)) {
            self::json(['ok' => false, 'error' => 'csrf'], 403);
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 4097);
        if (strlen($raw) > 4096) {
            self::json(['ok' => false, 'error' => 'too_large'], 413);
        }
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            self::json(['ok' => false, 'error' => 'invalid'], 400);
        }
        return [$user, $body];
    }

    public static function search(): never
    {
        [$user, $body] = self::guard();
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany('panel_search', $key, self::SEARCH_LIMIT, 60)) {
            self::json(['ok' => false, 'error' => 'rate_limited'], 429, ['Retry-After: 30']);
        }
        RateLimiter::hit('panel_search', $key, 60);
        $q = is_string($body['q'] ?? null) ? $body['q'] : '';
        self::json(['ok' => true] + GlobalSearch::run($q));
    }

    public static function notificationsRead(): never
    {
        [$user, $body] = self::guard();
        $keys = isset($body['keys']) && is_array($body['keys']) ? array_values(array_filter($body['keys'], 'is_string')) : null;
        $n = Notifications::markRead((int) $user['id'], $keys);
        self::json(['ok' => true, 'marked' => $n]);
    }
}
