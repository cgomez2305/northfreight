<?php
/**
 * Personas del equipo (/our-desk/). TODO cliente: nombres, cargos, una línea de experiencia y fotos reales.
 * Los textos usan la convención {{ph:...}} (phtext()); 'photo' => null hasta tener foto
 * (la plantilla pinta entonces ph('Team photo')). Con ENV=production los ph() salen vacíos:
 * no publicar /our-desk/ con estos datos sin completarlos.
 */

return [
    ['name' => '{{ph:Name}}', 'role' => '{{ph:Role}}', 'bio' => '{{ph:One line on experience}}', 'photo' => null],
    ['name' => '{{ph:Name}}', 'role' => '{{ph:Role}}', 'bio' => '{{ph:One line on experience}}', 'photo' => null],
    ['name' => '{{ph:Name}}', 'role' => '{{ph:Role}}', 'bio' => '{{ph:One line on experience}}', 'photo' => null],
    ['name' => '{{ph:Name}}', 'role' => '{{ph:Role}}', 'bio' => '{{ph:One line on experience}}', 'photo' => null],
];
