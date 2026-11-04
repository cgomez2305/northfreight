<?php
/**
 * "A load, stage by stage" (partial stage-table, usado en /how-it-works/ y reutilizable en servicios).
 * Cada fila: [etapa, lo que hace el desk, lo que recibe el cliente, lo que queda archivado].
 * REGLA: cada celda reutiliza una afirmación que YA está en el sitio (home, owner, tracking, back office, our desk). No añadir cifras,
 * tiempos ni promesas nuevas; lo no confirmado va como {{ph:...}}.
 */

return [
    ['Booking',    'Finds and books loads that fit your trucks and lanes, and handles brokers and rate confirmations.', 'You approve every load.',                     'Rate confirmation'],
    ['Pickup',     'Coordinates every pickup with your driver.',                                                       'Driver check-in with the photo attached.',      'Arrival timestamps and photos'],
    ['In transit', 'Runs check calls and ETA updates and keeps brokers informed.',                                     'Brokers updated without you picking up the phone.', 'Check-call log'],
    ['Delivery',   'Follows the trip to POD and files the BOL and POD with the load.',                                 'Updates on WhatsApp, where you already work.',  'BOL and POD'],
    ['Settlement', 'Prepares the settlement and follows disputes with evidence.',                                      'You see exactly what each load paid.',          'Settlement summary and dispute evidence'],
];
