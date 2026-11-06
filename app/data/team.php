<?php
/**
 * Personas del equipo (/our-desk/). Fase S: el cliente confirmó TRES nombres (Jonathan, Elias y Mileva); Jesús está por confirmar y NO se lista.
 * PENDIENTE (cliente), por persona: cargo, una línea de experiencia y foto real (no IA). Hasta tenerlos, 'role', 'bio' y 'photo' siguen como {{ph:...}} / null:
 * con ENV=production la plantilla (desk.php) solo muestra a quien tenga nombre Y cargo sin placeholder, así que hoy NADIE de esta lista se publica y la sección
 * "The people on your loads" se omite entera. En staging se ven las fichas con sus [ placeholders ] para completarlas.
 * Los textos usan la convención {{ph:...}} (phtext()); 'photo' => null hasta tener foto (la plantilla pinta entonces ph('Team photo')).
 */

return [
    ['name' => 'Jonathan', 'role' => '{{ph:Role}}', 'bio' => '{{ph:One line on experience}}', 'photo' => null],
    ['name' => 'Elias',    'role' => '{{ph:Role}}', 'bio' => '{{ph:One line on experience}}', 'photo' => null],
    ['name' => 'Mileva',   'role' => '{{ph:Role}}', 'bio' => '{{ph:One line on experience}}', 'photo' => null],
];
