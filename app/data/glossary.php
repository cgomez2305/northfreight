<?php
/**
 * Glosario de términos (Glossary.html del prototipo). Orden alfabético; la letra se deriva del término.
 * Cada entrada: id (ancla #term-ID), term, def, link opcional ['label','path'].
 * Los enlaces a rutas draft (hotshot, power only, reefer) solo deben pintarse si route_is_live($path).
 */

return [
    [
        'id'   => 'accessorial',
        'term' => 'Accessorial',
        'def'  => 'Any charge on top of the line haul — detention, lumper, layover, TONU or extra stops. Agree on them in the rate confirmation.',
    ],
    [
        'id'   => 'bill',
        'term' => 'Bill of lading (BOL)',
        'def'  => 'The document that travels with the freight: what was loaded, by whom, for whom. Signed at pickup and delivery.',
        'link' => ['label' => 'Free template', 'path' => '/resources/bol-template/'],
    ],
    [
        'id'   => 'broker',
        'term' => 'Broker',
        'def'  => 'A licensed intermediary who connects shippers with carriers and pays the carrier for the load.',
    ],
    [
        'id'   => 'carrier',
        'term' => 'Carrier packet',
        'def'  => 'The set of documents a broker needs before booking you: authority, insurance certificate, W-9 and agreement.',
    ],
    [
        'id'   => 'check',
        'term' => 'Check call',
        'def'  => 'A status update to the broker during the trip — location, ETA and any problem.',
    ],
    [
        'id'   => 'deadhead',
        'term' => 'Deadhead',
        'def'  => 'Miles driven with an empty trailer, usually to reach the next pickup. They cost fuel and time without pay.',
    ],
    [
        'id'   => 'detention',
        'term' => 'Detention',
        'def'  => 'Pay for waiting at a facility beyond the free time in the rate confirmation.',
        'link' => ['label' => 'How to get it paid', 'path' => '/blog/lumper-fees-detention-pay/'],
    ],
    [
        'id'   => 'dry',
        'term' => 'Dry van',
        'def'  => 'An enclosed, non-refrigerated trailer — usually 53\' — for general freight.',
        'link' => ['label' => 'Dry van dispatch', 'path' => '/dry-van-dispatch/'],
    ],
    [
        'id'   => 'eld',
        'term' => 'ELD',
        'def'  => 'Electronic logging device. Records driving time to comply with hours-of-service rules.',
    ],
    [
        'id'   => 'factoring',
        'term' => 'Factoring',
        'def'  => 'Selling your invoices to a company that pays you quickly, minus a fee, and then collects from the broker.',
    ],
    [
        'id'   => 'fmcsa',
        'term' => 'FMCSA',
        'def'  => 'Federal Motor Carrier Safety Administration — the agency that issues USDOT numbers and operating authority.',
    ],
    [
        'id'   => 'hotshot',
        'term' => 'Hotshot',
        'def'  => 'Smaller, time-sensitive loads hauled with a heavy pickup and a flatbed or gooseneck trailer.',
        'link' => ['label' => 'Hotshot dispatch', 'path' => '/hotshot-dispatch/'],
    ],
    [
        'id'   => 'ifta',
        'term' => 'IFTA',
        'def'  => 'International Fuel Tax Agreement. Quarterly fuel-tax report by state for interstate carriers.',
        'link' => ['label' => 'IFTA calculator', 'path' => '/resources/ifta-calculator/'],
    ],
    [
        'id'   => 'lumper',
        'term' => 'Lumper',
        'def'  => 'A third-party crew that loads or unloads the trailer at a facility, paid at the dock and reimbursed with a receipt.',
    ],
    [
        'id'   => 'mc',
        'term' => 'MC number',
        'def'  => 'Motor carrier operating authority number issued by the FMCSA. Required to haul regulated freight for hire across state lines.',
    ],
    [
        'id'   => 'owner-operator',
        'term' => 'Owner-operator',
        'def'  => 'A driver who owns or leases the truck and runs it as a business, under their own authority or leased on.',
        'link' => ['label' => 'Dispatch for owner-operators', 'path' => '/dispatch-for-owner-operators/'],
    ],
    [
        'id'   => 'pod',
        'term' => 'POD',
        'def'  => 'Proof of delivery — the BOL signed by the receiver. You need it to invoice.',
    ],
    [
        'id'   => 'power',
        'term' => 'Power only',
        'def'  => 'Hauling a trailer owned by the shipper or broker with your tractor.',
        'link' => ['label' => 'Power only dispatch', 'path' => '/power-only-dispatch/'],
    ],
    [
        'id'   => 'rate',
        'term' => 'Rate confirmation',
        'def'  => 'The broker\'s written agreement for a load: rate, stops, times, free time and accessorials. Read it before you roll.',
    ],
    [
        'id'   => 'reefer',
        'term' => 'Reefer',
        'def'  => 'A refrigerated trailer for temperature-controlled freight.',
        'link' => ['label' => 'Reefer dispatch', 'path' => '/reefer-dispatch/'],
    ],
    [
        'id'   => 'rpm',
        'term' => 'RPM (rate per mile)',
        'def'  => 'What a load pays divided by its miles.',
        'link' => ['label' => 'Rate per mile calculator', 'path' => '/resources/rate-per-mile-calculator/'],
    ],
    [
        'id'   => 'scac',
        'term' => 'SCAC',
        'def'  => 'Standard Carrier Alpha Code — a two-to-four-letter code that identifies your company. Required by Amazon Relay and many shippers.',
    ],
    [
        'id'   => 'tonu',
        'term' => 'TONU',
        'def'  => 'Truck ordered, not used — a fee paid when a load is cancelled after the truck was dispatched.',
    ],
    [
        'id'   => 'usdot',
        'term' => 'USDOT number',
        'def'  => 'The FMCSA identification number for commercial vehicles, tied to your safety record.',
    ],
];
