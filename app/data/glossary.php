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
        'def'  => 'Any charge on top of the line haul, such as detention, lumper, layover, TONU or extra stops. Agree on them in the rate confirmation.',
    ],
    [
        'id'   => 'amazon-relay',
        'term' => 'Amazon Relay',
        'def'  => 'Amazon’s load board and app for carriers, where loads, trips and blocks are booked and followed.',
        'link' => ['label' => 'Amazon Relay dispatch', 'path' => '/amazon-relay-dispatch/'],
    ],
    [
        'id'   => 'backhaul',
        'term' => 'Backhaul',
        'def'  => 'A load that brings the truck back toward its home area after a delivery, so it does not return empty.',
    ],
    [
        'id'   => 'bill',
        'term' => 'Bill of lading (BOL)',
        'def'  => 'The document that travels with the freight: what was loaded, by whom, for whom. Signed at pickup and delivery.',
        'link' => ['label' => 'Free template', 'path' => '/resources/bol-template/'],
    ],
    [
        'id'   => 'block',
        'term' => 'Block (Amazon Relay)',
        'def'  => 'A group of Amazon Relay trips booked together as one assignment.',
    ],
    [
        'id'   => 'bobtail',
        'term' => 'Bobtail',
        'def'  => 'Driving the tractor without a trailer attached.',
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
        'def'  => 'A status update to the broker during the trip with your location, ETA and any problem.',
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
        'id'   => 'dispatch-fee',
        'term' => 'Dispatch fee',
        'def'  => 'What a dispatch service charges for finding and booking your loads and handling the work around them: a percentage of each load, a flat weekly fee or a fixed fee per load.',
        'link' => ['label' => 'How dispatch fees work', 'path' => '/blog/how-much-does-truck-dispatch-cost/'],
    ],
    [
        'id'   => 'dispatcher',
        'term' => 'Dispatcher',
        'def'  => 'The person or team that finds and books loads, deals with brokers, tracks every trip and keeps the paperwork that gets you paid.',
        'link' => ['label' => 'How dispatching works', 'path' => '/how-it-works/'],
    ],
    [
        'id'   => 'drop-hook',
        'term' => 'Drop and hook',
        'def'  => 'Dropping a trailer at the facility and hooking another one, so the driver does not wait for loading or unloading.',
    ],
    [
        'id'   => 'dry',
        'term' => 'Dry van',
        'def'  => 'An enclosed, non-refrigerated trailer, usually 53\', for general freight.',
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
        'def'  => 'Federal Motor Carrier Safety Administration, the agency that issues USDOT numbers and operating authority.',
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
        'id'   => 'lane',
        'term' => 'Lane',
        'def'  => 'A regular route between an origin area and a destination area, such as Dallas to Atlanta.',
    ],
    [
        'id'   => 'layover',
        'term' => 'Layover',
        'def'  => 'An accessorial charge when the driver has to wait overnight or longer for loading, unloading or the next load. Agree on it in the rate confirmation.',
    ],
    [
        'id'   => 'line-haul',
        'term' => 'Line haul',
        'def'  => 'The base rate for moving the load from pickup to delivery, before accessorials and fuel.',
    ],
    [
        'id'   => 'load-board',
        'term' => 'Load board',
        'def'  => 'A marketplace where brokers and shippers post loads and carriers search for them.',
        'link' => ['label' => 'Amazon Relay load board', 'path' => '/blog/amazon-relay-load-board/'],
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
        'def'  => 'Proof of delivery. It is the BOL signed by the receiver, and you need it to invoice.',
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
        'def'  => 'Standard Carrier Alpha Code, a two-to-four-letter code that identifies your company. Required by Amazon Relay and many shippers.',
    ],
    [
        'id'   => 'settlement',
        'term' => 'Settlement',
        'def'  => 'The statement of what a carrier is paid for its loads, after deductions such as fees or advances. Check it line by line.',
        'link' => ['label' => 'Trucking back office', 'path' => '/trucking-back-office/'],
    ],
    [
        'id'   => 'spot-market',
        'term' => 'Spot market',
        'def'  => 'Loads booked one at a time at the current market rate, as opposed to a contracted or dedicated lane.',
    ],
    [
        'id'   => 'tonu',
        'term' => 'TONU',
        'def'  => 'Truck ordered, not used. It is a fee paid when a load is cancelled after the truck was dispatched.',
    ],
    [
        'id'   => 'usdot',
        'term' => 'USDOT number',
        'def'  => 'The FMCSA identification number for commercial vehicles, tied to your safety record.',
    ],
];
