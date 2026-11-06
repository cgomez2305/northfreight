<?php
/** Webhook entrante (calcom). Ver Captacion\Controllers\WebhookController (firma ANTES de procesar nada; 503 si faltan las variables del .env). */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

\Captacion\Controllers\WebhookController::run('calcom');
