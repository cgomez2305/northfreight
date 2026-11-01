<?php
/**
 * Mapa URL -> plantilla + meta + schema + estado (§4).
 *
 * Clave = ruta con barra inicial y final. Campos de cada ruta:
 *   template   plantilla en templates/pages/{template}.php
 *   title      <= 60 caracteres      meta  <= 155 caracteres      h1  único por página
 *   schema     tipos JSON-LD (ver page_schema() en helpers.php)
 *   status     'live' | 'draft' (draft = 404 salvo ?preview=TOKEN válido)
 *   sitemap    bool            priority  0.0-1.0
 *   wa         clave de WA_MESSAGES (mensaje de WhatsApp propio de la página)
 *   breadcrumb etiqueta corta de las migas
 *   parent     (opcional) ruta padre para las migas; sin parent: Home > esta página
 *   faq        clave de app/data/faq.php (o null)
 *   motion     bool, true => la página carga GSAP/motion.js (home y servicios)
 *   extras     doc (legal), equip (equipment), slug (post)
 *
 * La clave especial '404' no es una URL: es la ruta que usa index.php para el error 404.
 * Title/meta/h1 de las páginas del doc de copy son EXACTOS; los marcados TODO no están en el doc.
 */

return [

    // ------------------------------------------------------------------
    // FASE 1 — LIVE
    // ------------------------------------------------------------------
    '/' => [
        'template'   => 'home',
        'title'      => 'Truck Dispatch Services for Owner-Operators | NorthStar',
        'meta'       => 'Truck dispatch for owner-operators and small fleets: Amazon Relay and dry van booking, load tracking and back office. Message a dispatcher on WhatsApp.',
        'h1'         => 'Truck dispatch services for owner-operators & small fleets',
        'schema'     => ['Organization', 'ProfessionalService', 'FAQPage'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 1.0,
        'wa'         => 'default',
        'breadcrumb' => 'Home',
        'faq'        => 'home',
        'motion'     => true,
    ],
    '/dispatch-for-owner-operators/' => [
        'template'   => 'owner',
        'title'      => 'Dispatch Services for Owner-Operators | NorthStar Freight',
        'meta'       => 'Independent truck dispatcher for owner-operators: we book loads, handle brokers and paperwork, and track every trip. One desk, updates on WhatsApp.',
        'h1'         => 'Dispatch services for owner-operators',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.9,
        'wa'         => 'owner',
        'breadcrumb' => 'Owner-operators',
        'faq'        => 'owner',
        'motion'     => true,
    ],
    '/amazon-relay-dispatch/' => [
        'template'   => 'relay',
        'title'      => 'Amazon Relay Dispatch Service | NorthStar Freight',
        'meta'       => 'Amazon Relay dispatch for carriers: load board monitoring, block booking, trip records, scorecard follow-up and disputes filed with evidence.',
        'h1'         => 'Amazon Relay dispatch, handled end to end',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.9,
        'wa'         => 'relay',
        'breadcrumb' => 'Amazon Relay',
        'faq'        => 'relay',
        'motion'     => true,
    ],
    '/dry-van-dispatch/' => [
        'template'   => 'dryvan',
        'title'      => 'Dry Van Dispatch Service | NorthStar Freight',
        'meta'       => 'Dry van dispatch for owner-operators and small fleets: load search, rate negotiation, check calls and paperwork from one dispatch desk.',
        'h1'         => 'Dry van dispatch service',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.8,
        'wa'         => 'dryvan',
        'breadcrumb' => 'Dry van',
        'faq'        => 'dryvan',
        'motion'     => true,
    ],
    '/load-tracking/' => [
        'template'   => 'tracking',
        'title'      => 'Truck Load Tracking & Check Calls | NorthStar Freight',
        'meta'       => 'We follow every load from pickup to POD: check calls, ETAs, arrival timestamps and incident reports, so brokers stay informed and you get paid.',
        'h1'         => 'Load tracking and check calls for your trucks',
        'schema'     => ['Service', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.8,
        'wa'         => 'tracking',
        'breadcrumb' => 'Load tracking',
        'faq'        => null,
        'motion'     => true,
    ],
    '/trucking-back-office/' => [
        'template'   => 'backoffice',
        'title'      => 'Trucking Back Office Services | NorthStar Freight',
        'meta'       => 'Back office for trucking companies: settlement preparation, dispute management, document organization and driver onboarding, done by your dispatch desk.',
        'h1'         => 'Trucking back office services',
        'schema'     => ['Service', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.8,
        'wa'         => 'backoffice',
        'breadcrumb' => 'Back office',
        'faq'        => null,
        'motion'     => true,
    ],
    '/how-it-works/' => [
        'template'   => 'how',
        'title'      => 'How Truck Dispatching Works With NorthStar',
        'meta'       => 'How we plug into your trucking operation in four steps: we learn your fleet, agree on scope and contacts, run the day-to-day and review together.',
        'h1'         => 'How we plug into your operation',
        'schema'     => ['BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'default',
        'breadcrumb' => 'How it works',
        'faq'        => null,
        'motion'     => false,
    ],
    '/our-desk/' => [
        'template'   => 'desk',
        'title'      => 'About NorthStar Freight Solutions | Our Dispatch Desk',
        'meta'       => 'Meet the NorthStar dispatch desk: the people who book, track and document your loads. Watch how we work and why we run operations as a team.',
        'h1'         => 'Built by dispatchers. Run as a team.',
        'schema'     => ['BreadcrumbList'],   // VideoObject se añade cuando exista el video (§7.6)
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.6,
        'wa'         => 'default',
        'breadcrumb' => 'Our desk',
        'faq'        => null,
        'motion'     => false,
    ],
    '/contact/' => [
        'template'   => 'contact',
        'title'      => 'Contact NorthStar Freight | Talk to a Dispatcher',
        'meta'       => 'Talk to a NorthStar dispatcher on WhatsApp at +1 863-651-3322 or email nfreightsolutionsllc@gmail.com. No forms first: tell us about your trucks.',
        'h1'         => 'Talk to a dispatcher',
        'schema'     => ['BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'default',
        'breadcrumb' => 'Contact',
        'faq'        => null,
        'motion'     => false,
    ],
    // Legales: título/H1 del prototipo (no están en el doc de copy). Texto pendiente de abogado.
    '/privacy-policy/' => [
        'template'   => 'legal',
        'doc'        => 'privacy',
        'title'      => 'Privacy Policy | NorthStar Freight Solutions',
        'meta'       => 'How NorthStar Freight Solutions LLC collects, uses and protects the information you share through this website, WhatsApp and email.',
        'h1'         => 'Privacy policy',
        'schema'     => ['BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.3,
        'wa'         => 'default',
        'breadcrumb' => 'Privacy policy',
        'faq'        => null,
        'motion'     => false,
    ],
    '/terms/' => [
        'template'   => 'legal',
        'doc'        => 'terms',
        'title'      => 'Terms of Use | NorthStar Freight Solutions',
        'meta'       => 'The rules for using the NorthStar website and the free tools and templates published on it. Results are estimates, not legal or tax advice.',
        'h1'         => 'Terms of use',
        'schema'     => ['BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.3,
        'wa'         => 'default',
        'breadcrumb' => 'Terms',
        'faq'        => null,
        'motion'     => false,
    ],

    // ------------------------------------------------------------------
    // RESOURCES + BLOG — LIVE desde la Fase 1 (cambio de alcance: replicar la maqueta)
    // title/meta/h1 NO están en el doc de copy: TODO verificar con doc de copy (keywords de §7.2/§7.3).
    // ------------------------------------------------------------------
    '/resources/' => [
        'template'   => 'resources',
        'title'      => 'Free Trucking Resources for Owner-Operators | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Free trucking tools, templates and guides from a dispatch desk: IFTA and rate per mile calculators, BOL template, agreement, glossary and blog.', // TODO
        'h1'         => 'Free trucking resources from the desk',
        'schema'     => ['BreadcrumbList', 'ItemList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'default',
        'breadcrumb' => 'Resources',
        'faq'        => null,
        'motion'     => false,
    ],
    '/resources/bol-template/' => [
        'template'   => 'doc-bol',
        'title'      => 'Bill of Lading Template (PDF & Word) | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Free bill of lading (BOL) template in PDF and Word: every field brokers and receivers expect, ready to print. The same BOL our desk files on every load.', // TODO
        'h1'         => 'Bill of lading template',
        'schema'     => ['Article', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.8,
        'wa'         => 'default',
        'breadcrumb' => 'Bill of lading template',
        'parent'     => '/resources/',
        'faq'        => null,
        'motion'     => false,
    ],
    '/resources/ifta-calculator/' => [
        'template'   => 'tool-ifta',
        'title'      => 'IFTA Calculator for Owner-Operators | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Free IFTA calculator: enter miles and gallons by state to see fleet MPG, net taxable gallons and an estimated quarterly fuel tax. Estimates only.', // TODO
        'h1'         => 'IFTA calculator',
        'schema'     => ['WebApplication', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.8,
        'wa'         => 'default',
        'breadcrumb' => 'IFTA calculator',
        'parent'     => '/resources/',
        'faq'        => null,
        'motion'     => false,
    ],
    '/resources/rate-per-mile-calculator/' => [
        'template'   => 'tool-rpm',
        'title'      => 'Rate Per Mile Calculator for Truckers | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Free rate per mile calculator: loaded and all-in RPM, fuel cost and what is left after the trip. Know if a load pays before you book it.', // TODO
        'h1'         => 'Rate per mile calculator',
        'schema'     => ['WebApplication', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'default',
        'breadcrumb' => 'Rate per mile calculator',
        'parent'     => '/resources/',
        'faq'        => null,
        'motion'     => false,
    ],
    '/resources/dispatcher-carrier-agreement/' => [
        'template'   => 'doc-agreement',
        'title'      => 'Dispatcher-Carrier Agreement Template | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Dispatcher carrier agreement template: what a fair dispatch contract covers, from scope and fees to your right to refuse loads. Not legal advice.', // TODO
        'h1'         => 'Dispatcher-carrier agreement',
        'schema'     => ['Article', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.6,
        'wa'         => 'default',
        'breadcrumb' => 'Dispatcher-carrier agreement',
        'parent'     => '/resources/',
        'faq'        => null,
        'motion'     => false,
    ],
    '/resources/glossary/' => [
        'template'   => 'glossary',
        'title'      => 'Trucking Glossary: Dispatch Terms Explained | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Trucking glossary from a dispatch desk: deadhead, TONU, rate con, lumper, detention and more, explained in one or two lines.', // TODO
        'h1'         => 'Trucking glossary',
        'schema'     => ['DefinedTermSet', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.6,
        'wa'         => 'default',
        'breadcrumb' => 'Trucking glossary',
        'parent'     => '/resources/',
        'faq'        => null,
        'motion'     => false,
    ],
    '/blog/' => [
        'template'   => 'blog',
        'title'      => 'Truck Dispatch Blog for Owner-Operators | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Practical guides for owner-operators and small fleets: what dispatch costs, how Amazon Relay works and how to get paid for lumper and detention.', // TODO
        'h1'         => 'From the dispatch desk',
        'schema'     => ['BreadcrumbList', 'ItemList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'default',
        'breadcrumb' => 'Blog',
        'parent'     => '/resources/',
        'faq'        => null,
        'motion'     => false,
    ],
    '/blog/how-much-does-truck-dispatch-cost/' => [
        'template'   => 'post',
        'slug'       => 'how-much-does-truck-dispatch-cost',
        'title'      => 'How Much Does Truck Dispatch Service Cost? | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Dispatch fees explained: percentage of the gross, flat weekly or per load. See what each model costs an owner-operator and what should be included.', // TODO
        'h1'         => 'How much does truck dispatch service cost?',
        'schema'     => ['Article', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'post',
        'breadcrumb' => 'How much does truck dispatch service cost?',
        'parent'     => '/blog/',
        'faq'        => null,
        'motion'     => false,
    ],
    '/blog/amazon-relay-requirements/' => [
        'template'   => 'post',
        'slug'       => 'amazon-relay-requirements',
        'title'      => 'Amazon Relay Requirements for Carriers | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Amazon Relay requirements for carriers: authority, SCAC code, insurance, equipment and the steps to get approved, explained in plain language.', // TODO
        'h1'         => 'Amazon Relay requirements for carriers',
        'schema'     => ['Article', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'post',
        'breadcrumb' => 'Amazon Relay requirements',
        'parent'     => '/blog/',
        'faq'        => null,
        'motion'     => false,
    ],
    '/blog/amazon-relay-load-board/' => [
        'template'   => 'post',
        'slug'       => 'amazon-relay-load-board',
        'title'      => 'How the Amazon Relay Load Board Works | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'How the Amazon Relay load board works: one-way loads, round trips and blocks, and how loads are posted, booked and tracked in the Relay app.', // TODO
        'h1'         => 'How the Amazon Relay load board works',
        'schema'     => ['Article', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'post',
        'breadcrumb' => 'Amazon Relay load board',
        'parent'     => '/blog/',
        'faq'        => null,
        'motion'     => false,
    ],
    '/blog/lumper-fees-detention-pay/' => [
        'template'   => 'post',
        'slug'       => 'lumper-fees-detention-pay',
        'title'      => 'Lumper Fees & Detention Pay: Get Paid for Both | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Lumper fees and detention pay: what to document at the dock so lumper receipts and detention hours show up on your settlement.', // TODO
        'h1'         => 'Lumper fees and detention pay: how to get paid for both',
        'schema'     => ['Article', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'post',
        'breadcrumb' => 'Lumper fees and detention pay',
        'parent'     => '/blog/',
        'faq'        => null,
        'motion'     => false,
    ],

    // ------------------------------------------------------------------
    // PÁGINAS POR EQUIPO — DRAFT hasta que el cliente confirme (§7.4). Datos en app/data/equipment.php.
    // title/meta/h1 de la maqueta + keyword de §7.4. TODO verificar con doc de copy.
    // ------------------------------------------------------------------
    '/box-truck-dispatch/' => [
        'template'   => 'equipment',
        'equip'      => 'box',
        'title'      => 'Box Truck Dispatch Service | NorthStar Freight', // TODO
        'meta'       => 'Box truck dispatch for owner-operators: we find loads that fit a straight truck and handle brokers, check calls and paperwork from one dispatch desk.', // TODO
        'h1'         => 'Box truck dispatch services',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'draft',
        'sitemap'    => false,
        'priority'   => 0.7,
        'wa'         => 'equip-box',
        'breadcrumb' => 'Box truck',
        'faq'        => null,   // las FAQ viven en app/data/equipment.php
        'motion'     => true,
    ],
    '/hotshot-dispatch/' => [
        'template'   => 'equipment',
        'equip'      => 'hotshot',
        'title'      => 'Hotshot Dispatch Services | NorthStar Freight', // TODO
        'meta'       => 'Hotshot dispatch for owner-operators: time-sensitive loads that fit your pickup and trailer, securement confirmed first and brokers kept updated.', // TODO
        'h1'         => 'Hotshot dispatch services',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'draft',
        'sitemap'    => false,
        'priority'   => 0.6,
        'wa'         => 'equip-hotshot',
        'breadcrumb' => 'Hotshot',
        'faq'        => null,
        'motion'     => true,
    ],
    '/flatbed-dispatch/' => [
        'template'   => 'equipment',
        'equip'      => 'flatbed',
        'title'      => 'Flatbed Dispatch Services | NorthStar Freight', // TODO
        'meta'       => 'Flatbed dispatch for owner-operators: loads matched to your deck, dimensions, tarps and securement confirmed, and every load tracked to delivery.', // TODO
        'h1'         => 'Flatbed dispatch services',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'draft',
        'sitemap'    => false,
        'priority'   => 0.6,
        'wa'         => 'equip-flatbed',
        'breadcrumb' => 'Flatbed',
        'faq'        => null,
        'motion'     => true,
    ],
    '/power-only-dispatch/' => [
        'template'   => 'equipment',
        'equip'      => 'power-only',
        'title'      => 'Power Only Dispatch Service | NorthStar Freight', // TODO
        'meta'       => 'Power only dispatch for owner-operators: loads for your tractor, trailer pickup and drop details confirmed, and every move tracked and documented.', // TODO
        'h1'         => 'Power only dispatch services',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'draft',
        'sitemap'    => false,
        'priority'   => 0.6,
        'wa'         => 'equip-power-only',
        'breadcrumb' => 'Power only',
        'faq'        => null,
        'motion'     => true,
    ],
    '/reefer-dispatch/' => [
        'template'   => 'equipment',
        'equip'      => 'reefer',
        'title'      => 'Reefer Dispatch Service | NorthStar Freight', // TODO
        'meta'       => 'Reefer dispatch for owner-operators: temperature-controlled loads that fit your unit, set points confirmed and temperature records kept per load.', // TODO
        'h1'         => 'Reefer dispatch services',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'draft',
        'sitemap'    => false,
        'priority'   => 0.6,
        'wa'         => 'equip-reefer',
        'breadcrumb' => 'Reefer',
        'faq'        => null,
        'motion'     => true,
    ],

    // ------------------------------------------------------------------
    // ERROR 404 (clave especial, no es una URL)
    // ------------------------------------------------------------------
    '404' => [
        'template'   => '404',
        'title'      => 'Page Not Found | NorthStar Freight',
        'meta'       => 'The page you are looking for does not exist. Talk to a NorthStar dispatcher on WhatsApp or go back to the home page.',
        'h1'         => 'Page not found',
        'schema'     => [],
        'status'     => 'error',
        'sitemap'    => false,
        'priority'   => 0.0,
        'wa'         => 'default',
        'breadcrumb' => 'Page not found',
        'faq'        => null,
        'motion'     => false,
    ],
];

// ----------------------------------------------------------------------
// FASE 2 (no registrar todavía)
// ----------------------------------------------------------------------
// /blog/how-to-dispatch-a-truck/   (pendiente de escribir; no existe en la maqueta)
// /blog/freight-factoring/ · /blog/amazon-relay-review/ · /blog/freight-broker-vs-dispatcher/
// /resources/glossary/{término}/   (una URL por término del glosario)
// /es/...                          (versión en español + hreflang)
