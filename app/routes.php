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
 *   crumbs     (opcional) migas completas [[label, path], …] (incluye Home); anula a 'parent' en la miga visible y en el JSON-LD
 *   cta        (opcional) false => el layout no imprime el bloque CTA final
 *   css / js   (opcional) listas de grupos de CSS (assets/css/pages/*.css) y módulos JS (assets/js/*.js) que anulan los de la plantilla (solo lo que esa página usa)
 *   motion     (opcional) true => carga GSAP + ScrollTrigger + motion.js + motion.css (escenas de scroll y parallax de fotos)
 *   faq        clave de app/data/faq.php (o null)
 *   extras     doc (legal), equip (equipment), slug (post), job (vacante: slug de app/data/jobs.php)
 *   lastmod    (opcional) 'YYYY-MM-DD' real del último cambio de contenido: lo único que emite <lastmod> en el sitemap (no se inventan fechas)
 *   noindex    (opcional) true => <meta robots noindex, follow> en producción, aunque la ruta sea live (apply, gracias); no entra en sitemap ni llms
 *   gate       (opcional) 'lead-thanks' => solo se sirve con el token firmado de un envío real (?t=…); sin él, 303 a /careers/apply/ (sin sesiones)
 *   needs_files (opcional) lista de archivos bajo assets/ que la página promete (descargas). En ENV=production, si falta alguno la ruta pasa a draft (404, fuera de sitemap, llms, menús y enlaces); en staging se ve igual para poder revisar el diseño
 *   schema_name (opcional) nombre del nodo Service/WebPage cuando el H1 no sirve como nombre
 *
 * La clave especial '404' no es una URL: es la ruta que usa index.php para el error 404.
 * Title/meta/h1 de las páginas del doc de copy son EXACTOS; los marcados TODO no están en el doc.
 */

$routes = [

    // ------------------------------------------------------------------
    // FASE 1 — LIVE
    // ------------------------------------------------------------------
    '/' => [
        'template'   => 'home',
        'motion'     => true,
        'title'      => 'Truck Dispatch Services | Remote Dispatch Desk | NorthStar',
        'meta'       => 'Truck dispatch services from a remote dispatch company: Amazon Relay and dry van booking, load tracking and back office. Message a dispatcher on WhatsApp.',
        'h1'         => 'Truck dispatch services for owner-operators & small fleets',
        'schema'     => ['Organization', 'ProfessionalService', 'FAQPage'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 1.0,
        'wa'         => 'default',
        'breadcrumb' => 'Home',
        'faq'        => 'home',
    ],
    '/dispatch-for-owner-operators/' => [
        'template'   => 'owner',
        'motion'     => true,
        'title'      => 'Dispatch Services for Owner-Operators | NorthStar Freight',
        'meta'       => 'Dispatch services for owner-operators: your dispatch desk books your loads, handles brokers and tracks every trip. Updates on WhatsApp.',
        'h1'         => 'Dispatch services for owner-operators',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.9,
        'wa'         => 'owner',
        'breadcrumb' => 'Owner-operators',
        'faq'        => 'owner',
    ],
    '/amazon-relay-dispatch/' => [
        'template'   => 'relay',
        'motion'     => true,
        'title'      => 'Amazon Relay Dispatch Service | NorthStar Freight',
        'meta'       => 'Amazon Relay dispatch for carriers: load board monitoring, block booking, trip records, scorecard follow-up and disputes filed with evidence.',
        'h1'         => 'Amazon Relay dispatch, handled end to end',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.9,
        'wa'         => 'relay',
        'breadcrumb' => 'Amazon Relay',
        'crumbs'     => [['Home', '/'], ['Amazon Relay dispatch', '/amazon-relay-dispatch/']],   // maqueta
        'faq'        => 'relay',
    ],
    '/dry-van-dispatch/' => [
        'template'   => 'dryvan',
        'motion'     => true,
        'title'      => 'Dry Van Dispatch Service | NorthStar Freight',
        'meta'       => 'Dry van dispatch for owner-operators and small fleets: load search, rate negotiation, check calls and paperwork from one dispatch desk.',
        'h1'         => 'Dry van dispatch service',
        'schema'     => ['Service', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.8,
        'wa'         => 'dryvan',
        'breadcrumb' => 'Dry van',
        'crumbs'     => [['Home', '/'], ['Dry van dispatch', '/dry-van-dispatch/']],   // maqueta
        'faq'        => 'dryvan',
    ],
    '/load-tracking/' => [
        'template'   => 'tracking',
        'motion'     => true,
        'title'      => 'Truck Load Tracking & Check Calls | NorthStar Freight',
        'meta'       => 'Truck load tracking and check calls: we follow every load from pickup to POD with ETAs, arrival timestamps and incident reports, so brokers stay informed.',
        'h1'         => 'Load tracking and check calls for your trucks',
        'schema'     => ['Service', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.8,
        'wa'         => 'tracking',
        'breadcrumb' => 'Load tracking',
        'faq'        => null,
    ],
    '/trucking-back-office/' => [
        'template'   => 'backoffice',
        'motion'     => true,
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
    ],
    '/how-it-works/' => [
        'template'   => 'how',
        'motion'     => true,
        'title'      => 'How Truck Dispatching Works With NorthStar',
        'meta'       => 'How we plug into your trucking operation in four steps: we learn your fleet, agree on scope and contacts, run the day-to-day and review together.',
        'h1'         => 'How truck dispatching works with NorthStar',
        'schema'     => ['BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'default',
        'breadcrumb' => 'How it works',
        'faq'        => null,
    ],
    '/our-desk/' => [
        'template'   => 'desk',
        'motion'     => true,
        'title'      => 'About NorthStar Freight Solutions | Our Dispatch Desk',
        'meta'       => 'Meet the NorthStar dispatch desk: the people who book, track and document your loads, and why we run operations as a team.',
        'h1'         => 'Built by dispatchers. Run as a team.',
        'schema'     => ['BreadcrumbList'],   // VideoObject se añade cuando exista el video (§7.6)
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.6,
        'wa'         => 'default',
        'breadcrumb' => 'Our desk',
        'faq'        => null,
    ],
    '/contact/' => [
        'template'   => 'contact',
        'motion'     => true,
        'form'       => 'contact',   // handler POST en app/forms/contact.php
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
        'cta'        => false,   // la maqueta no tiene bloque CTA final
    ],
    // Legales: título/H1 del prototipo (no están en el doc de copy). Texto pendiente de abogado.
    '/privacy-policy/' => [
        'template'   => 'legal',
        'motion'     => true,
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
        'cta'        => false,   // la maqueta no tiene bloque CTA final
    ],
    '/terms/' => [
        'template'   => 'legal',
        'motion'     => true,
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
        'cta'        => false,   // la maqueta no tiene bloque CTA final
    ],

    // ------------------------------------------------------------------
    // RESOURCES + BLOG — LIVE desde la Fase 1 (cambio de alcance: replicar la maqueta)
    // title/meta/h1 NO están en el doc de copy: TODO verificar con doc de copy (keywords de §7.2/§7.3).
    // ------------------------------------------------------------------
    '/resources/' => [
        'template'   => 'resources',
        'motion'     => true,
        'js'         => ['calc-rpm.js'],   // mini calculadora de rate per mile (partial minicalc); su CSS va en company.css y blog.css
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
    ],
    '/resources/bol-template/' => [
        'template'   => 'doc-bol',
        'motion'     => true,
        'form'       => 'download',   // handler POST en app/forms/download.php
        'needs_files' => ['downloads/bol-template.pdf', 'downloads/bol-template.docx'],   // en producción la ruta es draft (404) si falta alguno
        'title'      => 'BOL Template (Bill of Lading) in PDF & Word | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Free bill of lading (BOL) template in PDF and Word: every field brokers and receivers expect, ready to print. The same BOL our desk files on every load.', // TODO
        'h1'         => 'Bill of lading template',
        'schema'     => ['Article', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.8,
        'wa'         => 'default',
        'breadcrumb' => 'Bill of lading template',
        'parent'     => '/resources/',
        'faq'        => 'doc-bol',
    ],
    '/resources/ifta-calculator/' => [
        'template'   => 'tool-ifta',
        'motion'     => true,
        'title'      => 'IFTA Calculator for Owner-Operators | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Free IFTA calculator: enter miles and gallons by state to see fleet MPG, net taxable gallons and an estimated quarterly fuel tax. Estimates only.', // TODO
        'h1'         => 'IFTA calculator',
        'schema'     => ['WebApplication', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.8,
        'wa'         => 'default',
        'breadcrumb' => 'IFTA calculator',
        'parent'     => '/resources/',
        'faq'        => 'tool-ifta',
    ],
    '/resources/rate-per-mile-calculator/' => [
        'template'   => 'tool-rpm',
        'motion'     => true,
        'title'      => 'Rate Per Mile Calculator for Truckers | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Free rate per mile calculator: loaded and all-in RPM, fuel cost and what is left after the trip. Know if a load pays before you book it.', // TODO
        'h1'         => 'Rate per mile calculator',
        'schema'     => ['WebApplication', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'default',
        'breadcrumb' => 'Rate per mile calculator',
        'parent'     => '/resources/',
        'faq'        => 'tool-rpm',
    ],
    '/resources/dispatcher-carrier-agreement/' => [
        'template'   => 'doc-agreement',
        'motion'     => true,
        'form'       => 'download',   // handler POST en app/forms/download.php
        'needs_files' => ['downloads/dispatcher-carrier-agreement.docx'],   // en producción la ruta es draft (404) mientras no exista el archivo revisado por el abogado
        'title'      => 'Dispatcher-Carrier Agreement Template | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Dispatcher carrier agreement template: what a fair dispatch contract covers, from scope and fees to your right to refuse loads. Not legal advice.', // TODO
        'h1'         => 'Dispatcher-carrier agreement',
        'schema'     => ['Article', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.6,
        'wa'         => 'default',
        'breadcrumb' => 'Dispatcher-carrier agreement',
        'parent'     => '/resources/',
        'faq'        => 'doc-agreement',
    ],
    '/resources/glossary/' => [
        'template'   => 'glossary',
        'motion'     => true,
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
    ],
    '/blog/' => [
        'template'   => 'blog',
        'motion'     => true,
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
    ],
    '/blog/how-much-does-truck-dispatch-cost/' => [
        'template'   => 'post',
        'motion'     => true,
        'js'         => ['calc-rpm.js'],
        'slug'       => 'how-much-does-truck-dispatch-cost',
        'title'      => 'How Much Does Truck Dispatch Service Cost? | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Dispatch fees explained: percentage of the gross, flat weekly or per load. See what each model costs an owner-operator and what should be included.', // TODO
        'h1'         => 'How much does truck dispatch service cost?',
        'schema'     => ['Article', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'post',
        'breadcrumb' => 'How much does truck dispatch service cost?',
        'parent'     => '/blog/',
        'faq'        => 'post-how-much-does-truck-dispatch-cost',
    ],
    '/blog/amazon-relay-requirements/' => [
        'template'   => 'post',
        'motion'     => true,
        'slug'       => 'amazon-relay-requirements',
        'title'      => 'Amazon Relay Requirements for Carriers | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Amazon Relay requirements for carriers: authority, SCAC code, insurance, equipment and the steps to get approved, explained in plain language.', // TODO
        'h1'         => 'Amazon Relay requirements for carriers',
        'schema'     => ['Article', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'post',
        'breadcrumb' => 'Amazon Relay requirements',
        'parent'     => '/blog/',
        'faq'        => 'post-amazon-relay-requirements',
    ],
    '/blog/amazon-relay-load-board/' => [
        'template'   => 'post',
        'motion'     => true,
        'slug'       => 'amazon-relay-load-board',
        'title'      => 'How the Amazon Relay Load Board Works | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'How the Amazon Relay load board works: one-way loads, round trips and blocks, and how loads are posted, booked and tracked in the Relay app.', // TODO
        'h1'         => 'How the Amazon Relay load board works',
        'schema'     => ['Article', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'post',
        'breadcrumb' => 'Amazon Relay load board',
        'parent'     => '/blog/',
        'faq'        => 'post-amazon-relay-load-board',
    ],
    '/blog/lumper-fees-detention-pay/' => [
        'template'   => 'post',
        'motion'     => true,
        'js'         => ['calc-rpm.js'],
        'slug'       => 'lumper-fees-detention-pay',
        'title'      => 'Lumper Fees & Detention Pay: Get Paid for Both | NorthStar', // TODO verificar con doc de copy
        'meta'       => 'Lumper fees and detention pay: what to document at the dock so lumper receipts and detention hours show up on your settlement.', // TODO
        'h1'         => 'Lumper fees and detention pay: how to get paid for both',
        'schema'     => ['Article', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'post',
        'breadcrumb' => 'Lumper fees and detention pay',
        'parent'     => '/blog/',
        'faq'        => 'post-lumper-fees-detention-pay',
    ],

    // ------------------------------------------------------------------
    // CARRERAS Y CAPTACIÓN DE CONDUCTORES (brief P). NorthStar es un servicio de dispatch, no un empleador de owner-operators: JobPosting solo en vacantes reales (jobs.php).
    // title/meta/h1 no están en el doc de copy: TODO verificar con Carlos. /careers/owner-operators/ apunta a "owner operator jobs" y "partner with a dispatcher", NO a
    // "dispatch services for owner-operators" (esa keyword es de /dispatch-for-owner-operators/).
    // ------------------------------------------------------------------
    '/careers/' => [
        'template'   => 'careers',
        'motion'     => true,
        'title'      => 'Truck Driver Jobs & Owner Operator Partnerships | NorthStar', // TODO
        'meta'       => 'Truck driver jobs and owner operator partnerships: NorthStar is a dispatch service, not a job offer. Partner with the desk or see company driver openings.', // TODO
        'h1'         => 'Truck driver jobs & owner operator partnerships',
        'schema'     => ['WebPage', 'FAQPage', 'BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => true,
        'priority'   => 0.7,
        'wa'         => 'careers',
        'breadcrumb' => 'Careers',
        'faq'        => 'careers',
    ],
    '/careers/owner-operators/' => [
        'template'    => 'careers-owner',
        'motion'      => true,
        'title'       => 'Owner Operator Jobs: Partner With a Dispatcher | NorthStar', // TODO
        'meta'        => 'Owner operator jobs? NorthStar is a dispatch partnership, not a job offer: you approve every load and the desk books, tracks and documents them.', // TODO
        'h1'          => 'Owner operator jobs: partner with a dispatcher',
        'schema'      => ['Service', 'FAQPage', 'BreadcrumbList'],
        'schema_name' => 'Dispatch partnership for owner-operators',
        'status'      => 'live',
        'sitemap'     => true,
        'priority'    => 0.8,
        'wa'          => 'careers-owner',
        'breadcrumb'  => 'Owner operators',
        'parent'      => '/careers/',
        'faq'         => 'careers-owner',
    ],
    '/careers/apply/' => [
        'template'   => 'apply',
        'motion'     => true,
        'form'       => 'lead',   // handler POST en app/forms/lead.php
        'js'         => ['apply.js'],
        'noindex'    => true,     // formulario sin contenido propio: no se indexa ni entra en sitemap/llms (el contenido está en /careers/ y /careers/owner-operators/)
        'title'      => 'Start Your Application | NorthStar Freight', // TODO
        'meta'       => 'Apply to work with the NorthStar dispatch desk: four short steps, no phone call first. Message us on WhatsApp if you prefer.', // TODO
        'h1'         => 'Start your application',
        'schema'     => ['BreadcrumbList'],
        'status'     => 'live',
        'sitemap'    => false,
        'priority'   => 0.4,
        'wa'         => 'careers',
        'breadcrumb' => 'Apply',
        'parent'     => '/careers/',
        'faq'        => null,
        'cta'        => false,
    ],
    '/careers/thank-you/' => [
        'template'   => 'thanks',
        'motion'     => true,
        'gate'       => 'lead-thanks',
        'noindex'    => true,
        'title'      => 'Application Received | NorthStar Freight', // TODO
        'meta'       => 'We received your application. Here is what happens next with the NorthStar dispatch desk.', // TODO
        'h1'         => 'Application received',
        'schema'     => [],
        'status'     => 'live',
        'sitemap'    => false,
        'priority'   => 0.0,
        'wa'         => 'careers',
        'breadcrumb' => 'Application received',
        'crumbs'     => [['Home', '/'], ['Careers', '/careers/'], ['Application received', '/careers/thank-you/']],
        'faq'        => null,
        'cta'        => false,
    ],

    // ------------------------------------------------------------------
    // PÁGINAS POR EQUIPO — DRAFT hasta que el cliente confirme (§7.4). Datos en app/data/equipment.php.
    // title/meta/h1 de la maqueta + keyword de §7.4. TODO verificar con doc de copy.
    // ------------------------------------------------------------------
    '/box-truck-dispatch/' => [
        'template'   => 'equipment',
        'motion'     => true,
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
        'crumbs'     => [['Home', '/'], ['Services', '/dispatch-for-owner-operators/'], ['Box truck', '/box-truck-dispatch/']],   // maqueta: Home / Services / equipo
        'faq'        => null,   // las FAQ viven en app/data/equipment.php
    ],
    '/hotshot-dispatch/' => [
        'template'   => 'equipment',
        'motion'     => true,
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
        'crumbs'     => [['Home', '/'], ['Services', '/dispatch-for-owner-operators/'], ['Hotshot', '/hotshot-dispatch/']],   // maqueta: Home / Services / equipo
        'faq'        => null,
    ],
    '/flatbed-dispatch/' => [
        'template'   => 'equipment',
        'motion'     => true,
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
        'crumbs'     => [['Home', '/'], ['Services', '/dispatch-for-owner-operators/'], ['Flatbed', '/flatbed-dispatch/']],   // maqueta: Home / Services / equipo
        'faq'        => null,
    ],
    '/power-only-dispatch/' => [
        'template'   => 'equipment',
        'motion'     => true,
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
        'crumbs'     => [['Home', '/'], ['Services', '/dispatch-for-owner-operators/'], ['Power only', '/power-only-dispatch/']],   // maqueta: Home / Services / equipo
        'faq'        => null,
    ],
    '/reefer-dispatch/' => [
        'template'   => 'equipment',
        'motion'     => true,
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
        'crumbs'     => [['Home', '/'], ['Services', '/dispatch-for-owner-operators/'], ['Reefer', '/reefer-dispatch/']],   // maqueta: Home / Services / equipo
        'faq'        => null,
    ],

    // ------------------------------------------------------------------
    // ERROR 404 (clave especial, no es una URL)
    // ------------------------------------------------------------------
    '404' => [
        'template'   => '404',
        'motion'     => true,
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
    ],
];

// ----------------------------------------------------------------------
// COMPANY DRIVERS (CDL-A): el hub y cada vacante se generan desde app/data/jobs.php. Sin vacantes activas el hub queda en draft (404) y no hay páginas de vacante.
// Una vacante vencida (valid_through < hoy) o pausada también da 404 y sale del sitemap. Sin JobPosting en ninguna otra página.
// ----------------------------------------------------------------------
$nsJobs = jobs_active();
$routes['/careers/cdl-a-drivers/'] = [
    'template'   => 'cdl-hub',
    'motion'     => true,
    'title'      => 'CDL-A Company Driver Jobs | NorthStar Freight', // TODO
    'meta'       => 'Open CDL-A company driver positions at NorthStar Freight Solutions: what each job involves and how to apply. Only real openings are listed.', // TODO
    'h1'         => 'CDL-A company driver jobs',
    'schema'     => ['ItemList', 'BreadcrumbList'],
    'status'     => $nsJobs ? 'live' : 'draft',
    'sitemap'    => true,
    'priority'   => 0.6,
    'wa'         => 'driver',
    'breadcrumb' => 'CDL-A drivers',
    'parent'     => '/careers/',
    'faq'        => null,
];
foreach (jobs_all() as $nsSlug => $nsJob) {
    $nsTitle = trim((string) $nsJob['title']);
    $routes['/careers/cdl-a-drivers/' . $nsSlug . '/'] = [
        'template'   => 'job',
        'motion'     => true,
        'job'        => $nsSlug,
        'lastmod'    => (string) ($nsJob['posted'] ?? ''),   // fecha real de publicación de la vacante (solo se emite si es una fecha ISO válida)
        'title'      => mb_strimwidth($nsTitle . ' | NorthStar Freight', 0, 60, '', 'UTF-8'),
        'meta'       => mb_strimwidth(trim((string) ($nsJob['summary'] ?? '')) !== '' ? trim((string) $nsJob['summary']) : $nsTitle . ' at NorthStar Freight Solutions. See what the job involves and how to apply.', 0, 155, '', 'UTF-8'),
        'h1'         => $nsTitle,
        'schema'     => ['JobPosting', 'BreadcrumbList'],
        'status'     => isset($nsJobs[$nsSlug]) ? 'live' : 'draft',
        'sitemap'    => true,
        'priority'   => 0.6,
        'wa'         => 'driver',
        'breadcrumb' => $nsTitle,
        'parent'     => '/careers/cdl-a-drivers/',
        'faq'        => null,
    ];
}

// Guarda de archivos (B1): una página que promete una descarga solo es pública en producción si el archivo existe.
// Se aplica aquí, al construir el mapa, para que sitemap, llms, menús y route_is_live() lo vean igual.
if (is_production()) {
    foreach ($routes as $nsKey => $nsR) {
        foreach ($nsR['needs_files'] ?? [] as $nsFile) {
            if (!is_file(NS_ROOT . '/assets/' . $nsFile)) {
                $routes[$nsKey]['status'] = 'draft';
                $routes[$nsKey]['sitemap'] = false;
                break;
            }
        }
    }
}

return $routes;

// ----------------------------------------------------------------------
// FASE 2 (no registrar todavía)
// ----------------------------------------------------------------------
// /blog/how-to-dispatch-a-truck/   (pendiente de escribir; no existe en la maqueta)
// /blog/freight-factoring/ · /blog/amazon-relay-review/ · /blog/freight-broker-vs-dispatcher/
// /resources/glossary/{término}/   (una URL por término del glosario)
// /es/...                          (versión en español + hreflang)
