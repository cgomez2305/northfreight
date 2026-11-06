<?php
/**
 * Bloques "In short" (partial summary): UNA sola fuente por ruta. Las plantillas los leen con summary_for($route) y /llms-full.txt los emite desde aquí, así que nunca se desincronizan.
 * Reglas (§8.2): solo información que ya está en el copy de la página; sin cifras, tarifas, cobertura ni promesas nuevas. Cada texto abre con la keyword principal de la página.
 * Clave = ruta. Campos: text (string, marcas de ns_rich()), points (hasta 3 strings).
 */

return [
    '/trucking-back-office/' => [
        'text'   => 'Trucking back office services cover the work behind the load: paperwork organized, settlements prepared and disputes followed until they close. The same NorthStar desk books and tracks the load, so it already has every document it needs.',
        'points' => [
            'Settlement preparation and dispute management',
            'Rate cons, BOLs, PODs and receipts filed per load',
            'Driver onboarding and Amazon Relay account follow-up',
        ],
    ],
    '/careers/owner-operators/' => [
        'text'   => 'Owner operator jobs at NorthStar are a dispatch partnership, not employment: you keep your truck and approve every load, and the desk books the freight, deals with brokers, tracks every trip and keeps the paperwork that gets you paid.',
        'points' => [
            'You approve every load',
            'Updates on WhatsApp, where you already work',
            'A team, not a single dispatcher',
        ],
    ],
    '/careers/' => [
        'text'   => 'Truck driver jobs and owner operator partnerships at NorthStar work like this: if you run your own truck, the dispatch desk finds and books your loads, deals with brokers, tracks every trip and keeps the paperwork, and you approve every load. It is a dispatch service, not employment. Company driver jobs are listed only when real openings exist.',
        'points' => [
            'You approve every load',
            'Updates on WhatsApp, where you already work',
            'Company driver openings listed only when real',
        ],
    ],
    '/dry-van-dispatch/' => [
        'text'   => 'Dry van dispatch service means a desk that finds and books dry van freight, negotiates the rate, coordinates pickup and delivery and keeps every document on file. NorthStar handles it from one dispatch desk.',
        'points' => [
            'Load search and rate negotiation',
            'Check calls and ETAs while the load moves',
            'BOL and POD collected, detention documented',
        ],
    ],
    '/' => [
        'text'   => 'NorthStar is a remote truck dispatch company for owner-operators and small fleets. Our trucking dispatch services cover load booking on Amazon Relay and dry van, load tracking from pickup to POD and back-office work, all from one dispatch desk.',
        'points' => [
            'Amazon Relay and dry van booking',
            'Load tracking and check calls',
            'Settlements, disputes and paperwork',
        ],
    ],
    '/how-it-works/' => [
        'text'   => 'Truck dispatching with NorthStar starts with four steps: we learn your operation, define scope and contacts, run the day-to-day and review and improve together. You keep control of your trucks.',
        'points' => [
            'One channel for every update',
            'A record of every load',
            'A team, not a single dispatcher',
        ],
    ],
    '/dispatch-for-owner-operators/' => [
        'text'   => 'Dispatch services for owner-operators put a dispatch desk behind your truck: the desk finds and books your loads, deals with brokers, tracks every trip and keeps the paperwork that gets you paid. NorthStar runs that desk for you.',
        'points' => [
            'You approve every load',
            'Updates on WhatsApp, where you already work',
            'Arrival timestamps and photos kept on file',
        ],
    ],
    '/amazon-relay-dispatch/' => [
        'text'   => 'Amazon Relay dispatch is hands-on management of your Relay account. If you are an Amazon Relay carrier, our desk watches the load board, books the work that fits your trucks and manages performance and disputes inside your account.',
        'points' => [
            'Block and load booking',
            'Trip and arrival records',
            'Scorecard follow-up and disputes filed with evidence',
        ],
    ],
    '/load-tracking/' => [
        'text'   => 'Truck load tracking means following each trip from pickup to POD: ETAs, arrivals and departures logged, incidents documented and brokers kept informed. NorthStar\'s desk handles the check calls so you are not on the phone.',
        'points' => [
            'Check calls and ETA updates',
            'Arrival and departure times, delays and the detention clock',
            'Incident photos and notes, BOL and POD',
        ],
    ],
];
