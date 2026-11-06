# Cumplimiento: cómo lo aplica el sistema y qué decide el abogado

> No es asesoría legal. Es el resumen de lo que el CRM hace hoy, qué queda pendiente y qué debe revisar un abogado en EE. UU. antes de enviar mensajes reales. Nada de esto se probó contra un servicio real.

## 1. Mapa rápido

| Tema | Qué exige (resumen) | Lo que hace el sistema hoy | Lo que decide el abogado |
|---|---|---|---|
| **TCPA** (llamadas y SMS automatizados a celulares) | Consentimiento expreso y por escrito para mensajes automatizados o de marketing; revocación fácil | Casilla **sin marcar** y texto exacto guardado por canal (`consents`: texto, versión, IP, agente, fecha); un STOP por SMS revoca SMS y WhatsApp al instante; cada envío comprueba el consentimiento | Texto `CONSENT_TEXT_V1`; si un STOP debe cortar también correo y llamadas automáticas; vigencia del consentimiento; registros de prueba |
| **A2P 10DLC** | Registrar marca y campaña antes de enviar SMS comerciales por número local de EE. UU. | **No se envía ningún SMS saliente.** `/internal/sequences/due?channel=sms` devuelve vacío salvo que se active a mano; el SMS de respaldo a Carlos (A3) sigue «parked» | Descripción de la campaña, textos de ejemplo, texto de opt-in y de ayuda |
| **WhatsApp Business Policy** | Primer mensaje de la empresa con plantilla aprobada; texto libre solo dentro de las 24 h siguientes a un mensaje del usuario; opt-in para marketing | Ventana de 24 h guardada (`wa_last_inbound_at`); `consent-check` distingue `opt_in` de `inbound_window`; ver §3 | Opt-in de WhatsApp (nueva versión del texto del sitio o aviso de Meta), uso de plantillas de marketing |
| **CAN-SPAM** (correo) | Remitente real, dirección física, baja de un clic, respetar la baja | Pie obligatorio con dirección física (sin ella no se envía nada), baja `/u/{token}` de un clic con `List-Unsubscribe-Post`, topes de envío | Dirección que se imprime, nombre del remitente, retención de bajas |
| **Llamadas en frío** (prospectos FMCSA) | Respetar listas de no llamar (internas, estatales y la nacional), horarios permitidos | Llamadas **siempre manuales**; botón Llamar solo de 8 a. m. a 9 p. m. hora local; lista interna permanente «No llamar más» | Consulta de la lista nacional y estatales, reglas por estado, scripts |
| **No discriminación** | No usar criterios prohibidos | Nunca se pregunta nacionalidad, estatus migratorio, edad ni raza; el bot tampoco (ver `bot-whatsapp-spec.md`); descarte solo por CDL domiciliada o inglés | Redacción de las preguntas de elegibilidad y declaración EEO |
| **Privacidad** | Política publicada; acceso y borrado | Política del sitio (texto del abogado pendiente); borrado lógico en el panel | Menciones de `localStorage`, IP, mensajería, terceros (Meta, Twilio, n8n, Cal.com) |

## 2. Cómo trata el sistema cada tipo de consentimiento

- **Formulario del sitio** (`CONSENT_TEXT_V1`): cubre **llamada, SMS y correo**. **No cubre WhatsApp.**
- **Meta Lead Ads:** el lead entra **sin consentimiento de mensajería**, salvo que el formulario traiga la aceptación: hace falta el texto exacto del aviso de ese formulario, los canales que cubre y la pregunta (o casilla) que lo confirma (Configuración > Canales externos). Si el aviso de Meta cubre WhatsApp, el consentimiento de WhatsApp se registra y entonces A1 y A6 pueden usarlo.
- **WhatsApp entrante** (el lead escribió primero): se registra `whatsapp_inbound` con el texto y la hora del primer mensaje. **Solo permite responder dentro de 24 h.** No es consentimiento de marketing: `isGranted('whatsapp')` sigue en falso.
- **Cal.com:** reservar una llamada **no** da consentimiento de mensajería. El recordatorio por correo exige consentimiento de correo vigente; si no lo hay, no se envía y queda anotado en la línea de tiempo.
- **Baja de correo:** `/u/{token}` revoca el correo y detiene todas las secuencias.
- **STOP por SMS:** revoca **SMS y WhatsApp** (incluida la ventana de 24 h) y pone las banderas `stop_sms`, `stop_whatsapp`, `stop_whatsapp_inbound`. **No** toca el correo ni la llamada manual. START, YES o UNSTOP restablecen **solo el SMS** y solo si ese lead ya había dado consentimiento de SMS; nunca levantan un STOP total puesto a mano.

## 3. Qué falta antes de enviar WhatsApp o SMS automáticos a leads del sitio

1. Ampliar el texto de consentimiento del sitio a **v2 con WhatsApp** (o apoyarse solo en leads que escriben primero). Hasta entonces, A1 y los pasos de marketing **no** se ofrecen a leads del sitio (el CRM lo impide en `consent-check` y en `/internal/sequences/due`).
2. Aprobar las plantillas (`whatsapp-templates.md`).
3. Registrar A2P 10DLC (marca + campaña) en Twilio antes del primer SMS.
4. Revisión del abogado de los textos de consentimiento, STOP/HELP y las respuestas automáticas (`SmsInbound::DEFAULT_HELP` es un borrador).

## 4. Reactivación de leads perdidos (A11)

Por correo, apagada por defecto, con interruptor propio. Reglas: lead perdido hace 60 días o más (configurable de 30 a 365), pipeline A, consentimiento de correo vigente, sin STOP ni baja, fuera de «No llamar más», no descartado, no firmado, no rechazado por NorthStar, una sola vez por lead. Los textos son prudentes (sin cifras ni promesas) y llevan `[[CONFIRMAR]]`: no se envían hasta que Carlos los edite. Pregunta para el abogado: cuánto tiempo después de una pérdida sigue siendo razonable escribir con el consentimiento original.

## 5. Datos y proveedores

El sistema guarda datos personales de los leads (contacto, equipo, USDOT, mensajes entrantes recortados, consentimientos). Pasan por: Meta (leads de anuncios), Cal.com (citas), Twilio (SMS), Meta/WhatsApp y n8n (mensajería), Telegram (avisos a Carlos, con nombre y teléfono), Hostinger (hosting). Cada uno necesita su política y, si aplica, un acuerdo de tratamiento de datos. Los documentos de los conductores **no** pasan por este sistema.

## 6. Pendiente de confirmar (resumen)

- Propiedad de los leads y qué pasa con ellos si termina la relación con NorthStar.
- Texto de consentimiento v2 (WhatsApp) y revisión de `CONSENT_TEXT_V1`.
- Si un STOP debe revocar también correo y llamadas automáticas (hoy: solo SMS y WhatsApp).
- Textos de HELP, STOP y del bot.
- Dirección física que se imprime en los correos.
- Retención de mensajes entrantes y de `webhook_events` (el CRM purga los eventos a los 60 días).
