# Fase AM · datos (supresión, anonimización, fusión, envío manual e importación CSV)

Migración: `db/migrations/016_datos_privacidad.sql` (SQL estándar, válido en MariaDB 10.11 y MySQL 8; `php captacion/bin/migrate.php` y luego `--schema`).
Pruebas: `tests/cases/43_datos_privacidad.php`. Sin variables nuevas en el `.env` ni crons nuevos (la importación corre por la cola de siempre).

## 1. Lista de supresión y STOP de desconocidos

**Bug que corrige.** Un `STOP` por SMS de un número que todavía no era lead solo quedaba como `ignored` en `webhook_events` y se perdía: si ese número llegaba después como lead, nacía con consentimiento de SMS.

**Qué hace ahora.**
- `SmsInbound`: un `STOP` de un número sin lead se anota en la lista de supresión (razón `sms_stop`). Un `STOP` de un lead existente hace lo de siempre (revoca SMS, WhatsApp y WhatsApp entrante, bandera `stop_sms`) y además anota el número. `START` quita el número si la razón era `sms_stop` (nunca una `erasure`).
- `LeadIngest` (alta nueva y reingreso, para todas las fuentes): consulta la lista con el teléfono y el correo del lead.
  - `sms_stop`: banderas `stop_sms` y `stop_whatsapp`; no se guarda el consentimiento de SMS ni de WhatsApp aunque viniera marcado; llamada y correo se conservan.
  - `erasure` (teléfono o correo): bandera `stop` y NO se guarda ningún consentimiento.
  - Siempre deja el evento `suppressed` en la línea de tiempo con el motivo y si el formulario traía consentimiento marcado.

**Decisión: tabla `suppression` y no `dnc_numbers`.** Hace falta guardar también correos y un borrado a petición no puede dejar el teléfono ni el correo en claro. `dnc_numbers` sigue siendo la lista de «no llamar» de los prospectos FMCSA y no se toca. Para que ambas listas no se contradigan, `ProspectImport` también descarta los teléfonos con razón `erasure`.

**Tabla `suppression`** (`kind` phone|email, `value_hash`, `reason`, `source_lead_id`, `created_by`): solo guarda `HMAC-SHA256(kind|valor normalizado)` con una clave derivada de `APP_KEY` (`Suppression::hash`). Teléfono en E.164, correo en minúsculas. Una razón `erasure` nunca se rebaja a `sms_stop`.
- Límite: si `APP_KEY` cambia, los hashes guardados dejan de coincidir (la lista «se olvida»). No rotar `APP_KEY` sin una migración que recalcule los hashes (hoy imposible: no se guarda el valor).

## 2. Anonimización a petición del titular

Solo administradores. Ficha del lead > tarjeta «Privacidad y datos» > «Anonimizar a petición del titular». Ruta: `POST /leads/{id}/anonimizar`.

**Requisitos:** CSRF, rol administrador, escribir `ANONIMIZAR` (sin importar mayúsculas ni espacios) y reautenticación 2FA reciente (`Reauth`): si no hay confirmación vigente, el propio formulario pide contraseña y un código TOTP nuevo (no el del acceso). **Irreversible y sin «Deshacer».**

**Decisión: no se borra la fila, se anonimiza** para que las estadísticas agregadas (embudo, fuentes, tiempos, puntuación) no cambien.

| Se elimina o se vacía | Se conserva |
|---|---|
| Nombre y apellidos (queda «Anonimizado»), teléfono, correo, ciudad, USDOT, `fmcsa_data`, referrer, ID del CRM externo | id, fechas, fuente, perfil, etapa, puntuación, temperatura y su desglose |
| Página de llegada (solo queda la ruta, sin parámetros) y UTM (solo fuente, medio y campaña) | estado de EE. UU., equipo, camiones, años de experiencia (agregados) |
| Cuerpo y asunto de mensajes; notas libres (y sus ediciones; quedan fuera de la ficha) | filas de `messages` (fecha, canal, dirección, estado) |
| IP y user agent de cada consentimiento; texto del primer WhatsApp del lead | filas de `consents` (qué se consintió y cuándo; quedan revocadas con `revoked_via = erasure`) y el texto legal aceptado |
| Títulos de tareas y de citas, enlace de reunión, nota de rechazo de entregas | tareas completadas (sin título), citas, entregas |
| Payload de `lead_events` (solo sobreviven claves estructurales de una lista blanca: etapa, puntuación, canal, motivo…) | los eventos (tipo y fecha) |
| `changes` de `activity_log` para este lead; detalle de `rule_applications` | `activity_log`: solo el id y quién (`lead_anonymize`, sin cambios) |
| Prospecto FMCSA convertido: nombre, DBA, teléfono, correo, ciudad, datos; queda «no llamar» | el USDOT (para que el censo no lo vuelva a listar) |
| Código de referido propio: nombre «Anónimo» y desactivado | `site_lead_id` (para que reimportar el JSONL del sitio no recree el lead) |

Además: teléfono y correo van a la lista de supresión (`erasure`); se detienen las secuencias (`paused_reason = erasure`), se cancelan las tareas abiertas, las citas futuras y los trabajos pendientes del lead, de sus citas y de sus entregas (y se limpia el error de los fallidos); los enlaces `/c/{token}` de entregas pendientes pasan a `superseded`. Se marca `leads.anonymized_at`, banderas `stop` y `anonymized`. Un lead anonimizado **no se vuelve a puntuar** (`LeadIngest::rescore` devuelve null): conserva su puntuación histórica.

**La ficha** muestra un aviso, no ofrece llamar, escribir, enviar plantillas ni registrar llamadas. Si el mismo teléfono o correo vuelve a llegar, nace un lead NUEVO con `stop`, sin consentimientos y con el evento `suppressed`.

**Límites (importante):**
- No se recupera lo ya enviado fuera del panel: correos de entrega a NorthStar, webhooks, n8n, hojas de cálculo, Telegram. Hay que pedirlo a mano a quien lo recibió.
- Las copias de seguridad de la base (30 días de retención) conservan el dato hasta que caducan.
- Los JSONL del sitio (`storage/leads/*.jsonl` del sitio público) siguen teniendo el lead original: borrarlo allí es manual.
- El aviso con la lista de «otros leads con el mismo teléfono o correo» obliga a decidir: fusionarlos antes o anonimizarlos también (cada anonimización es por lead).
- Los registros de actividad anteriores a este cambio que mencionaran datos en `entity_id` o `action` no se tocan (solo `changes`).

## 3. Fusión manual de duplicados

Solo administradores. Ficha > tarjeta «Fusionar duplicados» (aparece si hay «Otros leads con los mismos datos»). Ruta: `POST /leads/{id}/fusionar` (`other`, `primary`, `confirm=1`). Servicio: `LeadMerge`.

- Una sola transacción, bajo el mismo bloqueo `captacion_ingest` del motor (una entrada nueva no se cuela a mitad).
- Se mueven al principal: notas, tareas, eventos, mensajes, consentimientos, citas, avisos de webhooks, aplicaciones de reglas, codigos de referido que posee, prospecto convertido, **entregas** (su `attempt` se desplaza para no chocar con el `UNIQUE lead+attempt+version`), **inscripciones** (una por lead y secuencia: si el principal ya tiene una, la del secundario se detiene con `paused_reason = merged`; las demás se mueven) y el **referido** (si el principal no tiene uno).
- Campos vacíos del principal se rellenan con los del secundario (correo, teléfono, ciudad, USDOT, fechas de contacto/entrega/firma, etc.); **nunca se pisa un valor**. Las banderas se unen (un STOP del secundario manda). Etapa, perfil, fuente y fecha de alta son los del principal.
- El principal recalcula su puntuación (`LeadIngest::rescore`, sin alertas). El secundario queda con `deleted_at` y `merged_into` (su ficha redirige al principal); sus trabajos pendientes se cancelan.
- Evento `merged` en los dos leads y `activity_log` (`lead_merge`, `lead_merged_into`).
- **Reglas que bloquean:** no comparten teléfono ni correo, distinto pipeline, alguno anonimizado, o el secundario está firmado/contratado y el principal no (el firmado tiene que ser el principal).
- **Sin «Deshacer», a propósito:** la fusión reasigna filas de una docena de tablas y rellena campos; deshacerla exigiría un diario fila a fila que el panel no lleva. A cambio: solo administradores, con confirmación, el secundario se conserva y todo queda en la línea de tiempo y en `activity_log`.
- Límite: si el secundario tenía un segundo teléfono o correo distinto, solo queda en su fila (borrada lógicamente); un lead nuevo con ese dato no se deduplicaría contra el principal.

## 4. Envío manual de una plantilla desde la ficha

Solo administradores. Ficha > «Enviar plantilla» > `GET /leads/{id}/enviar?tpl=ID` (vista previa con variables resueltas, el pie del correo y avisos) > `POST /leads/{id}/enviar` (`channel` = `email` | `whatsapp`). Servicio: `LeadSend`. Plantillas: las de Comunicaciones (EN/ES, activas).

**Correo.** Se envía uno a uno con `Mailer::deliver` (cabeceras `List-Unsubscribe`, pie CAN-SPAM con la dirección física) y queda en `messages` (con el usuario), evento `template_sent` y `activity_log`. No cuenta como primer contacto. Se bloquea si:
- el lead está anonimizado o no tiene correo; tiene `stop` o `stop_email`; su correo está en la lista de supresión;
- **no hay consentimiento de correo vigente Y el lead no escribió primero** (`leads.last_inbound_at`). *Decisión:* se acepta «escribió primero» porque es una conversación uno a uno que inició el lead; las secuencias automáticas siguen exigiendo consentimiento. Con `stop_email`/`stop` se bloquea siempre, aunque haya escrito;
- falta la dirección física (Configuración > Correo);
- la plantilla trae un marcador `[[CONFIRMAR…]]` o una variable sin resolver;
- se alcanzó el tope por hora (`cap_hour`, global) o por lead cada 24 h (`cap_lead`) de Configuración > Correo. *Decisión:* ambos topes cuentan TODOS los correos salientes (secuencias y manuales). Con `cap_lead = 1` (valor por defecto) no se puede enviar un manual en las 24 h siguientes a un correo de secuencia; se ajusta en Configuración;
- **no hay SMTP**: no se envía ni se anota como enviado; la pantalla lo explica (el correo tampoco se «registra» en `mail.log` para esta acción).

**WhatsApp.** Sin API: el botón envía un POST (CSRF) que anota `whatsapp_opened` («WhatsApp abierto con plantilla X») y responde con una página que salta a `https://wa.me/<número>?text=<texto resuelto>` (refresco de cabecera `meta refresh` + botón de respaldo). El formulario usa `target="_blank"`, así que se abre en otra pestaña.
- *Por qué no es una redirección 303:* la CSP del panel lleva `form-action 'self'`, que bloquea la redirección de un formulario a otro origen. Por eso `layout.php` acepta una variable `refresh` (una línea) y la respuesta es un 200.
- El texto va sin pie de baja. El panel no sabe si se envió: la línea de tiempo lo dice. No crea fila en `messages`.
- Se bloquea con `stop`, `stop_whatsapp`, `stop_sms`, lista de supresión, lead anonimizado, sin teléfono, texto pendiente de confirmar o más de 1.500 caracteres.

El botón también existe solo en la ficha: **no se añadió al vistazo rápido** (otro frente lo modifica a la vez; queda como mejora).

## 5. Importación CSV de leads

Solo administradores. `/importar` (enlace en la bandeja de Leads y en la paleta de comandos «Importar leads (CSV)»).

| Ruta | Qué hace |
|---|---|
| `GET /importar` | Subida y lista de importaciones recientes |
| `POST /importar` | Sube el archivo (≤ 2 MB, ≤ 2.000 filas, 10 subidas por hora y usuario) |
| `GET /importar/{id}` | Mapeo de columnas + vista previa (o avance y resultado) |
| `POST /importar/{id}/simular` | **Simulación: no escribe nada** (ni leads, ni eventos, ni consentimientos, ni cola) |
| `POST /importar/{id}/start` | Importa por la cola (exige la casilla de confirmación) |
| `POST /importar/{id}/discard` | Descarta la subida y borra el archivo |

- **Formato:** UTF-8 (con o sin BOM), UTF-16 con BOM o Windows-1252; separador autodetectado (`,` `;` tabulador `|`); la primera fila son los encabezados. Se rechazan: más de 2 MB, más de 2.000 filas, extensión que no sea .csv/.txt, contenido binario, solo encabezados.
- **Mapeo:** autodetección por el nombre del encabezado en inglés o español (nombre, teléfono, correo, estado, ciudad, perfil, idioma, equipo, camiones, años, USDOT, autoridad). Hace falta nombre y teléfono o correo; el perfil se elige para todo el archivo (o viene en una columna). No existe campo de consentimiento: **los importados quedan SIEMPRE sin consentimiento** y cualquier columna así se ignora.
- **Simulación:** cuántos se crearían, cuántos son duplicados (y de qué lead o de qué fila del mismo archivo) y cuántos se rechazan y por qué (falta el nombre, teléfono o correo inválido, sin teléfono ni correo, en la lista de supresión), con las primeras 100 filas con novedad.
- **Importación real:** job `lead_import` (50 filas por pasada, cada minuto) con `LeadIngest::handle` (validación, `Dedupe`, puntuación, reglas), fuente `csv_import`, `quiet` (sin alertas de Telegram ni correos por cada importado) y `fmcsa_sync = false` (si hay USDOT se consulta FMCSA por la cola). Los teléfonos y correos suprimidos **no se importan**.
- **Etiqueta de origen:** el nombre del archivo, como bandera `import:<archivo>` del lead (se muestra «Importado: <archivo>»). Se usa `import:` y no `tag:` porque las banderas `tag:*` son de las reglas y se recalculan.
- **Duplicados:** por defecto se omiten sin tocar el lead existente; con «completar» pasan por el motor como un reingreso (rellena campos vacíos y deja el evento `reentry`).
- **Archivo:** `storage/imports/<token>.csv` (directorio 0750, archivo 0600, token aleatorio), guardado ya como UTF-8; se **borra al terminar** o al descartar, y las subidas abandonadas o fallidas de más de 24 h se borran al abrir `/importar`.
- **Fórmulas:** el dato se guarda tal cual llega; al exportar leads, `Csv::cell` ya antepone `'` a las celdas que empiezan por `= + - @` tab o CR (la prueba lo comprueba con un nombre `=HYPERLINK(...)`).
- Doble clic en «Importar»: solo el primero pasa de `uploaded` a `queued`.
- La tabla `lead_imports` guarda contadores, mapeo, opciones y hasta 100 filas con novedad (solo número de fila, motivo y id del lead; nunca datos personales).

## Cosas que toca en código compartido (para integrar)

- `public/index.php`: bloque «Fase AM · datos» (2 rutas), antes de la ruta genérica `/leads/{id}/{accion}`.
- `views/lead.php`: variable `$anon`, aviso (`_lead_anon_notice.php`), una línea que incluye `_lead_datos.php` (que incluye `_lead_send.php`, `_lead_merge.php`, `_lead_privacy.php`) y el botón «Registrar llamada» oculto si está anonimizado.
- `views/layout.php`: una línea que emite `<meta http-equiv="refresh">` si la vista trae `$refresh`.
- `views/leads.php`: botón «Importar CSV» (administradores).
- `public/assets/ux.css`: 3 reglas de los formularios de la ficha (diferida; la ficha no cambia de hojas). `public/assets/datos.css`: hoja nueva de `/importar` y `/leads/{id}/enviar`.
- `LeadsController::show`: redirige a su principal un lead fusionado.
- `LeadIngest` (supresión, `ctx['flags']`, `rescore` ignora anonimizados), `SmsInbound`, `ProspectImport`, `JobHandlers` (`lead_import`), `Catalog` (fuente, banderas, eventos), `Timeline` (5 eventos), `Sequences::REASONS`, `Commands` (paleta).
- Pruebas compartidas editadas: `01_migrations` (ya no fija la lista de migraciones nuevas), `17_pulido` (`/importar`), `36_css_classes` (`/importar`), `39_security_o` (6 rutas de muestra) y `lib/T.php` (`reset` vacía `suppression` y `lead_imports`).

## Pendiente

- Botón de «Enviar plantilla» en el vistazo rápido.
- Doble confirmación (doble opt-in) del consentimiento y recalcular la lista de supresión si algún día se rota `APP_KEY`.
- Borrar a mano los JSONL del sitio y lo ya enviado a NorthStar tras una anonimización (proceso, no código).
- Importar la columna `created_at` o el consentimiento de un CSV con prueba real: hoy se ignoran a propósito.
