# Fase AM · automatización y correcciones

Frente «automatización y correcciones» de la fase AM. Migración: `captacion/db/migrations/017_automatizacion.sql`. Pruebas: `captacion/tests/cases/44_automatizacion.php`.
Los otros dos frentes de la fase (responsable/SLA/resumen diario y supresión/anonimización/fusión/importación/envío de plantilla) tienen sus propios documentos.

## A · Correcciones verificadas (cada una con prueba de regresión)

| # | Qué pasaba | Qué hace ahora | Dónde |
|---|---|---|---|
| 1 | Sin SMTP, `Sequences::runStep` y `Appointments::jobReminder` avanzaban/consumían pasos como «enviados» (`messages.status = logged`) y contaban en los topes. | Con `Mailer::canDeliver()` falso, el paso de la secuencia se **bloquea** (`blocked_reason = «Sin SMTP»`, reintenta cada hora) y NO avanza; el recordatorio de cita queda `reminder_skipped` con motivo `no_smtp`. Los topes por hora y por lead solo cuentan `sent` (un `logged` manual no consume tope). | `Sequences.php`, `Appointments.php` |
| 2 | Un evento de n8n cuyo `applyEvent` devolvía `ok=false` quedaba reclamado en `webhook_events` y el reintento con el mismo `event_id` se descartaba como duplicado. | `Webhook::claim(..., $retryErrors = true)` vuelve a reclamar un evento que quedó en `error`; uno `processed` sigue siendo duplicado. Lo usan `WebhookController` e `InternalController` (solo n8n). | `Webhook.php`, controladores |
| 3 | `LeadIngest::handle` soltaba `GET_LOCK` antes del commit cuando corría dentro de otra transacción (ruta Cal.com): otra ingesta no veía el lead recién creado. | Nuevo `Db::onEnd()` (se ejecuta tras el commit o el rollback de la transacción EXTERNA). Si ya hay transacción abierta, el bloqueo se suelta al terminar esa; fuera de transacción, igual que antes. Cambio mínimo en `LeadIngest::handle`. | `Db.php`, `LeadIngest.php` |
| 4 | Claves de dedupe que nunca se purgaban: un paso de secuencia reanudado no se volvía a encolar; un recordatorio A→B→A (o cancelar y reactivar) no se reprogramaba. | Reanudar borra los jobs YA TERMINADOS (`done/failed/cancelled`) de esa inscripción (`Sequences::clearFinishedJobs`). Al programar recordatorios se borra solo el job `cancelled` con la misma clave. | `Sequences.php`, `Appointments.php` |
| 5 | `Prospects` mezclaba ET fija con la zona de visualización. | `Prospects::etToUtc()` construye las fechas con `America/New_York` explícito (fin del día, siguiente día hábil 9:00, fecha del siguiente intento). La vista de prospectos usa `Prospects::etDay()` y el rótulo dice «ET». | `Prospects.php`, `views/prospectos.php` |
| 6 | El informe mensual precargaba acciones con texto operativo interno que se publicaba al lector. | Separado en **internas** (`ReportJobs::suggestInternal`: leads calientes sin contactar, tareas vencidas, costos por cargar, tiempo de respuesta, mantenimiento; solo admin, van en el resumen semanal y en `metrics.internal_actions` del borrador, bloque «Sugerencias internas · no se publican») y **para el cliente** (`suggestForClient`: solo lo que se deduce de agregados que NorthStar ya ve: canales, captación, presentados sin respuesta, genéricas). Solo estas últimas se precargan en las acciones publicables. Las internas nunca llegan al lector ni a un informe publicado. | `ReportJobs.php`, `InformesController.php`, `views/informe.php` |
| 7 | `config.js` hacía `location.reload()` al expirar la cuenta atrás (se perdía lo editado) y cada 6 s durante un recálculo. | Al expirar: se deshabilitan los campos (los valores se conservan), aviso `role=alert` «Edición bloqueada: vuelve a confirmar tu identidad» con enlace. El recálculo pide solo el estado por `POST /ajax/config/recalc` (JSON) y, al terminar, avisa con `role=status` (sin recargar). «Copiado» usa `NS.toast`. | `config.js`, `ConfigController::recalcStatus` |

## B · Accesibilidad y UX

* **8** El menú de usuario y los menús de tarea ya no son `role=menu`: son popovers de botones normales con `aria-expanded`. Todos los desplegables (`[data-dd]`) se cierran también con `focusout` (`panel.js`).
* **9** Listbox con `li role=presentation` (paleta y selector de lead). El selector de lead usa `aria-activedescendant` y opciones no tabulables; al elegir un lead el foco pasa a «Quitar».
* **10** Paleta: con una búsqueda de leads pendiente o en vuelo, Enter no ejecuta; con texto, los leads van antes que las acciones.
* **11** Ajuste `keys` (`on`/`off`, por defecto `on`) en `user_prefs` (clave nueva en `Prefs::DEFAULTS`/`valid`). Interruptor «Atajos de una tecla» en el menú de usuario (solo admin). `<html data-keys>` lo lleva la página (el lector no lo recibe). Desactivados solo funcionan Ctrl/⌘ K y Esc (`ux.js`, `peek.js`).
* **12** Diálogo de confirmación configurable: `data-confirm-title`, `data-confirm-label` y `data-confirm-danger` (botón rojo solo si es destructivo; si no, botón primario). Ayudante `Ui::confirm(string|array)`. Clasificación de los ~24 usos:
  * destructivos (rojo): eliminar nota, revocar consentimiento, borrar borrador de vacante, restablecer puntuación/reglas, apagar reglas, descartar borrador de reglas, eliminar costo, eliminar tarea, No llamar más, cancelar bono, eliminar vista, restaurar plantilla de fábrica, eliminar plantilla, reiniciar 2FA, desactivar cuenta, sacar de una secuencia.
  * NO destructivos (primario): publicar informe, publicar versión de reglas, volver a una versión anterior, convertir prospecto en lead, marcar bono como pagado, confirmar montos de bonos, pausar/cerrar vacante, marcar firmó, reenviar entrega.
* **13** Peek: guardar nota o tarea vacía muestra un error `role=alert` junto al campo; J/K mueven el foco al título solo la primera vez.
* **14** `NS.inertOthers([...])` (en `panel.js`) deja el resto de la página `inert` mientras el cajón móvil o el vistazo en modo hoja están abiertos y lo revierte al cerrar (no toca diálogos nativos ni los avisos).
* **15** `aria-label` en las 15 tablas que no tenían nombre (la prueba comprueba que TODA `<table>` de las vistas tenga `aria-label` o `<caption>`).
* **16** Bloque `@media (pointer:coarse)` en `ux.css` (diferida): `icon-btn`, `btn--sm`, `seg__i`, `nav-i`, `dd__item`, acciones por fila a ≥ 44 px y `.tick` con área ampliada (`::after`, sin mover el diseño); borde de `.tick` con `--field` (`calendario.css`, sin añadir bytes). Las páginas 403/404/500 con sesión se dibujan dentro del shell (`Responder::errorPage`, `views/message_shell.php`) con «Volver al panel» (o «Volver a resultados» si es lector); sin sesión o si el shell falla (500 con la base caída), la tarjeta simple de siempre.

## C17 · Reglas por eventos y por tiempo

Un tipo nuevo de conjunto, `automation` («Eventos y tiempo»), sobre el mismo editor, versiones, borrador/publicar atómico, historial con diferencias y 2FA de publicar/revertir. Cada regla tiene un **disparador** además de CUANDO (ahora «Solo si», opcional) / ENTONCES / EXCEPTO:

| Disparador | Parámetro | Cuándo se evalúa |
|---|---|---|
| `stage_change` «Cambia de etapa» | etapa de destino (vacía = cualquiera) | al instante (`LeadActions::event`) |
| `consent_revoked` «Se revoca un consentimiento» | canal (call/sms/email, vacío = cualquiera; «todos» también cuenta) | al instante (`Consent::revoke*`) |
| `no_first_contact` «Pasan horas sin primer contacto» | 1 a 720 h | job `rules_time` cada 15 min (lead en «Nuevo» sin primer contacto) |
| `task_overdue` «Una tarea de un lead vence» | 0 a 720 h de retraso | job `rules_time` cada 15 min (tarea abierta de un lead) |

Acciones (`Rules::KIND_ACTIONS['automation']`): `notify_bell` (avisar en la campana), `outbound_webhook` (evento `rule.fired`), `telegram_alert` y `create_task` (las que ya existían). **No** hay acciones que cambien etapa ni asignen responsable (decisión: lo primero se saltaría las validaciones de etapa; lo segundo lo cubre el reparto automático del otro frente).

* Motor: `RuleEngine::triggerMatches` y `matchesEvent` (puros). Ejecución: `RuleAutomation` (`onEvent`, `fire`, `tick`, `apply`). Los conjuntos de eventos **no entran** al motor de puntuación (`RuleSets::engineSets()` los excluye; `automationSets()` los da): publicar uno deja la puntuación idéntica (probado).
* Idempotencia: `rule_applications.dedupe_key` por regla + lead + intento + suceso (id de tarea y vencimiento; segundo del evento; «nfc»). Ni el job, ni un reintento ni republicar repiten un efecto.
* Job: `RuleAutomation::enqueueTick()` (lo llama `cron/process_jobs.php` en cada pasada) encola `rules_time` UNA vez por tramo de 15 minutos (clave de dedupe) y solo si hay reglas de tiempo publicadas o el webhook está suscrito a `task.overdue`. Sin trabajo, no encola nada.
* Avisos de campana: tabla `rule_notices`; cada aviso es una clave `rule_notice:<id>` en `notification_reads` (se marca leído por persona). Se muestran 14 días, máx. 10. Evento de línea de tiempo `rule_notice`.
* Simulación y vista previa soportan los disparadores sin escribir nada (`RuleSim::previewAuto`, `RuleAutomation::simLead/simPrefetch`): cuentan leads donde habría actuado y avisos/webhooks/alertas/tareas. Aproximación declarada: usan el estado actual del lead y el historial de eventos ya ocurridos. `rule_simulation_rows` gana `bells` y `hooks`.
* Editor: fila «Cuando ocurre» con el selector de evento y solo los parámetros del evento elegido (`syncTrigger` en `rules.js`; sin JS se ven todos y se ignoran los que no aplican). Las reglas de ejemplo (`RuleTemplates`) no incluyen un conjunto de automatización (se mantiene el arranque de 3 conjuntos).

## C18 · Webhooks salientes genéricos

Configuración > Integraciones > «Webhooks salientes» (ajuste `webhooks.out` en `settings`): interruptor, URL, eventos suscritos, «incluir contacto» (apagado) y secreto HMAC (generado, **cifrado** con `Crypto`, mostrado UNA vez, borrable; nunca se registra). Guardar exige 2FA reciente y queda en `activity_log` (destino y eventos, sin secreto). Botón «Probar» (`webhook.test` con un lead ficticio, enviado ya, máx. 4/min). Últimas entregas y contadores de 7 días en la tarjeta.

* Eventos: `lead.created`, `lead.hot`, `lead.stage_changed`, `task.overdue`, `consent.revoked`, `lead.anonymized` y `rule.fired` (este último lo emite la acción de las reglas; se añadió a la lista).
* Envío por la cola (`outbound_webhook`, reintentos `Queue::BACKOFF` 1/5/15 min), tabla `outbound_deliveries` (UNIQUE `event_id` = idempotencia; cuerpo guardado tal cual se firma; se purga a los 30 días). POST JSON `northstar.captacion.v1` con `X-NS-Signature: sha256=` = HMAC-SHA256(secreto, `<X-NS-Timestamp>.<cuerpo>`), `X-NS-Timestamp`, `X-NS-Event`, `X-NS-Delivery` e `Idempotency-Key` (= `event_id`). Sin redirecciones. Mismo filtro SSRF que la entrega a NorthStar (`Handoff::urlProblem`, https obligatorio en producción) **revalidado al enviar**; si falla es fallo permanente.
* Cuerpo SIN datos sensibles innecesarios: id del lead, código, etapa, pipeline, temperatura, fuente, puntuación y enlace a la ficha (+ datos propios del evento). Nombre y teléfono solo con «incluir contacto». `lead.anonymized` NUNCA lleva contacto (solo id, código y enlace) y `$extra` se limpia de claves de nombre/teléfono/correo.
* **Para el otro frente (anonimización):** llamar `OutboundWebhooks::emit('lead.anonymized', $leadId, [], 'anon:' . $leadId . ':' . $sello)` después de anonimizar. Firma: `emit(string $event, ?int $leadId, array $extra = [], ?string $key = null): ?int`. Nunca lanza; devuelve el id de la entrega o `null` (apagado, sin suscripción, duplicado o error). Dentro de una transacción, la entrega se encola dentro de ella.
* Ganchos: `LeadIngest::create` (`lead.created`, solo sin `quiet`), `LeadIngest::enqueueFor` (`lead.hot`), `LeadActions::event` y `Consent` → `RuleAutomation::onEvent` (`lead.stage_changed`, `consent.revoked` + reglas), `RuleAutomation::tick` (`task.overdue`, una vez por tarea y vencimiento).

## Rutas nuevas

* `POST /ajax/config/recalc` (JSON, solo admin): estado del recálculo. Bloque «Fase AM · automatización» en `public/index.php`.
* `POST /config/webhooks/save`, `POST /config/webhooks/test` (dentro de `/config`, admin + CSRF + 2FA reciente). Añadidas a la matriz de `39_security_o`.

## Ajustes y valores por defecto

* `keys` (user_prefs): `on`. `webhooks.out` (settings): apagado, sin eventos. Las reglas de eventos respetan el interruptor general `rules.enabled`.
* Job `rules_time`: cada 15 min. Retención de `outbound_deliveries`: 30 días. Avisos de campana de reglas: 14 días.

## Migración 017

`ALTER` del ENUM `kind` de `rule_sets` y `rule_applications` (+ `automation`), columnas `bells`/`hooks` en `rule_simulation_rows`, tablas `rule_notices` y `outbound_deliveries`. SQL estándar (MariaDB 10.11 y MySQL 8). `db/schema.sql` regenerado.

## Pruebas que cambiaron por estas correcciones

`01_migrations` (listas de migraciones dinámicas en vez de fijas), `17_pulido` (404 con sesión en el shell), `20_reports` (la sugerencia de leads calientes pasó a ser interna), `21_comms` (sin SMTP la secuencia se bloquea en vez de «logged»), `30_ux_q5a` (atributo `data-keys`), `31_rules_engine` (catálogo de acciones), `39_security_o` (rutas nuevas).

## Límites y pendientes

* Las reglas de tiempo revisan hasta 500 leads/tareas por pasada y por disparador y no miran nada más viejo de 45 días.
* `no_first_contact` solo considera leads en «Nuevo» (como la campana de calientes sin contactar).
* `lead.hot` se emite al encolar la alerta de caliente (una vez por lead e intento) y no en importaciones `quiet`.
* El vistazo en hoja pone inerte el resto de la página; el cajón y el vistazo no se probaron con lector de pantalla real ni en Firefox/Safari.
* Las reglas de ejemplo no incluyen eventos; las simulaciones de eventos son una aproximación con el estado actual.
* El ajuste «atajos» no tiene aún entrada en la pantalla de Configuración (solo el menú de usuario).
* Pendiente de decisión: fijar la IP resuelta con `CURLOPT_RESOLVE` en los webhooks (DNS rebinding), ya anotado en la auditoría AI.
