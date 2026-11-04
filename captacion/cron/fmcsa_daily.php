<?php
/**
 * STUB (fase 6 del documento maestro, A10): lista diaria de autoridades nuevas de FMCSA para llamadas en frio (outbound).
 * Cuando exista: cron diario 6:00 a. m. ET (11:00 UTC en invierno, 10:00 UTC en verano; el cron de Hostinger usa UTC o la zona que se elija),
 *   0 11 * * * /opt/alt/php83/usr/bin/php /home/u480515229/domains/northstarfreightsolutions.us/public_html/captacion/cron/fmcsa_daily.php
 *
 * Plan: leer el dataset publico de autoridades nuevas en data.transportation.gov (Socrata; SOCRATA_APP_TOKEN opcional), insertar en prospects_fmcsa
 * (usdot UNIQUE, do_not_call respetado), procesar en lotes que no superen el tiempo maximo de PHP y dejar la lista de llamadas del dia. Los prospectos
 * se convierten en leads con LeadIngest::handle(..., 'fmcsa_prospect'). NADA de esto se ejecuta todavia: este archivo solo documenta el punto de entrada.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
fwrite(STDOUT, "fmcsa_daily: pendiente (fase 6). Sin accion.\n");
exit(0);
