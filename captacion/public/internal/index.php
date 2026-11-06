<?php
/** API interna para n8n (/internal/*). Bearer INTERNAL_API_TOKEN. Ver Captacion\Controllers\InternalController. */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

\Captacion\Controllers\InternalController::run();
