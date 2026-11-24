<?php
/** Contenido de /privacy-policy/ (Privacy.html). Texto GENÉRICO redactado por petición de Carlos (2026-11-24) para cumplir con lo básico; NO lo revisó un abogado. Cada afirmación describe algo que el sitio ya hace. Devuelve el documento como array. */
return [
    'h1'      => 'Privacy {hl}policy{/hl}',
    'lead'    => 'How NorthStar Freight Solutions LLC collects, uses and protects the information you share with us through this website, WhatsApp and email.',
    'updated' => 'Last updated November 24, 2026',
    'sections' => [
        ['collect', 'Information we collect', 'Contact details you send us (name, company, email, phone, MC number), messages you send through forms, WhatsApp or email, and the details you give us when you ask for a template or apply to work with us. Our web server also keeps standard technical logs, such as the pages requested, the date and time and your IP address.'],
        ['use', 'How we use it', 'To reply to you, prepare a dispatch proposal, provide our services, send the resources you request and improve this website. We do not sell your information.'],
        ['share', 'When we share it', 'With service providers that help us run the business (for example hosting and email) and, once you are a client, with brokers and partners as needed to book and manage your loads. We may also share information when the law requires it.'],
        ['cookies', 'Cookies and analytics', 'This website does not set cookies of its own and does not load analytics or advertising trackers. If we add analytics tools in the future, we will list them on this page before they start collecting data.'],
        ['retention', 'How long we keep it', 'As long as needed to provide the service and meet legal and accounting obligations.'],
        ['rights', 'Your choices', 'You can ask to see, correct or delete your information, or unsubscribe from emails at any time, by writing to {email}. Depending on where you live, you may have additional rights over your personal information; write to us and we will respond as the law requires.'],
        // Secciones PENDIENTES del abogado (no inventar texto legal): llevan solo {{ph:…}}, se ven en staging y se omiten en producción. Cada una documenta algo que el sitio YA hace.
        ['downloads', 'Template downloads', 'When a template is requested through the site, we collect the name and email you enter. They are kept in a server log and used only to send you the file and to follow up on your request.'],
        ['applications', 'Applications and the dispatch CRM', 'Applications sent through the careers form are stored on our servers and in our internal dispatch CRM. We keep the answers you give, the consent text you were shown, the date, and the IP address as proof of that consent, together with the page and campaign you came from. If you give us your phone number or email, we may contact you by call, WhatsApp or email about your request.'],
        ['storage', 'Browser storage', 'The site stores in your browser (localStorage, not cookies) the first page and campaign you arrived from, for 30 days, and a referral code, for 60 days. We use them only to know where an application came from. You can clear them at any time from your browser settings.'],
        ['thirdparty', 'Third-party resources', 'The site loads the GSAP animation library from cdnjs.cloudflare.com, which means your browser makes a request to that third party. Links to WhatsApp open WhatsApp, which has its own privacy policy.'],
        ['security', 'Security', 'We use reasonable technical and organizational measures to protect your information, but no method of transmission or storage is completely secure.'],
        ['children', 'Children', 'This website and our services are for businesses and are not directed to children under 13. We do not knowingly collect their information.'],
        ['changes', 'Changes to this policy', 'We may update this policy. The date at the top shows the latest version.'],
        ['contact', 'Contact', 'NorthStar Freight Solutions LLC · {address} · {phone} · {email}'],
    ],
    'other' => ['Terms of use', '/terms/'],
];
