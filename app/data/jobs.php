<?php
/**
 * Vacantes REALES de company drivers (CDL-A). Vacío = no hay vacantes: /careers/cdl-a-drivers/ queda en 404 (draft), el hub /careers/ lo dice con honestidad
 * ("No openings listed right now") y el formulario no ofrece la opción "company driver". NO inventes vacantes: cada entrada publica una página con JSON-LD JobPosting.
 *
 * Cada clave es el slug de la URL: /careers/cdl-a-drivers/{slug}/. Una vacante solo es "activa" (en el sitemap, indexable y con JobPosting) si
 *   status === 'active'  Y  posted <= hoy  Y  valid_through >= hoy.
 * Al pasar valid_through, la página deja de existir sola (404) y sale del sitemap. Los campos opcionales que falten simplemente no se imprimen
 * (ni en la página ni en el JSON-LD); `pay` NO se inventa: sin el dato no hay baseSalary.
 *
 * Estructura (ejemplo comentado; copia, rellena con datos confirmados por el cliente y quita los comentarios):
 *
 * 'regional-cdl-a-driver' => [
 *     'status'          => 'active',                 // active | paused
 *     'title'           => 'Regional CDL-A Company Driver',
 *     'posted'          => '2026-11-10',             // YYYY-MM-DD, fecha real de publicación (datePosted)
 *     'valid_through'   => '2026-12-31',             // YYYY-MM-DD, obligatorio para activarla (validThrough)
 *     'employment_type' => 'FULL_TIME',              // FULL_TIME | PART_TIME | CONTRACTOR | TEMPORARY
 *     'location'        => ['city' => 'Lakeland', 'region' => 'FL', 'postal' => '33801', 'country' => 'US'],   // lugar de trabajo o base
 *     'summary'         => 'Una o dos frases: qué es el puesto.',
 *     'equipment'       => 'Dry van',                // opcional
 *     'responsibilities' => ['…', '…'],               // opcional (lista)
 *     'requirements'    => ['Standard CDL-A', '…'],  // opcional (lista)
 *     'benefits'        => ['…'],                    // opcional (lista; solo beneficios confirmados)
 *     'pay'             => ['min' => 0, 'max' => 0, 'unit' => 'WEEK', 'currency' => 'USD'],   // opcional; unit: HOUR | WEEK | MONTH | YEAR (sin cifras inventadas)
 * ],
 */

return [];
