<?php
/**
 * Contenido de las páginas por equipo (Equip*.html del prototipo). Todas son status 'draft' hasta que el cliente
 * confirme los equipos que despacha (§7.4), salvo 'power-only' que pasó a live en la fase S (el cliente lo incluye en su oferta: Amazon Relay, power only y dry van;
 * por confirmar cuáles se atienden hoy). Clave = $route['equip']. Textos de la maqueta, sin revisar con el cliente.
 * Las 5 páginas comparten FAQ y 'needs' salvo la línea de detalles del equipo.
 */

return [
    'box' => [
        'path'    => '/box-truck-dispatch/',
        'image'   => ['base' => 'nf-faq-dock-light-wide', 'alt' => 'Open loading dock door under a warm wall light at dusk, with yellow bollards'],   // foto ambiental provisional (páginas en draft)
        'name'    => 'Box truck',
        'h1'      => 'Box truck dispatch services',
        'tagline' => 'Loads that fit a straight truck',
        'lead'    => 'Straight-truck freight is its own market: smaller loads, tighter docks and more stops. We find the loads that fit your box truck and handle the brokers, tracking and paperwork.',
        'sheet'   => ['equipment' => '16 to 26 ft straight truck', 'freight' => 'Palletized LTL, final mile, expedited', 'confirm' => 'Dock or liftgate, pallets, weight'],
        'handles_title' => 'Built around your box truck',
        'handles' => [
            ['Load search for straight trucks', 'Boards and brokers filtered for box-truck size, weight and dock needs.'],
            ['Multi-stop planning', 'Stops in order, appointments confirmed, receivers called ahead.'],
            ['Tracking & check calls', 'ETAs to the broker and every stop logged with times.'],
            ['PODs on every stop', 'Signed paperwork collected and filed per load.'],
        ],
        'checks'  => [
            'Pallet count and total weight fit your truck',
            'Dock-high or liftgate delivery confirmed',
            'Residential or limited-access stops flagged',
            'Appointment times realistic for your hours',
        ],
        'needs'   => [
            'MC and USDOT authority',
            'Certificate of insurance',
            'W-9',
            'Your box truck details and photos',
            'Preferred lanes and home time',
        ],
        'faq_title' => 'Box truck dispatch questions',
        'faq'     => [
            ['q' => 'Do you dispatch box trucks under my own authority?', 'a' => 'Yes. We book loads under your MC and USDOT. You keep your authority and decide which loads to take.'],
            ['q' => 'Can I refuse a load you find?', 'a' => 'Always. We send the details first (rate, miles, stops and requirements) and book only what you approve.'],
            ['q' => 'How do you send updates?', 'a' => 'On WhatsApp, where you already work: load offers, confirmations, check calls and paperwork reminders.'],
        ],
    ],
    'hotshot' => [
        'path'    => '/hotshot-dispatch/',
        'image'   => ['base' => 'nf-setup-owner-operator', 'alt' => 'White semi truck parked alone in an empty lot at dusk, with bare trees behind'],   // foto ambiental provisional (páginas en draft)
        'name'    => 'Hotshot',
        'h1'      => 'Hotshot dispatch services',
        'tagline' => 'Fast loads, secured right',
        'lead'    => 'Hotshot freight is time-sensitive and often on open decks. We find loads that fit your pickup and trailer, confirm securement needs and keep the broker updated until delivery.',
        'sheet'   => ['equipment' => 'Heavy pickup + flatbed or gooseneck', 'freight' => 'Equipment, materials, expedited parts', 'confirm' => 'Weight, dimensions, securement'],
        'handles_title' => 'Built around your hotshot',
        'handles' => [
            ['Expedited load search', 'Time-sensitive loads that match your trailer length and weight limits.'],
            ['Securement details first', 'Straps, chains or tarps confirmed before you accept.'],
            ['Tracking & updates', 'The broker knows where the freight is without calling you.'],
            ['Paperwork handled', 'Rate cons, BOLs and PODs filed per load.'],
        ],
        'checks'  => [
            'Weight and dimensions fit your rig and your licence',
            'Securement and tarping requirements known up front',
            'Loading method confirmed at pickup',
            'Delivery window realistic for the miles',
        ],
        'needs'   => [
            'MC and USDOT authority',
            'Certificate of insurance',
            'W-9',
            'Your hotshot details and photos',
            'Preferred lanes and home time',
        ],
        'faq_title' => 'Hotshot dispatch questions',
        'faq'     => [
            ['q' => 'Do you dispatch hotshots under my own authority?', 'a' => 'Yes. We book loads under your MC and USDOT. You keep your authority and decide which loads to take.'],
            ['q' => 'Can I refuse a load you find?', 'a' => 'Always. We send the details first (rate, miles, stops and requirements) and book only what you approve.'],
            ['q' => 'How do you send updates?', 'a' => 'On WhatsApp, where you already work: load offers, confirmations, check calls and paperwork reminders.'],
        ],
    ],
    'flatbed' => [
        'path'    => '/flatbed-dispatch/',
        'image'   => ['base' => 'nf-setup-small-fleet', 'alt' => 'Several white semi trucks parked in a row in a depot lot at dusk'],   // foto ambiental provisional (páginas en draft)
        'name'    => 'Flatbed',
        'h1'      => 'Flatbed dispatch services',
        'tagline' => 'Open deck, no surprises',
        'lead'    => 'Flatbed loads come with dimensions, securement and tarps to plan. We book loads that fit your deck and equipment, confirm every detail with the broker and track the load to delivery.',
        'sheet'   => ['equipment' => 'Flatbed, step deck', 'freight' => 'Steel, lumber, building materials, machinery', 'confirm' => 'Dimensions, tarps, securement, permits'],
        'handles_title' => 'Built around your flatbed',
        'handles' => [
            ['Flatbed load search', 'Open-deck freight matched to your trailer type and length.'],
            ['Load details confirmed', 'Dimensions, weight, tarps and securement agreed before booking.'],
            ['Tracking & check calls', 'Location, ETA and any delay reported to the broker.'],
            ['Paperwork handled', 'Rate cons, BOLs, PODs and permits filed per load.'],
        ],
        'checks'  => [
            'Dimensions and weight fit your deck',
            'Tarp and securement requirements agreed and paid',
            'Oversize permits flagged when needed',
            'Loading and unloading method confirmed',
        ],
        'needs'   => [
            'MC and USDOT authority',
            'Certificate of insurance',
            'W-9',
            'Your flatbed details and photos',
            'Preferred lanes and home time',
        ],
        'faq_title' => 'Flatbed dispatch questions',
        'faq'     => [
            ['q' => 'Do you dispatch flatbeds under my own authority?', 'a' => 'Yes. We book loads under your MC and USDOT. You keep your authority and decide which loads to take.'],
            ['q' => 'Can I refuse a load you find?', 'a' => 'Always. We send the details first (rate, miles, stops and requirements) and book only what you approve.'],
            ['q' => 'How do you send updates?', 'a' => 'On WhatsApp, where you already work: load offers, confirmations, check calls and paperwork reminders.'],
        ],
    ],
    'power-only' => [
        'path'    => '/power-only-dispatch/',
        'image'   => ['base' => 'nf-setup-amazon-relay', 'alt' => 'White semi truck at a warehouse dock wall at night under tall floodlights'],   // foto ambiental de marca (no muestra al equipo real)
        'name'    => 'Power only',
        'h1'      => 'Power only dispatch services',
        'tagline' => 'Your tractor, their trailer',
        'lead'    => 'Power only loads let you haul trailers owned by shippers, brokers or Amazon. We find the loads, confirm trailer pickup and drop details and track every move.',
        'sheet'   => ['equipment' => 'Tractor with fifth wheel', 'freight' => 'Drop trailers and Amazon Relay power only loads', 'confirm' => 'Trailer location, interchange, drop yard'],
        'handles_title' => 'Built around your power only',
        'handles' => [
            ['Power only load search', 'Broker and Amazon Relay power-only freight that fits your lanes.'],
            ['Trailer logistics', 'Pickup yard, trailer number and drop location confirmed.'],
            ['Tracking & check calls', 'Every move logged, including empties.'],
            ['Paperwork handled', 'Interchange documents, BOLs and PODs filed per load.'],
        ],
        'checks'  => [
            'Trailer pickup and interchange details confirmed',
            'Trailer number and condition checked at pickup',
            'Drop location and hours confirmed',
            'Empty-trailer moves priced in',
        ],
        'needs'   => [
            'Your carrier details',
            'Your tractor details',
            'Preferred lanes and home time',
            'How you want to get updates',
        ],
        'faq_title' => 'Power only dispatch questions',
        'faq'     => [
            ['q' => 'Do you dispatch power only under my own authority?', 'a' => 'Yes. We book loads under your MC and USDOT. You keep your authority and decide which loads to take.'],
            ['q' => 'Can I refuse a load you find?', 'a' => 'Always. We send the details first (rate, miles, stops and requirements) and book only what you approve.'],
            ['q' => 'How do you send updates?', 'a' => 'On WhatsApp, where you already work: load offers, confirmations, check calls and paperwork reminders.'],
        ],
    ],
    'reefer' => [
        'path'    => '/reefer-dispatch/',
        'image'   => ['base' => 'nf-onboarding-run', 'alt' => 'White semi truck with a dry van trailer driving past at dusk, with the background blurred by motion'],   // foto ambiental provisional (páginas en draft)
        'name'    => 'Reefer',
        'h1'      => 'Reefer dispatch services',
        'tagline' => 'Temperature on record, every load',
        'lead'    => 'Refrigerated freight pays for precision. We book reefer loads that fit your unit and schedule, confirm temperature requirements and keep a record of every reading the broker may ask for.',
        'sheet'   => ['equipment' => 'Refrigerated trailer', 'freight' => 'Produce, dairy, frozen, pharma', 'confirm' => 'Set point, mode, pre-cool, appointment'],
        'handles_title' => 'Built around your reefer',
        'handles' => [
            ['Reefer load search', 'Temperature-controlled freight on your lanes, checked against your unit.'],
            ['Temperature instructions', 'Set point and continuous or cycle mode confirmed before pickup.'],
            ['Tracking with readings', 'ETAs, stops and temperature notes logged on the load.'],
            ['Claims-ready paperwork', 'BOLs, PODs and temperature records filed together.'],
        ],
        'checks'  => [
            'Set point and mode written on the BOL',
            'Trailer pre-cooled before loading',
            'Product temperature noted at pickup',
            'Delivery appointment confirmed with the receiver',
        ],
        'needs'   => [
            'MC and USDOT authority',
            'Certificate of insurance',
            'W-9',
            'Your reefer details and photos',
            'Preferred lanes and home time',
        ],
        'faq_title' => 'Reefer dispatch questions',
        'faq'     => [
            ['q' => 'Do you dispatch reefers under my own authority?', 'a' => 'Yes. We book loads under your MC and USDOT. You keep your authority and decide which loads to take.'],
            ['q' => 'Can I refuse a load you find?', 'a' => 'Always. We send the details first (rate, miles, stops and requirements) and book only what you approve.'],
            ['q' => 'How do you send updates?', 'a' => 'On WhatsApp, where you already work: load offers, confirmations, check calls and paperwork reminders.'],
        ],
    ],
];
