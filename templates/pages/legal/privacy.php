<?php
/** Contenido de /privacy-policy/ (Privacy.html). Texto pendiente de aprobación del abogado. Devuelve el documento como array. */
return [
    'h1'      => 'Privacy {hl}policy{/hl}',
    'lead'    => 'How NorthStar Freight Solutions LLC collects, uses and protects the information you share with us through this website, WhatsApp and email.',
    'updated' => 'Last updated {{ph:date}}',
    'sections' => [
        ['collect', 'Information we collect', 'Contact details you send us (name, company, email, phone, MC number), messages you send through forms, WhatsApp or email, and basic usage data from cookies and analytics.'],
        ['use', 'How we use it', 'To reply to you, prepare a dispatch proposal, provide our services, send the resources you request and improve this website. We do not sell your information.'],
        ['share', 'When we share it', 'With service providers that help us run the business (hosting, email, analytics) and, once you are a client, with brokers and partners as needed to book and manage your loads.'],
        ['cookies', 'Cookies and analytics', '{{ph:Cookie and analytics tools used on the site: to be listed once the stack is final.}}'],
        ['retention', 'How long we keep it', 'As long as needed to provide the service and meet legal and accounting obligations.'],
        ['rights', 'Your choices', 'You can ask to see, correct or delete your information, or unsubscribe from emails at any time, by writing to {email}.'],
        // Secciones PENDIENTES del abogado (no inventar texto legal): llevan solo {{ph:…}}, se ven en staging y se omiten en producción. Cada una documenta algo que el sitio YA hace.
        ['downloads', 'Template downloads', '{{ph:Counsel to draft: name and email collected when a template is requested, kept in a server log, used only to send the file and to follow up on the request.}}'],
        ['applications', 'Applications and the dispatch CRM', '{{ph:Counsel to draft: applications sent through the careers form are stored on our servers and in our internal dispatch CRM, with the answers given, the consent text shown, the date and the IP address stored as proof of consent, and the page and campaign the visitor came from.}}'],
        ['storage', 'Browser storage', '{{ph:Counsel to draft: the site stores, in the visitor\'s browser (localStorage, no cookies), the first page and campaign seen for 30 days and a referral code for 60 days, used only to know where an application came from.}}'],
        ['thirdparty', 'Third-party resources', '{{ph:Counsel to draft: the site loads the GSAP animation library from cdnjs.cloudflare.com, which means a request to that third party; WhatsApp links open WhatsApp.}}'],
        ['contact', 'Contact', 'NorthStar Freight Solutions LLC · {address} · {phone} · {email}'],
    ],
    'other' => ['Terms of use', '/terms/'],
];
