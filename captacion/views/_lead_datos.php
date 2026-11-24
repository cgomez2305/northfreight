<?php
/**
 * Fase AM · bloques de datos de la ficha (una sola linea en lead.php). Cada partial decide por si mismo si se muestra (solo administradores, y nada si el lead esta anonimizado).
 * Variables de lead.php: $lead, $csrf, $isAdmin, $dupes.
 */
require __DIR__ . '/_lead_send.php';
require __DIR__ . '/_lead_merge.php';
require __DIR__ . '/_lead_privacy.php';
