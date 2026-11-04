<?php
/** URLs absolutas del panel (APP_URL del .env, sin barra final). */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Env;

final class Url
{
    public static function base(): string
    {
        return rtrim(Env::get('APP_URL', 'https://captacion.northstarfreightsolutions.us') ?? '', '/');
    }

    public static function lead(int $id): string
    {
        return self::base() . '/leads/' . $id;
    }
}
