<?php
/**
 * /resources/dispatcher-carrier-agreement/ (DocAgreement.html): hero con vista previa del contrato (HTML/CSS), What's inside +
 * formulario de descarga, How to use it, FAQ y relacionados. Mismo formulario que el BOL (POST a esta URL).
 * Archivo real en assets/downloads/ (dispatcher-carrier-agreement.docx): pendiente de revisión legal. No es asesoría legal (§8.2.6).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$doc = [
    'inside' => [
        ['Scope of services', 'load search, rate negotiation, broker setup, tracking and paperwork — listed one by one'],
        ['Your rights as the carrier', 'you keep your authority and can accept or refuse any load'],
        ['Dispatch fee', 'percentage or flat fee, and exactly what it is calculated on'],
        ['Payment terms', 'when and how the fee is invoiced and paid'],
        ['Limited authorization', 'what the dispatcher can sign or send on your behalf, and what it cannot'],
        ['Term & termination', 'notice period and what happens to loads in transit'],
    ],
    'formats' => [['docx', 'Word — editable (.docx)'], ['pdf', 'PDF — read only']],
    'submit'  => 'Download the agreement',
    'notice'  => 'this template is not legal advice. Have it reviewed for your state and your business before you sign it.',
    'files'   => [['Word', 'dispatcher-carrier-agreement.docx']],
    'steps_title' => 'Review it in four steps',
    'steps' => [
        ['Read the scope', 'Every service you expect should be written down. If it is not listed, assume it is not included.'],
        ['Check the fee base', 'A percentage of what — line haul only, or the full gross with fuel surcharge?'],
        ['Confirm your control', 'No forced dispatch: the agreement should say you can refuse any load.'],
        ['Look at the exit', 'Short notice periods and no penalties keep the relationship honest.'],
    ],
    'faq' => [
        ['Is this template legal advice?', 'No. It is a starting point that shows what a fair dispatch agreement usually covers. Have it reviewed for your state and your business before you sign.'],
        ['Should a dispatcher ever control my authority?', 'No. You keep your MC and USDOT authority. A dispatcher acts on your behalf with limited authorization for paperwork and broker communication only.'],
        ['Can I see NorthStar\'s agreement before starting?', 'Yes. We send it before onboarding so you can read every clause and ask questions on WhatsApp.'],
    ],
    'related' => [
        ['path' => '/blog/how-much-does-truck-dispatch-cost/', 'code' => 'ARTICLE', 'title' => 'How much does truck dispatch cost?', 'text' => 'Percentage vs flat fee and the red flags.'],
        ['path' => '/how-it-works/', 'code' => 'COMPANY', 'title' => 'How we work with you', 'text' => 'Scope, contacts and the day-to-day, step by step.'],
        ['path' => '/resources/bol-template/', 'code' => 'TEMPLATE', 'title' => 'Bill of lading template', 'text' => 'Free BOL in PDF and Word.', 'dark' => true],
    ],
];

ob_start();
include __DIR__ . '/company/preview-agreement.php';
echo ns_star(54, 'co-paper-star');
$aside = ob_get_clean();

partial('hero', [
    'route'   => $route,
    'h1'      => 'Dispatcher-carrier {hl}agreement{/hl}',
    'eyebrow' => 'Free template',
    'lead'    => 'A plain-English template for the contract between an owner-operator and a truck dispatch service: what the dispatcher does, what you keep control of, the fee and how either side can end it.',
    'aside'   => $aside,
    'cls'     => 'co-hero',
]);
include __DIR__ . '/company/doc-body.php';
