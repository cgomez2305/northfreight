# Guía de conexión de cuentas (para Carlos)

> Paso a paso para conectar WhatsApp, Meta Lead Ads, Cal.com, Twilio, n8n y el correo del dominio al CRM. **Todo lo del CRM está probado con servidores simulados; nada se probó contra los servicios reales.** Los pasos de las pantallas de Meta, Twilio, Cal.com y n8n están escritos de memoria y esas pantallas cambian: si algo no coincide, manda la documentación oficial del servicio.

Dominio del CRM en esta guía: `https://captacion.northstarfreightsolutions.us`. Las claves van en `captacion/config/.env` del servidor (por SSH; `chmod 600`) y **nunca** en el código ni en chats. Después de editar el `.env` no hace falta reiniciar nada.

## Orden recomendado

1. **Correo del dominio (SMTP)** y dirección física: sin eso no sale ningún correo (secuencias, recordatorios, reactivación).
2. **n8n Cloud** (lo necesitan WhatsApp, A1/A4/A5/A6/A7 y Google Sheets).
3. **Cal.com** (lo más simple: solo un webhook).
4. **WhatsApp Cloud API** (Meta Business; tarda por la verificación del negocio y las plantillas).
5. **Meta Lead Ads** (cuando haya anuncios; necesita la app de Meta y revisión).
6. **Twilio + A2P 10DLC** (último: el registro tarda días o semanas y los SMS no son urgentes).

Cada paso termina con **«Probar»** en `Configuración > Integraciones` (firma y envía un evento de prueba al propio endpoint; no crea datos) y con un **caso real**.

## Variables nuevas del `.env`

| Variable | Para qué |
|---|---|
| `META_VERIFY_TOKEN` | Texto que inventas (mínimo 8 caracteres) y pegas también en Meta al configurar el webhook |
| `META_APP_SECRET` | «Clave secreta de la app» de Meta (firma los eventos) |
| `META_PAGE_TOKEN` | Token de acceso de la página (para pedir los datos del lead a Graph API) |
| `META_GRAPH_VERSION` | Opcional, p. ej. `v21.0`. Vacío = sin versión en la URL. **Ajusta a la versión vigente** |
| `CALCOM_WEBHOOK_SECRET` | Secreto que escribes al crear el webhook en Cal.com |
| `TWILIO_TOKEN` | «Auth Token» de Twilio (firma los SMS entrantes) |
| `TWILIO_SID`, `TWILIO_FROM` | Reservadas para enviar SMS (hoy el CRM no envía) |
| `TWILIO_WEBHOOK_URL` | Opcional. Solo si Twilio firma una URL distinta de `APP_URL` + `/api/webhooks/sms.php` |
| `N8N_WEBHOOK_SECRET` | Secreto (HMAC) con el que n8n firma sus eventos a `/api/webhooks/n8n.php` |
| `INTERNAL_API_TOKEN` | Ya existe (≥ 32 caracteres). Es el Bearer de `/api/lead.php` y de `/internal/*` |
| `WEBHOOK_RATE_LIMIT`, `WEBHOOK_MAX_BODY`, `WEBHOOK_SIG_FAILS`, `INTERNAL_RATE_LIMIT` | Opcionales (1200 peticiones/hora, 131072 bytes, 15 firmas malas/15 min, 1500/hora) |
| `SELF_BASE_URL` | Solo pruebas: base a la que el botón «Probar» se llama a sí mismo (por defecto `APP_URL`) |

Genera secretos con `/opt/alt/php83/usr/bin/php -r 'echo bin2hex(random_bytes(24));'`.

## URLs de los webhooks

| Servicio | URL (método) |
|---|---|
| Meta Lead Ads | `https://captacion.northstarfreightsolutions.us/api/webhooks/meta.php` (GET verificación y POST) |
| Cal.com | `https://captacion.northstarfreightsolutions.us/api/webhooks/calcom.php` (POST) |
| Twilio | `https://captacion.northstarfreightsolutions.us/api/webhooks/sms.php` (POST) |
| n8n → CRM | `https://captacion.northstarfreightsolutions.us/api/webhooks/n8n.php` (POST) |
| n8n → CRM (REST) | `https://captacion.northstarfreightsolutions.us/internal/...` (Bearer) |

También aparecen listas para copiar en cada tarjeta de **Configuración > Integraciones**.

---

## 1. Correo del dominio (SMTP)

1. En hPanel crea un buzón con el dominio (p. ej. `hola@northstarfreightsolutions.us`).
2. En el `.env`: `SMTP_HOST=smtp.hostinger.com`, `SMTP_PORT=465`, `SMTP_SECURE=ssl`, `SMTP_USER=<buzón>`, `SMTP_PASS=<contraseña>`, `MAIL_TO=<donde recibes los avisos>`.
3. DNS del dominio: registros **SPF, DKIM y DMARC** (hPanel > Correos > DNS). Sin ellos los correos caen en spam.
4. En `Configuración > Correo`: escribe la **dirección física** (CAN-SPAM) y enciende las secuencias solo cuando quieras que salgan correos.
5. Prueba: `Configuración > Integraciones > Correo (SMTP) > Probar`.

## 2. n8n Cloud

1. Crea la cuenta en n8n Cloud.
2. **Credenciales** (Credentials > New):
   - *Header Auth* llamada **Captacion CRM (Bearer)**: nombre de cabecera `Authorization`, valor `Bearer <INTERNAL_API_TOKEN>`.
   - *Header Auth* llamada **WhatsApp Cloud API (Bearer)**: `Authorization` = `Bearer <token permanente de WhatsApp>` (ver §4).
   - *WhatsApp Trigger* (OAuth de la app de Meta) para el flujo A7.
   - *Google Sheets OAuth2* para la entrega a la hoja.
3. **Importar** cada JSON de `captacion/docs/n8n/` (Workflows > Import from file). Abre cada flujo, asigna las credenciales a los nodos HTTP (los nombres de los marcadores son `REEMPLAZAR_*`) y edita el nodo **Config**: `wa_phone_number_id` (el ID del número en Meta) y, si hace falta, `graph_version`. **Ningún JSON trae secretos.**
4. **No actives** un flujo hasta que su plantilla de WhatsApp esté aprobada y su paso habilitado en `Configuración > Canales externos > n8n`. Los flujos son un borrador **no probado en n8n real**.
5. Flujo de Google Sheets: en `Configuración > Entrega a NorthStar` crea el destino «webhook», pega la URL de producción del flujo `entrega-google-sheets` y copia el secreto que muestra el CRM **una sola vez** al nodo Config del flujo. Crea en la hoja las columnas del nodo («Fecha de entrega», «Lead ID», «Nombre»…).
6. Prueba: ejecuta a mano el flujo A1 y mira en el CRM la línea de tiempo del lead (evento «Mensaje enviado»). Para n8n → CRM con firma: `Configuración > Integraciones > n8n > Probar`.

## 3. Cal.com

1. En Cal.com crea el tipo de evento (p. ej. «Llamada con NorthStar») y **pide el teléfono** en las preguntas de la reserva (el CRM busca al lead por correo y por teléfono).
2. Settings > Developer > Webhooks > New: URL del webhook de Cal.com, **Secret** = `CALCOM_WEBHOOK_SECRET`, eventos *Booking created*, *Booking rescheduled* y *Booking cancelled*.
3. `.env`: `CALCOM_WEBHOOK_SECRET=<el mismo secreto>`.
4. En `Configuración > Canales externos > Cal.com` pega el **enlace de reserva** (lo usa A4).
5. Prueba: `Probar` (envía un PING firmado). Caso real: reserva una llamada con un correo de prueba; deben aparecer la cita y la tarea «Llamada con…» en `/tareas`, el lead en «Llamada agendada» y, con consentimiento de correo, el recordatorio 24 h y 1 h antes. Cancela y comprueba que vuelve a «Calificado».

## 4. WhatsApp Cloud API (Meta)

1. Crea o usa un **Meta Business** y verifícalo (documentos del negocio; puede tardar días).
2. developers.facebook.com > Crear app (tipo Business) > añadir el producto **WhatsApp**.
3. Añade el **número de teléfono** (no puede estar ya en la app de WhatsApp normal) y anota su **Phone number ID**.
4. **Token permanente:** Business Settings > Users > System users > crea uno, asígnale la app y la cuenta de WhatsApp con permisos `whatsapp_business_messaging` y `whatsapp_business_management`, y genera el token. Guárdalo solo en la credencial de n8n.
5. **Plantillas:** WhatsApp Manager > Message templates > crea las de `whatsapp-templates.md` (EN y ES) y espera la aprobación.
6. **Webhook de mensajes entrantes:** lo gestiona el nodo *WhatsApp Trigger* de n8n (te da la URL y la suscribe). Suscríbete a `messages`.
7. Habilita los pasos en `Configuración > Canales externos > n8n` (solo los de plantillas aprobadas).
8. Prueba: escribe desde tu teléfono al número de la empresa. En el CRM debe aparecer el lead (o el mensaje en uno existente), con el consentimiento `whatsapp_inbound` y la ventana de 24 h; Carlos recibe el aviso por Telegram.

## 5. Meta Lead Ads

1. En la misma app de Meta añade **Webhooks** y el producto **Lead Ads**/permiso `leads_retrieval`. Permisos que suelen hacer falta: `leads_retrieval`, `pages_manage_metadata`, `pages_read_engagement`, `pages_show_list`. Para usarlo con anuncios reales la app pasa **revisión de Meta** y se pone en modo «Live».
2. **Token de página** (`META_PAGE_TOKEN`): token de larga duración de la página que lanza los anuncios.
3. Webhooks > objeto **Page** > Callback URL = webhook de Meta, **Verify token** = `META_VERIFY_TOKEN` > suscríbete al campo **leadgen**. Meta hace un GET: si el `.env` tiene el mismo token, el CRM responde el `hub.challenge`.
4. Suscribe la página a la app (leadgen) con el token de página.
5. `.env`: `META_VERIFY_TOKEN`, `META_APP_SECRET`, `META_PAGE_TOKEN` (y opcional `META_GRAPH_VERSION`).
6. **Mapeo de campos:** `Configuración > Canales externos > Meta`. Escribe el nombre de cada pregunta personalizada del formulario tal como la manda Meta y a qué dato corresponde. **Consentimiento:** pega el texto exacto del aviso del formulario, los canales que cubre y mapea la pregunta de aceptación; sin las tres cosas el lead entra sin consentimiento de mensajería.
7. Prueba: `Probar` (verificación + evento de prueba) y la **Lead Ads Testing Tool** de Meta (envía un lead de prueba). El lead debe aparecer con fuente «Meta Ads», sus UTM (campaña, anuncio, formulario) y las preguntas sin mapear anotadas en su línea de tiempo.

## 6. Twilio + A2P 10DLC

1. Crea la cuenta de Twilio y compra un número local de EE. UU. (o toll-free; los requisitos de registro cambian).
2. **A2P 10DLC:** registra la marca (datos de NorthStar LLC) y la campaña (caso de uso, textos de ejemplo, texto de opt-in y de ayuda). Tarda días o semanas; **hasta que se apruebe, no se envía ningún SMS comercial.**
3. En el número: Messaging > «A message comes in» = webhook de Twilio (POST). Opcional: *Status callback URL* = el mismo webhook (actualiza el estado de entrega).
4. `.env`: `TWILIO_TOKEN` (Auth Token). Si la URL que Twilio firma no coincide con `APP_URL`, `TWILIO_WEBHOOK_URL`.
5. Respuesta a HELP: `Configuración > Canales externos > Twilio` (borrador por defecto; que lo revise el abogado).
6. Prueba: `Probar` (SMS de prueba firmado, sin efectos). Caso real: manda STOP, HELP y START desde un teléfono de prueba que sea un lead con consentimiento; revisa que STOP revoque SMS y WhatsApp, HELP responda y START restablezca solo el SMS.

---

## Cómo probar sin esperar a los servicios (solo en tu equipo)

`php captacion/tests/run.php --only=25_`, `--only=26_`, `--only=27_`, `--only=28_` corre las pruebas de cada canal con servidores simulados (Graph API, SMTP, Telegram). Sirven para ver los formatos exactos de las peticiones, que además están en `captacion/README.md` §20.

## Si algo falla

| Síntoma | Qué mirar |
|---|---|
| La tarjeta dice «Sin configurar» | Faltan variables en el `.env` (la tarjeta dice cuáles) |
| «Firma rechazada» repetida (campana) | La clave del `.env` no es la que tiene el servicio. Corrígela y pulsa «Probar» |
| `Probar` dice «no se pudo llegar» | `APP_URL` mal puesta o el subdominio sin SSL |
| Meta responde 503 | Falta `META_PAGE_TOKEN` (Meta reintenta más tarde; no se pierde nada) |
| Leads de Meta sin datos | Preguntas sin mapear (línea de tiempo del lead) o token vencido (error rojo en la tarjeta) |
| n8n no envía nada | Paso no habilitado en Canales externos, lead sin consentimiento de WhatsApp, o credencial sin asignar |
| El cron no corre | `process_jobs.php` cada minuto (recordatorios, Meta y reactivación dependen de él) |
