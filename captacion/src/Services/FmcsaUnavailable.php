<?php
/** La API de FMCSA no respondio bien (sin llave, llave rechazada, 429/5xx, tiempo agotado). Es reintentable: el lead no se bloquea. */

declare(strict_types=1);

namespace Captacion\Services;

final class FmcsaUnavailable extends \RuntimeException
{
}
