<?php
/**
 * /resources/bol-template/ (DocBol.html): hero con vista previa del BOL (HTML/CSS), What's inside + formulario de descarga,
 * How to use it, FAQ y relacionados. Formulario: POST a esta misma URL (name, email, format, website=honeypot, form_ts, csrf).
 * Archivos reales en assets/downloads/ (bol-template.pdf / .docx): pendientes del cliente.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$doc = [
    'inside' => [
        ['Shipper & consignee', 'names, addresses, contacts and pickup / delivery numbers'],
        ['Carrier block', 'carrier name, MC# and USDOT, trailer and seal numbers'],
        ['Reference numbers', 'BOL #, PRO #, PO and load numbers in one place'],
        ['Freight table', 'handling units, pieces, description, weight and NMFC class'],
        ['Special instructions', 'appointments, temperature, hazmat flags and accessorials'],
        ['Three signatures', 'shipper, driver and receiver, with date and time for detention proof'],
    ],
    'formats' => [['pdf', 'PDF (print and fill by hand)'], ['docx', 'Word (fill on your computer)']],
    'submit'  => 'Download the BOL template',
    'notice'  => 'this is a generic template. Check it against what your shippers, brokers and receivers require before you use it.',
    'files'   => [['PDF', 'bol-template.pdf'], ['Word', 'bol-template.docx']],
    'steps_title' => 'Fill it in four steps',
    'steps' => [
        ['Fill the header', 'Shipper, consignee, pickup and delivery numbers straight from the rate confirmation.'],
        ['Describe the freight', 'Pieces, weight and class. Count before signing. The driver signs for what is on the trailer.'],
        ['Sign at pickup', 'Shipper and driver sign; write the seal number and in/out times.'],
        ['Get the POD', 'Receiver signs on delivery. Send a clear photo to the desk the same day.'],
    ],
    'related' => [
        ['path' => '/blog/lumper-fees-detention-pay/', 'code' => 'ARTICLE', 'title' => 'Lumper fees & detention pay', 'text' => 'How to document both and actually get reimbursed.'],
        ['path' => '/load-tracking/', 'code' => 'SERVICE', 'title' => 'Load tracking & check calls', 'text' => 'Times, photos and PODs logged by the desk.'],
        ['path' => '/resources/dispatcher-carrier-agreement/', 'code' => 'TEMPLATE', 'title' => 'Dispatcher-carrier agreement', 'text' => 'What a fair dispatch contract covers.', 'dark' => true],
    ],
];

ob_start();
include __DIR__ . '/company/preview-bol.php';
echo ns_star(54, 'co-paper-star');
$aside = ob_get_clean();

partial('hero', [
    'route'   => $route,
    'h1'      => 'Bill of lading {hl}template{/hl}',
    'eyebrow' => 'Free template',
    'lead'    => 'A clean, printable straight bill of lading with every field brokers, shippers and receivers expect. Free in PDF and Word. It is the same layout our desk files on every load.',
    'aside'   => $aside,
    'cls'     => 'co-hero',
]);
include __DIR__ . '/company/doc-body.php';
