<?php
/**
 * Índice del blog (Blog.html del prototipo). El cuerpo de cada artículo vive en su plantilla (templates/pages/post.php
 * + datos propios que volcará el coder de páginas); aquí solo el índice.
 *
 * Campos: slug, path, title, category (Dispatch|Amazon Relay|Paperwork & pay), minutes, excerpt, date, featured,
 *   type ('article' = página propia en /blog/SLUG/; 'link' = tarjeta del índice que apunta a un recurso),
 *   kind (etiqueta TOOL|GUIDE|TEMPLATE en las tarjetas 'link'), lead y toc (solo artículos).
 * date usa la convención {{ph:...}} (phtext()): '{{ph:Publish date}}' hasta que haya fecha real (YYYY-MM-DD).
 * published / modified (solo artículos): fechas ISO YYYY-MM-DD. 'published' queda VACÍO hasta que el cliente confirme la fecha real
 *   de publicación (no se inventa; mientras tanto la pantalla usa 'date' con ph()). 'modified' = última edición real del contenido.
 *   Alimentan datePublished/dateModified del JSON-LD Article y el byline (<time datetime>) del artículo.
 * Orden = orden de aparición en la maqueta: destacado primero, luego el resto.
 */

return [
    [
        'slug'     => 'how-much-does-truck-dispatch-cost',
        'path'     => '/blog/how-much-does-truck-dispatch-cost/',
        'title'    => 'How much does truck dispatch service cost?',
        'category' => 'Dispatch',
        'minutes'  => 7,
        'excerpt'  => 'Percentage of the gross, flat weekly fee or per load: what each model really costs an owner-operator and what should be included.',
        'date'     => '{{ph:Publish date}}',
        'published' => '',            // PENDIENTE (cliente): YYYY-MM-DD
        'modified' => '2026-11-02',
        'featured' => true,
        'type'     => 'article',
        'lead'     => 'Most dispatch services charge a percentage of each load, a flat weekly fee or a fee per load. Here is how each model works, what should be included and what to watch for before you sign.',
        'toc'      => [
            'The three ways dispatchers charge',
            'What the math looks like',
            'What the fee should include',
            'Red flags before you sign',
            'How NorthStar charges',
        ],
    ],
    [
        'slug'     => 'amazon-relay-requirements',
        'path'     => '/blog/amazon-relay-requirements/',
        'title'    => 'Amazon Relay requirements for carriers',
        'category' => 'Amazon Relay',
        'minutes'  => 6,
        'excerpt'  => 'Authority, SCAC code, insurance, equipment and the steps to get approved on Amazon Relay.',
        'date'     => '{{ph:Publish date}}',
        'published' => '',            // PENDIENTE (cliente): YYYY-MM-DD
        'modified' => '2026-11-02',
        'featured' => false,
        'type'     => 'article',
        'lead'     => 'Before you can book a single Amazon Relay load, your company has to be approved. This is what Relay looks for, in plain language, and how to prepare your application.',
        'toc'      => [
            'Who can sign up',
            'Insurance',
            'Equipment and technology',
            'The approval steps',
            'After approval: keep the account healthy',
        ],
    ],
    [
        'slug'     => 'amazon-relay-load-board',
        'path'     => '/blog/amazon-relay-load-board/',
        'title'    => 'How the Amazon Relay load board works',
        'category' => 'Amazon Relay',
        'minutes'  => 6,
        'excerpt'  => 'One-way loads, round trips and blocks: how Relay freight is posted, booked and tracked.',
        'date'     => '{{ph:Publish date}}',
        'published' => '',            // PENDIENTE (cliente): YYYY-MM-DD
        'modified' => '2026-11-02',
        'featured' => false,
        'type'     => 'article',
        'lead'     => 'Relay freight does not work like a regular broker load board. Loads are posted with a rate, booked in a click and tracked through the Relay app. Here is how it fits together.',
        'toc'      => [
            'What you will find on the board',
            'Searching and filtering',
            'Booking and assigning a driver',
            'Why the scorecard matters',
            'Where a dispatcher helps',
        ],
    ],
    [
        'slug'     => 'lumper-fees-detention-pay',
        'path'     => '/blog/lumper-fees-detention-pay/',
        'title'    => 'Lumper fees and detention pay: how to get paid for both',
        'category' => 'Paperwork & pay',
        'minutes'  => 5,
        'excerpt'  => 'What to document at the dock so lumper receipts and detention hours turn into money on your settlement.',
        'date'     => '{{ph:Publish date}}',
        'published' => '',            // PENDIENTE (cliente): YYYY-MM-DD
        'modified' => '2026-11-02',
        'featured' => false,
        'type'     => 'article',
        'lead'     => 'Lumper fees and detention are money you are owed, but only if they are documented the right way. What to do at the dock so they show up on your settlement.',
        'toc'      => [
            'What a lumper fee is',
            'How to get the lumper reimbursed',
            'How detention works',
            'The evidence that gets detention paid',
            'Putting it on the settlement',
        ],
    ],
    [
        'slug'     => 'rate-per-mile',
        'path'     => '/resources/rate-per-mile-calculator/',
        'title'    => 'Rate per mile: know if a load pays',
        'category' => 'Dispatch',
        'minutes'  => null,
        'excerpt'  => 'Loaded vs all-in rate per mile, fuel and what is left, with a free calculator.',
        'date'     => null,
        'featured' => false,
        'type'     => 'link',
        'kind'     => 'TOOL',
    ],
    [
        'slug'     => 'glossary',
        'path'     => '/resources/glossary/',
        'title'    => 'The trucking glossary for new owner-operators',
        'category' => 'Dispatch',
        'minutes'  => null,
        'excerpt'  => 'Deadhead, TONU, rate con, lumper: the words you hear on every broker call.',
        'date'     => null,
        'featured' => false,
        'type'     => 'link',
        'kind'     => 'GUIDE',
    ],
    [
        'slug'     => 'fill-a-bol',
        'path'     => '/resources/bol-template/',
        'title'    => 'How to fill a bill of lading',
        'category' => 'Paperwork & pay',
        'minutes'  => null,
        'excerpt'  => 'Field by field, plus a free BOL template in PDF and Word.',
        'date'     => null,
        'featured' => false,
        'type'     => 'link',
        'kind'     => 'TEMPLATE',
    ],
];
