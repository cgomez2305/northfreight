<?php
/** Socrata no respondio bien (red, 429, 5xx) o rechazo la consulta (permanent = true: columna o dataset inexistente). La corrida diaria se detiene y se reanuda sola. */

declare(strict_types=1);

namespace Captacion\Services;

final class SocrataUnavailable extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}
