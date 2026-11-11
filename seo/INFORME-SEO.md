# Informe SEO, fase AF (2026-11-11)

Rama `claude/seo-fase-af`. Alcance: auditoría técnica EN/ES, investigación de keywords, mejoras on-page, plan de blog y guías de lanzamiento. No se tocó `captacion/`, `ENV` ni producción.
Las guías paso a paso (Search Console, Google Business Profile, Bing, analítica y lanzamiento) están en `seo/GUIAS-LANZAMIENTO.md`.

## 1. Cómo se auditó

- Local (`php -S`): las 48 URLs del sitemap de staging y las 44 del sitemap con `ENV=production` simulado (BOL y agreement desaparecen en los dos idiomas, por `DOWNLOADS_APPROVED=false`).
- En vivo, solo lectura (`curl`): `/`, `/es/`, `robots.txt`, `sitemap.xml`, `llms.txt`, 404 en ambos idiomas, `www`, `http`, `//`.
- Por página: title, meta, H1 único, H2 repetidos, canonical, `lang`, hreflang recíproco, Open Graph/Twitter, JSON-LD válido y tipos, alt de imágenes, enlaces internos (HEAD a cada uno) y enlaces de `/es/` hacia inglés.
- Sin Lighthouse en esta fase (no se tocó CSS ni JS; las cifras de §11 de CLAUDE.md siguen vigentes).

## 2. Hallazgos

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| 1 | Alta | `https://www.northstarfreightsolutions.us/` (y `/es/`) respondía **200** con el mismo contenido que el apex (la regla www→apex del `.htaccess` estaba comentada). El canonical apunta al apex, pero es contenido duplicado y reparte señales. | **Corregido** en `.htaccess` (301 en un solo salto, antes del forzado HTTPS). Hay que desplegar y verificar con `curl -I https://www.northstarfreightsolutions.us/`. |
| 2 | Alta | H1 en español calcados del inglés en 12 páginas ("Dry van servicio de dispatch", "Power only dispatch servicios", "IFTA calculadora", "Rate por milla calculadora", "Trucking glosario", "Back office de trucking servicios", "Bill of lading plantilla", "Dispatcher-carrier contrato", "Trabajo de chofer y owner operator alianzas", etc.). La plantilla traducía el H1 por trozos (por el acento cian `{hl}`) y rompía el orden de palabras; además el H1 visible no coincidía con el `h1` de `routes.es.php` que usa el JSON-LD. | **Corregido**: el partial `hero` toma `h1_hl` de `routes.es.php` en `/es/`. H1 visible = H1 de ruta = nombre en JSON-LD. |
| 3 | Media | JSON-LD en `/es/`: la Organization tenía otro `@id` y `url` (`/es/#organization`), es decir, dos entidades para la misma empresa; `contactType` y `availableLanguage` se traducían ("servicio al cliente", "Inglés"). | **Corregido**: un solo `@id` `…/#organization` y `url` raíz en los dos idiomas; `contactType` y `availableLanguage` fuera del traductor (códigos `en`, `es`). |
| 4 | Media | `llms-full.txt` no incluía el español (solo `llms.txt`). | **Corregido**: sección «Español» también en `llms-full.txt`. |
| 5 | Media | `/es/trabaja-con-nosotros/` mostraba en inglés "No openings listed right now…" y la respuesta de la FAQ "Not right now…". El auditor de i18n lo marcaba y CLAUDE.md decía «audit en 0». | **Corregido** (traducidos). |
| 6 | Baja | Titles en español con cada palabra en mayúscula (anglicismo; en español basta mayúscula inicial). No es error técnico; cambiarlo es estilo, así que **no se tocó**. Decisión de Carlos. | Abierto |
| 7 | Baja | Cadena de redirecciones `http://apex/es` → `https://apex/es` → `/es/` (2 saltos). Normal con barra final tras HTTPS; Google la sigue. | Abierto (opcional) |
| 8 | Info | Sitemap sin `lastmod` (a propósito: no hay fecha real; CLAUDE.md §4). Los artículos no tienen `datePublished` hasta que el cliente dé fechas. | Pendiente cliente |
| 9 | Info | Staging: `X-Robots-Tag: noindex, nofollow`, `robots.txt` con `Disallow: /` y `llms.txt` con noindex, todo coherente con `ENV=staging`. Con `ENV=production` simulado: sin noindex, robots abierto con la línea `Sitemap` y bots de IA, sitemap de 44 URLs con `xhtml:link`. | OK |

Sin incidencias: title ≤ 60 y meta ≤ 155 en las 48 URLs, un H1 por página, sin H2 repetidos, canonical autorreferente, `lang` correcto (`en-US`/`es-US`), hreflang `en-us`/`es-us`/`x-default` recíproco en head y sitemap, OG y Twitter por idioma con `og:locale` y alternate, JSON-LD válido en todas las páginas, todas las imágenes con atributo `alt`, 0 enlaces internos rotos, las páginas `/es/` solo enlazan a inglés en el selector de idioma y en privacidad/términos (sin versión española a propósito), 404 en inglés y español con `noindex`, sin `Fatal error` ni `Warning` en las rutas probadas.

## 3. Investigación de palabras clave (DataForSEO, Google Ads EE. UU., últimos 12 meses hasta sep 2026)

Volumen mensual del último mes disponible; KD de DataForSEO Labs. Intención: C comercial, I informativa, E empleo/curso (se evita, CLAUDE.md §7.5). "n/d" = Google Ads no devolvió volumen en esta consulta.

### Inglés

| Keyword | Vol. | KD | Int. | Página | Estado |
|---|---|---|---|---|---|
| truck dispatch services | 590 | 20 | C | `/` | Hecho (title) |
| dispatch services for truckers | 590 | 15 | C | `/` | Mismo grupo que el anterior; "truckers" no está en title/meta del home. Candidato (ver §5) |
| dispatch services for owner operators | 260 | 11 | C | `/dispatch-for-owner-operators/` | Hecho |
| how to dispatch a truck | 320 | 6 | I | `/blog/how-to-dispatch-a-truck/` | Pendiente de escribir (plan §6) |
| truck dispatcher | 5.400 | 0 | I/E mixta | `/` y blog | No atacar como principal (mezcla empleo); cubierto con la FAQ nueva "What does a truck dispatcher do?" |
| what does a truck dispatcher do | n/d | 0 | I | `/` (FAQ) y blog | FAQ añadida (fase AF) |
| freight factoring | 880 | 7 | I (CPC US$ 62,9) | blog | Plan §6; NorthStar no ofrece factoring: solo contenido educativo |
| amazon relay dispatch | 40 | 0 | C | `/amazon-relay-dispatch/` | Hecho |
| power only dispatch | 20 | 0 | C | `/power-only-dispatch/` | Hecho |
| dry van dispatch | 10 | 0 | C | `/dry-van-dispatch/` | Hecho (volumen mínimo; cola larga) |
| truck load tracking | 30 | 27 | C | `/load-tracking/` | Hecho |
| trucking back office services | 10 | 4 | C | `/trucking-back-office/` | Hecho |
| how does truck dispatching work | n/d | 36 | I | `/how-it-works/` | Hecho |
| box truck dispatch | 320 (CLAUDE.md §7.4) | 0 | C | `/box-truck-dispatch/` | Draft hasta que el cliente confirme equipo |
| freight broker vs dispatcher | 110 (CLAUDE.md) | 0 | I | blog | Plan §6 |
| amazon relay review | 140 (CLAUDE.md) | 4 | I | blog | Plan §6 |
| load board | 8.100 | n/d | I (busca el tablero, no el servicio) | `/blog/amazon-relay-load-board/` cubre el ángulo Relay | No atacar el genérico |

### Español (location EE. UU., `language_code=es`)

| Keyword | Vol. | Int. | Página | Estado |
|---|---|---|---|---|
| amazon relay en español | 110 | I | `/es/dispatch-amazon-relay/` | Hecho ("en Español" en el title) |
| amazon relay requisitos | 70 | I | `/es/blog/requisitos-de-amazon-relay/` | Hecho |
| bill of lading en español | 70 | I | `/es/recursos/plantilla-bol/` | **Title nuevo** "Bill of Lading en Español: Plantilla de BOL" (descarga aún bloqueada en producción) |
| dispatcher de camiones | 40 | C | `/es/` | **Meta nueva** del home la incluye |
| trabajo de dispatcher | 40 | E | no atacar | Evitar |
| despachador de camiones | 20 | C | `/es/` | Sinónimo; no se fuerza |
| que es un dry van | 20 | I | glosario / `/es/dispatch-dry-van/` | Cubierto por el glosario |
| ifta en español | 20 | I | `/es/recursos/calculadora-ifta/` | **Title nuevo** con "en Español" |
| cómo ser dispatcher de camiones | 20 | E | no atacar | Evitar |
| servicio de dispatch | 10 | C | `/es/` | Hecho |
| dispatch de camiones | 10 | C | `/es/` | Hecho (title) |
| qué es amazon relay | 140 (estudio previo, CLAUDE.md) | I | blog ES nuevo | Plan §6 |

Conclusión: el volumen en español es muy bajo (decenas al mes); el valor de `/es/` es captar cola larga y conversión directa por WhatsApp, no tráfico masivo. Lo que más mueve es el cluster inglés (590 + 260) y los informativos de Relay en los dos idiomas. Varias consultas pedidas ("dispatch para camioneros", "dispatch para owner operators", "calculadora ifta", "load board camiones") no devuelven volumen en Google Ads: se consideran sin demanda medible.

### No hecho (declarado)

- **Brechas frente a competidores** (Ninja Dispatch, Lean Solutions, Logity, NTS Logistics): no se ejecutó análisis de ranked keywords ni de SERP/AI Overviews en esta pasada; no se gastó presupuesto de OpenSEO. Siguiente paso recomendado con `dataforseo_labs_google_ranked_keywords` y `domain_intersection` (coste bajo).
- No se midió Lighthouse ni posiciones reales (el sitio sigue en staging con noindex, no hay datos de Search Console).

## 4. Cambios hechos (antes / después)

Title, meta, H1 y H2 **en inglés: cero diferencias** en las rutas live (comparado antes y después). Solo se tocó el español, con evidencia de la tabla anterior o por error de redacción.

| Ruta | Campo | Antes | Después | Razón |
|---|---|---|---|---|
| `/es/` | meta | "Servicio de dispatch de camiones para owner-operators y flotas pequeñas: Amazon Relay, dry van, seguimiento y back office. Escríbenos por WhatsApp." | "Dispatcher de camiones y servicio de dispatch para owner-operators y flotas pequeñas: Amazon Relay, dry van, seguimiento y back office. Escríbenos." (147) | "dispatcher de camiones" 40/mes frente a "dispatch de camiones" 10 |
| `/es/recursos/plantilla-bol/` | title | Plantilla de BOL (Bill of Lading) en PDF y Word \| NorthStar | Bill of Lading en Español: Plantilla de BOL \| NorthStar (55) | "bill of lading en español" 70/mes; "PDF y Word" prometía formatos aún no aprobados |
| `/es/recursos/calculadora-ifta/` | title | Calculadora IFTA para Owner Operators \| NorthStar | Calculadora IFTA en Español para Camioneros \| NorthStar (55) | "ifta en español" 20/mes |
| 12 rutas `/es/` | H1 visible | ver hallazgo 2 | = `h1` de `routes.es.php` | Español natural; coincide con el JSON-LD |
| `/` y `/es/` | FAQ + FAQPage | 6 preguntas | + "What does a truck dispatcher do?" / "¿Qué hace un dispatcher de camiones?" | KD 0, intención informativa; respuesta tomada de copy ya afirmado en el sitio (careers) |
| `/es/` | JSON-LD | Organization `/es/#organization`, `servicio al cliente`, `Inglés` | `@id` único, `customer service`, `en`/`es` | entidad única |
| `.htaccess` | www | 200 en www | 301 a apex | duplicado |
| `llms-full.txt` | español | ausente | sección «Español» | GEO |

Comprobaciones: `php bin/i18n.php check` OK; `audit` en 26 apariciones, todas falsos positivos de URLs `og:image` más ninguna cadena real (había 2 reales sin traducir y ya están). Sin CSS ni JS tocados, no hizo falta build. Sin guiones largos nuevos, sin código de pruebas en el repo.

## 4b. Segunda pasada (misma fase): base técnica y competencia

Aplicado tras la petición de Carlos de dejar la base técnica completa y trabajar la competencia (detalle y datos en `seo/ANALISIS-COMPETENCIA.md`):

- **Titles en español en minúscula de frase** (24 rutas; hallazgo 6 resuelto): «Dispatch de camiones para owner operators», «Cómo funciona el load board de Amazon Relay», etc. Mismas palabras, solo la capitalización.
- **Meta del home en inglés** ahora «Truck dispatch company for truckers and small fleets: …» (152): suma «dispatching services for truckers» (720) sin perder «dispatch company» (720). Title sin cambios.
- **Meta robots en páginas indexables** (producción): `index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1`; las `noindex` no cambian.
- **FAQ nuevas con keywords de competencia** (EN y ES, FAQPage): home «Is there a dispatch company near me?» (near me, ~1.470 búsquedas entre variantes) y «Do you offer night or after-hours dispatch?» (usa `COVERAGE_TEXT`, 24/7 pendiente de validar por Carlos); post de costes «What is a dispatch fee?» y «How is a dispatcher fee calculated?» (140 + 140 + 70).
- **«In short» del home** menciona «freight dispatch service» (170).
- Sin cambios en H1/H2 en inglés. Hallazgo 7 (cadena http→https→barra final) se deja: arreglarlo exige tocar `.htaccess` sin poder probarlo aquí (el servidor local no lo lee) y el beneficio es mínimo.

## 5. Recomendaciones que necesitan decisión de Carlos

1. ~~«truckers» en el meta del home~~ y ~~titles en español~~: aplicados en la segunda pasada (§4b).
2. Validar el 24/7 (la FAQ nocturna se apoya en él) y decidir si el cliente publica un rango de tarifa de dispatch (ver `seo/ANALISIS-COMPETENCIA.md` §4).
3. Un solo salto http → https con barra final (hallazgo 7), si se quiere pulir.
4. Fechas reales de los artículos (`published` en `posts.php`) para `datePublished` y `lastmod`.
5. Ampliar FAQ en relay, owner y dry van: el sitio no afirma más de lo que ya dice, así que no se inventó nada; hacen falta respuestas del cliente (contrato, autoridades nuevas, cargos).

## 6. Plan de blog (EN y ES)

Reglas: solo afirmaciones seguras sobre NorthStar (§8.2); el tema educativo del sector puede ser general y sin cifras. Cada pieza enlaza a su servicio con la keyword como anchor. ES = adaptación, no calco.

| # | EN | ES | Keyword | Vol. / KD | Int. | Esquema (H2) | Enlaces internos |
|---|---|---|---|---|---|---|---|
| 1 | How to dispatch a truck (`/blog/how-to-dispatch-a-truck/`) | Cómo hacer dispatch de un camión | how to dispatch a truck | 320 / 6 | I | Qué es dispatchar; paso a paso (buscar, negociar, confirmar, seguir, cerrar papeleo); qué revisar en cada carga; cuándo conviene un equipo | `/how-it-works/`, `/dispatch-for-owner-operators/` |
| 2 | What does a truck dispatcher do? | ¿Qué hace un dispatcher de camiones? | what does a truck dispatcher do | n/d / 0 | I | Tareas diarias; dispatcher vs broker; qué recibe el owner-operator; qué no hace | `/`, `/how-it-works/` |
| 3 | Freight broker vs dispatcher | Freight broker vs dispatcher | freight broker vs dispatcher | 110 / 0 | I | Quién contrata a quién; quién cobra qué; ejemplo de una carga; qué elegir | `/dispatch-for-owner-operators/` |
| 4 | Freight factoring explained for owner-operators | Factoring de carga para owner-operators | freight factoring | 880 / 7 | I | Qué es; flujo de pago; qué preguntar al contrato; relación con el settlement (NorthStar no ofrece factoring: guía educativa) | `/trucking-back-office/`, `/load-tracking/` |
| 5 | Amazon Relay review: what carriers should know | Amazon Relay: reseña para carriers | amazon relay review | 140 / 4 | I | Cómo funciona; scorecard; disputas; dónde ayuda un desk (solo lo ya afirmado) | `/amazon-relay-dispatch/` |
| 6 | What is Amazon Relay? | ¿Qué es Amazon Relay? | qué es amazon relay | ES 140 | I | Definición; tipos de carga (one-way, round trip, bloques); cómo se reserva; primeros pasos | `/amazon-relay-dispatch/`, `/blog/amazon-relay-requirements/` |
| 7 | What is a check call and why it matters | Qué es un check call y por qué importa | check calls trucking | n/d | I | Qué se reporta; ETA; cuándo avisar al broker; evidencia para el POD | `/load-tracking/` |
| 8 | Power only dispatch: how it works | Cómo funciona el dispatch power only | power only dispatch | 20 / 0 | C/I | Qué es; qué se coordina (tractor, recogida y entrega del trailer); documentos | `/power-only-dispatch/` |
| 9 | What is a dry van? | Qué es un dry van | what is a dry van | EN 1.900 (§7.2) / ES 20 | I | Definición; qué carga lleva; cómo se compara con otros equipos (sin cifras) | `/dry-van-dispatch/`, `/resources/rate-per-mile-calculator/` |
| 10 | Rate per mile: how to know if a load pays | Tarifa por milla: cómo saber si una carga paga | rate per mile | calculadora 170 | I | Fórmula; ejemplo con la mini calculadora; costos fuera de la tarifa | `/resources/rate-per-mile-calculator/` |

Prioridad: 1, 2, 6, 5, 3, 4. Detention y lumper ya están cubiertos por el artículo existente (no canibalizar). El artículo 1 **no se escribió ni registró** en `routes.php`: sin un guion revisado por Carlos habría que afirmar prácticas de dispatch que el cliente no ha validado; queda con su esquema listo.

## 7. Pendientes del cliente (relevantes para SEO)

Fechas reales de publicación; nombre legal, dirección y perfiles (`LEGAL_NAME`, `ADDRESS`, `SAME_AS`, `FOUNDING_DATE`); equipos adicionales (box truck, reefer, flatbed, hotshot); BOL y agreement aprobados; textos legales; equipo, video y testimonios reales; Google Business Profile y propiedad de Search Console; correo con dominio propio. Revisión por un hablante nativo del español (CLAUDE.md fase AE).
