<?php
/**
 * Opciones del formulario de solicitud (/careers/apply/). UNA sola fuente para la plantilla (apply.php) y el servidor (app/forms/lead.php):
 * el servidor solo acepta estos valores. Las etiquetas son lo que ve el visitante; las claves es lo que se guarda en el lead.
 *
 * Preguntas sensibles (revisar con el cliente y el abogado antes de publicar): `cdl_standard` y `english` se preguntan tal cual (CDL estándar emitido por el estado de residencia;
 * inglés exigido por FMCSA 49 CFR 391.11). NUNCA se pregunta nacionalidad ni estatus migratorio, y ninguna respuesta bloquea el envío ni se rechaza en pantalla: el lead se guarda con `qualified` true/false.
 */

return [
    'profile' => [
        'owner-operator' => 'I own my truck (owner-operator)',
        'small-fleet'    => 'I run a small fleet',
        'company-driver' => 'I am a CDL-A driver looking for a driving job',   // solo se ofrece si hay vacantes activas (app/data/jobs.php)
    ],
    'equipment' => [
        'dry-van'     => 'Dry van',
        'amazon-relay' => 'Amazon Relay',
        'reefer'      => 'Reefer',
        'flatbed'     => 'Flatbed',
        'box-truck'   => 'Box truck',
        'hotshot'     => 'Hotshot',
        'power-only'  => 'Power only',
        'other'       => 'Other',
    ],
    'authority' => [
        'active'  => 'I have active authority (MC)',
        'getting' => 'I am getting my authority',
        'none'    => 'I do not have my own authority',
    ],
    'cdl_standard' => [
        'yes'    => 'Yes',
        'no'     => 'No',
        'unsure' => 'Not sure',
    ],
    'english' => [
        'yes'   => 'Yes',
        'basic' => 'Basic',
        'no'    => 'No',
    ],
    'language' => [
        'en' => 'English',
        'es' => 'Español',
    ],
    'contact_pref' => [
        'whatsapp' => 'WhatsApp (recommended)',
        'call'     => 'Phone call',
        'text'     => 'Text message',
        'email'    => 'Email',
    ],
    'best_time' => [
        'morning'   => 'Morning',
        'afternoon' => 'Afternoon',
        'evening'   => 'Evening',
        'any'       => 'Any time',
    ],
    'state' => [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
        'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
        'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ],
];
