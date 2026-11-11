<?php
/**
 * Rutas en español (fase AE). Clave = ruta inglesa; valor = ruta española + title/meta/h1/breadcrumb propios.
 * title <= 60 caracteres y meta <= 155 (bin/i18n.php check lo comprueba). Un solo H1 por página.
 *
 * Investigación de palabras clave (Google Ads, EE. UU., idioma español, oct. 2026): el volumen en español es pequeño y mezcla términos en inglés
 * ("truck dispatcher" 5.400 búsquedas/mes frente a "dispatcher de camiones" 40 y "despachador de camiones" 20). Por eso las páginas combinan el
 * español natural con los términos del oficio en inglés que los choferes hispanos ya usan (dispatch, owner operator, Amazon Relay, dry van, BOL, IFTA).
 *   amazon relay en español 110 · qué es amazon relay 140 · amazon relay requisitos 70 · bill of lading en español 70 · dispatcher de camiones 40
 *   despachador de camiones 20 · cómo ser dispatcher de camiones 20 · qué es un dry van 20 · ifta en español 20 · dispatch de camiones 10
 *
 * Las páginas legales (privacy-policy, terms) NO se traducen: el texto legal lo revisa un abogado y no se publica una traducción sin revisar.
 * Las descargas (BOL y contrato) y las vacantes de company drivers siguen solo en inglés hasta que el cliente las apruebe.
 */

return [
    '/' => [
        'path'       => '/es/',
        'title'      => 'Dispatch de Camiones para Owner Operators | NorthStar',
        'meta'       => 'Dispatcher de camiones y servicio de dispatch para owner-operators y flotas pequeñas: Amazon Relay, dry van, seguimiento y back office. Escríbenos.',
        'h1'         => 'Servicio de dispatch de camiones para owner-operators y flotas pequeñas',
        'breadcrumb' => 'Inicio',
    ],
    '/dispatch-for-owner-operators/' => [
        'path'       => '/es/dispatch-para-owner-operators/',
        'title'      => 'Dispatch para Owner Operators en Español | NorthStar',
        'meta'       => 'Servicio de dispatch para owner-operators: tu equipo de dispatch consigue tus cargas, trata con los brokers y sigue cada viaje. Avisos por WhatsApp.',
        'h1'         => 'Servicio de dispatch para owner-operators',
        'h1_hl'      => 'Servicio de dispatch para {hl}owner-operators{/hl}',
        'breadcrumb' => 'Owner-operators',
    ],
    '/amazon-relay-dispatch/' => [
        'path'       => '/es/dispatch-amazon-relay/',
        'title'      => 'Dispatch para Amazon Relay en Español | NorthStar',
        'meta'       => 'Dispatch para carriers de Amazon Relay: vigilamos el load board, reservamos bloques, registramos viajes y damos seguimiento al scorecard y las disputas.',
        'h1'         => 'Dispatch para Amazon Relay, de principio a fin',
        'breadcrumb' => 'Amazon Relay',
    ],
    '/dry-van-dispatch/' => [
        'path'       => '/es/dispatch-dry-van/',
        'title'      => 'Servicio de Dispatch para Dry Van | NorthStar Freight',
        'meta'       => 'Dispatch para dry van para owner-operators y flotas pequeñas: búsqueda de cargas, negociación de tarifas, check calls y papeleo desde un solo equipo.',
        'h1'         => 'Servicio de dispatch para dry van',
        'h1_hl'      => 'Servicio de dispatch para {hl}dry van{/hl}',
        'breadcrumb' => 'Dry van',
    ],
    '/power-only-dispatch/' => [
        'path'       => '/es/dispatch-power-only/',
        'title'      => 'Dispatch para Power Only | NorthStar Freight',
        'meta'       => 'Dispatch power only para owner-operators: cargas para tu tractor, recogida y entrega del trailer confirmadas, con seguimiento y documentos.',
        'h1'         => 'Servicios de dispatch para power only',
        'h1_hl'      => 'Servicios de dispatch para {hl}power only{/hl}',
        'breadcrumb' => 'Power only',
    ],
    '/load-tracking/' => [
        'path'       => '/es/seguimiento-de-cargas/',
        'title'      => 'Seguimiento de Cargas y Check Calls | NorthStar Freight',
        'meta'       => 'Seguimiento de cargas y check calls: seguimos cada carga desde la recogida hasta el POD con ETAs, horas de llegada e informes de incidentes.',
        'h1'         => 'Seguimiento de cargas y check calls para tus camiones',
        'breadcrumb' => 'Seguimiento de cargas',
    ],
    '/trucking-back-office/' => [
        'path'       => '/es/back-office-para-camioneros/',
        'title'      => 'Back Office para Camioneros | NorthStar Freight',
        'meta'       => 'Back office para empresas de camiones: settlements, disputas, documentos y alta de choferes, a cargo de tu equipo de dispatch.',
        'h1'         => 'Servicios de back office para camiones',
        'h1_hl'      => 'Servicios de back office para {hl}camiones{/hl}',
        'breadcrumb' => 'Back office',
    ],
    '/how-it-works/' => [
        'path'       => '/es/como-funciona/',
        'title'      => 'Cómo Funciona el Dispatch de Camiones | NorthStar',
        'meta'       => 'Cómo nos integramos a tu operación en cuatro pasos: conocemos tu flota, acordamos alcance y contactos, llevamos el día a día y revisamos juntos.',
        'h1'         => 'Cómo funciona el dispatch de camiones con NorthStar',
        'breadcrumb' => 'Cómo funciona',
    ],
    '/our-desk/' => [
        'path'       => '/es/nuestro-equipo/',
        'title'      => 'Sobre NorthStar Freight Solutions | Nuestro Equipo',
        'meta'       => 'Conoce al equipo de dispatch de NorthStar: quienes reservan, siguen y documentan tus cargas, y por qué operamos como un solo equipo.',
        'h1'         => 'Creado por dispatchers. Operado como equipo.',
        'h1_hl'      => 'Creado por dispatchers. {hl}Operado como equipo.{/hl}',
        'breadcrumb' => 'Nuestro equipo',
    ],
    '/contact/' => [
        'path'       => '/es/contacto/',
        'title'      => 'Contacto NorthStar Freight | Habla con un Dispatcher',
        'meta'       => 'Habla con un dispatcher de NorthStar por WhatsApp al +1 863-651-3322 o escribe a nfreightsolutionsllc@gmail.com. Sin formularios primero.',
        'h1'         => 'Habla con un dispatcher',
        'breadcrumb' => 'Contacto',
    ],
    '/resources/' => [
        'path'       => '/es/recursos/',
        'title'      => 'Recursos Gratis para Camioneros | NorthStar',
        'meta'       => 'Herramientas, plantillas y guías gratis de un equipo de dispatch: calculadoras de IFTA y tarifa por milla, plantilla de BOL, contrato, glosario y blog.',
        'h1'         => 'Recursos gratis para camioneros, del equipo de dispatch',
        'h1_hl'      => 'Recursos gratis para camioneros, {hl}del equipo de dispatch{/hl}',
        'breadcrumb' => 'Recursos',
    ],
    '/resources/bol-template/' => [
        'path'       => '/es/recursos/plantilla-bol/',
        'title'      => 'Bill of Lading en Español: Plantilla de BOL | NorthStar',
        'meta'       => 'Plantilla gratis de bill of lading (BOL) en PDF y Word: los campos que esperan brokers y receptores, lista para imprimir. El BOL de nuestro equipo.',
        'h1'         => 'Plantilla de bill of lading',
        'h1_hl'      => 'Plantilla de {hl}bill of lading{/hl}',
        'breadcrumb' => 'Plantilla de bill of lading',
    ],
    '/resources/ifta-calculator/' => [
        'path'       => '/es/recursos/calculadora-ifta/',
        'title'      => 'Calculadora IFTA en Español para Camioneros | NorthStar',
        'meta'       => 'Calculadora IFTA gratis: ingresa millas y galones por estado y mira el MPG de la flota, los galones gravables netos y el impuesto estimado. Solo estimados.',
        'h1'         => 'Calculadora IFTA',
        'h1_hl'      => 'Calculadora {hl}IFTA{/hl}',
        'breadcrumb' => 'Calculadora IFTA',
    ],
    '/resources/rate-per-mile-calculator/' => [
        'path'       => '/es/recursos/calculadora-tarifa-por-milla/',
        'title'      => 'Calculadora de Tarifa por Milla (RPM) | NorthStar',
        'meta'       => 'Calculadora gratis de tarifa por milla: RPM cargado y total, costo de combustible y lo que te queda del viaje. Sabe si una carga paga antes de reservarla.',
        'h1'         => 'Calculadora de tarifa por milla',
        'h1_hl'      => 'Calculadora de {hl}tarifa por milla{/hl}',
        'breadcrumb' => 'Calculadora de tarifa por milla',
    ],
    '/resources/dispatcher-carrier-agreement/' => [
        'path'       => '/es/recursos/contrato-dispatcher-carrier/',
        'title'      => 'Contrato Dispatcher-Carrier (Plantilla) | NorthStar',
        'meta'       => 'Plantilla de contrato dispatcher-carrier: qué cubre un contrato justo, del alcance y las tarifas a tu derecho a rechazar cargas. No es asesoría legal.',
        'h1'         => 'Contrato dispatcher-carrier',
        'h1_hl'      => 'Contrato {hl}dispatcher-carrier{/hl}',
        'breadcrumb' => 'Contrato dispatcher-carrier',
    ],
    '/resources/glossary/' => [
        'path'       => '/es/recursos/glosario/',
        'title'      => 'Glosario de Trucking: Términos de Dispatch | NorthStar',
        'meta'       => 'Glosario de trucking de un equipo de dispatch: deadhead, TONU, rate con, lumper, detention y más, explicados en una o dos líneas.',
        'h1'         => 'Glosario de trucking',
        'h1_hl'      => 'Glosario de {hl}trucking{/hl}',
        'breadcrumb' => 'Glosario de trucking',
    ],
    '/blog/' => [
        'path'       => '/es/blog/',
        'title'      => 'Blog de Dispatch de Camiones | NorthStar',
        'meta'       => 'Guías prácticas para owner-operators y flotas pequeñas: cuánto cuesta el dispatch, cómo funciona Amazon Relay y cómo cobrar lumper y detention.',
        'h1'         => 'Desde el equipo de dispatch',
        'breadcrumb' => 'Blog',
    ],
    '/blog/how-much-does-truck-dispatch-cost/' => [
        'path'       => '/es/blog/cuanto-cuesta-un-servicio-de-dispatch/',
        'title'      => '¿Cuánto Cuesta el Servicio de Dispatch? | NorthStar',
        'meta'       => 'Cómo cobran los dispatchers: porcentaje del gross, tarifa semanal o por carga. Qué cuesta cada modelo a un owner-operator y qué debería incluir.',
        'h1'         => '¿Cuánto cuesta un servicio de dispatch de camiones?',
        'breadcrumb' => '¿Cuánto cuesta un servicio de dispatch de camiones?',
    ],
    '/blog/amazon-relay-requirements/' => [
        'path'       => '/es/blog/requisitos-de-amazon-relay/',
        'title'      => 'Requisitos de Amazon Relay para Carriers | NorthStar',
        'meta'       => 'Requisitos de Amazon Relay para carriers: autoridad, código SCAC, seguro, equipo y los pasos para que te aprueben, en lenguaje sencillo.',
        'h1'         => 'Requisitos de Amazon Relay para carriers',
        'breadcrumb' => 'Requisitos de Amazon Relay',
    ],
    '/blog/amazon-relay-load-board/' => [
        'path'       => '/es/blog/load-board-de-amazon-relay/',
        'title'      => 'Cómo Funciona el Load Board de Amazon Relay | NorthStar',
        'meta'       => 'Cómo funciona el load board de Amazon Relay: cargas one-way, round trips y bloques, y cómo se publican, reservan y siguen en la app de Relay.',
        'h1'         => 'Cómo funciona el load board de Amazon Relay',
        'breadcrumb' => 'Load board de Amazon Relay',
    ],
    '/blog/lumper-fees-detention-pay/' => [
        'path'       => '/es/blog/lumper-fees-y-detention-pay/',
        'title'      => 'Lumper Fees y Detention Pay: Cómo Cobrar Ambos | NorthStar',
        'meta'       => 'Lumper fees y detention pay: qué documentar en el muelle para que los recibos de lumper y las horas de detention aparezcan en tu settlement.',
        'h1'         => 'Lumper fees y detention pay: cómo cobrar ambos',
        'breadcrumb' => 'Lumper fees y detention pay',
    ],
    '/careers/' => [
        'path'       => '/es/trabaja-con-nosotros/',
        'title'      => 'Trabajo de Chofer y Alianzas con Owner Operators | NorthStar',
        'meta'       => 'Trabajo de chofer y alianzas con owner operators: NorthStar es un servicio de dispatch, no una oferta de empleo. Asóciate con el equipo o mira vacantes.',
        'h1'         => 'Trabajo de chofer y alianzas con owner operators',
        'h1_hl'      => 'Trabajo de chofer y {hl}alianzas con owner operators{/hl}',
        'breadcrumb' => 'Trabaja con nosotros',
    ],
    '/careers/owner-operators/' => [
        'path'       => '/es/trabaja-con-nosotros/owner-operators/',
        'title'      => 'Trabajo para Owner Operators: Asóciate con un Dispatcher',
        'meta'       => '¿Buscas trabajo de owner operator? NorthStar es una alianza de dispatch, no un empleo: tú apruebas cada carga y el equipo la reserva, sigue y documenta.',
        'h1'         => 'Trabajo para owner operators: asóciate con un dispatcher',
        'breadcrumb' => 'Owner operators',
    ],
    '/careers/apply/' => [
        'path'       => '/es/trabaja-con-nosotros/aplicar/',
        'title'      => 'Empieza tu Solicitud | NorthStar Freight',
        'meta'       => 'Aplica para trabajar con el equipo de dispatch de NorthStar: cuatro pasos cortos, sin llamada primero. Escríbenos por WhatsApp si prefieres.',
        'h1'         => 'Empieza tu solicitud',
        'breadcrumb' => 'Solicitud',
    ],
    '/careers/thank-you/' => [
        'path'       => '/es/trabaja-con-nosotros/gracias/',
        'title'      => 'Solicitud Recibida | NorthStar Freight',
        'meta'       => 'Recibimos tu solicitud. Esto es lo que pasa ahora con el equipo de dispatch de NorthStar.',
        'h1'         => 'Solicitud recibida',
        'breadcrumb' => 'Solicitud recibida',
    ],
    '404' => [
        'path'       => '/es/404/',
        'title'      => 'Página No Encontrada | NorthStar Freight',
        'meta'       => 'La página que buscas no existe. Habla con un dispatcher de NorthStar por WhatsApp o vuelve al inicio.',
        'h1'         => 'Página no encontrada',
        'breadcrumb' => 'Página no encontrada',
    ],
];
