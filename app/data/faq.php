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
            'a' => 'Our dispatch fee is {{ph:YOUR FEE — % of gross or flat weekly}}. Scope is agreed before we start, so you always know what the desk handles and what it costs.',
        ],
        [
            'q' => 'Do you dispatch Amazon Relay loads?',
            'a' => 'Yes. Operational and administrative management of Amazon Relay accounts is a core part of what we do — booking, trip records, performance follow-up and disputes.',
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
            'a' => 'Yes — owner-operators and small fleets with {{ph:confirm equipment types}}. Tell us about your trucks and we will tell you honestly if we are a fit.',
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
            'a' => '{{ph:YOUR FEE — % of gross or flat weekly}}. Scope is agreed before we start.',
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
            'a' => 'Yes — dry van freight through brokers.',
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
];
