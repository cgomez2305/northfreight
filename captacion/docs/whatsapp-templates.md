# Plantillas de WhatsApp para aprobación de Meta

> Borrador para el Administrador de plantillas de WhatsApp (Meta Business). **Nada de esto está aprobado ni probado contra Meta.** Meta decide la categoría final y puede rechazar o reclasificar cualquier plantilla. Los textos no llevan cifras, garantías, tiempos de respuesta ni afirmaciones que NorthStar no haya confirmado.

## Reglas que conviene recordar al enviarlas (verifícalas en el Administrador de plantillas, cambian)

- Nombre en minúsculas con guiones bajos; un idioma por plantilla. Aquí cada texto va **dos veces**: `_en` (idioma `en_US`) y `_es` (idioma `es`). El flujo de n8n elige `ns_<clave>_<idioma>` según el idioma del lead.
- Las variables son posicionales (`{{1}}`, `{{2}}`) y **Meta pide un valor de ejemplo por variable**. No las pongas al principio ni al final del texto ni seguidas.
- **Utilidad (UTILITY):** mensajes ligados a algo que el usuario ya pidió o a una transacción (recordatorio de una cita que reservó). Sin contenido promocional.
- **Marketing (MARKETING):** todo lo que busca que el lead haga algo comercial (secuencias de seguimiento, pedido de referidos). **Exige consentimiento de WhatsApp (opt-in) del lead**; mira `compliance.md`.
- Si una plantilla de utilidad contiene algo promocional, Meta la reclasifica a marketing (cambia el precio y las reglas). Los textos de abajo evitan lo promocional.
- La plantilla solo abre la conversación. Dentro de las 24 h siguientes a un mensaje del lead se puede responder con texto libre (el bot, ver `bot-whatsapp-spec.md`).
- Los avisos de baja: las plantillas de marketing llevan un botón de respuesta rápida «Stop» / «Dejar de recibir» y el cuerpo menciona STOP.

## Qué variable es cada una

El flujo de n8n arma los parámetros con los datos que entrega `GET /internal/sequences/due` (`vars.nombre`, `vars.booking_url`, `vars.link_calculadora`) y `GET /internal/reminders/due` (`vars.nombre`, `when_text`).

## Plantillas

### 1. `ns_a1_welcome_en` / `ns_a1_welcome_es` (A1, bienvenida)

Categoría propuesta: **UTILITY** (confirma una solicitud que el lead hizo). Si Meta la pasa a MARKETING, necesita opt-in de WhatsApp.

| Variable | Ejemplo |
|---|---|
| `{{1}}` nombre | Mike |

EN:
```
Hi {{1}}, thanks for your interest in NorthStar Freight Solutions. We received your request about dispatch. You can reply here with any question. If you would rather not get WhatsApp messages from us, reply STOP.
```
ES:
```
Hola {{1}}, gracias por tu interés en NorthStar Freight Solutions. Recibimos tu solicitud sobre dispatch. Puedes responder aquí con cualquier pregunta. Si prefieres no recibir mensajes por WhatsApp, responde STOP.
```

### 2. `ns_a4_booking_en` / `ns_a4_booking_es` (A4, enlace de Cal.com)

Categoría propuesta: **UTILITY** (agendar la llamada que el lead ya está teniendo con NorthStar); alternativa MARKETING.

| Variable | Ejemplo |
|---|---|
| `{{1}}` nombre | Mike |
| `{{2}}` enlace de reserva | https://cal.com/ejemplo/llamada |

EN:
```
Hi {{1}}, you can pick a time to talk with NorthStar here: {{2}} If you have questions first, reply to this message.
```
ES:
```
Hola {{1}}, puedes elegir una hora para hablar con NorthStar aquí: {{2}} Si tienes preguntas antes, responde a este mensaje.
```

### 3. `ns_a5_reminder_24h_en` / `_es` y `ns_a5_reminder_1h_en` / `_es` (A5, recordatorios de cita)

Categoría propuesta: **UTILITY** (recordatorio de una cita que el lead reservó).

| Variable | Ejemplo |
|---|---|
| `{{1}}` nombre | Mike |
| `{{2}}` fecha y hora (con zona) | Tuesday, November 10 at 3:00 PM (America/New_York) |

24 h, EN:
```
Hi {{1}}, a reminder of your call with NorthStar: {{2}}. If you need to change it, use the link in your Cal.com confirmation email.
```
24 h, ES:
```
Hola {{1}}, te recordamos tu llamada con NorthStar: {{2}}. Si necesitas cambiarla, usa el enlace del correo de confirmación de Cal.com.
```
1 h, EN:
```
Hi {{1}}, your call with NorthStar is coming up: {{2}}. See you there.
```
1 h, ES:
```
Hola {{1}}, tu llamada con NorthStar es pronto: {{2}}. Hasta ahora.
```

### 4. `ns_a6_d3_en` / `_es` (A6 día 3, calculadora de rate per mile)

Categoría propuesta: **MARKETING** (opt-in de WhatsApp obligatorio). Botón de respuesta rápida: «Stop».

| Variable | Ejemplo |
|---|---|
| `{{1}}` nombre | Mike |
| `{{2}}` enlace de la calculadora | https://northstarfreightsolutions.us/resources/rate-per-mile-calculator/ |

EN:
```
Hi {{1}}, before you accept your next load it helps to know what it really pays per mile. Our free rate per mile calculator is here: {{2}} It gives an estimate from the numbers you type in, nothing more. Reply STOP to opt out.
```
ES:
```
Hola {{1}}, antes de aceptar tu próxima carga conviene saber cuánto paga de verdad por milla. Nuestra calculadora gratuita de rate per mile está aquí: {{2}} Da una estimación con los números que escribas, nada más. Responde STOP para dejar de recibir mensajes.
```

### 5. `ns_a6_d14_en` / `_es` (A6 día 14, último intento)

Categoría propuesta: **MARKETING**. Botón «Stop».

| Variable | Ejemplo |
|---|---|
| `{{1}}` nombre | Mike |

EN:
```
Hi {{1}}, this is our last note for now. If dispatch is still on your mind, just reply here. If not, no problem: you will not hear from us again about this. Reply STOP to opt out.
```
ES:
```
Hola {{1}}, este es nuestro último mensaje por ahora. Si el dispatch todavía te interesa, responde aquí. Si no, no hay problema: no volverás a recibir mensajes nuestros sobre esto. Responde STOP para dejar de recibir mensajes.
```

### 6. `ns_a12_referral_en` / `_es` (A12, pedido de referidos a los 30 días de firmar)

Categoría propuesta: **MARKETING**. Sin bonos ni cifras (el monto del bono no está confirmado). Botón «Stop».

| Variable | Ejemplo |
|---|---|
| `{{1}}` nombre | Mike |

EN:
```
Hi {{1}}, thanks for working with NorthStar. If you know another owner-operator or small fleet that could use a dispatch desk, reply here with their name and phone number and we will reach out. Only if you think it would help them. Reply STOP to opt out.
```
ES:
```
Hola {{1}}, gracias por trabajar con NorthStar. Si conoces a otro owner-operator o flota pequeña que pueda usar una mesa de dispatch, responde aquí con su nombre y teléfono y los contactamos. Solo si crees que le ayudaría. Responde STOP para dejar de recibir mensajes.
```

## NO enviar todavía a aprobación

| Clave | Por qué |
|---|---|
| `ns_a6_d1_*` (día 1, caso real) | Necesita un caso real aprobado por NorthStar. Mientras tanto, déjalo desmarcado en Configuración > Canales externos. |
| `ns_a6_d7_*` (día 7, tarifas) | No existe una fuente real de tarifas; el CRM también omite este paso por correo. |

Cuando existan, el texto se redacta igual que el de arriba (sin cifras que no tengan respaldo) y se añade la plantilla con el mismo patrón de nombre.

## Cómo activarlas en el CRM

1. Aprobada una plantilla, abre **Configuración > Canales externos > n8n** y marca el paso correspondiente (A1, A4, A5, A6 día 3, A6 día 14, A12). Sin marcar, el CRM no se lo ofrece a n8n.
2. Mientras `CONSENT_TEXT_V1` del sitio no cubra WhatsApp, los leads del sitio **no** aparecen en la lista de A1: solo los que escribieron primero por WhatsApp (ventana de 24 h) o los de un formulario de Meta cuyo aviso cubra WhatsApp. Los pasos de marketing (A6, A12) exigen opt-in de WhatsApp.
