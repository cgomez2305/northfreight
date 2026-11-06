<?php
/** Endpoints JSON del panel (POST, cuerpo JSON, cabecera X-CSRF-Token): buscador global y avisos leidos. Limite de uso por usuario. */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Services\GlobalSearch;
use Captacion\Services\Notifications;
use Captacion\Services\Prefs;
use Captacion\Services\RateLimiter;

final class AjaxController extends Responder
{
    public const SEARCH_LIMIT = 90;   // peticiones por minuto y usuario

    /** @return array{0:array,1:array} [usuario, cuerpo JSON] tras comprobar metodo, sesion y CSRF. */
    private static function guard(): array
    {
        return self::jsonPost();
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
        self::json(['ok' => true] + GlobalSearch::run($q, trim($q) === '' ? Prefs::recent((int) $user['id']) : []));
    }

    public static function notificationsRead(): never
    {
        [$user, $body] = self::guard();
        $keys = isset($body['keys']) && is_array($body['keys']) ? array_values(array_filter($body['keys'], 'is_string')) : null;
        $n = Notifications::markRead((int) $user['id'], $keys);
        self::json(['ok' => true, 'marked' => $n]);
    }
}
