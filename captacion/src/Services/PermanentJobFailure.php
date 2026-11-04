<?php
/** Un handler la lanza cuando reintentar no sirve (p. ej. el lead ya no existe): el job pasa a failed sin esperar los reintentos. */

declare(strict_types=1);

namespace Captacion\Services;

final class PermanentJobFailure extends \RuntimeException
{
}
