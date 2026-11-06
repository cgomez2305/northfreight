# Fuentes que NO se implementaron en la fase Q4b

## 1. Bolsas de empleo (Indeed, ZipRecruiter, CDLjobs): diseño, sin código

El documento maestro (§4) propone un cron que lea por IMAP un buzón dedicado cada 5 minutos y extraiga los datos de las aplicaciones. **No se construyó** por tres razones:

1. **No hay muestras reales** de los correos de esas plataformas. Cada una usa su propio formato (HTML con tablas, enlaces a un panel, adjuntos de CV, a veces solo un aviso «hay una aplicación nueva» sin datos). Parsear a ciegas produciría leads con datos inventados o mal asignados.
2. **La extensión `imap` de PHP no está garantizada** en el hosting compartido (en Hostinger depende de la versión de PHP y del plan). Un cron que falla por falta de extensión no aporta nada.
3. Muchas aplicaciones de bolsas de empleo traen datos de candidatos a **empleados** (CV, historial laboral): es un perfil distinto al de captación de owner-operators y arrastra más obligaciones (EEO, retención). Conviene decidirlo con el abogado antes.

### Diseño propuesto (cuando haya muestras)

**Opción A (recomendada): reenvío a n8n.**
1. En cada bolsa, el correo de las aplicaciones apunta a un buzón dedicado (p. ej. `aplicaciones@northstarfreightsolutions.us`).
2. n8n lee ese buzón con el nodo *Email Trigger (IMAP)* (n8n Cloud sí lo soporta) o recibe un reenvío.
3. Un nodo *Code* extrae nombre, teléfono, correo, estado y puesto con expresiones regulares **escritas contra las muestras reales**; si falta nombre o contacto, el correo se deja en una carpeta «revisar a mano» y se avisa por Telegram.
4. n8n llama a `POST /api/lead.php` con `source = indeed` (o `ziprecruiter`, `cdljobs`), `profile = company_driver` y el texto del anuncio en `landing_page`. El motor ya deduplica, puntúa y avisa; la **idempotencia** se logra con `site_lead_id` (p. ej. el `Message-ID` del correo recortado a 40 caracteres).
5. Sin consentimiento de mensajería: un candidato que aplica por una bolsa no aceptó mensajes de NorthStar. Solo se le puede llamar a mano o escribir por correo cuando sea una respuesta directa a su aplicación (decisión legal).

**Opción B: parser IMAP en PHP** (`cron/imap_jobboards.php`). Solo si el hosting confirma `imap` y se tienen las muestras. Reutilizaría `LeadIngest::handle()` y la tabla `webhook_events` para la idempotencia (`source = 'imap'`, clave = `Message-ID`).

### Qué hace falta para empezar

- 3 a 5 correos reales **anonimizados** de cada bolsa (aplicación nueva, con y sin teléfono, con adjunto).
- Confirmar si las bolsas permiten un reenvío automático o solo notificación.
- Confirmar con NorthStar si quiere vacantes de company drivers publicadas (hoy `/vacantes` y el sitio ya soportan vacantes reales) antes de abrir este canal.
- Decisión legal sobre consentimiento y retención de datos de candidatos.
- Nuevas claves de `Catalog::SOURCES` (`indeed` ya existe; `ziprecruiter`, `cdljobs` no).

## 2. «Send me my results» de la calculadora del sitio: propuesta

El doc maestro (§4) incluye un POST al pulsar «Send me my results» en la calculadora de rate per mile. **No se implementó** porque exige cambios en la interfaz y el copy del sitio público, que no son parte de esta fase.

### Propuesta

- **UI:** bajo el resultado de `/resources/rate-per-mile-calculator/`, un botón secundario «Email me these results» que abre un campo de correo (y, opcional, teléfono) con la casilla de consentimiento **sin marcar** y el texto de `CONSENT_TEXT_V1` (o la v2 con WhatsApp cuando exista). Nada de enviar sin casilla.
- **Copy (EN, para validar con Carlos):** título «Send me my results», ayuda «We email you the numbers you typed. No spam», casilla = texto de consentimiento. La nota «Estimate only» ya existe.
- **Backend:** `POST /careers/apply/`-style en el sitio (`app/forms/lead.php` nuevo formulario `'form' => 'calc'`) con CSRF sin sesión, honeypot, límite por IP y los mismos pasos que el formulario de Careers; entrega al CRM con `source = web_calculator`, `profile = owner_operator` (por defecto) y las cifras de la calculadora como `landing_page`/nota (sin guardar más de lo que el visitante escribió).
- **Correo de resultados:** plantilla EN/ES del CRM (`email_templates`, clave `calc_results`) enviada por el motor de secuencias con el pie CAN-SPAM; las cifras son las que el visitante tecleó y llevan el aviso de «estimación».
- **Secuencia:** el lead entra como tibio/frío y puede inscribirse en A6 solo si dio el consentimiento de correo.
- **Medición:** evento `calculator_results_requested` en `dataLayer` solo si ya existe.

Hace falta decidir: copy exacto, si se pide teléfono, y si el correo de resultados cuenta como «mensaje transaccional» o como marketing (consulta al abogado). Estimación de trabajo: sitio (formulario, JS y CSS) + una plantilla + 1 fuente nueva en el catálogo; el CRM ya recibe todo lo demás.
