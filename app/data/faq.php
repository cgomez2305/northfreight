<?php
/**
 * Bloques de FAQ reutilizables, por clave de página ($route['faq']).
 *
 * Cada ítem: ['q' => pregunta, 'a' => respuesta, 'link' => ['label'=>..., 'path'=>...] (opcional)].
 * `topic` (opcional, solo el home): índice de la foto del FAQ que se muestra al abrir la pregunta (partial faq, param image.topics).
 * Convención de placeholders: `{{ph:TEXTO}}` marca un dato pendiente del cliente.
 *   - phtext($s)  -> HTML escapado; cada token pasa por ph() ([ TEXTO ] en staging, vacío en producción).
 *   - phplain($s) -> texto plano para JSON-LD ("[TEXTO]").
 *   - faq_items($key) devuelve los ítems y, en producción, DESCARTA los que contengan un token,
 *     para que ninguna respuesta incompleta llegue a prod ni a FAQPage.
 */

// Vacantes reales (app/data/jobs.php): las preguntas del hub de carreras dependen de si hay vacantes publicadas.
$nsJobsActive = function_exists('jobs_active') ? jobs_active() : [];

return [

    // /careers/ (hub): preguntas de conductores. Solo se afirma lo que el sitio ya afirma; lo demás va con {{ph:…}} y se descarta en producción.
    'careers' => [
        [
            'q' => 'Is this a job offer?',
            'a' => 'For owner-operators, no. NorthStar is a dispatch service: the desk works as your dispatch desk while you keep running your own truck and approving every load.',
            'link' => ['label' => 'Owner operator partnership', 'path' => '/careers/owner-operators/'],
        ],
        [
            'q' => 'What does the desk handle for me?',
            'a' => 'We find and book the loads, deal with brokers, track every trip and keep the paperwork that gets you paid.',
        ],
        [
            'q' => 'Do I have to take every load you find?',
            'a' => 'No. You approve each one.',
        ],
        [
            'q' => 'How do I get started?',
            'a' => 'Send the short application or message us on WhatsApp. We learn your operation, agree on contacts and scope, and review how we will work together before the first load.',
            'link' => ['label' => 'Start the application', 'path' => '/careers/apply/'],
        ],
        [
            'q' => 'What does it cost?',
            'a' => 'NorthStar quotes each operation individually, based on the volume of active trucks and the scope of service. Scope is agreed before we start, so you always know what the desk handles. Message the desk on WhatsApp to get a quote for your operation.',
        ],
        [
            'q' => 'What do I need to apply?',
            'a' => '{{ph:confirm requirements: authority, insurance, equipment and documents}}.',
        ],
        [
            'q' => 'I do not own a truck. Can you help me find a driving job?',
            'a' => $nsJobsActive
                ? 'Yes, when there are openings. The open company driver positions are listed on the CDL-A drivers page.'
                : 'Not right now: there are no company driver openings listed. If that changes, they will appear on this site.',
            'link' => $nsJobsActive ? ['label' => 'CDL-A company driver jobs', 'path' => '/careers/cdl-a-drivers/'] : null,
        ],
        [
            'q' => 'How will I get updates?',
            'a' => 'On WhatsApp, at every stage of the load.',
        ],
    ],

    // /careers/owner-operators/
    'careers-owner' => [
        [
            'q' => 'Is this an owner operator job?',
            'a' => 'No. It is a dispatch partnership: you keep running your own truck and the NorthStar desk works as your dispatch desk.',
        ],
        [
            'q' => 'How much does a dispatcher charge an owner-operator?',
            'a' => 'NorthStar quotes each operation individually, based on the volume of active trucks and the scope of service. Scope is agreed before we start. Message the desk on WhatsApp to get a quote for your operation.',
        ],
        [
            'q' => 'Who decides which loads I run?',
            'a' => 'You do. You approve each load, and we tell you what we booked and why.',
        ],
        [
            'q' => 'What equipment do you dispatch?',
            'a' => 'We dispatch Amazon Relay, power only and dry van freight. Tell us about your trucks and we will tell you honestly if we are a fit.',
        ],
        [
            'q' => 'Can you help if I am just getting my authority?',
            'a' => '{{ph:confirm whether the desk works with new authorities and what it needs}}.',
        ],
        [
            'q' => 'Do you dispatch Amazon Relay loads?',
            'a' => 'Yes. Operational and administrative management of Amazon Relay accounts is a core part of what we do: booking, trip records, performance follow-up and disputes.',
            'link' => ['label' => 'Amazon Relay dispatch', 'path' => '/amazon-relay-dispatch/'],
        ],
        [
            'q' => 'Will I have one dispatcher or a team?',
            'a' => 'A team, not a single dispatcher. Updates reach you on WhatsApp, where you already work.',
        ],
        [
            'q' => 'What happens after I apply?',
            'a' => 'We confirm we received it, and the team reviews your application. If it looks like a fit, we get in touch the way you chose, on WhatsApp if you prefer, and together we agree on who approves loads, how and when we report, and which tasks the desk owns.',
        ],
    ],
    // FAQ global (doc de copy §FAQ) — textos exactos. Se usa en el home.
    'home' => [
        [
            'q' => 'How much does a truck dispatch service cost?',
            'topic' => 0,
            'a' => 'NorthStar quotes each operation individually, based on the volume of active trucks and the scope of service. Scope is agreed before we start, so you always know what the desk handles. Message the desk on WhatsApp to get a quote for your operation.',
        ],
        [
            'q' => 'Do you dispatch Amazon Relay loads?',
            'topic' => 1,
            'a' => 'Yes. Operational and administrative management of Amazon Relay accounts is a core part of what we do: booking, trip records, performance follow-up and disputes.',
        ],
        [
            'q' => 'Do you only book loads?',
            'topic' => 2,
            'a' => 'No. We combine dispatch, load tracking and back-office work according to the scope we agree with you.',
        ],
        [
            'q' => 'What does a truck dispatcher do?',
            'topic' => 2,
            'a' => 'A truck dispatcher finds and books the loads, deals with brokers, tracks every trip and keeps the paperwork that gets you paid. At NorthStar one desk does all of it, and you approve every load.',
            'link' => ['label' => 'How truck dispatching works', 'path' => '/how-it-works/'],
        ],
        [
            'q' => 'How do we get started?',
            'topic' => 0,
            'a' => 'Send us a WhatsApp message. We learn your operation, agree on contacts and scope, and review how we will work together before the first load.',
        ],
        [
            'q' => 'Do you work with owner-operators?',
            'topic' => 3,
            'a' => 'Yes. We work with owner-operators and small fleets that run Amazon Relay, power only and dry van freight. Tell us about your trucks and we will tell you honestly if we are a fit.',
        ],
        [
            'q' => 'Do I need a contract?',
            'topic' => 0,
            'a' => '{{ph:confirm terms: weekly, monthly, cancel anytime}}.',
        ],
    ],

    // /dispatch-for-owner-operators/ (copy-owner.md)
    'owner' => [
        [
            'q' => 'How much does a dispatcher charge an owner-operator?',
            'a' => 'NorthStar quotes each operation individually, based on the volume of active trucks and the scope of service. Scope is agreed before we start. Message the desk on WhatsApp to get a quote for your operation.',
        ],
        [
            'q' => 'Do I have to take every load you find?',
            'a' => 'No. You approve each one.',
        ],
        [
            'q' => 'What equipment do you dispatch?',
            'a' => 'We dispatch Amazon Relay, power only and dry van freight. Tell us about your trucks and we will tell you honestly if we are a fit.',
        ],
    ],

    // /amazon-relay-dispatch/ (copy-relay.md)
    'relay' => [
        [
            'q' => 'What does Amazon Relay dispatch include?',
            'a' => 'The desk searches and coordinates loads, plans the work, tracks drivers and trips, monitors your account performance and handles operational or payment disputes with evidence, according to the scope we agree with you.',
        ],
        [
            'q' => 'Do you need access to my Relay account?',
            'a' => 'Access is set up with you during onboarding, using the credentials and permissions you provide. Scope is agreed before we start.',
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
            'a' => 'We search freight on DAT.',
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
            'a' => '{{ph:confirm with the client whether NorthStar sends its agreement before onboarding, and who reviewed it}}',   // sin respaldo todavía (informe de auditoría I1): oculta en producción
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
            'a' => 'It depends on the load. Check the load details inside Relay and the current requirements on relay.amazon.com before you book.',
        ],
        [
            'q' => 'How long does Amazon Relay approval take?',
            'a' => 'It varies, and Amazon decides. Having your FMCSA and insurance information consistent and ready helps.',
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
