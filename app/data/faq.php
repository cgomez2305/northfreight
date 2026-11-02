<?php
/**
 * Bloques de FAQ reutilizables, por clave de página ($route['faq']).
 *
 * Cada ítem: ['q' => pregunta, 'a' => respuesta, 'link' => ['label'=>..., 'path'=>...] (opcional)].
 * Convención de placeholders: `{{ph:TEXTO}}` marca un dato pendiente del cliente.
 *   - phtext($s)  -> HTML escapado; cada token pasa por ph() ([ TEXTO ] en staging, vacío en producción).
 *   - phplain($s) -> texto plano para JSON-LD ("[TEXTO]").
 *   - faq_items($key) devuelve los ítems y, en producción, DESCARTA los que contengan un token,
 *     para que ninguna respuesta incompleta llegue a prod ni a FAQPage.
 */

return [

    // FAQ global (doc de copy §FAQ) — textos exactos. Se usa en el home.
    'home' => [
        [
            'q' => 'How much does a truck dispatch service cost?',
            'a' => 'Our dispatch fee is {{ph:YOUR FEE: % of gross or flat weekly}}. Scope is agreed before we start, so you always know what the desk handles and what it costs.',
        ],
        [
            'q' => 'Do you dispatch Amazon Relay loads?',
            'a' => 'Yes. Operational and administrative management of Amazon Relay accounts is a core part of what we do: booking, trip records, performance follow-up and disputes.',
        ],
        [
            'q' => 'Do you only book loads?',
            'a' => 'No. We combine dispatch, load tracking and back-office work according to the scope we agree with you.',
        ],
        [
            'q' => 'How do we get started?',
            'a' => 'Send us a WhatsApp message. We learn your operation, agree on contacts and scope, and review how we will work together before the first load.',
        ],
        [
            'q' => 'Do you work with owner-operators?',
            'a' => 'Yes. We work with owner-operators and small fleets that run {{ph:confirm equipment types}}. Tell us about your trucks and we will tell you honestly if we are a fit.',
        ],
        [
            'q' => 'Do I need a contract?',
            'a' => '{{ph:confirm terms: weekly, monthly, cancel anytime}}.',
        ],
    ],

    // /dispatch-for-owner-operators/ (copy-owner.md)
    'owner' => [
        [
            'q' => 'How much does a dispatcher charge an owner-operator?',
            'a' => '{{ph:YOUR FEE: % of gross or flat weekly}}. Scope is agreed before we start.',
        ],
        [
            'q' => 'Do I have to take every load you find?',
            'a' => 'No. You approve each one.',
        ],
        [
            'q' => 'What equipment do you dispatch?',
            'a' => '{{ph:confirm equipment types}}.',
        ],
    ],

    // /amazon-relay-dispatch/ (copy-relay.md)
    'relay' => [
        [
            'q' => 'Do you need access to my Relay account?',
            'a' => '{{ph:confirm how account access is handled}}.',
        ],
        [
            'q' => 'Can you help a new Relay carrier?',
            'a' => '{{ph:confirm}}.',
        ],
        [
            'q' => 'Do you also dispatch non-Relay loads?',
            'a' => 'Yes. We book dry van freight through brokers.',
            'link' => ['label' => 'Dry van dispatch', 'path' => '/dry-van-dispatch/'],
        ],
    ],

    // /dry-van-dispatch/ (copy-dryvan.md)
    'dryvan' => [
        [
            'q' => 'Which load boards do you use?',
            'a' => '{{ph:confirm load boards}}.',
        ],
        [
            'q' => 'Do you book backhauls?',
            'a' => '{{ph:confirm}}.',
        ],
        [
            'q' => 'How do I get updates?',
            'a' => 'On WhatsApp, at every stage of the load.',
        ],
    ],

    // Recursos y blog: textos movidos desde las plantillas (sin cambios). Se leen con route_faq_items($route).
    'tool-rpm' => [
        [
            'q' => 'What is the difference between loaded and all-in rate per mile?',
            'a' => 'Loaded RPM divides the pay by the miles with freight on the trailer, and it is the number brokers quote. All-in RPM also counts the deadhead miles to reach the shipper, so it shows what the truck really earns for every mile it drives.',
        ],
        [
            'q' => 'What is a good rate per mile?',
            'a' => 'It depends on your lane, equipment, season and, above all, your own cost per mile. A load is worth booking when the net per mile covers your fixed and variable costs plus what you want to earn. Our desk checks this on every load before booking.',
        ],
        [
            'q' => 'Should I include the fuel surcharge?',
            'a' => 'Yes. Use the total you will be paid for the load. If the rate confirmation lists line haul and fuel surcharge separately, add both.',
        ],
        [
            'q' => 'Does a dispatcher fee change the math?',
            'a' => 'Yes. If you pay a percentage of the gross, subtract it from the load pay before comparing loads. Ask us how NorthStar charges.',
        ],
    ],
    'tool-ifta' => [
        [
            'q' => 'How is fleet MPG calculated for IFTA?',
            'a' => 'Total miles in all jurisdictions divided by total gallons bought in the quarter. That single MPG is applied to every state to get the taxable gallons.',
        ],
        [
            'q' => 'What are net taxable gallons?',
            'a' => 'Taxable gallons (state miles ÷ fleet MPG) minus the gallons you bought in that state. If you bought more fuel than you burned there, the result is negative and becomes a credit.',
        ],
        [
            'q' => 'When are IFTA returns due?',
            'a' => 'Quarterly, on the last day of the month after each quarter ends: April 30, July 31, October 31 and January 31.',
        ],
        [
            'q' => 'Can the desk keep my IFTA records?',
            'a' => 'Yes. Our back office keeps miles and fuel receipts organized per load so the quarterly numbers are ready when you or your accountant file.',
        ],
    ],
    'doc-bol' => [
        [
            'q' => 'Is a bill of lading the same as a proof of delivery?',
            'a' => 'No. The BOL travels with the freight from pickup. Once the receiver signs it at delivery, that signed copy becomes the proof of delivery (POD) you need to get paid.',
        ],
        [
            'q' => 'Who usually provides the BOL?',
            'a' => 'Most shippers print their own. Carry a blank template for the loads where they do not, and always check that the details match your rate confirmation.',
        ],
        [
            'q' => 'Why write in and out times on the BOL?',
            'a' => 'Signed times on the BOL are the strongest evidence for a detention claim. Our desk logs them with photos on every load.',
        ],
    ],
    'doc-agreement' => [
        [
            'q' => 'Is this template legal advice?',
            'a' => 'No. It is a starting point that shows what a fair dispatch agreement usually covers. Have it reviewed for your state and your business before you sign.',
        ],
        [
            'q' => 'Should a dispatcher ever control my authority?',
            'a' => 'No. You keep your MC and USDOT authority. A dispatcher acts on your behalf with limited authorization for paperwork and broker communication only.',
        ],
        [
            'q' => 'Can I see NorthStar\'s agreement before starting?',
            'a' => 'Yes. We send it before onboarding so you can read every clause and ask questions on WhatsApp.',
        ],
    ],
    'post-how-much-does-truck-dispatch-cost' => [
        [
            'q' => 'Is a cheaper dispatcher always better?',
            'a' => 'Not necessarily. Compare what is included and how the dispatcher chooses loads. A lower fee on loads that pay less per mile can cost you more than a higher fee on better lanes.',
        ],
        [
            'q' => 'Do I need my own MC authority to use a dispatch service?',
            'a' => 'Yes. A dispatch service books loads under your carrier authority. If you lease on to another carrier, their dispatch usually handles your loads.',
        ],
        [
            'q' => 'Can I try a dispatch service before committing?',
            'a' => 'Many services, including NorthStar, start with a conversation about your trucks and lanes. Ask for the agreement in advance and check the notice period.',
        ],
    ],
    'post-amazon-relay-requirements' => [
        [
            'q' => 'Do I need my own trailer for Amazon Relay?',
            'a' => 'Not always. Many Relay loads are power-only, using Amazon trailers. Some loads require your own 53\' dry van.',
        ],
        [
            'q' => 'How long does Amazon Relay approval take?',
            'a' => 'It varies. Having consistent FMCSA, insurance and SCAC information ready speeds it up.',
        ],
        [
            'q' => 'Can a dispatcher manage my Relay account?',
            'a' => 'Yes, with your authorization. NorthStar books and monitors Relay loads for carriers and follows up on scorecard issues.',
        ],
    ],
    'post-amazon-relay-load-board' => [
        [
            'q' => 'Can I negotiate Amazon Relay rates?',
            'a' => 'Most loads are booked at the posted rate. Some freight is offered at different prices over time, which is why monitoring the board matters.',
        ],
        [
            'q' => 'What happens if I cancel a Relay load?',
            'a' => 'Cancellations count against your scorecard, especially close to pickup. Book only loads you can cover.',
        ],
        [
            'q' => 'Do I need the Relay app?',
            'a' => 'Yes. Drivers use it for check-ins and tracking on every load.',
        ],
    ],
    'post-lumper-fees-detention-pay' => [
        [
            'q' => 'Who pays the lumper fee?',
            'a' => 'Usually the shipper or receiver, through the broker. The driver often pays first and is reimbursed with the receipt.',
        ],
        [
            'q' => 'How long is detention free time?',
            'a' => 'It is set in each rate confirmation. Two hours is common, but always check the document for your load.',
        ],
        [
            'q' => 'What if the broker refuses detention?',
            'a' => 'Send the signed times and messages you sent while waiting. Without that record, claims are hard to win.',
        ],
    ],
];
