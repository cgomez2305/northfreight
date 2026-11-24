# Fase AM · Operación del equipo (responsable, SLA, resumen diario, métricas)

Frente «operación». Migración: `captacion/db/migrations/015_responsable_sla.sql`. Pruebas: `captacion/tests/cases/42_responsable_sla.php`. Sin crons de cuenta nuevos ni variables de entorno nuevas.

## 1. Responsable del lead

- **Dato:** `leads.assigned_to` (id de usuario, `NULL` = sin asignar; índice `idx_leads_assigned (assigned_to, deleted_at)`). Sin clave foránea a propósito: si un usuario deja de existir el lead no bloquea nada (la ficha lo muestra «ya no disponible» y se reasigna).
- **Quién puede ser responsable:** solo usuarios con rol `admin` activos (`Assignment::admins()`). **No hay rol nuevo**: el equipo es pequeño y un rol nuevo obligaría a rehacer la matriz de autorización (`39_security_o`). El lector (`viewer`) nunca ve ni cambia responsables.
- **Servicio:** `Services/Assignment`: `assign()`, `bulkAssign()` (máx. 500), `undo()`, `pickNext()` (reparto) y `validate()` (formulario de Configuración). Cada cambio deja el evento `assigned` en la línea de tiempo (con los nombres dentro del payload, así sigue legible aunque el usuario se desactive) y un registro en `activity_log` (`lead_assign`; el masivo, un solo `leads_bulk_assign`).
- **Dónde se cambia:**
  - Ficha `/leads/{id}`: selector «Responsable» junto a los datos de contacto (`POST /leads/{id}/assign`, campo `to`; vacío = sin asignar).
  - Vistazo (peek): selector con la acción rápida `POST /ajax/lead/act {act:"assign", to}`.
  - Barra de selección de Lista y Tabla: «Asignar a…» + «Sin asignar» + botón «Asignar» (`POST /leads/bulk`, `do=assign`, `assignee` = id, `0` = quitar). El aviso trae «Deshacer» como las demás acciones masivas.
  - **Deshacer** (`Undo::assign`): el token es del usuario que lo hizo y solo revierte los leads que siguen con el responsable que se puso (si alguien lo movió después, ese no se toca).
- **Dónde se ve:** columna «Responsable» elegible en la Tabla (clave `resp`, por defecto tras «Etapa», ordenable) y en la Lista (`Prefs::COLUMNS['resp']`); iniciales en la tarjeta del tablero (`_kcard`, con texto accesible «Responsable: …»); el CSV de la Tabla la incluye.
- **Filtro compartido** `rs` en `LeadQuery` (y por tanto en Lista, Tabla, Tablero, Calendario, Cronología y vistas guardadas): `rs=me` (el usuario que mira; se resuelve con `LeadQuery::forUser()`, que fija `Responder::user()`; sin usuario no devuelve nada), `rs=none` (sin asignar) o `rs=<id>`. Selector «Responsable» en `_lfilters` y en la barra rápida del tablero.
- **Vistas del sistema nuevas** (`SavedViews::SYSTEM`): «Mis leads» (`rs=me` + `op=1`, fijada en la barra lateral con contador) y «SLA vencido» (`sla=1`).

### Reparto automático (opcional)

- Configuración > Alertas > «Reparto de leads». **Apagado por defecto.** Ajuste `assign` = `{auto, users[], last}`.
- Encendido: al **crear** un lead nuevo, round-robin entre los administradores marcados como «reciben leads» (cursor `last` = último id repartido, así el orden es estable aunque cambie la lista). Corre dentro de la transacción y bajo el candado del motor (`LeadIngest::create`): no rompe la alta ni la deduplicación, y si falla el lead se crea sin responsable.
- Un **reingreso conserva el responsable** (no se reparte otra vez). Las altas `quiet` (importaciones y alta manual) **no se reparten**: un volcado histórico no debe llenar «Mis leads» de nadie.
- Si nadie de la lista es válido (desactivado, rol cambiado), el lead se crea sin responsable.

## 2. SLA de primer contacto

Ajustes (tabla `settings`, Configuración > Alertas > «Plazo de primer contacto»):

| Clave | Por defecto | Qué hace |
|---|---|---|
| `sla.first_contact` | `60` (5 a 1440) | minutos del plazo |
| `sla.escalate` | `true` | al vencer, alerta de Telegram por la cola |
| `sla.auto_task` | `true` | tarea «Primer contacto con <nombre>» al crear el lead |

- **Qué leads tienen SLA** (`Sla::trackedSql`): sin `first_contact_at`, no perdidos (`stage <> 'lost'`) y no descartados automáticamente (`temperature <> 'disqualified'`; decisión: un descartado no espera contacto y llenaría los avisos de ruido). El plazo corre desde `COALESCE(stage_changed_at, created_at)`: un lead reabierto como intento nuevo cuenta desde la reapertura.
- **Indicador** `Ui::sla()`: punto de 6 px + texto «vence en 23 min» / «vencido hace 2 h» (+ etiqueta para lector de pantalla «Plazo de primer contacto»). Aparece en Lista, Tabla (bajo el nombre), tarjeta del tablero, vistazo y ficha (cabecera y «Primer contacto»). Estilos en `ux.css` (diferida).
- **Campana** (`Notifications`): «N leads con el plazo de primer contacto vencido» para el responsable (sus leads **y** los que no tienen responsable); cada administrador ve el suyo. Clave `sla_overdue:N:maxId`, se calcula al vuelo (no hay tabla de avisos).
- **Tarea automática** (`Sla::ensureTask`, solo altas no `quiet` y reaperturas): título «Primer contacto con <nombre>», vencimiento = llegada + plazo, prioridad Alta si es caliente. Columna nueva `tasks.kind = 'sla_first_contact'`: no se duplica si ya hay una abierta y se cierra sola al contactar al lead. No deja evento en la línea de tiempo (la crea el sistema en cada alta y sería ruido).
- **Pasada periódica** `Sla::tick()` dentro de `cron/process_jobs.php` (cada minuto, sin cron de cuenta nuevo; deja la marca `cron.sla`): (1) cierra las tareas «Primer contacto» de los leads ya contactados, perdidos o borrados; (2) con el escalado activo, encola un job `sla_breach` por lead e intento (clave `sla_breach:lead:intento`, así que **no avisa dos veces**) para los que vencieron en las últimas 24 h (los más viejos no se escalan: nadie los está esperando). El aviso es **no urgente**: respeta el horario de silencio de `Alerts`.
- **Handler** `Sla::jobBreach`: vuelve a comprobar (si ya lo contactaron, lo perdieron, se apagó el escalado o se amplió el plazo, no manda nada), manda el Telegram «PLAZO VENCIDO …» con el responsable y deja el evento `sla_breached`.
- **Vista/filtro** «SLA vencido» (`sla=1`).

## 3. Resumen diario

- `Services/DailyDigest`, llamado desde `cron/reports_cron.php` (ya corre cada hora). A las **8:00 de la zona de visualización** (Configuración > Horario y zona), con recuperación hasta las 23:59 del mismo día.
- Contenido: leads de ayer (total, calientes y tibios), sin contactar, con el plazo vencido (con hasta 5 nombres), tareas vencidas y para hoy, citas de hoy (hora y nombre) y las «Siguiente mejor acción» de `DashboardActions`. Solo conteos y nombres: nunca teléfonos ni correos. Solo para administradores (destinatario = el correo de avisos de Configuración > Alertas / `MAIL_TO`, más Telegram si está configurado); el lector no lo recibe.
- Interruptor `alerts.daily_digest` en Configuración > Alertas, **encendido por defecto**.
- **Idempotente por día:** `cron.digest = {date}` (fecha local ya enviada). Si no hay ningún canal (ni correo ni Telegram) o todos fallan, **no** se marca como enviado y se reintenta en la siguiente pasada. `reports_cron.php --now=AAAA-MM-DDTHH:MM` también sirve para ensayarlo.

## 4. Métricas por responsable

- `/reportes` > «Por responsable» (`Reports::byAssignee`), con CSV (`/reportes/export`, tabla `responsables`): leads asignados, contactados, mediana a primer contacto, % dentro del plazo, calificados, presentados, firmados y tareas vencidas; fila «Sin asignar» al final.
- Definiciones: leads **recibidos** en el rango. «Dentro del plazo» usa el plazo **vigente** (no el de entonces) sobre los leads cuyo plazo ya terminó (los perdidos y descartados sin contacto, y los que aún están a tiempo, no cuentan). Tareas vencidas = abiertas **ahora** de los leads que tiene asignados hoy (sin filtro de fechas).
- **No** forma parte de `Reports::compute()` (alimenta los informes mensuales guardados y la proyección del lector): lleva nombres de usuarios y por eso la tabla no aparece en `/resultados` ni en `/informes` (probado).

## 5. Accesibilidad (lista y ficha)

- Miga «Leads» de la ficha y enlace «Volver a la lista» conservan modo, filtros, orden, página y vista guardada. Las listas enlazan a la ficha con `?back=<url>`; solo se aceptan rutas internas de Lista, Tabla, Tablero, Calendario y Cronología con caracteres seguros (mismo criterio que `backUrl`; un `back` inválido vuelve a `/leads`). Tras una acción de la ficha (que redirige sin `back`) se usa el último válido recordado en la sesión **para ese mismo lead**.
- `aria-label` de editar y eliminar nota con autor y fecha.
- Cabeceras abreviadas «Est.», «Pts», «Temp.» (y las de la Tabla): forma corta visible y texto completo para el lector de pantalla.
- `aria-sort` en las `<th>` ordenables (Lista y Tabla).
- `aria-current="page"` en las pestañas de navegación de Lista, Tabla y Tablero (antes `true`).
- Enlaces `target="_blank"` a WhatsApp con «(abre en otra pestaña)» solo para lector (ficha, barra rápida y vistazo).

## Rutas y permisos

| Ruta | Método | Quién |
|---|---|---|
| `/leads/{id}/assign` (`to`) | POST + CSRF | admin (lector 403) |
| `/leads/bulk` (`do=assign`, `assignee`) | POST + CSRF | admin |
| `/ajax/lead/act` (`act=assign`, `to`) | POST JSON + CSRF | admin |
| `/config/alerts` (campos `sla_*`, `assign_*`, `daily_digest`, marcador `am_form`) | POST + CSRF + 2FA reciente | admin |
| `/reportes`, `/reportes/export` (`table=responsables`) | GET / POST | admin |

El formulario de Alertas lleva el marcador oculto `am_form=1`: un POST antiguo (sin él) no toca el plazo, el reparto ni el resumen (compatibilidad con clientes y pruebas anteriores).

## Decisiones y porqué

- Responsable = admin activo, sin rol nuevo (ver arriba).
- El SLA, la campana y el indicador se calculan al vuelo; solo la escalada pasa por la cola (una alerta por lead e intento).
- La tarea automática no deja evento (ruido) y se identifica por `tasks.kind`, no por el título.
- El aviso de SLA es no urgente (horario de silencio) y solo escala lo vencido hace menos de 24 h.
- El reparto automático no toca `quiet` ni reingresos.
- Las métricas por responsable quedan fuera de `compute()` para no filtrar nombres al lector ni a los informes guardados.

## Límites y pendientes

- El SLA no distingue horario laboral (cuenta minutos corridos, también de noche); si hace falta, un plazo «en horas hábiles» usaría `Display::hours()`.
- Las tareas «Primer contacto» se cierran con la pasada del cron (hasta 1 minuto después), no al instante.
- «Dentro del plazo» del reporte usa el plazo vigente, no el histórico.
- Los avisos de la campana de SLA no se agrupan por responsable en la interfaz (cada administrador ve solo su cuenta).
- Prospectos, referidos y Tareas siguen con `aria-current="true"` en sus pestañas (fuera de las vistas de lista/ficha de este frente).
- El tablero (arrastrar) y las tarjetas que se piden por AJAX (`Ver más`) no llevan `?back=` en el enlace a la ficha (usan el último recordado en la sesión).
- Un lead sin teléfono ni correo válidos puede quedar sin primer contacto posible: el plazo vencerá igual (se descarta o se marca perdido a mano).
