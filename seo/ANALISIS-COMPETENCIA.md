# Análisis de competencia: Ninja Dispatch (fase AF, 2026-11-11)

Fuente: DataForSEO Labs (Google EE. UU., inglés, datos de oct 2026) y los sitemaps públicos de ninjadispatch.com. **Limitación:** el HTML de ninjadispatch.com está detrás de Cloudflare y bloquea clientes automáticos; no se eludió el bloqueo, así que no hay lectura de sus titles, H1 ni copy actual, solo de lo que posicionan y cómo está organizado el sitio.

## 1. Qué hace Ninja (y qué tan fuerte es de verdad)

- **Tamaño:** 130 keywords en Google EE. UU., tráfico orgánico estimado muy bajo (el home suma ~30 de ETV; el resto de páginas, de 0 a 7 cada una). Es débil en SEO: solo 30 de las 130 están en top 10 y 3 de esas son su propia marca.
- **Arquitectura:** WordPress con Rank Math; 78 entradas de blog (2018 a 2026), 36 páginas, de ellas **15 páginas por estado (más el hub `/locations/`)** (`/locations/kentucky/`, etc.), un grupo de **8 páginas de dispatch nocturno / after-hours**, páginas de **Samsara** y **McLeod**, `pricing`, `faq`, `our-team`, `careers`.
- **Dónde gana (top 10):** nicho y marca. Car hauler (3 coches, Kaufman, CDL para car haulers), tow truck dispatch, **night dispatch / overnight dispatcher / dispatch hours** (posiciones 2 a 8), "dispatch company" (8, 720/mes), Samsara.
- **Dónde es débil (aprovechable):** los términos comerciales centrales los posiciona con el home en posiciones 15 a 35: "dispatching services for truckers" 720 (pos. 19), "trucking dispatch services" 590 (20), "truck dispatching company" 390 (17), "freight dispatch service" 170 (15), "dispatch services for owner operators" 260 (11, con un post de 2018), y todo el bloque de **precio** ("price dispatch" 2.900, "how much does dispatch cost" 1.300, "dispatch fee" 140…) en posiciones 22 a 35.
- **Lo que NO tiene:** ninguna keyword de **Amazon Relay**, dry van, power only, box truck, seguimiento de cargas ni back office, y ninguna en español, en su top 130. Es el diferencial de NorthStar y tiene KD 0 a 4.
- **Contenido de bajo valor para copiar:** páginas por estado (la de Kentucky: 1 keyword, posición 11 a 20, ETV 0,08), entradas de empleo ("how to become a dispatcher" 2.400, posición 69), reseñas de remolques. No encajan con un servicio remoto ni con la regla de §7.5.


## 1b. Lectura del sitemap completo de Ninja (36 páginas y 78 entradas)

- **Giro de posicionamiento:** todo lo publicado en 2025 y 2026 trata de dispatch nocturno / after-hours y de dispatch gestionado (frente a call centers y a un dispatcher interno), con páginas nuevas para flotas y brokers (`night-after-hours-fleet-manager`, `reefer-after-hours-dispatch`, `overnight-freight-coordinator`, `supply-chain-dispatch-service`). Ya no apunta de frente al owner-operator.
- **Segmento descuidado:** su contenido para owner-operators (`truck-dispatching-services-for-owner-operators`, `how-much-do-dry-van-owner-operators-make`, `dispatch-service-cost`) tiene última modificación entre 2021 y 2022. El hueco para NorthStar es owner-operators y flotas pequeñas.
- **Ritmo:** unas 14 entradas en 2026, casi todas opinión de mercado (aranceles, petróleo, ciclo del flete), de poco valor de búsqueda.
- **Páginas de confianza que sí tiene:** `pricing`, `why-ninja`, `dashboard` (visibilidad para el cliente), `our-team`, `faq`, `careers`. Idea aprovechable: una página propia del «Desk log» (visibilidad del cliente) si Carlos lo aprueba; la de precio solo con respaldo del cliente (§8.2).
- **Precio (verificado con el HTML del home):** publican **$30 por hora cubierta** y «sin contrato largo»; también va en su schema (`priceRange`).

## 1c. Home de Ninja Dispatch (HTML recibido de Carlos, 2026-11-11)

**Metadatos:** title «Managed Night Dispatch Service | After-Hours Coverage» (52), meta de 155, robots `follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large` (igual que el que añadimos), canonical, OG y Twitter, sin hreflang. WordPress + Flatsome + Rank Math + WP Rocket + GA4 + Cloudflare.

**Secciones en orden:** hero (H1 eslogan «Your Office Closes. Your Freight Doesn't. Neither Do We.», línea «No hiring. No training. No overnight management.», un solo CTA a Calendly, franja de pruebas Google 5★ / since 2018 / FreightWaves / BBB, 4 beneficios) → problema con 4 «soluciones de siempre» (personal de día, contratar un nocturno, contestadora, agencia) → cómo funciona (dispatcher dedicado + equipo detrás, «tú mantienes el control») → testimonio con nombre y empresa + «100+ operaciones desde 2018» → 6 tareas y 6 enlaces por tipo de operación → sistemas (McLeod, Samsara, Motive) y portal `/dashboard/` → video de 43 min con capítulos → precio ($30 por hora, onboarding en 4 pasos, «2 a 3 semanas») → CTA final y FAQ.

**Debilidades técnicas aprovechables:** H1 sin la keyword; `VideoObject` con fecha 2016 y descripción genérica de YouTube; `openingHours` 09:00–17:00 en un servicio nocturno; dirección solo con código postal; `sameAs` solo con Facebook; sin FAQPage en el home; «#1 por FreightWaves» en schema y pie pero «Listed in…» en la página (afirmación inconsistente); sin español; sin WhatsApp; sin enlaces al blog desde el home.

**Qué adaptar (con las reglas de §8.2):** esquema problema → alternativas habituales → nuestro modelo (redacción neutra, sin atacar a nadie); línea de negaciones más prominente; franja de pruebas **solo con datos del cliente** (fundación, BBB, reseñas de Google); capítulos de video con `VideoObject`/`Clip` correctos cuando exista el video real; página propia del «Desk log» como equivalente a su portal. **No copiar:** páginas por estado, McLeod/Samsara, «#1», plazos de onboarding sin respaldo.

## 2. Qué copiamos, qué mejoramos y qué descartamos

| Estrategia de Ninja | Decisión | Aplicación |
|---|---|---|
| Home como página de «dispatch company / services for truckers» | **Copiar y mejorar** | Meta del home ahora: «Truck dispatch company for truckers and small fleets…» (title sin cambios). Cubre «dispatch company» 720, «dispatching services for truckers» 720, «truck dispatching company» 390 en un solo snippet. |
| Bloque de precio (post de costes con muchas variantes) | **Copiar y mejorar** | Ya existe `/blog/how-much-does-truck-dispatch-cost/`. Se añadieron 2 preguntas con la redacción exacta que se busca: «What is a dispatch fee?» y «How is a dispatcher fee calculated?» (FAQPage), con definición directa para snippet y AI Overview. Mejora frente al SERP: los resultados que ganan publican porcentajes (5 a 10 %, «8 %»); NorthStar no los publica (§8.2), así que el ángulo es **comparar los tres modelos, qué incluye y qué preguntar**, con la calculadora de rate per mile. |
| Dispatch nocturno / after-hours (8 páginas) | **Cubrir sin competir de frente** | Ninja ya ocupa las posiciones 2 a 8 con 8 páginas para ~90 + 70 + 50 búsquedas al mes. NorthStar declara cobertura 24/7: FAQ nueva «Do you offer night or after-hours dispatch?» en el home. No se crea página propia hasta que Carlos valide el 24/7 (§10) y se vea demanda real en Search Console. |
| «Near me» (dispatchers near me 1.000, dispatching company near me 210, dispatch companies near me 170, dispatch service near me 90) | **Mejorar** | FAQ nueva «Is there a dispatch company near me?» (home): servicio remoto, no hace falta oficina cerca, EE. UU. completo, WhatsApp. Sin páginas por ciudad. |
| Páginas por estado (15) | **Descartar** | Casi sin tráfico y duplican contenido; un servicio remoto no tiene oficina por estado. Revisar solo si el cliente atiende un estado en particular y hay dato real. |
| Samsara / McLeod | **Descartar** | NorthStar no ha afirmado ese software (§8.1: no presentar plataformas que no tenga). |
| Car hauler, tow truck | **Descartar** | Equipos no ofrecidos. |
| Entradas de empleo y «how to become a dispatcher» | **Descartar** | Intención de empleo/curso (§7.5). |
| Español | **Ventaja propia** | Ninja no aparece en español en su top 130; `/es/` ya existe en NorthStar. |

## 3. Long tail y brechas (EN, EE. UU.; volumen mensual)

KD de DataForSEO Labs cuando está disponible. «Hecho» = ya cubierto en el sitio.

| Keyword | Vol. | KD | Intención | Dónde atacarla | Estado |
|---|---|---|---|---|---|
| dispatchers near me | 1.000 | n/d | local/comercial | FAQ home (nueva) | Hecho |
| dispatching service for truckers / dispatch services for truckers | 590 a 720 | 15 a 31 | C | meta del home | Hecho |
| dispatch truck service | 720 | 20 | C | home | Cubierto por «truck dispatch services» |
| dispatch service / truck dispatch service / dispatch service trucking | 590 | 5 a 15 | C | home | Cubierto |
| truck dispatching company / dispatch company | 390 / 720 | 13 / 32 | C | meta del home («dispatch company») | Hecho |
| dispatch service for owner operator(s) / owner operator dispatch service | 320 / 260 | 11 | C | `/dispatch-for-owner-operators/` | Hecho |
| box truck dispatch service | 320 | 2 | C | `/box-truck-dispatch/` | **Mayor oportunidad pendiente**: página lista en `draft`; pasa a live cuando el cliente confirme que despacha box truck |
| dispatch fee / dispatcher fee | 140 | n/d | I | post de costes (FAQ nueva) | Hecho |
| what is a dispatch fee | 70 | n/d | I | post de costes (FAQ nueva) | Hecho |
| dispatching company near me / dispatch companies near me | 210 / 170 | 1 | C | FAQ home | Hecho |
| freight dispatch service | 170 | 16 | C | home («In short» y servicios) | Cubierto parcialmente; ver §4 |
| freight dispatching companies | 140 | 16 | C | home | Pendiente de decidir (§4) |
| dispatch service near me | 90 | 19 | local | FAQ home | Hecho |
| best dispatch service for owner operators | 90 | 13 | C | owner (FAQ) | Pendiente (§4) |
| night dispatch / overnight dispatcher / after hours dispatch | 90 / 70 / 30 | 8 | I/C | FAQ home | Cubierto con FAQ; página propia solo si Carlos lo valida |
| flatbed dispatch service | 70 | 6 | C | `/flatbed-dispatch/` | `draft` hasta confirmación |
| hot shot dispatch service | 70 | n/d | C | `/hotshot-dispatch/` | `draft` hasta confirmación |
| power only dispatch service | 30 | n/d | C | `/power-only-dispatch/` | Hecho |
| dry van dispatch service | 30 | n/d | C | `/dry-van-dispatch/` | Hecho |
| dat zone / DAT load board | 1.000 | n/d | I | blog (guía de cómo se usan los load boards) | Plan (§5) |
| amazon relay dispatch | 40 | 0 | C | `/amazon-relay-dispatch/` | Hecho; sin competencia directa de Ninja |

**SERP «what is a dispatch fee trucking»** (consulta en vivo): hay AI Overview, bloque «People also ask» («What does a dispatch fee cover?», «What does a trucking dispatcher do?», «What are TONU fees?») y resultados de glosarios y foros que publican porcentajes. Respuestas cortas y directas, con FAQPage, son el formato que gana.

## 4. Decisiones para Carlos (no aplicadas por tocar title/H1/H2 o por faltar dato)

1. **Subir «freight dispatch service» (170) y «freight dispatching companies» (140)** al home: meta ya usa el espacio completo; la opción segura es añadirlas al texto del «In short» del home (`app/data/summaries.php`) sin cambiar título ni H2. Pendiente de tu visto bueno por regla de copy §7.6.
2. **Box truck, flatbed, hotshot, reefer:** ratio volumen/KD muy bueno (box truck 320 con KD 2). Confirmar con el cliente qué equipos despacha hoy para pasar las páginas a `live`.
3. **Cobertura 24/7:** la FAQ de dispatch nocturno se apoya en `COVERAGE_TEXT` (respuesta del cliente de la fase S, marcada «a validar por Carlos» en §12). Si no se valida, quitar la pregunta del home.
4. **Porcentaje de dispatch:** el SERP de «dispatch fee» premia a quien publica un número; NorthStar cotiza por operación. Si el cliente decide publicar un rango, el post de costes es el sitio, con respaldo del cliente (§8.2).

## 5. Plan de contenido para tráfico inicial (orden de ataque)

Cada pieza: título con la keyword, respuesta directa de 2 frases arriba, H2 por pregunta real, FAQPage, enlace con anchor exacto a su servicio y versión en español. Solo afirmaciones ya hechas en el sitio (§8.2).

| # | Pieza | Keyword | Vol. | Por qué primero |
|---|---|---|---|---|
| 1 | Cómo hacer dispatch de un camión (`/blog/how-to-dispatch-a-truck/`) | how to dispatch a truck | 320 (KD 6) | Alta intención de cliente y tarea ya definida (§7.3); necesita guion revisado |
| 2 | Qué hace un dispatcher (ampliar la FAQ del home a artículo) | what does a truck dispatcher do | KD 0, PAA | Ya hay FAQ; artículo captura el snippet y el PAA |
| 3 | Dispatch fee explicada: modelos, qué incluye, qué preguntar | dispatch fee / dispatcher fee | 140 + 70 | Variantes con KD bajo; Ninja en posición 22 a 35 |
| 4 | Cómo se usan los load boards (DAT) | dat zone / load board | 1.000 / 8.100 | Volumen alto, KD bajo, conecta con dry van y Relay |
| 5 | Qué es Amazon Relay (EN y ES) | qué es amazon relay | 140 | Ninja no compite; nicho propio |
| 6 | Freight broker vs dispatcher | freight broker vs dispatcher | 110 (KD 0) | Intención informativa que lleva al servicio |
| 7 | Box truck dispatch (página de servicio, tras confirmar) | box truck dispatch service | 320 (KD 2) | La mejor oportunidad de la tabla |
| 8 | Dispatch nocturno (solo si se valida 24/7 y hay demanda en Search Console) | night dispatch | 90 | Ninja ya domina; baja prioridad |

Métricas para el seguimiento en Search Console (guía §1 de `GUIAS-LANZAMIENTO.md`): consultas de «dispatch fee», «near me», «truckers» y «amazon relay» en 4 a 8 semanas tras abrir producción, y comparación EN vs `/es/`.
