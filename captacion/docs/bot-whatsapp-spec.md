# Especificación del bot de precalificación por WhatsApp (A8)

> **Es una especificación, no un bot construido.** Aquí se define qué pregunta, qué guarda, cuándo para y a quién avisa, y cómo habla con el CRM. No se probó contra WhatsApp, n8n ni ningún modelo de lenguaje. Antes de ponerlo en marcha: revisión del abogado (`compliance.md`) y plantillas aprobadas (`whatsapp-templates.md`).

## 1. Objetivo y alcance

El bot atiende **solo a quien escribe primero** por WhatsApp (o responde a una plantilla). Hace tres cosas: completa los datos que faltan del lead, responde preguntas frecuentes con lo que NorthStar ya afirma en su sitio, y **pasa a Carlos únicamente a los aptos** (o a quien pide hablar con una persona). No vende, no promete, no negocia precios y no decide quién es apto: solo recoge datos y el CRM puntúa.

## 2. Dónde corre

n8n recibe el mensaje (WhatsApp Trigger, flujo `a7-pausa-al-responder.json`), consulta al CRM con `/internal/*` (Bearer `INTERNAL_API_TOKEN`) y llama a un modelo de lenguaje para redactar la respuesta. El CRM es la fuente de verdad: el bot nunca guarda datos por su cuenta.

## 3. Reglas legales y de política (no negociables)

1. **Ventana de 24 h.** Texto libre solo dentro de las 24 h siguientes al último mensaje del lead. Antes de CADA respuesta: `POST /internal/leads/{id}/consent-check {"channel":"whatsapp"}`; si `allowed` es `false` o `free_text_allowed` es `false`, el bot **no responde** (si hay opt-in y la ventana se cerró, solo plantilla aprobada).
2. **STOP manda.** Si el lead escribe STOP, BAJA, "no me escribas", "unsubscribe" o similares: el bot **no argumenta**, envía un único mensaje de confirmación (EN/ES), reporta `bot_finished` con `outcome = opted_out` (el CRM revoca WhatsApp) y se calla.
3. **Nunca pregunta ni infiere:** nacionalidad, estatus migratorio, lugar de nacimiento, edad, raza, religión, género, estado civil, discapacidad, ni pide documentos (licencia, pasaporte, seguro, SSN). Si el lead los menciona por su cuenta, el bot **no los guarda** y sigue sin comentarlos.
4. **No promete** ingresos, tarifas, cargas, tiempos de respuesta, cobertura 24/7, resultados ni condiciones de contrato. No da cifras ni porcentajes. No afirma lo que no esté en el sitio.
5. **Se identifica como asistente automático** en el primer mensaje de cada conversación ("Soy el asistente automático de NorthStar") y ofrece hablar con una persona.
6. **No da asesoría legal, fiscal ni de seguros.** Redirige a una persona.
7. Un solo idioma por conversación: el del lead (`language` del CRM; si el lead escribe en el otro, cambia).
8. **Máximo 10 mensajes del bot sin respuesta del lead.** Sin respuesta, el bot se calla y reporta `abandoned`.

## 4. Flujo de preguntas

El bot pregunta **una cosa por mensaje**, solo lo que falta (el CRM ya sabe lo demás: se lee del lead al empezar con `GET /internal/leads/by-phone` y, si hace falta, el contexto que n8n guarde de la conversación). Orden:

| # | Dato | Pregunta (EN) | Pregunta (ES) | Campo del PATCH |
|---|---|---|---|---|
| 1 | Nombre | "What is your first name?" | "¿Cómo te llamas?" | (se guarda al crear el lead con `/api/lead.php`) |
| 2 | Perfil | "Are you an owner-operator, a small fleet, or a company driver?" | "¿Eres owner-operator, flota pequeña o conductor de empresa?" | (cambia el flujo; ver §5) |
| 3 | Estado | "Which state are you based in?" | "¿En qué estado estás?" | `state` |
| 4 | Equipo | "What equipment do you run? (dry van, reefer, flatbed, box truck, hotshot, power only)" | "¿Qué equipo manejas?" | `equipment_types` |
| 5 | Camiones | "How many trucks?" | "¿Cuántos camiones?" | `truck_count` |
| 6 | USDOT | "Do you have a USDOT number? You can send it here." | "¿Tienes número USDOT? Puedes enviarlo aquí." | `usdot` |
| 7 | Experiencia | "How many years have you been driving or dispatching?" | "¿Cuántos años llevas manejando o despachando?" | `years_experience` |
| 8 | Autoridad | "Is your authority active, in process, or do you not have one yet?" | "¿Tu autoridad está activa, en trámite o aún no tienes?" | `authority_status` |
| 9 | CDL (solo company drivers) | "Is your CDL issued by your state of residence?" | "¿Tu CDL fue emitida por tu estado de residencia?" | `cdl_type` (`standard`, `non_domiciled`, `unsure`) |
| 10 | Inglés (solo company drivers) | "How would you rate your English: good, basic, or none?" | "¿Cómo es tu inglés: bueno, básico o ninguno?" | `english_level` (`yes`, `basic`, `no`) |
| 11 | Mejor horario | "What is the best time for a call?" | "¿A qué hora te llamamos mejor?" | `best_time` |

Notas: los pasos 9 y 10 se hacen **como en el formulario del sitio** ("emitida por tu estado de residencia", nada de nacionalidad ni estatus). Un `non_domiciled` o inglés `no` los **puntúa y descarta el CRM** (lead guardado, visible para revisión manual): el bot **no le dice al lead que fue descartado** ni lo rechaza; cierra con el mensaje neutro de §6.

Cada respuesta se valida en el CRM: `PATCH /internal/leads/{id}` devuelve `rejected` por campo (`invalid`, `already_set`, `phone_in_use`). Si un campo vuelve `invalid`, el bot repite la pregunta **una vez** con otro texto y, si falla, la salta y pasa a la siguiente.

## 5. Cuándo escala a Carlos (`handoff-ready` o `needs_human`)

| Situación | Acción |
|---|---|
| El lead pide hablar con una persona, llamar, o dice "agent", "persona", "llámame" | `bot_finished` con `needs_human` + `POST /internal/leads/{id}/handoff-ready` |
| Completó los datos y el CRM le da `temperature = hot` o `warm` (respuesta del PATCH) | `handoff-ready` con un resumen de 1 a 2 líneas (solo lo que dijo el lead) y `bot_finished` con `qualified` |
| Pregunta algo que el bot no puede responder con el sitio (precio, contrato, cobertura, casos concretos) | `needs_human`; al lead: "Eso te lo explica mejor una persona del equipo. Le paso tu mensaje." |
| Menciona una queja, un problema legal, una carga activa con un incidente o una emergencia | `needs_human` inmediato |
| Tres respuestas ininteligibles seguidas | `needs_human` |
| Lead ya firmado (`stage = signed`) que escribe | No lo atiende el bot: `needs_human` |

`handoff-ready` crea la tarea «Llamar a X» y avisa a Carlos por Telegram; **no cambia la etapa** (eso lo decide Carlos).

## 6. Mensajes del bot (plantilla de tono: operador a operador, frases cortas)

- Primer mensaje (dentro de la ventana): EN "Hi, I am NorthStar's automatic assistant. I can ask you a few quick questions so the team has what it needs, or you can ask for a person any time." / ES "Hola, soy el asistente automático de NorthStar. Puedo hacerte unas preguntas rápidas para que el equipo tenga lo que necesita, o puedes pedir hablar con una persona cuando quieras."
- Cierre neutro (siempre el mismo, aptos o no): EN "Thanks. The team will review what you sent. If you have questions, reply here." / ES "Gracias. El equipo revisará lo que enviaste. Si tienes preguntas, responde aquí."
- No entendí: EN "Sorry, I did not get that. Could you say it another way?" / ES "Perdón, no entendí. ¿Puedes decirlo de otra forma?"
- Fuera de alcance: EN "That is better answered by a person on the team. I will pass your message along." / ES "Eso lo responde mejor una persona del equipo. Le paso tu mensaje."
- Baja: EN "Done. You will not get more WhatsApp messages from us." / ES "Listo. No recibirás más mensajes de WhatsApp de nuestra parte."
- Error interno (n8n no pudo hablar con el CRM): el bot responde "Dame un momento." una vez y avisa por `event error`; no inventa datos.

## 7. Preguntas frecuentes que puede responder

Solo con texto que ya está afirmado en el sitio (`northstarfreightsolutions.us`): qué es un dispatch desk remoto, que se atiende por WhatsApp sin formulario previo, que hay herramientas gratuitas (calculadora de rate per mile, IFTA), BOL y modelo de acuerdo como descargas. **No** puede responder: precio o modelo de cobro, equipos que se despachan, horarios, contrato, cobertura, Amazon Relay en detalle (los textos están marcados `[PLACEHOLDER]` en el sitio). Para eso: `needs_human`.

## 8. Prompt de sistema propuesto

```
Eres el asistente automático de WhatsApp de NorthStar Freight Solutions, una empresa de dispatch remoto para owner-operators y flotas pequeñas en EE. UU.
Hablas en el idioma del usuario (inglés o español), con frases cortas y trato de operador a operador. Una pregunta por mensaje.
Tu trabajo: (1) completar los datos que faltan de la lista que recibes en CONTEXTO, (2) responder preguntas usando SOLO la sección HECHOS, (3) pasar a una persona cuando corresponda.
NUNCA preguntes ni guardes nacionalidad, estatus migratorio, lugar de nacimiento, edad, raza, religión, género, estado civil, discapacidad ni documentos. Si el usuario los menciona, ignóralos sin comentarlos.
NUNCA prometas ingresos, tarifas, cargas, tiempos de respuesta, cobertura 24/7 ni condiciones de contrato; no des cifras ni porcentajes; no inventes datos de la empresa.
No des asesoría legal, fiscal ni de seguros. Si no sabes algo, di que lo responde una persona del equipo y marca needs_human.
Si el usuario pide STOP, baja o que no le escribas: confirma en una frase y marca opted_out. No insistas.
Preséntate como asistente automático en el primer mensaje.
Responde SOLO con un JSON: {"reply": "...", "fields": {campo: valor}, "action": "continue|needs_human|qualified|opted_out"}. "fields" solo puede tener: state, equipment_types, truck_count, usdot, years_experience, authority_status, cdl_type, english_level, best_time, language.
CONTEXTO: {datos que ya tiene el lead y cuáles faltan, en una lista}
HECHOS: {texto aprobado del sitio, copiado aquí por Carlos}
```

n8n **valida el JSON** antes de usarlo: si `fields` trae una clave fuera de la lista o `action` no es uno de los cuatro valores, se descarta la respuesta y se escala a una persona. El modelo nunca llama al CRM directamente: lo hace n8n, con el token en su credencial.

## 9. Esquema de las llamadas al CRM

Todas con `Authorization: Bearer <INTERNAL_API_TOKEN>` y `Content-Type: application/json`. Detalle y ejemplos en `captacion/README.md` §20.

| Cuándo | Llamada | Cuerpo |
|---|---|---|
| Llega un mensaje de un número | `GET /internal/leads/by-phone?phone=+1305…` | |
| Número nuevo | `POST /api/lead.php` | `{"source":"n8n_whatsapp","profile":"owner_operator","name":"…","phone":"+1…","inbound":{"channel":"whatsapp","text":"…","at":"ISO","message_id":"wamid…"}}` |
| Lead existente escribe | `POST /internal/leads/{id}/events` | `{"event":"reply_received","channel":"whatsapp","text":"…","message_id":"wamid…","event_id":"in-wamid…"}` |
| Antes de responder | `POST /internal/leads/{id}/consent-check` | `{"channel":"whatsapp"}` → `{allowed, basis, free_text_allowed, window_expires_at, reason}` |
| El lead dio un dato | `PATCH /internal/leads/{id}` | `{"state":"TX","truck_count":3}` → `{updated, rejected, score, temperature}` |
| El bot respondió | `POST /internal/leads/{id}/events` | `{"event":"message_sent","channel":"whatsapp","template":"bot_reply","message_id":"wamid…","event_id":"…"}` |
| Listo para Carlos | `POST /internal/leads/{id}/handoff-ready` | `{"summary":"…"}` |
| Termina la conversación | `POST /internal/leads/{id}/events` | `{"event":"bot_finished","outcome":"qualified\|needs_human\|not_qualified\|abandoned\|opted_out","summary":"…","event_id":"…"}` |
| Algo falla | `POST /internal/leads/{id}/events` | `{"event":"error","workflow":"A8","error":"…","event_id":"…"}` |

Cada `event_id` debe ser único y estable por evento (8 a 80 caracteres): reintentar el mismo no duplica nada.

## 10. Qué falta para construirlo

Plantillas aprobadas, número de WhatsApp Business conectado, credenciales en n8n, textos `HECHOS` aprobados por NorthStar, elección del modelo y revisión del abogado de §3. Hasta entonces el CRM ya está listo (webhooks, API interna, consentimiento y ventana de 24 h), y el flujo `a7-pausa-al-responder.json` deja el mensaje registrado y a Carlos avisado.
