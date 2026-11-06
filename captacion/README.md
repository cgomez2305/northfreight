# NorthStar Captación (mini-CRM) — fases Q1, Q2a, Q2b, Q3, Q4a, Q4b, Q5a, Q5b y Q5c

Aplicación PHP **aparte del sitio** (el sitio sigue sin base de datos) que capta, califica y da seguimiento a owner-operators, carriers pequeños y company drivers. La opera una sola persona (Carlos, ClikRank). Documento maestro: `sistema-captacion-choferes-northstar.md` v2.0.

**Qué incluye esta entrega (Q1):** base (estructura, migraciones, usuarios, login con 2FA, sesiones, CSRF, límites, registro de actividad, cola `jobs` + cron), motor de captación (validar, antispam, deduplicar, FMCSA, puntuar, encolar), alertas de Telegram (A2/A3), revocación STOP (A15), integración en proceso con el formulario del sitio y un panel mínimo.
**Q2a (panel con identidad NorthStar):** sistema de diseño, shell, login/2FA restyled, dashboard con datos reales, bandeja de leads con filtros y acciones masivas, ficha del lead completa (etapas, notas, tareas, llamadas, consentimientos) y datos de demostración locales (ver §15).
**Q2b (panel de trabajo, §17 y §16):** kanban del pipeline con arrastre, tareas y agenda, configuración (puntuación, alertas, costos por canal, integraciones, horario), registro de actividad y copias de seguridad.
**Q3 (entrega, reportes y correo, §18):** entrega de leads a NorthStar (correo y webhook firmado, confirmación sin login), rol de solo lectura para NorthStar, reportes, informes automáticos y plantillas y secuencias de correo.
**Q4a (captación saliente, §19):** prospectos FMCSA (llamadas en frío manuales), referidos y vacantes publicadas en el sitio.
**Q4b (canales externos, §20):** webhooks de Meta Lead Ads, Cal.com, Twilio y n8n, API interna para n8n, WhatsApp entrante, reactivación A11 y paquete de integración (`docs/`).
**Q5a (base de la experiencia, §21):** tema claro/oscuro/automático por usuario, vistazo rápido de un lead, vistas guardadas, tarjetas nuevas del tablero, barra lateral plegable con vistas y bandeja, paleta de comandos, atajos de teclado y «Deshacer». Las vistas de tabla, calendario y cronología (Q5c) no están.
**Q5b (editor de reglas, §22):** reglas CUANDO / ENTONCES / EXCEPTO sobre la puntuación del documento, alertas y tareas, con panel lateral, vista previa de impacto, simulación sin escritura, versiones con historial, diferencias y reversión.
**Q5c (vistas de trabajo, §23):** modos Tabla, Calendario y Cronología con los mismos filtros, chips, vistas guardadas (que ahora recuerdan el modo), densidad y vistazo rápido; reprogramar tareas arrastrando (con «Deshacer»); barrido de pulido de todas las pantallas y prueba automática de clases CSS.
**Qué NO incluye todavía:** parser de job boards y «Send me my results» (`docs/job-boards.md`); envío saliente de SMS (A2P 10DLC pendiente).

Sin dependencias nuevas: PHP 8.2+ (el servidor tiene 8.3), PDO MySQL, curl, sodium, openssl, mbstring. PHPMailer (el del sitio, `../vendor/`) solo si hay SMTP; el CRM funciona sin él.

---

## 1. Arquitectura

```
Formulario /careers/apply/ ──(en proceso, sin HTTP)──┐
n8n / carga manual ──POST /api/lead.php (Bearer)─────┤
JSONL del sitio ──bin/import-jsonl.php───────────────┤
                                                     ▼
                       LeadIngest::handle()  (src/Services/LeadIngest.php)
   1 validar/normalizar → 2 antispam → 3 deduplicar → 4 FMCSA → 5 puntuar → 6 encolar   (una transacción)
                                                     │
                                       tabla jobs ◄──┘   cron/process_jobs.php cada minuto (flock, reintentos 1/5/15 min)
                                          │
              telegram_alert · telegram_alert_followup · fmcsa_enrich · email_notify  (sms_backup_alert: registrado, NO ejecutado)
                                          ▼
                     Telegram · FMCSA · correo (PHPMailer o storage/mail.log)

Panel (captacion.northstarfreightsolutions.us): login + 2FA → dashboard, bandeja de leads y ficha (ver §15)
```

### Estructura

```
captacion/
  public/            RAÍZ DEL SUBDOMINIO. index.php (panel), setup.php, api/lead.php, api/webhooks/ (vacío), assets/ (panel.css, fonts.css, panel.js, fonts/, isotipos), .htaccess, robots.txt
  src/               bootstrap.php, Env.php, Db.php, Clock.php, Migrator.php, View.php · Controllers/ · Models/ · Services/ · Middleware/   (namespace Captacion\)
  views/             layout (shell), _icons (sprite SVG), _auth_open/_auth_close, login, twofa, setup, message, dashboard, leads, lead
  db/                schema.sql (generado) · migrations/NNN_nombre.sql
  bin/               migrate.php · import-jsonl.php · seed-demo.php (SOLO local)        (solo CLI)
  cron/              process_jobs.php (cada minuto) · fmcsa_daily.php (cada hora; Q4a) · reports_cron.php · backup_db.php
  config/            .env (NO versionado) · .env.example · disposable-domains.txt · .htaccess (Require all denied)
  storage/           logs, sesiones, locks, ratelimit (NO versionado, con .gitignore y .htaccess deny)
  tests/             run.php (runner propio) · cases/ · lib/ · fixtures/
```

`src/`, `views/`, `db/`, `config/`, `storage/`, `cron/`, `bin/` y `tests/` llevan cada uno su `.htaccess` con `Require all denied` (cinturón y tirantes: el subdominio solo sirve `public/`).

---

## 2. Modelo de datos

MySQL 8 y MariaDB 10.11 (`utf8mb4`, `utf8mb4_unicode_ci`, InnoDB, borrados lógicos con `deleted_at`, fechas en UTC, `JSON` como tipo `JSON`: nativo en MySQL 8; en MariaDB es un alias de LONGTEXT con `CHECK (JSON_VALID)`). Probado en MariaDB 10.11 (ver §10: MySQL 8 no se probó).

| Migración | Contenido |
|---|---|
| `001_core.sql` | Todas las tablas del documento §13: `users`, `leads`, `lead_events`, `consents`, `prospects_fmcsa`, `tasks`, `appointments`, `messages`, `sequence_enrollments`, `referral_codes`, `referrals`, `job_postings`, `channel_costs`, `settings`, `jobs`, `activity_log` + los índices del documento (`leads(phone_e164)`, `leads(email)`, `leads(stage,temperature)`, `leads(source,created_at)`, `prospects_fmcsa(status,last_call_at)`, `jobs(status,run_at)`). |
| `002_auth.sql` | `users.totp_confirmed_at`, `users.totp_last_step` (anti-reutilización), `users.password_algo`; tablas `recovery_codes` (solo hash) y `login_attempts`. |
| `003_settings_seed.sql` | Pesos y umbrales de puntuación y los ajustes **provisionales** (ver §4). Deben coincidir con `Scoring::DEFAULTS` (lo comprueba `tests/run.php`). |
| `004_panel.sql` | Q2a: tablas `lead_notes` (notas con borrado lógico) y `notification_reads` (avisos de la campana leídos, por usuario); `messages.duration_sec/user_id` (llamadas manuales); `jobs.status` admite `cancelled` (A3 anulada al contactar); `tasks.created_by`; índices `leads(deleted_at,created_at)`, `leads(temperature,first_contact_at,deleted_at)` y `leads(profile,created_at)`. |

| `011_ux_prefs_views.sql` | Q5a: `user_prefs` (tema, barra lateral, densidad, columnas de la lista y leads recientes de la paleta, una fila por usuario y clave) y `saved_views` (vistas guardadas por usuario: filtros en JSON, orden, pipeline, fijada, borrado lógico). `CREATE TABLE IF NOT EXISTS`, idempotente; probada desde cero y sobre la base de Q2b. |
| `012_rules.sql` | Q5b: `rule_sets` (conjuntos por tipo: puntuación, alertas, tareas y etiquetas), `rule_versions` (versiones con JSON validado; UNA publicada y UN borrador por conjunto garantizados por `pub_slot` y `draft_slot`), `rule_applications` (qué regla influyó en cada lead, con `dedupe_key` único para alertas, tareas y etiquetas), `rule_simulations` y `rule_simulation_rows` (resultado de las simulaciones). `CREATE TABLE IF NOT EXISTS`, idempotente; probada desde cero y sobre la base de Q5a con datos. En el servidor: `php captacion/bin/migrate.php`. |
| `013_lead_modes.sql` | Q5c: `saved_views.mode` (la vista recuerda el modo en que se guardó; NULL = la pantalla actual) y cuatro índices: `lead_events (created_at, id)` (cronología con cursor), `tasks (deleted_at, due_at)` y `jobs (type, status, run_at)` (calendario por rango). SQL estándar (MariaDB y MySQL 8); el migrador registra la versión y no la repite. |

Columnas añadidas a `leads` respecto al documento (necesarias para esta fase): `site_lead_id` (UNIQUE, id `ld_…` del sitio: idempotencia), `phone_raw`, `authority_status`, `endorsements`, `contact_pref`, `best_time`, `job_slug`, `disqualified_reason`, `lost_at`, `attempt`, `updated_at`. `jobs` añade `dedupe_key` (UNIQUE), `started_at`, `finished_at`.

`bin/migrate.php` es idempotente (tabla `schema_migrations`, `GET_LOCK`); una migración que falla a medias queda **sin registrar** y el mensaje dice cuál fue y qué sentencia. `php captacion/bin/migrate.php --schema` regenera `db/schema.sql` (referencia legible; la fuente de verdad son las migraciones).

---

## 3. Motor de captación (`LeadIngest`)

`LeadIngest::handle(array $data, string $source, array $ctx = []): array` → `['ok','status','lead_id','reason','errors','score','temperature','flags']`. `status`: `created` · `reentry` · `reopened` · `signed_reentry` · `duplicate_id` · `rejected`. Lanza excepción solo ante fallos de infraestructura (base caída…): quien llama decide.

1. **Validación y normalización** (`LeadPayload`, `Phone`, `UsStates`): perfil, nombre y al menos un medio de contacto son obligatorios. Teléfono a E.164 con reglas NANP de EE. UU. (código de área 2-9XX sin N11 ni N9X, central 2-9XX sin N11, no todos los dígitos iguales; acepta `+1`, `1`, paréntesis, guiones, extensión `x12`; `+` con otro país se acepta como internacional con bandera `intl_phone`). **Un teléfono inválido con correo válido NO pierde el lead**: `phone_e164 = NULL`, `phone_raw` guardado y bandera `phone_invalid`. Estado a 2 letras (acepta el nombre completo). Todo se sanea (UTF-8, sin caracteres de control, longitudes máximas). Formato de entrada: ver la cabecera de `LeadPayload.php` (acepta los alias del formulario del sitio).
2. **Antispam**: honeypot (`website`), 5 envíos por hora por IP (`ctx.public = true`, `INGEST_RATE_LIMIT`; archivos en `storage/ratelimit/`) y correos desechables (`config/disposable-domains.txt`, ~360 dominios; edítalo sin tocar código). Un lead rechazado por antispam **no se guarda en la base** (en el sitio sigue en el JSONL con `crm_ingested=false` y su `crm_reason`).
3. **Deduplicación** por teléfono E.164 (primero) y por correo:
   - existe y está abierto (o se perdió hace menos de 90 días): evento `reentry`, se **completan solo los campos vacíos** (nunca se pisan los que ya tenían valor), se suma el consentimiento nuevo, se reevalúa la puntuación (si pasa a caliente se encolan A2/A3) y se avisa por correo;
   - se perdió hace **más de 90 días**: evento `reopened`, nuevo intento (`attempt + 1`, etapa `new`, motivo de pérdida y primer contacto reiniciados) y los datos nuevos reemplazan a los viejos;
   - **ya firmado** (`signed_at`, `handoff_status='signed'` o etapa `signed`/`hired`): evento `reentry_signed` + alerta a Carlos, **sin lead nuevo** (una alerta por día como máximo);
   - mismo `site_lead_id` (reimportación): `duplicate_id`, sin cambios.
4. **FMCSA** (`Services/Fmcsa.php`, §5). Va **antes** de la transacción para no retener bloqueos esperando a la API.
5. **Puntuación** (`Services/Scoring.php`, §4). Se recalcula cada vez que cambian los datos (`LeadIngest::rescore`).
6. **Acciones** según temperatura: **caliente** → `telegram_alert` (A2, inmediato) + `telegram_alert_followup` (A3, a los 15 min); **tibio y frío** → `email_notify`; **descartado** → solo el evento. Claves únicas por intento (`telegram_alert:{lead}:{attempt}`) evitan duplicados.

Todo (lead, eventos, consentimientos, jobs) se escribe en **una transacción** (`GET_LOCK('captacion_ingest')` serializa los ingresos para que dos envíos simultáneos del mismo teléfono no creen dos leads). Eventos: `created`, `reentry`, `reopened`, `reentry_signed`, `scored`, `fmcsa`, `disqualified`, `alert_sent`, `consent_revoked`.

### Descarte (doc §6)

`cdl_type = non_domiciled` o inglés `no` → temperatura `disqualified`, puntuación 0, `disqualified_reason` (`cdl_non_domiciled`, `english_no` o ambos) y evento `disqualified`. El lead **se guarda** y queda visible para revisión manual. **El visitante ve siempre el mismo agradecimiento neutro** (decisión de la fase P; el motivo no se muestra hasta que el abogado revise los textos). Mapeo del formulario actual: CDL estándar «Sí» → `standard`; «No» → `non_domiciled`; «No estoy seguro» → `unsure` (0 puntos, bandera `cdl_unsure`, **no** descarta). No se pregunta ni se infiere nacionalidad ni estatus migratorio.

### Consentimiento (TCPA) y STOP (A15)

`consents` guarda el **texto exacto** (`CONSENT_TEXT_V1`), versión, IP, user agent y fecha, **una fila por canal que cubre el texto: `call`, `sms`, `email`**. WhatsApp **no** lo cubre (ver §11). `Consent::revoke($leadId|$telefono, 'all'|'sms'|…, $via)` marca `revoked_at`/`revoked_via`, añade el evento `consent_revoked` y la bandera `stop` (o `stop_sms`…) que `Consent::isGranted()` respeta aunque llegue un consentimiento nuevo. El webhook de Twilio que la llame es de Q4 (`public/api/webhooks/README.md`).

---

## 4. Puntuación (tablas implementadas)

0 a 100, pesos y umbrales en la tabla `settings` (claves `scoring.owner`, `scoring.driver`, `scoring.temperature`, `dispatched_equipment`, `hiring_states`, `driver_min_years`); `Scoring::settings()` completa con `Scoring::DEFAULTS`. Tramos: «menor que N» (`lt`); `null` = el resto.

**Owner-operators y carriers pequeños** (suman hasta 110; se limita a 100 y el desglose marca `capped`)

| Criterio | Puntos |
|---|---|
| CDL estándar | +20 (`unsure` = 0; no domiciliada = descarte) |
| Inglés Sí / Básico | +15 / +5 (No = descarte) |
| Autoridad **activa verificada en FMCSA** | +15 |
| Seguro vigente registrado (FMCSA) | +10 |
| Antigüedad de la autoridad: < 6 meses / 6 a 24 / > 24 | +5 / +10 / +10 |
| Equipo que NorthStar despacha (`dispatched_equipment`) | +10 |
| Camiones: 1 / 2 a 5 / 6 o más | +5 / +10 / +15 |
| Experiencia ≥ 2 años | +5 |
| Referido (`referral_code`) | +5 |
| Formulario completo (`complete_fields`) | +5 |

**Company drivers**

| Criterio | Puntos |
|---|---|
| CDL-A estándar (clase vacía se asume A; B/C no suman) | +25 |
| Inglés Sí / Básico | +15 / +5 |
| Experiencia: < 1 año / 1 a 2 / más de 2 | 0 / +10 / +20 |
| Vive en un estado de contratación (`hiring_states`) | +15 |
| Endosos Hazmat o Tanker | +5 |
| Referido | +10 |
| Formulario completo | +5 |

**Temperatura:** ≥ 70 caliente · 40 a 69 tibio · 0 a 39 frío · descarte automático. El desglose (cada criterio con sus puntos y notas) se guarda en `leads.score_breakdown` y se ve en la ficha mínima.

### ⚠️ Ajustes PROVISIONALES (hasta que NorthStar los confirme; la clave `provisional` de `settings` los lista)

| Ajuste | Valor sembrado | Por qué |
|---|---|---|
| `dispatched_equipment` | `dry-van, reefer, flatbed, box-truck, hotshot, power-only` | Los 6 equipos del doc §4. **No incluye `amazon-relay`** aunque el sitio lo ofrece como servicio estrella: decisión pendiente. |
| `hiring_states` | `[]` (vacío) | No hay lista confirmada: nadie recibe los +15 de «estado de contratación» hasta que se cargue. |
| `driver_min_years` | `1` | Por debajo se marca la bandera `below_min_experience` (no descarta). |
| Tramos de experiencia de drivers (`scoring.driver.experience`) | < 1 / < 3 | «1 a 2» y «más de 2» del doc `[CONFIRMAR mínimo]`. |
| `LEAD_MIN_YEARS` del sitio | 1 | Solo afecta a `qualified` del JSONL; el CRM usa su propio scoring. |

---

## 5. FMCSA (`Services/Fmcsa.php`) — NO PROBADO contra la API real

Aquí no hay `FMCSA_WEBKEY`. Todo se probó con **fixtures** (`tests/fixtures/fmcsa/*.json`) y un cliente HTTP inyectable. Antes de dar por bueno el enriquecimiento hay que probar con una llave real.

Peticiones (`FMCSA_API_BASE`, por defecto `https://mobile.fmcsa.dot.gov/qc/services`):

- `GET /carriers/{usdot}?webKey=…` → `content.carrier`
- `GET /carriers/{usdot}/authority?webKey=…` → `content[].carrierAuthority` (opcional: si falla se conserva lo del carrier)

Campos que se leen (constantes `F_*` y `AUTH_DATE_KEYS` en `Fmcsa.php`; **ajustar si la respuesta real usa otros nombres**):

| Dato guardado en `leads.fmcsa_data` | Campo de QCMobile |
|---|---|
| `allowed_to_operate` | `allowedToOperate` (`Y`/`N`) |
| `authority_status` (`active`/`inactive`/`none`/`unknown`) | `commonAuthorityStatus`, `contractAuthorityStatus` (`A`/`I`/`N`) + `allowedToOperate` |
| `authority_granted_at` → `authority_age_months` | **`authorityGrantDate`** (supuesto; también prueba `commonAuthorityGrantDate`, `grantDate`, `commonAuthorityDate`; la más antigua). Sin fecha: antigüedad `null` y no se inventa. |
| `insured`, `bipd_on_file` | `bipdInsuranceOnFile` (miles de USD; 0 = sin seguro) |
| `power_units`, `drivers` | `totalPowerUnits`, `totalDrivers` |
| `driver_oos_rate`, `vehicle_oos_rate` | `driverOosRate`, `vehicleOosRate` |
| `legal_name`, `dba`, `state`, `safety_rating` | `legalName`, `dbaName`, `phyState`, `safetyRating` |

Banderas rojas: `red:authority_inactive` (no autorizado a operar o autoridad inactiva) y `red:no_insurance` (seguro registrado = 0). Salen en la alerta de Telegram.

Comportamiento ante fallos: sin webKey, llave rechazada (401/403), 429/5xx, red caída o respuesta que no es JSON → `FmcsaUnavailable`: **el lead se guarda igual** con la bandera `fmcsa_pending` y se encola `fmcsa_enrich` (reintentos 1/5/15 min; al tener éxito quita la bandera, reevalúa y, si pasa a caliente, dispara A2/A3). USDOT inexistente (404 o contenido vacío) → `found:false`, bandera `usdot_not_found`, sin reintentos. La webKey va en la URL (así lo exige la API): `Storage::redact()` la quita de todo log y mensaje de error.

---

## 6. Cola y cron

Tabla `jobs`: `pending → running → done`. Si el handler lanza una excepción: reintento a **1, 5 y 15 minutos** y después `failed` (4 ejecuciones en total; visible en el panel). `PermanentJobFailure` (p. ej. el lead ya no existe) pasa a `failed` sin reintentos. `last_error` se trunca a 480 caracteres y se redacta. Un job `running` más de 10 min (proceso muerto) vuelve a `pending`. `parked` = registrado y **no ejecutado** (`sms_backup_alert`, **TODO Q4**: SMS de respaldo a Carlos con Twilio tras el registro A2P 10DLC).

`cron/process_jobs.php`: `flock` en `storage/locks/process_jobs.lock` (si la pasada anterior sigue, sale con «locked»), máximo ~50 s por pasada (`JOBS_MAX_SECONDS`), lotes de 20, y deja la marca `settings['cron.process_jobs']` que el panel muestra como «última ejecución del cron».

Tipos de job de esta fase: `telegram_alert` (A2; `kind`: `hot`, `signed_reentry`), `telegram_alert_followup` (A3: a los 15 min, solo si no hay `first_contact_at`, el lead sigue caliente y en etapa `new`), `fmcsa_enrich`, `email_notify`, `rescore_batch` (recálculo de puntuación en lotes, Q2b-C) y `backup_db` (copia manual desde Configuración, Q2b-E).

Telegram: `TELEGRAM_BOT_TOKEN` + `TELEGRAM_CHAT_ID`. Sin ellas el job falla con «Telegram sin configurar…» (reintentos) y el panel avisa. El token nunca se registra. `TELEGRAM_API_BASE` existe solo para las pruebas.

---

## 7. Seguridad (doc §17)

- Contraseñas: **Argon2id** (`PASSWORD_ARGON2ID`); si el PHP no lo trae, **bcrypt coste 12** y queda anotado (pantalla final del alta + `activity_log.changes.password_algo`). `password_needs_rehash` actualiza el hash al entrar.
- **2FA TOTP obligatorio** (RFC 6238, SHA-1, 30 s, 6 dígitos, ventana ±1, un paso usado no se reutiliza) y **10 códigos de recuperación** de un solo uso guardados como HMAC-SHA256 (clave `APP_KEY`). El secreto TOTP se guarda **cifrado** (sodium `secretbox`, `APP_KEY`). Sin QR: clave en texto en grupos de 4 + enlace `otpauth://`.
- Sesión PHP propia (`NSCRM`, archivos en `storage/sessions` 0700), cookie `HttpOnly; Secure; SameSite=Lax` (sin `Secure` solo con `APP_ENV=local`), ID regenerado al pasar la contraseña y otra vez al completar el 2FA, caduca a las **8 h de inactividad** (`SESSION_IDLE_SECONDS`).
- **CSRF** en todo POST del panel (token por sesión, `hash_equals`); logout solo por POST.
- **5 intentos fallidos cada 15 min por IP y por correo** (contraseña y 2FA cuentan); 429 con `Retry-After`; bloqueo registrado.
- PDO con sentencias preparadas nativas, salida siempre escapada, errores PHP nunca en pantalla (van a `storage/error.log`).
- Cabeceras: CSP `default-src 'none'; style-src 'self'; script-src 'self' 'nonce-…'; frame-ancestors 'none'; base-uri 'none'` (sin inline), `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options`, `X-Robots-Tag: noindex, nofollow, noarchive`, `Cache-Control: no-store`, HSTS bajo https; `robots.txt` Disallow.
- `activity_log`: altas, logins, fallos, bloqueos, códigos de recuperación usados, logouts y, desde Q2a, cada exportación CSV, cambio de etapa (individual y masivo), nota, tarea, llamada registrada, reapertura y revocación de consentimiento.
- **Sin cuentas por defecto.** `setup.php` solo funciona con `users` vacía **y** `SETUP_TOKEN` en el `.env` (token en un campo de formulario, nunca en la URL); la cuenta no se crea hasta confirmar un código TOTP válido; después responde 404 para siempre. No hace falta borrar el token (el chequeo de `users` lo bloquea), pero conviene vaciarlo del `.env`.
- `/api/lead.php`: Bearer `INTERNAL_API_TOKEN` (≥ 32 caracteres; si falta, 503), `hash_equals`, 10 autenticaciones fallidas / 15 min y `API_RATE_LIMIT` (120) peticiones/h por IP, 64 KB máximo, sin CORS, sin datos personales en las respuestas.
- El sitio principal devuelve 404/403 para todo `/captacion/` y `/cron/` (`.htaccess` raíz + `index.php`).

---

## 8. Integración con el sitio

`app/forms/lead.php` (el handler de `POST /careers/apply/`):

1. Valida y arma el lead como en la fase P (mismos errores, mismos textos, mismo 303).
2. Si `CAPTACION_ENABLED = 1` (en `app/config.local.php` del servidor): `require captacion/src/bootstrap.php` y `LeadIngest::handle(fromSiteLead($lead), 'web_careers', ['ip' => REMOTE_ADDR, 'public' => true])` **dentro de try/catch**.
3. Escribe el JSONL de respaldo **siempre**, con `crm_ingested` (true/false) y, si falló, `crm_reason` (`error`, `not_installed`, `invalid`, `disposable_email`, `rate_limited`). El 503 de «no se pudo guardar» solo sale si fallaron **los dos** destinos.
4. 303, correo y webhook como siempre.

Si el CRM está apagado, sin `.env`, con la base caída o salta cualquier excepción, el lead **no se pierde**: queda en el JSONL con `crm_ingested=false` y se reimporta con `php captacion/bin/import-jsonl.php`.

`bin/import-jsonl.php [--dir=…] [--month=AAAA-MM] [--only-pending] [--alerts] [--dry-run]`: idempotente por `id` (`site_lead_id` UNIQUE), conserva fecha original y atribución, **silencioso por defecto** (un lote viejo no dispara Telegram; `--alerts` lo activa), no consulta FMCSA en línea (encola `fmcsa_enrich`), salta líneas ilegibles y avisa. No modifica los JSONL.

Nota: el sitio sigue enviando su propio correo por cada solicitud; con el CRM encendido, los leads tibios/fríos generan además un `email_notify` (aviso duplicado). Decidir si se apaga el correo del sitio.

---

## 9. API `POST /api/lead.php`

```bash
curl -sS https://captacion.northstarfreightsolutions.us/api/lead.php \
  -H "Authorization: Bearer $INTERNAL_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"source":"n8n_whatsapp","profile":"owner_operator","name":"Luis Ortega","phone":"+1 305 234 5600","email":"luis@example.com","state":"FL","cdl_type":"standard","english_level":"yes","years_experience":5,"equipment_types":["reefer"],"truck_count":3,"usdot":"1234567"}'
# 201 {"ok":true,"status":"created","lead_id":12,"score":60,"temperature":"warm"}
```

También acepta `application/x-www-form-urlencoded`. Respuestas: 201 creado · 200 reingreso/reapertura/duplicado · 400 JSON malformado · 401 · 405 · 413 · 415 · 422 (`error`: `invalid` con `fields`, `disposable_email`, `honeypot`) · 429 · 503 (`api_disabled`, `unavailable`). Los campos aceptados están en la cabecera de `src/Services/LeadPayload.php`.

---

## 10. Variables de entorno (`config/.env`)

Ver `config/.env.example` (comentado). Resumen:

| Variable | Uso |
|---|---|
| `APP_ENV` | `production` (defecto) o `local` (solo pruebas por http: quita `Secure` de la cookie). |
| `APP_KEY` | 64 hex. Cifra el secreto TOTP y firma los códigos de recuperación. **Si se pierde o cambia hay que repetir el alta del 2FA.** |
| `APP_URL` | Base de los enlaces de las alertas. |
| `SETUP_TOKEN` | Alta del primer administrador (≥ 32 caracteres; vacío = `setup.php` da 404). |
| `INTERNAL_API_TOKEN` | Bearer de `/api/lead.php` (≥ 32 caracteres; vacío = 503). |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | MySQL/MariaDB de Hostinger: host **127.0.0.1**, puerto 3306. |
| `FMCSA_WEBKEY` | Llave QCMobile (gratuita). Sin ella: `fmcsa_pending`. |
| `TELEGRAM_BOT_TOKEN` `TELEGRAM_CHAT_ID` | Alertas A2/A3. |
| `MAIL_TO` `SMTP_HOST` `SMTP_PORT` `SMTP_USER` `SMTP_PASS` `SMTP_SECURE` | Avisos por correo (mismos nombres que `app/config.local.php` del sitio). Sin SMTP: `storage/mail.log`. |
| Opcionales | `SESSION_IDLE_SECONDS` (28800) · `API_RATE_LIMIT` (120/h) · `INGEST_RATE_LIMIT` (5/h) · `JOBS_MAX_SECONDS` (50) · `BACKUP_MAX_SECONDS` (240, tope de tiempo del volcado) · `REAUTH_SECONDS` (600, vigencia de la confirmación contraseña + 2FA en Configuración y descargas) · `TOTP_ISSUER` · `STORAGE_DIR` (solo pruebas) · `CAPTACION_ENV_FILE` (otra ruta del .env). |

El sitio solo necesita `define('CAPTACION_ENABLED', 1);` en `app/config.local.php` (ver `app/config.local.example.php`). Si se sube `RATE_LIMIT` del sitio por encima de 5, subir también `INGEST_RATE_LIMIT`.

---

## 11. Despliegue en Hostinger (paso a paso)

Hostinger despliega `main` a `public_html` (Git deploy), así que `captacion/` llega a `public_html/captacion/`. **Nada de esto lo hace el código: se configura una vez a mano.**

1. **Base de datos.** hPanel → Bases de datos → MySQL: crea base (p. ej. `u480515229_captacion`), usuario y contraseña fuerte. Desde la app el host es `127.0.0.1`, puerto `3306`.
2. **Subdominio.** hPanel → Dominios → Subdominios → crear `captacion` en `northstarfreightsolutions.us` con **carpeta personalizada** `public_html/captacion/public` (por defecto Hostinger usaría `public_html/captacion`, que contiene el código: no lo dejes así). Espera a que se emita el SSL y activa «Forzar HTTPS».
3. **`.env`** (por SSH, Business tiene SSH):
   ```bash
   cd ~/domains/northstarfreightsolutions.us/public_html/captacion
   cp config/.env.example config/.env && chmod 600 config/.env
   /opt/alt/php83/usr/bin/php -r 'echo bin2hex(random_bytes(32)), "\n";'   # úsalo para APP_KEY; repite para SETUP_TOKEN e INTERNAL_API_TOKEN
   nano config/.env      # APP_URL, DB_*, MAIL_TO, SMTP_*, FMCSA_WEBKEY, TELEGRAM_*
   ```
   Guarda una copia de `APP_KEY` fuera del servidor.
4. **Migración inicial:**
   ```bash
   /opt/alt/php83/usr/bin/php bin/migrate.php
   /opt/alt/php83/usr/bin/php bin/migrate.php --status
   ```
5. **Alta del administrador:** abre `https://captacion.northstarfreightsolutions.us/setup.php`, escribe el `SETUP_TOKEN`, tu nombre, correo y contraseña (≥ 12 caracteres); añade la clave TOTP en tu app de autenticación, **guarda los 10 códigos de recuperación** y confirma el código de 6 dígitos. Luego vacía `SETUP_TOKEN` en el `.env`.
6. **Cron** (hPanel → Avanzado → Cron Jobs → personalizado, **cada minuto**):
   ```
   * * * * * /opt/alt/php83/usr/bin/php /home/u480515229/domains/northstarfreightsolutions.us/public_html/captacion/cron/process_jobs.php >/dev/null 2>&1
   ```
   (Prospectos FMCSA: `cron/fmcsa_daily.php`, ver §19.)
7. **Telegram:** crea un bot con @BotFather → `TELEGRAM_BOT_TOKEN`; escríbele cualquier mensaje y abre `https://api.telegram.org/bot<TOKEN>/getUpdates` para leer `chat.id` → `TELEGRAM_CHAT_ID`. El panel deja de avisar «Telegram sin configurar».
8. **FMCSA:** registra una webKey en https://mobile.fmcsa.dot.gov/QCDevsite/HomePage → `FMCSA_WEBKEY`. **Probar con un USDOT real** y comparar con §5.
9. **Encender el sitio:** en `public_html/app/config.local.php` añade `define('CAPTACION_ENABLED', 1);`. Reimporta lo acumulado: `/opt/alt/php83/usr/bin/php captacion/bin/import-jsonl.php --dry-run` y luego sin `--dry-run`.
10. **Verificación** (todo debe dar 403/404, nunca contenido):
    ```bash
    curl -sI https://northstarfreightsolutions.us/captacion/config/.env
    curl -sI https://northstarfreightsolutions.us/captacion/public/setup.php
    curl -sI https://captacion.northstarfreightsolutions.us/config/.env
    curl -sI https://captacion.northstarfreightsolutions.us/setup.php        # 404 una vez creado el admin
    curl -sI https://captacion.northstarfreightsolutions.us/login            # 200, X-Robots-Tag noindex, CSP, Set-Cookie ... Secure; HttpOnly; SameSite=Lax
    ```
11. **Copias de seguridad (Q2b-E).** Segundo cron en hPanel → Cron Jobs, **cada hora a los :30** (el propio script decide si toca: hace la copia solo a las 3:30 a. m. de Nueva York, con cambio de hora incluido, y recupera un día perdido):
    ```
    30 * * * * /opt/alt/php83/usr/bin/php /home/u480515229/domains/northstarfreightsolutions.us/public_html/captacion/cron/backup_db.php >/dev/null 2>&1
    ```
    Prueba manual por SSH: `/opt/alt/php83/usr/bin/php cron/backup_db.php --force` (hace una copia ahora; sale 0 si fue bien). Ver §16 (qué guarda, retención, verificación y **cómo restaurar**) y haz además una descarga periódica a un disco tuyo.
12. **Migraciones de Q2b:** tras el deploy, `/opt/alt/php83/usr/bin/php bin/migrate.php` aplica `005_pipeline_config.sql` (índices del kanban y de tareas, `leads.stage_changed_at`, `appointments.title`, `activity_log.agent` y la tabla `backups`). Es idempotente (`--status` la muestra como aplicada).

### `.htaccess`

No se pudo probar con Apache/LiteSpeed (aquí solo hay `php -S`, que no lo lee). Las pruebas cubren lo equivalente en `index.php` y revisan por texto las directivas. **Comprobar en el servidor** con los `curl` del paso 10. Reglas añadidas al `.htaccess` raíz: `RewriteCond %{HTTP_HOST} !^captacion\. [NC]` + `RewriteRule ^(captacion|cron)(/|$) - [F,L,NC]`. El subdominio usa `captacion/public/.htaccess`, que activa su propio `RewriteEngine` (así no hereda las reglas del raíz) y pasa la cabecera `Authorization` a PHP (algunos Apache/LiteSpeed la descartan).

---

## 12. Runbook

- **Perdí el móvil con el TOTP:** entra con uno de los 10 códigos de recuperación (cada uno sirve una vez; el panel avisa cuántos quedan).
- **Perdí también los códigos:** por SSH/phpMyAdmin: `DELETE FROM recovery_codes; DELETE FROM users;` (el `activity_log` y los leads se conservan), pon un `SETUP_TOKEN` nuevo en el `.env` y repite el alta en `setup.php`.
- **Cola con fallidos:** el panel muestra tipo, intentos y último error. Para reintentar uno: `UPDATE jobs SET status='pending', attempts=0, run_at=UTC_TIMESTAMP() WHERE id=…;`. Causas típicas: Telegram sin configurar o token malo; webKey rechazada; SMTP.
- **«El cron no corre»:** el panel avisa si pasaron más de 5 min desde la última pasada. Revisa la línea de cron (ruta de PHP y del script) y `storage/error.log`.
- **Un lead no llegó al CRM:** busca `crm_ingested":false` en `../storage/leads/*.jsonl` (`crm_reason` dice por qué) y corre `import-jsonl.php`.
- **Rotar tokens:** cambia `INTERNAL_API_TOKEN`, `TELEGRAM_BOT_TOKEN` o `FMCSA_WEBKEY` en el `.env` (no hace falta reiniciar nada). Cambiar `APP_KEY` obliga a repetir el 2FA.
- **Ver la cola o los logs:** `storage/error.log`, `storage/mail.log`, `storage/fmcsa.log`.
- **STOP de un lead:** `Consent::revoke('+13055550100', 'all')` (el webhook de SMS la llamará en Q4).

---

## 13. Pruebas

```bash
php captacion/tests/run.php               # todo (~30 s): levanta un MariaDB temporal (puerto libre desde 33306) y servidores php -S; los apaga por PID al terminar
php captacion/tests/run.php --only=scoring --workdir=/ruta --keep
```

Necesita `mariadbd`/`mariadb-install-db` en el PATH. No toca ningún servidor remoto ni el `storage/` del repo. Cubre: migraciones desde cero y repetidas (+ una que falla), alta del admin y bloqueo posterior, login correcto/incorrecto, 2FA (válido, ±1 ventana, inválido, reutilización, código de recuperación de un solo uso), límite de login (IP, correo, 2FA), CSRF, sesión caducada, cookie `Secure` en producción, cabeceras/CSP, escapado, TOTP con vectores del RFC 6238, teléfonos, normalización, puntuación (tabla de 45 casos + ajustes desde la base), FMCSA con fixtures (activa, inactiva, sin seguro, no existe, API caída, llave rechazada), cola (éxito, reintentos 1/5/15, fallo definitivo, parked, tiempo máximo, solape real con `flock`), Telegram con servidor simulado (A2, A3, sin configurar, 401/500/sin servidor, el token no aparece en logs), `LeadIngest` extremo a extremo (las 3 reglas de deduplicación, antispam, transacción atómica, rescore), consentimiento y STOP, `api/lead.php` (token válido/inválido/ausente, límite de tasa, 413/415/422/503) y el sitio completo (`php -S` con el CRM habilitado apuntando al MariaDB: envío válido, inválido, honeypot, descartado, reingreso; base caída → JSONL + 303 igual; CRM apagado; `import-jsonl.php` idempotente).

**No cubierto:** FMCSA real, Telegram real (incluida la apertura del enlace `tel:` en la app), SMTP real, MySQL 8 (solo MariaDB 10.11), Apache/LiteSpeed y el `.htaccess`, Argon2id en el servidor de Hostinger (aquí sí hay; si allí faltara, cae a bcrypt y lo anota).

---

## 14. Pendientes y decisiones abiertas

- Equipos que NorthStar despacha, estados de contratación y mínimo de experiencia de company drivers (§4).
- Texto de consentimiento: revisión legal. **`CONSENT_TEXT_V1` cubre llamada, SMS y correo, no WhatsApp**; antes de que Q4 mande el WhatsApp A1 automático hay que ampliar el texto (nueva versión `v2`) o apoyarse en que el lead escribió primero.
- Correo duplicado: el sitio avisa por correo y el CRM también (`email_notify`).
- Fase Q3: prospectos FMCSA y reportes. Pendiente de Q2b: la sincronización de citas con Cal.com (la tabla `appointments` y la vista ya están listas; Q4) y una copia automática fuera de Hostinger (hoy es una descarga manual, §16).
- CSS del panel (Q2b-F): partido en `panel.css` (base, 30,9 KB) + una hoja por pantalla (§17). Total por pantalla con `fonts.css` entre 36,6 y 44,9 KB sin comprimir (la ficha del lead es la más pesada); JS: `panel.js` 14,7 KB + el de la pantalla (kanban 18,4 KB). Sigue pendiente de Q2a: probar en Firefox y Safari.
---

## 15. Panel (Q2a): identidad, pantallas y operación

### Rutas

| Ruta | Qué es |
|---|---|
| `GET /` | Dashboard (`?p=today|week|month`, `?rq=` y `?rp=` para la tabla de leads recientes) |
| `GET /leads` | Bandeja. Filtros por URL: `t` temperatura · `pf` perfil · `src` fuente · `st` etapa · `eq` equipo · `us` estado · `lg` idioma · `from`/`to` (ET, AAAA-MM-DD) · `q` texto · `ct=none` sin contactar · `fl=fmcsa_pending` · `sort` (created, score, name, stage) · `dir` · `page` · `per` (25/50/100). Todo se valida con lista blanca. |
| `GET /leads/{id}` | Ficha (la misma URL que usan las alertas de Telegram) |
| `POST /leads/{id}/{stage\|note\|note-edit\|note-delete\|task\|task-done\|call\|reopen\|revoke}` | Acciones de la ficha (sesión + CSRF + `activity_log`) |
| `POST /leads/bulk` | Cambio masivo (`contacted`, `qualified` → Precalificado en el pipeline B, `lost`) o exportar la selección |
| `POST /leads/export` | CSV (`scope=filters\|selection\|period`); UTF-8 con BOM y protección contra fórmulas (`= + - @` y tab se prefijan con `'`; también el `+` de los teléfonos) |
| `POST /ajax/search` | Buscador global (JSON, cabecera `X-CSRF-Token`, 90 peticiones/min por usuario → 429) |
| `POST /ajax/notifications/read` | Marca los avisos de la campana como leídos (guardado en `notification_reads`) |

### Paleta (R1; solo en `public/assets/panel.css`, `:root`)

Desde la fase R1 (2026-11-06) el panel ya no usa la identidad navy y cian del sitio: neutros CÁLIDOS con un único acento (cian profundo, para que el isotipo de NorthStar siga encajando). Superficies sólidas: cero `backdrop-filter`, cero degradados decorativos (solo el área tenue bajo la línea de los gráficos), cero glows y sin sombras salvo `0 1px 2px` en menús, diálogos y avisos. Todos los colores viven en `:root` y `:root[data-theme="dark"]`; las hojas por pantalla solo usan variables.

Tokens (modo claro / oscuro) y contrastes medidos (WCAG, texto salvo donde se indica):

| Token | Claro | Oscuro | Uso |
|---|---|---|---|
| `--canvas` | `#EFEEEA` | `#0D0D0C` | Lienzo: barra lateral y páginas sin sesión |
| `--bg` | `#F7F6F3` | `#141413` | Fondo de la aplicación |
| `--surface` | `#FFFFFF` | `#1B1B19` | Tarjetas, menús, diálogos, campos |
| `--sunk` | `#F1F0EC` | `#222220` | Hover de filas, segmented control, notas |
| `--line` / `--line-2` | `#E7E5E0` / `#D6D3CC` | `#2A2A27` / `#3A3A36` | Separadores y borde de botones secundarios |
| `--field` | `#8A8882` | `#75746E` | Borde de campos (≥ 3:1) |
| `--ink` / `--ink-2` / `--ink-3` | `#1A1A18` / `#4A4945` / `#6B6A66` | `#F2F1EE` / `#C4C2BC` / `#9C9A95` | Texto principal, secundario y de etiquetas (todos AA) |
| `--ink-4` | `#A3A19C` | `#5F5E5A` | Solo decorativo (separadores, iconos inactivos); nunca texto útil |
| `--accent` / `--accent-hover` | `#087089` / `#065A70` | `#3FBFDF` / `#6FD3EC` | Botón primario, selección, serie principal, punto «en vivo» |
| `--accent-ink` | `#087089` | `#5FCDE8` | Enlaces y texto de acento |
| `--accent-tint` / `--accent-line` | `#EEF5F7` / `#B4D4DD` | `#162A30` / `#24505C` | Fila o elemento seleccionado (6 a 8 %) |
| `--ok` · `--ok-bg` · `--ok-dot` | `#1F6B40` · `#E6F1EA` · `#2E7D4F` | `#7BD99A` · `#1F3A27` · `#4CB874` | Estados y variaciones |
| `--bad` · `--bad-bg` · `--bad-dot` · `--bad-solid` | `#B3321F` · `#F8E7E3` · `#C2412D` · `#C2412D` | `#F29A8C` · `#3A1D17` · `#E5604B` · `#EE7A66` | Errores, retrasos, botón destructivo |
| `--warn` · `--warn-bg` · `--warn-dot` | `#8A5A0B` · `#F8EDD6` · `#C7881A` | `#E8B65A` · `#3A2B0F` · `#D9A23A` | Avisos |

| Par (claro) | Contraste | Par (oscuro) | Contraste |
|---|---|---|---|
| `--ink` sobre surface / bg / canvas | 17,4 / 16,1 / 15,0 | `--ink` sobre surface / bg / canvas | 15,3 / 16,3 / 17,2 |
| `--ink-2` sobre surface / canvas | 9,0 / 7,8 | `--ink-2` sobre surface / canvas | 9,7 / 10,9 |
| `--ink-3` sobre surface / bg / canvas / sunk | 5,4 / 5,0 / 4,7 / 4,8 | `--ink-3` sobre surface / bg / canvas / sunk | 6,1 / 6,6 / 6,9 / 5,7 |
| `--accent-ink` sobre surface / bg / canvas / tinte | 5,7 / 5,3 / 4,9 / 5,2 | `--accent-ink` sobre surface / tinte | 9,4 / 8,1 |
| Blanco sobre `--accent` / `--accent-hover` | 5,7 / 7,8 | `--on-accent` `#0D0D0C` sobre `--accent` / `--accent-hover` | 9,0 / 11,3 |
| Blanco sobre `--bad-solid` | 5,1 | `#0D0D0C` sobre `--bad-solid` | 7,1 |
| `--ok` / `--bad` / `--warn` sobre su fondo | 5,6 / 5,2 / 5,1 | ídem | 7,2 / 7,2 / 7,4 |
| `--field` (borde, componente) sobre surface / bg | 3,5 / 3,3 | ídem | 3,7 / 3,9 |
| Texto de tooltips y avisos sobre `--pop` | 15,4 | ídem | 11,1 |
| Puntos de estado (gráficos, ≥ 3:1) sobre surface: caliente / tibio / frío / OK / error | 4,2 / 3,0 / 3,5 / 5,1 / 5,1 | ídem | 5,7 / 7,5 / 6,0 / 6,9 / 5,0 |

Significado constante: **temperatura** y **etapa** se muestran con un punto de 6 px y texto neutro (nunca fondos de color en filas ni tarjetas): caliente = naranja rojizo, tibio = ámbar, frío = azul pizarra, descartado = gris; etapa = gris → pizarra → azul → acento → verde al firmar → rojo al perder. Con 5 series (fuentes) se usa una paleta categórica apagada (`--seg-0` a `--seg-4`: acento, pizarra, arena, salvia, gris); con 2 series, la principal en el acento y la otra en gris. Los avatares son neutros (iniciales sobre `--av`). Foco: contorno de 2 px `--focus`; campos con borde y anillo de 1 px del acento. El texto pequeño útil nunca va en `--ink-4`.

### Tema oscuro (Q5a, recolorado en R1)

El tema se elige por usuario (**Claro / Oscuro / Auto**, en el menú del usuario y con el botón de la barra superior; el lector de NorthStar también puede cambiarlo). La preferencia vive en `user_prefs` y `<html>` la trae ya escrita (`data-theme-pref`); un script con nonce al inicio del `<head>` resuelve `auto` con `prefers-color-scheme` y fija `data-theme` ANTES de pintar (sin parpadeo; sin JS queda claro). Las páginas sin sesión usan lo último elegido en ese navegador (`localStorage.ns-theme`) o el modo automático. Los informes impresos vuelven siempre a claro (`beforeprint`/`afterprint`). El logo cambia al isotipo blanco con `content:url()`. Los pares del tema oscuro están en la tabla de arriba.

### Tipografía y marca (R1)

Dos familias, ambas locales en `public/assets/fonts/` (subset latino variable, `font-display: swap`, `font-src 'self'`; origen y licencia OFL en `fonts/README.md`): **Geist** (interfaz, tablas, títulos y números grandes; 29.400 B) y **Geist Mono** (solo IDs y códigos `LD-0142`, metadatos, ejes de gráficos y atajos de teclado; 23.128 B). Total 52.528 B frente a 72.356 B antes (Barlow, Barlow Condensed y JetBrains Mono, retirados del panel). Escala en tokens: `--fs-xs` 12 · `--fs-sm` 13 (etiquetas, en gris) · `--fs-md` 14 (cuerpo y tablas) · `--fs-lg` 16 · `--fs-xl` 20 · `--fs-h1` 26 a 30 (peso 600) · `--fs-num` 44 (números protagonistas, peso 500, `tabular-nums`, `-0.02em`, decimales y unidades atenuados). Isotipo oscuro y claro en `public/assets/`. Botón primario relleno con el acento (8 px de radio, 40 px de alto; 44 px en móvil), un solo primario por vista.

### Pantallas y comportamiento

- **Shell** (desde Q5a, §21: la sección «Atajos» es ahora «Vistas» con contador en vivo, hay bloque «Bandeja», barra plegable, tema y paleta de comandos): barra lateral (Principal: Dashboard, Leads; Atajos: calientes pendientes, llegaron hoy, descartados; estado del sistema y usuario abajo), barra superior con migas, **buscador global** (`Ctrl/⌘ K` o `/`, panel de resultados con flechas/Enter, diálogo nativo `<dialog>`), reloj ET, **campana** (leads calientes sin contactar, trabajos fallidos, Telegram sin configurar, FMCSA pendiente/sin llave, cron detenido; la clave de cada aviso incluye su estado, así que uno resuelto y vuelto a ocurrir sale otra vez) y menú del usuario. En ≤ 1023 px la barra lateral es un cajón; en ≤ 760 px hay barra inferior (Inicio, Leads, Buscar, Menú) y la ficha trae su propia barra fija (Llamar, WhatsApp, Correo, Etapa).
- **Dashboard**: 4 KPI (leads con sparkline y variación; calificados con anillo; calientes sin contactar con la espera del más antiguo; mediana de primera respuesta), selector Hoy / Semana / Mes (hoy se compara con ayer a la misma hora; semana y mes, con los 7 / 30 días previos), «Exportar CSV» del periodo, gráfico de líneas semanal / mensual / anual (las pestañas solo aparecen si hay datos para ellas), desglose por fuente (90 días) con una línea de insight solo si hay ≥ 10 leads y una fuente que crece (≥ 3 leads y ≥ 1 en el periodo previo), franja secundaria (12 semanas; el costo por lead dice «Sin costos cargados» mientras `channel_costs` esté vacía), tabla de leads recientes con búsqueda y paginación, mapa de calor de 13 semanas, lista «Hoy» y estado del sistema. Los cálculos de día/semana/mes son en hora ET; el agrupado por día se hace en PHP (no hace falta `CONVERT_TZ`).
- **Bandeja**: pestañas por temperatura con conteos (respetan el resto de filtros), filtros con chips activos y URL compartible, orden por columnas, paginación, selección múltiple con barra de acciones, exportación, filas urgentes marcadas y tarjetas apiladas en móvil. Estados vacíos distintos para «no hay leads» (explica cómo llegan) y «ningún lead coincide».
- **Ficha**: cabecera con nombre, perfil, temperatura, etapa, banderas y puntuación; Llamar (`tel:`), WhatsApp (`wa.me` con saludo en el idioma del lead; WhatsApp **no** está cubierto por `CONSENT_TEXT_V1`: es un mensaje manual), Correo, Registrar llamada, Cambiar etapa / Reabrir; datos de contacto; avance en el pipeline; tareas, notas (editar, borrado lógico), línea de tiempo unificada con filtros, puntuación desglosada, elegibilidad y operación, FMCSA con banderas rojas, atribución, consentimientos (texto, fecha, canal, revocar) y otros leads con el mismo teléfono o correo.

### Reglas de etapa (doc §7) implementadas

«Perdido»/«Descartado» exige motivo de la lista fija (y nota con «Otro»); «Contactado» y cualquier etapa posterior fija `first_contact_at` y **cancela** el job A3 pendiente (`jobs.status = 'cancelled'`); «Presentado» solo desde Calificado / Llamada agendada (A) o Precalificado (B); «Firmado»/«Contratado» solo desde Presentado; saltarse el orden o sacar un lead de Firmado/Contratado exige marcar el override; un lead perdido solo se reabre (vuelve a Nuevo o Contactado). Una llamada registrada como «Contestó» fija el primer contacto y pasa de Nuevo a Contactado. Todo va en transacción, con evento en la línea de tiempo y registro en `activity_log`.

### Datos de demostración (solo local)

```bash
CAPTACION_ENV_FILE=/ruta/.env php captacion/bin/seed-demo.php --reset     # ~120 leads ficticios, 14 semanas
```

Se niega a correr si `APP_ENV` no es `local`, si `DB_HOST` no es 127.0.0.1/localhost, si `DB_NAME` no contiene `dev`, `demo` o `test` (o parece de Hostinger: `u123456_…`) o si `leads` tiene algún lead que no sea de demostración (`site_lead_id` = `demo_…`). Nombres genéricos, teléfonos 555-01XX y correos `example.com`. **Nunca se despliega ni se ejecuta contra la base real.**

### Pruebas

`php captacion/tests/run.php` (ahora con `11_panel_q2a`): dashboard contra datos conocidos y consistencia con la base, filtros/orden/paginación de la bandeja, transiciones de etapa y validaciones (ambos pipelines, masivo, A3 cancelada, llamadas, notas, tareas, reabrir, revocar), buscador, avisos y «leído», exportación CSV con protección contra fórmulas, y por HTTP: sin sesión, sin/ con CSRF, límite de uso y escapado en cada endpoint nuevo.

---

## 16. Copias de seguridad y restauración (Q2b-E, doc §17)

**Qué hace.** `cron/backup_db.php` (cada hora a los :30; actúa a las 3:30 a. m. ET) genera `storage/backups/captacion-AAAAMMDD-HHMMSS[-manual].sql.gz` con **todas** las tablas del CRM (estructura + datos, incluidas `settings`, `users` con sus secretos TOTP **cifrados**, `activity_log` y `consents`). Es PHP puro: no usa `mysqldump` (se prueba con el PATH vacío), lee con una conexión propia en una **instantánea consistente** (`REPEATABLE READ` + `CONSISTENT SNAPSHOT`: todas las tablas al mismo instante aunque el panel siga escribiendo), por lotes de 200 filas, en streaming (memoria constante), con `flock` (una copia a la vez, `storage/locks/backup.lock`) y un tope de tiempo (`BACKUP_MAX_SECONDS`, 240 s). El archivo queda en `storage/backups/` (carpeta 0700, archivo 0600, `.htaccess` «deny»; `storage/` ya está fuera del docroot del subdominio). Dos copias en el mismo segundo no se pisan (`-b`, `-c`…).

**Verificación (antes de darla por buena).** Se descomprime TODO el `.gz`; las tablas del archivo son exactamente las de la base; las filas escritas de cada tabla coinciden con su marcador `-- TABLE … ROWS n` y con `COUNT(*)` leído en la misma instantánea; el archivo termina en `-- END OK tables=N rows=M`. Si algo falla se borra el parcial, la copia queda `failed` con el motivo y `settings['cron.backup']` pasa a `failed`. Se guarda también el `sha256`.

**Retención.** 30 días (siempre se conserva la última copia buena); lo purgado deja `backup_purge` en `activity_log`. El mismo cron purga el registro de actividad según su retención.

**Dónde se ve.** Configuración → *Copias de seguridad* (lista con fecha, tamaño, tablas, filas y estado; **Hacer copia ahora** la encola y la hace el cron de la cola; **Descargar** exige contraseña + 2FA recientes, responde con `Cache-Control: no-store` y queda en `activity_log` como `backup_download`), la tarjeta *Copia diaria* de Integraciones y la **campana** (aviso rojo si la última copia falló, ámbar si la última buena tiene más de 26 h o no hay ninguna). Solo administradores.

**Fuera de Hostinger (recomendado, no automático).** Las copias del servidor se pierden si el servidor se pierde. Descarga una cada semana o mes desde la pantalla y guárdala **cifrada** en un disco tuyo (p. ej. `gpg -c captacion-….sql.gz`). Contiene datos personales de leads: no la subas sin cifrar a ningún servicio de terceros ni la mandes por correo. El CRM no envía copias a ningún tercero.

### Restaurar (paso a paso)

Probado en la suite (`16_backup`: restaura en una base vacía y compara `CHECKSUM TABLE` y número de filas de las 20 tablas de datos; `backups` y `settings` se excluyen porque la propia copia las modifica después de la instantánea) y a mano con el cron real.

1. **Elige la copia.** En el servidor: `ls -lh captacion/storage/backups/`; o descarga una desde la pantalla. Comprueba que está sana (no necesita la base): `php captacion/bin/backup-verify.php RUTA.sql.gz` → `OK` (exit 0).
2. **Pon el sitio en pausa** si restauras sobre la base en uso: quita la línea del cron de la cola y `define('CAPTACION_ENABLED', 0);` en `app/config.local.php` (el sitio sigue guardando los leads en JSONL; se reimportan con `bin/import-jsonl.php`).
3. **Crea una base vacía** (hPanel → MySQL; o, para ensayar, `CREATE DATABASE cap_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`). Para restaurar sobre la misma base basta con que exista: el archivo hace `DROP TABLE IF EXISTS` de cada tabla y desactiva las llaves foráneas mientras carga.
4. **Carga el volcado:**
   ```bash
   gunzip -c captacion/storage/backups/captacion-AAAAMMDD-HHMMSS.sql.gz | mysql -h127.0.0.1 -P3306 -uUSUARIO -p NOMBRE_BASE
   ```
   (`mariadb` en lugar de `mysql` si es el cliente instalado.) Sin SSH: phpMyAdmin → Importar acepta `.sql.gz`, pero hosting compartido limita el tamaño (si no entra, usa SSH). Debe terminar sin errores.
5. **Comprueba:** `SELECT COUNT(*) FROM leads;` y compara con el recuento de la pantalla de copias (columna Filas) o ejecuta `CHECKSUM TABLE leads, lead_events, lead_notes, tasks, consents;` en la base original (si aún existe) y en la restaurada: deben coincidir.
6. **Apunta el CRM a esa base** si es otra (`DB_NAME` en `config/.env`) y corre `php captacion/bin/migrate.php --status` (la tabla `schema_migrations` viaja en la copia: debe decir que no falta nada; si la copia es de antes de una migración, aplícala).
7. **`APP_KEY` tiene que ser la misma** que cuando se hizo la copia: cifra los secretos TOTP guardados. Con otra clave nadie puede iniciar sesión (haz el alta de nuevo, §12 «Perdí también los códigos»). Por eso se guarda una copia de `APP_KEY` fuera del servidor (§11 punto 3).
8. **Reactiva** el cron de la cola y `CAPTACION_ENABLED`; entra al panel, mira *Estado del sistema* y reimporta los leads que llegaron durante la pausa: `php captacion/bin/import-jsonl.php --dry-run` y luego sin `--dry-run` (deduplica).
9. Anota la restauración: el panel deja `login_ok` en el registro de actividad, pero la restauración en sí no (la base restaurada trae el registro hasta el momento de la copia).

**Ensayo recomendado** (cada trimestre): descarga una copia, restáurala en una base `cap_restore` local y compara `CHECKSUM TABLE`. Un volcado que nunca se ha restaurado no es una copia.

**Limitaciones conocidas.** Sin cifrado propio del archivo (los permisos 0600 y `storage/` fuera del docroot son la protección en el servidor); sin copia incremental (la base del CRM es pequeña: ~40 KB con 120 leads de demostración); el volcado usa transacción de instantánea, no bloquea el panel, pero en una base de cientos de miles de filas el tope de 240 s podría quedarse corto (subir `BACKUP_MAX_SECONDS`).

---

## 17. Panel de trabajo (Q2b): pipeline, tareas, configuración y actividad

Migración nueva: **`005_pipeline_config.sql`** (`leads.stage_changed_at` + índice del kanban, índices de tareas y citas, `appointments.title`, `activity_log.agent`, tabla `backups`); idempotente, probada desde cero y sobre la base de Q2a. Cron nuevo: solo el de copias (§11 punto 11). Todas las acciones: sesión + 2FA + rol en el servidor (`viewer` solo lee) + CSRF + validación + `activity_log`; los POST de configuración y la descarga de copias exigen además contraseña + 2FA recientes (`Reauth`, `REAUTH_SECONDS`).

| Ruta | Qué es |
|---|---|
| `GET /pipeline?pl=A\|B&t=&src=&q=` | Kanban: pestañas por pipeline, una columna por etapa del doc §7 con su conteo real, 25 tarjetas por columna y «Ver más» (`POST /ajax/pipeline/cards`), «Perdido/Descartado» colapsada. |
| `POST /ajax/pipeline/move` | Mover una tarjeta. Llama a `LeadActions::setStage`, **la misma función que la ficha** (motivo obligatorio para Perdido/Descartado, orden de Presentado/Firmado, «Contactado» fija `first_contact_at` y cancela A3, transacción + evento + `activity_log`); si falta un dato responde qué pedir y el panel abre un diálogo. Actualización optimista con reversión y mensaje si el servidor rechaza. Alternativa por teclado y táctil: menú «Mover a…» en cada tarjeta; en móvil las columnas son un carrusel con snap y chips de etapa. |
| `GET /tareas?tab=today\|overdue\|upcoming\|done\|week` · `POST /tareas/new` · `POST /tareas/{id}/{done\|reopen\|snooze\|edit\|delete}` | Tareas y agenda (las mismas `tasks` de la ficha; borrado lógico). Posponer: +1 h, mañana o fecha. Las citas (`appointments`) salen junto a las tareas con enlace al lead (la sincronización con Cal.com es de la fase Q4). Contador de vencidas en la barra lateral y en la campana. |
| `GET /config?tab=puntuacion\|alertas\|costos\|integraciones\|horario\|copias` + `POST /config/...` | Solo administradores. **Puntuación**: pesos del doc §6 y umbrales (validación de rangos, caliente > tibio), vista previa del efecto, recálculo por la cola en lotes (`rescore_batch`, evento `scored`), restablecer, y los ajustes provisionales con su historial. **Alertas**: Telegram caliente (A2), segunda alerta A3 (minutos configurables, 15 por defecto), **alerta de Telegram también para tibios** (`auto` = solo mientras no haya llave de FMCSA, porque sin ella un lead perfecto no pasa de ~65 puntos; `on`/`off`), correo de aviso y horario de silencio (ET) para las no urgentes. **Costos por canal** (`channel_costs`, por mes y fuente; el dashboard calcula CPL y costo por firmado). **Integraciones**: Telegram, FMCSA, SMTP, cron y copia diaria con estado en vivo y botón «Probar» (nunca se muestran secretos). **Horario y zona**. **Copias**: §16. |
| `GET /actividad` · `POST /actividad/{export\|retention}` | Solo administradores: inicios de sesión (éxitos y fallos con IP y agente resumidos), exportaciones, cambios de configuración, cambios masivos y borrados, con filtros, paginación, CSV (protección contra fórmulas) y retención configurable (la purga corre en el cron de copias). |

### CSS y JS por pantalla

`panel.css` es la base (variables de color, shell, botones, campos, tarjetas, tablas, diálogos). Cada pantalla añade solo su hoja: `charts.css`+`dashboard.css` (/), `leads.css`, `charts.css`+`lead.css` (ficha), `kanban.css`, `tareas.css`, `config.css`+`cfg-<pestaña>.css` (configuración), `config.css`+`actividad.css`, `auth.css` (login, 2FA, alta y mensajes). Lo indica cada controlador con `'css' => [...]` y `layout.php` las enlaza (nombre `[a-z0-9-]{2,24}`); sin `css` las pantallas sin barra lateral cargan `auth`. La prueba `17_pulido` comprueba que cada ruta carga exactamente sus hojas y que la suma con `fonts.css` no pasa de 45.000 bytes. Al tocar CSS: el color nuevo va en `:root` de `panel.css`; una regla va a la hoja de la única pantalla que la usa y a `panel.css` si la usan varias. JS sin librerías, con nonce: `panel.js` (todas) + `kanban.js`, `tareas.js` o `config.js`.

### Pruebas

`php captacion/tests/run.php`: nuevos `12_kanban`, `13_tareas`, `14_config`, `15_actividad`, `16_backup` y `17_pulido` (kanban: transiciones válidas e inválidas y paginación; tareas: filtros, posponer y vencidas; configuración: validación de pesos, recálculo en lotes, alertas tibias en la cola, costos y CPL, integraciones contra servidores simulados; actividad: filtros y CSV; copias: §16; autorización por rol en cada endpoint).

---

## 18. Entrega a NorthStar, lector, reportes, informes y correo (Q3)

Migraciones nuevas (idempotentes; probadas desde cero y sobre la base de Q2b con datos): **`006_handoff`** (`handoffs`, `handoff_deliveries`), **`007_viewer_reports`** (`user_invites`, `reports`, `users.invited_by/deactivated_at`, índice `leads(deleted_at, signed_at)`) y **`008_comms`** (`email_templates`, columnas de `sequence_enrollments` y `messages`, inscripción única por lead y secuencia). Variables nuevas del `.env`: **ninguna** (todo lo nuevo se configura en el panel; el SMTP sigue siendo `SMTP_*`). Cron nuevo: ver abajo.

### 18.1 Entrega a NorthStar (A9, doc §11)

`Services\Handoff` + interfaz `Services\Handoff\HandoffTarget` con tres destinos: **correo** (`EmailTarget`), **webhook firmado** (`WebhookTarget`) y **`DispatchCrmTarget`** (esqueleto documentado, siempre inactivo). Se dispara **dentro de `LeadActions::setStage`** al pasar a «Presentado a NorthStar» (tablero, ficha o masivo) y con «Reenviar entrega». Es **idempotente**: una entrega abierta del mismo intento no se duplica; «Reenviar» crea la versión siguiente y deja sin efecto (`superseded`) los enlaces de la anterior. Cada destino es un job `handoff_deliver` (reintentos 1/5/15 min; tras el cuarto fallo la entrega queda `failed`).

- **Sin destino configurado** (`Configuración > Entrega a NorthStar`) no se envía nada: la entrega queda `no_target`, el lead conserva `handoff_status = pending`, la ficha y la campana dicen «Destino de entrega sin configurar» y, al guardar un destino, las entregas que esperaban se ponen en cola.
- **Ficha** (`HandoffCard`): contacto, perfil, equipo, estado, puntuación y desglose, FMCSA con banderas rojas, notas y llamadas, preferencias, consentimientos (canal y fecha) y atribución; correo HTML y texto. Nunca documentos de conductores.
- **Webhook** (pensado para n8n → Google Sheet): `POST` JSON `northstar.handoff.v1`; cabeceras `X-NS-Timestamp`, `X-NS-Signature: sha256=` + HMAC-SHA256 de `timestamp.cuerpo` con el secreto, `X-NS-Delivery` e `Idempotency-Key`; timeout 5 s, sin redirecciones; HTTPS obligatorio (http solo con `APP_ENV=local`), sin credenciales en la URL ni direcciones privadas. El secreto se guarda **cifrado** (`APP_KEY`), se muestra una sola vez al generarlo y nunca aparece en pantalla, logs ni `activity_log`. La documentación para quien recibe está en la propia pantalla.
- **Confirmación sin login** `/c/{token}`: dos enlaces en el correo (**Firmó** / **Lo rechazamos**), token HMAC con `APP_KEY` (sin punto: `php -S` trata `.xxx` como archivo), 30 días, **un solo uso en conjunto**, sin datos personales en la URL. El GET solo muestra el nombre del lead (los escáneres de correo abren enlaces); el cambio ocurre con el POST. Firmó: etapa Firmado/Contratado, `signed_at`, `handoff_status = signed`, evento `conversion`, A12 a los 30 días y aviso por Telegram. Rechazado: pide un motivo de la lista del doc §7 (y nota con «Otro»); el lead pasa a Perdido/Descartado con ese motivo (`handoff_status = rejected`, vuelve a la bandeja y se reabre con «Reabrir»), se crea una tarea «Revisar el rechazo de NorthStar» y se avisa por Telegram. Límite de 60 peticiones/hora por IP, `noindex`, CSP estricta, sin cookies. Token manipulado: 404; usado, reemplazado, caducado o lead ya no presentado: 410.
- **Manual**: botones «Marcar firmó», «Marcar rechazado» y «Reenviar entrega» en la ficha (cambiar la etapa a Firmado con el selector hace lo mismo). Prueba de entrega con un lead ficticio («PRUEBA», sin enlaces de confirmación) desde la configuración.

### 18.2 Lector de NorthStar (rol `viewer`, doc §10)

Alta por invitación en `Configuración > Usuarios` (con confirmación reciente de identidad): se crea la cuenta sin contraseña ni 2FA utilizables y un enlace `/invite/{token}` de **un solo uso y 48 h** (solo se guarda su SHA-256; se muestra una vez) donde la persona fija su contraseña (≥ 12) y activa el 2FA TOTP con 10 códigos de recuperación, como el administrador. Se puede desactivar/activar, emitir un enlace nuevo o **reiniciar el 2FA** (borra contraseña, secreto y códigos; sus sesiones dejan de valer en la siguiente petición); todo queda en `activity_log` junto con el último acceso. **Autorización denegada por defecto**: `Responder::user()` rechaza (403) a un `viewer` salvo que el controlador declare `user(true)`, algo que solo hacen `/resultados` e `/informes`; los endpoints JSON responden `403 forbidden`; el shell del lector no trae buscador, campana, atajos ni administración. La prueba `19_viewer` pide ~40 rutas por GET y ~35 por POST y comprueba que ninguna respuesta lleva nombres, teléfonos, correos, USDOT ni ids de lead y que no cambia nada en la base.

### 18.3 Reportes (`/reportes`, admin) y `/resultados` (lector)

Definiciones (también en pantalla, código en `Services\Reports`): cohorte = leads **recibidos** en el rango (hora ET); el embudo cuenta cuántos de ellos **llegaron** a cada etapa (etapa actual o historial `stage_changed`; los company drivers no tienen «Llamada agendada» y cuentan ahí si llegaron más lejos); «firmados» es de esa cohorte y «firmados en el periodo» cuenta por fecha de firma; primera respuesta = mediana y p90; tiempo hasta la firma = mediana en días. **Costos**: son mensuales, así que CPL y costo por firmado usan solo los **meses completos** contenidos en el rango y los leads/firmados de esos meses; sin costos o sin mes completo se dice «Sin costos cargados» (no se prorratea ni se inventa). Presets (7/30/90 días, mes, mes pasado, año) y rango libre (≤ 400 días) con comparación con el periodo anterior; tablas por canal, página de llegada, perfil, equipo y estado, motivos de pérdida y tendencia; **CSV de cada tabla** con protección contra fórmulas y registro `report_export`. Medido: un mes de 30.000 leads se calcula en ~1 s y los `EXPLAIN` usan `idx_leads_created`, `idx_leads_signed` e `idx_events_lead`. El lector ve solo la proyección `Reports::forViewer` (totales, embudo, costo por firmado por canal, tendencia y motivos de pérdida), sin exportaciones.

### 18.4 Informes automáticos (A13, A14) y cron

`cron/reports_cron.php` (cada hora; decide por la hora de **Nueva York** con cambio de hora): **lunes 8:00 a. m. ET** (con recuperación hasta el martes) resumen semanal por correo a Carlos de la semana anterior (leads, calientes sin contactar, tareas vencidas, firmados, mejor y peor canal y 3 acciones sugeridas por reglas simples); **días 1 a 5 de cada mes** (desde la 1:00 a. m.) genera el **informe mensual** del mes anterior como borrador (instantánea JSON en `reports`, identificador `INF-AAAA-MM`, comparación con el mes anterior y tres acciones) y avisa a Carlos. En `/informes/{id}` Carlos edita las acciones y pulsa **Publicar**: solo entonces lo ve el lector y se envía el enlace a los destinatarios de `Configuración > Correo`. «Generar ahora» crea el de un mes pasado; imprimible/PDF desde el navegador (hoja `@media print` en `reportes.css`). Idempotente por `(kind, periodo)` y marcas `notified_at` / `sent_at`.

```
10 * * * * /opt/alt/php83/usr/bin/php /home/u480515229/domains/northstarfreightsolutions.us/public_html/captacion/cron/reports_cron.php >/dev/null 2>&1
```
(`--now=AAAA-MM-DDTHH:MM`, hora ET, solo para ensayar.) Además `cron/process_jobs.php` ahora encola los pasos de secuencias vencidos antes de procesar la cola.

### 18.5 Plantillas y secuencias (A6, A7, A12)

`Comunicaciones` (admin): plantillas EN/ES (CRUD, vista previa, prueba), inscripciones (pausar, reanudar, sacar) y registro de envíos. `Services\Mailer::deliver()` (PHPMailer por SMTP si hay `SMTP_*`; sin SMTP todo queda «registrado» en `storage/mail.log` y la interfaz lo dice). Variables `{nombre} {equipo} {estado} {link_baja}` (+ `{link_calculadora}`, `{whatsapp}`). **Reglas** (comprobadas al enviar, job `sequence_step`): secuencias **encendidas** y **dirección física** configuradas (`Configuración > Correo`; sin ella no se encola nada), consentimiento de correo vigente, plantilla activa y **sin marcadores `[[CONFIRMAR: …]]` ni variables sin resolver** (el paso espera 1 h y avisa), topes por hora y por lead cada 24 h, pie con dirección y baja, cabeceras `List-Unsubscribe` y `List-Unsubscribe-Post: List-Unsubscribe=One-Click`. **A6** (tibios): día 1 (caso real: lleva marcador hasta que haya un caso aprobado), día 3 (calculadora de RPM del sitio), día 7 (tarifas de la semana: **se omite** mientras no haya fuente real, queda en la línea de tiempo) y día 14 (último intento). **A7**: se pausa sola al pasar el lead a cualquier etapa distinta de Nuevo o con «El lead respondió»; se reanuda a mano. **A12**: pedido de referidos a los 30 días de firmar, sin bonos ni códigos. **Baja `/u/{token}`**: el GET solo muestra una página de confirmación (EN/ES según el lead) con un botón «Confirmar baja»; **solo el POST aplica la baja** (el botón o el `List-Unsubscribe=One-Click` del cliente de correo, sin CSRF: el token firmado es la credencial), así los escáneres que precargan enlaces no dan bajas falsas. Idempotente: revoca el consentimiento de correo en `consents`, detiene todas las secuencias y se registra.

### 18.6 Pruebas

`php captacion/tests/run.php`: nuevos `18_handoff` (destinos, reintentos, idempotencia, tokens válido/caducado/reutilizado/manipulado, rechazo, botones de la ficha), `19_viewer` (invitaciones y prueba negativa de fugas), `20_reports` (embudo, tiempos, costos y pérdidas contra datos conocidos, consistencia con la base, EXPLAIN, exportación, informe mensual, crons semanal y mensual con zona horaria) y `21_comms` (plantillas, marcadores, secuencias, topes, A7, baja, A12), con un **SMTP simulado** (`tests/lib/smtp_mock.php`) y un webhook simulado; `01_migrations` prueba además la migración sobre una base de Q2b con datos. No cubierto: SMTP, Telegram y n8n reales, MySQL 8, `.htaccess`.

---

## 19. Prospectos FMCSA, referidos y vacantes (Q4a)

Migración nueva: **`009_prospects_referrals_jobs.sql`** (idempotente; probada desde cero y sobre la base de Q2b con filas previas): completa `prospects_fmcsa`, crea `prospect_calls` (historial de intentos) y `dnc_numbers` (lista interna de no llamar), amplía `referral_codes`, `referrals` (único por lead) y `job_postings`, y actualiza la plantilla A12 de fábrica si nadie la editó. Rutas nuevas, **solo administradores** (el lector recibe 403 sin datos; sin sesión, `/login`): `/prospectos`, `/referidos`, `/vacantes`, y la pestaña `Configuración > Prospectos`. Sidebar «Captación saliente».

### 19.1 Fuente de datos de FMCSA (probada contra la API real el 2026-11-05)

Las APIs de `data.transportation.gov` (Socrata/SODA) son públicas y de solo lectura, sin llave. Qué existe HOY (los datasets viejos ya no se actualizan: «Carrier - All With History» `6eyk-hxee` y «AuthHist» `9mw4-x3tu` dicen *last refreshed 05/14/2026*; FMCSA migró a **Motus**):

| Uso | Dataset | Campos que usamos |
|---|---|---|
| Autoridades concedidas | `yu5v-wbh6` «Motus AuthHist - All With History» | `docket_number` (MC), `usdot_number`, `op_auth_type` = *Motor Carrier of Property (Except Household Goods)*, `op_auth_status` = *Active*, `reason` = *Granted* (en mayúsculas, `GRANTED`, las anteriores al 2026-05-28), `status_change_date` (AAAAMMDD = **fecha de concesión**) |
| Datos del carrier | `az4n-8mr2` «Company Census File» | `dot_number`, `legal_name`, `dba_name`, `phone`, `email_address`, `phy_city`, `phy_state`, `phy_country`, `add_date`, `status_code`, `carrier_operation`, `power_units`, `truck_units`, `total_drivers`, `classdef`, `hm_ind` y las banderas de carga `crgo_*` |

Medido: ~29.000 concesiones en 12 meses (hoy ~200 por día hábil); en una muestra de 200, el 100 % trae teléfono y correo, 96 % es de EE. UU., 64 % tiene 1 unidad. **Limitaciones reales:** no hay tipo de equipo (dry van, reefer…; solo la carga que el carrier *declara*), no hay nombre de contacto (la razón social es lo único), el correo del censo es el del registro (aquí **no se usa para escribir**: solo se muestra), algunos datos son erróneos (p. ej. 1002 conductores con 1 unidad) y una concesión puede ser de un USDOT viejo reactivado. No se verifica que la autoridad siga activa hoy (una suspensión posterior no se ve hasta la próxima consulta a QCMobile al convertir). Territorios, Canadá y México se descartan.
**Cambiar de dataset:** constantes `GRANTS_DATASET` y `CENSUS_DATASET` y los campos de `grantsParams()`, `censusParams()` y `buildRow()` en `Services/ProspectImport.php`; `SOCRATA_API_BASE` cambia de portal. `SOCRATA_APP_TOKEN` (opcional, gratis en data.transportation.gov) va en la cabecera `X-App-Token` y sube el límite compartido; sin él una corrida diaria (2 a 6 consultas) sobra. Los reintentos (429 y 5xx) esperan 2 y 4 s; un 4xx (columna o dataset inexistente) es un error permanente con el mensaje de Socrata.

### 19.2 Corrida diaria (`cron/fmcsa_daily.php`)

```
20 * * * * /opt/alt/php83/usr/bin/php /home/u480515229/domains/northstarfreightsolutions.us/public_html/captacion/cron/fmcsa_daily.php >/dev/null 2>&1
```
**Cada hora**; decide por la hora de Nueva York (con cambio de hora): una corrida al día desde las 6:00 a. m. ET; si ese día no corrió (cron caído, servidor apagado), la siguiente hora la recupera. Idempotente (USDOT único), `flock` (`storage/locks/fmcsa_daily.lock`), páginas de 200 con cursor `(fecha, docket)` y presupuesto de **40 s** (`FMCSA_MAX_SECONDS`) y 60 % de `memory_limit`: si no termina, la siguiente hora continúa desde el cursor (la tarjeta dice «a medias»). Una corrida completa sin tope ni errores deja `prospects.complete_until`; la siguiente solo mira desde 3 días antes. Una corrida con **tope diario** no avanza esa marca (entran primero los más recientes; lo demás se ve otro día). Cambiar un filtro reinicia la marca. Prueba por SSH: `php cron/fmcsa_daily.php --force` (ignora la hora y «ya corrió hoy»; no salta filtros ni tope) y `--now=AAAA-MM-DDTHH:MM` (hora ET simulada). Exit 1 si Socrata falla (se reintenta a la hora siguiente).
**Filtros** (`Configuración > Prospectos`, `ProspectConfig`): antigüedad máxima (1 a 24 meses, 12), estados, flota máxima (10; sin dato no descarta), solo con teléfono (sí), categorías de carga (ninguna), tope diario (40) y encendido. **Deduplicación y descartes contados** (tarjeta «Última corrida» y `activity_log` `prospects_run`): ya en la lista, ya es lead (USDOT o teléfono), teléfono en no llamar, sin ficha en el censo, USDOT inactivo, fuera de EE. UU., estado, flota, sin teléfono, teléfono repetido, carga, tope. La tarjeta de estado de **Integraciones** muestra el cron (rojo/ámbar si lleva más de 3 h sin correr) y Socrata (con botón «Probar»); la campana avisa del cron sin correr o con error.

### 19.3 `/prospectos` (llamadas en frío, a mano)

«Lista de hoy» = por llamar + reintentos que ya tocan, ordenada por autoridad más reciente y dato más completo (los reintentos vencidos primero); pestañas con contadores (Por llamar, Sin respuesta, Conversó, Interesado, No interesado, No llamar más), búsqueda (razón social, USDOT, MC, teléfono, ciudad), filtros (estado, flota, antigüedad), CSV (`prospects_export`, UTF-8 con BOM y protección contra fórmulas). Cada tarjeta: botón **Llamar** (`tel:`), hora local del prospecto, datos de FMCSA, **resultado con un clic** (sin respuesta, conversó, interesado, no interesado, no llamar más; nota y siguiente intento opcionales) e historial. **Máximo 3 intentos sin respuesta seguidos**: el 3.º cierra («Sin respuesta (cerrada)», reabrible a mano; cada reintento se reprograma al siguiente día hábil, 9:00 a. m. ET). **Convertir en lead**: fuente `fmcsa_outbound`, perfil owner-operator (1 unidad o sin dato) o carrier pequeño (2 o más), datos de FMCSA ya cargados (sin `fmcsa_pending`), etapa «Contactado» con `first_contact_at` ahora, **sin consentimiento de mensajería y sin alertas, correos ni secuencias**; si ya hay un lead con ese teléfono o correo se enlaza a él sin tocarlo. **«No llamar más»** es permanente: guarda el teléfono en `dnc_numbers`, no se reabre ni convierte, y ni FMCSA (mismo USDOT) ni otro USDOT con el mismo teléfono lo vuelven a listar. Se puede quitar solo a mano en la base (`DELETE FROM dnc_numbers`).
**Horario (doc §16):** el botón Llamar solo está activo de **8 a. m. a 9 p. m. hora local** (`Services/UsTimeZones`). Solo se conoce el estado, así que en los estados con dos zonas (AK, AZ, FL, ID, IN, KS, KY, MI, NE, NV, ND, OR, SD, TN, TX) se exige que sea hora válida en **todas** sus zonas (p. ej. Florida: 9 a. m. a 9 p. m. ET). Fuera de horario la tarjeta dice la hora de allá y cuándo abre, y deja «Ver teléfono». El servidor no marca nada: las llamadas son siempre manuales.
> **Carlos: valida con tu abogado el cumplimiento de las llamadas en frío (TCPA, reglas de horario y listas de no llamar estatales, incluida la nacional DNC) antes de operarlas.** Esta pantalla ayuda (horario, lista interna, nada automático) pero no sustituye esa revisión.

### 19.4 Referidos (`/referidos`)

Alta de códigos (nombre, lead asociado opcional, código elegido o generado `NOMBRE-XXXX`, bono, nota), enlace compartible `SITE_URL/careers/?ref=CODIGO` con botón Copiar, activar/desactivar, lista de leads referidos con estado (referido → calificado → firmado) y bono (sin bono → **pendiente de aprobar** → aprobado → pagado, o cancelado) con aprobar (exige monto > 0), pagar, cancelar y reabrir; todo en `activity_log`. El monto es **provisional, pendiente de confirmar con NorthStar**, hasta pulsar «NorthStar ya confirmó» (`referrals.bonus_confirmed`). **Captura en el sitio** (sin cambios visuales ni de textos): `assets/js/main.js` guarda `?ref=` validado (`[A-Za-z0-9_-]{3,40}`) en `localStorage` **60 días** (clave `ns_ref`, sin cookie) y lo manda dentro del campo oculto de atribución; `app/forms/lead.php` lo vuelve a validar y lo guarda en el JSONL (`attribution.ref`); `LeadIngest` lo normaliza a mayúsculas, **ignora sin error** un código inexistente o desactivado, suma los puntos de referido (+5 owner, +10 driver), crea la fila de `referrals` y el evento «Llegó por un código de referido». Un lead que ya existía y vuelve con código lo recibe si no tenía. Un lead no se refiere a sí mismo. Al **firmar** (por la vía que sea, `Handoff::afterSigned`) el referido pasa a «Firmado» y el bono a «pendiente de aprobar», con aviso por Telegram (job `referral_notify`, solo si Telegram está configurado), insignia en la barra lateral y aviso en la campana. **A12:** la plantilla de fábrica usa `{link_referido}` (crea el código del lead firmado al enviarse; sin cifras de bono).

### 19.5 Vacantes (`/vacantes`) y el sitio

Alta, edición, publicar, pausar, cerrar, reabrir y borrar borradores, con validación estricta (`Services/JobPostings`): sin HTML (`<`, `>`, `&lt;`), fechas reales, `valid_through` **futura** para publicar y ≤ 1 año, slug único, texto sin cifras inventadas y **pago solo si se marca «Publicar el pago»** con cifras y unidad. Publicar exige resumen, ciudad y estado y al menos un requisito o responsabilidad. Cada cambio reescribe de forma atómica (`.tmp` + `rename`, 0640) **`storage/jobs.json` del sitio** (`SITE_STORAGE_DIR` o, por defecto, `<repo>/storage`: en Hostinger `public_html/storage`, fuera de la web) dentro de la misma transacción: si no se puede escribir, el cambio se revierte y el panel lo explica. Esquema `{"schema":1,"generated_at":…,"jobs":{slug:{status,title,posted,valid_through,employment_type,location,summary,equipment,responsibilities,requirements,benefits,pay}}}`; solo publicadas y en pausa, sin vencer. `app/data/jobs.php` del sitio lo lee, **valida y sanea cada campo** (sin `<` ni `>`, largos máximos, tipos, fechas, máximo 50 y 256 KB) e **ignora lo inválido**; con JSON ausente, vacío, corrupto o malicioso devuelve el arreglo vacío y nunca falla (probado). Comprobado de punta a punta con el sitio real: publicar crea `/careers/cdl-a-drivers/{slug}/` con `JobPosting`, el hub, la opción «company driver» del formulario, el sitemap y `llms`; pausar, cerrar o vencer dan 404 y salen del sitemap. **Retraso por caché:** LiteSpeed cachea el HTML 5 minutos en producción: un cambio puede tardar hasta 5 min en verse (la pantalla lo avisa). Con 0 vacantes el sitio queda idéntico a antes.

### 19.6 Variables nuevas del `.env`

`SOCRATA_APP_TOKEN` (opcional) · `SITE_URL` (por defecto `https://northstarfreightsolutions.us`, para los enlaces de referido) · `SITE_STORAGE_DIR` (por defecto `<repo>/storage`) · `FMCSA_MAX_SECONDS` (40) · `FMCSA_PAGE_SIZE` (200) · `SOCRATA_API_BASE` (solo pruebas o cambio de portal).

### 19.7 Pruebas

`php captacion/tests/run.php`: nuevos `22_prospects` (horario por zona con cambio de hora y estados de dos zonas, filtros, cliente Socrata con token/reintentos/errores, corrida con **fixtures reales** `tests/fixtures/socrata/`, dedupe contra leads y no llamar, tope diario, cursor y reanudación, compuerta por hora ET, resultados, intentos, no llamar más, conversión), `23_prospects_ui` (pantalla, HTTP con CSRF, CSV, campana, configuración, autorización por rol en cada ruta nueva y el cron por CLI contra un Socrata simulado, con `flock`) y `24_referrals_jobs` (referidos con el sitio real y vacantes de punta a punta, y `jobs.php` con JSON vacío, corrupto y malicioso). `01_migrations` prueba la 009 sobre una base con filas previas. No cubierto: Socrata fuera de la prueba manual del 2026-11-05, el `tel:` en un celular real, LiteSpeed y su caché.

---

## 20. Canales externos, puente con n8n y reactivación (Q4b)

Migración nueva: **`010_channels.sql`** (idempotente; probada desde cero y sobre la base de Q2b con datos): tabla `webhook_events` (idempotencia: `UNIQUE(source, event_key)`), columnas de citas de Cal.com en `appointments` (`calcom_id` único, `prev_calcom_id`, `last_event_at`, `tz`, `meeting_url`, recordatorios por WhatsApp), `tasks.appointment_id`, `leads.last_inbound_at` y `wa_last_inbound_at` (ventana de 24 h), `sequence_enrollments.wa_step` y un índice en `messages.provider_id`. **Sin crons nuevos**: todo corre en `process_jobs.php` (cada minuto). Los textos operativos de las cuentas están en `captacion/docs/` (`setup-guide.md`, `compliance.md`, `whatsapp-templates.md`, `bot-whatsapp-spec.md`, `job-boards.md`, `n8n/*.json`).

### 20.1 Reglas comunes de toda entrada pública

Cada integración se activa **solo si sus variables existen en el `.env`**; si no, el endpoint responde `503 {"error":"not_configured"}` con el nombre de lo que falta y el panel la muestra «Sin configurar». Orden fijo: método (405) → configurada (503) → límite por IP (`WEBHOOK_RATE_LIMIT`, 1200/h; 429) → tamaño (`WEBHOOK_MAX_BODY`, 128 KB; 413) → **firma en tiempo constante antes de interpretar nada** (401; `WEBHOOK_SIG_FAILS`, 15 firmas malas/15 min por IP bloquean con 429) → idempotencia (`webhook_events`; el mismo evento dos veces responde 200 y no se repite) → trabajo (lo pesado, a la cola). Cada evento se procesa en **una transacción** junto con su registro: si algo falla, no queda marcado como recibido y el emisor reintenta. No se registran secretos ni cuerpos de mensajes (`webhook_events.summary` es una frase corta; `Storage::redact` quita también `access_token=` y los secretos nuevos). La campana avisa si un webhook configurado lleva 3 errores seguidos o si la firma se rechaza 5 veces en una hora (posible clave mal puesta).

### 20.2 Webhooks (`public/api/webhooks/`)

| Archivo | Variables | Qué hace |
|---|---|---|
| `meta.php` | `META_VERIFY_TOKEN`, `META_APP_SECRET`, `META_PAGE_TOKEN` (+ `META_GRAPH_VERSION`, `META_GRAPH_BASE` solo pruebas) | GET: verificación (`hub.mode=subscribe`, token en tiempo constante, responde el `hub.challenge` en texto plano). POST: `X-Hub-Signature-256`; por cada `leadgen_id` registra el evento y encola `meta_fetch_lead`, que consulta Graph API, traduce los campos con el **mapeo** (Configuración > Canales externos) y llama a `LeadIngest::handle(…, 'meta_ads')` (antispam, dedupe, FMCSA, puntuación, alertas). UTM: campaña, anuncio y formulario. **Sin consentimiento de mensajería salvo que el formulario lo traiga** (texto exacto + canales + pregunta mapeada). Errores de Graph: reintento 1/5/15 min; lead inexistente (404): fallo definitivo; lead inutilizable (sin nombre ni contacto): `ignored`. Sin `META_PAGE_TOKEN` el POST da 503 y Meta reintenta |
| `calcom.php` | `CALCOM_WEBHOOK_SECRET` | `X-Cal-Signature-256` (HMAC hex). Reserva creada/reprogramada/cancelada: enlaza o crea el lead (fuente `calcom`), crea o mueve la cita y la tarea «Llamada con X», pasa a «Llamada agendada» (pipeline A, mismas reglas de etapa; nunca mueve un lead presentado, firmado o perdido), programa recordatorios por **correo** 24 h y 1 h antes (consentimiento de correo y dirección física obligatorios; si no, se omiten y se anota). Cancelar: cita `cancelled`, tarea borrada, recordatorios cancelados y vuelve a «Calificado» si no queda otra cita. **Eventos desordenados:** cada cita guarda `last_event_at`; un evento más viejo es «obsoleto»; una reprogramación conserva una sola fila (`prev_calcom_id`) |
| `sms.php` | `TWILIO_TOKEN` (+ `TWILIO_WEBHOOK_URL`) | `X-Twilio-Signature` (HMAC-SHA1 de URL + parámetros ordenados; se prueban las variantes con y sin puerto). STOP/STOPALL/UNSUBSCRIBE/CANCEL/END/QUIT revocan **SMS y WhatsApp** (`Consent::revokeChannels`); START/YES/UNSTOP restablecen **solo SMS** y solo con consentimiento previo; HELP responde TwiML con el texto configurable; cualquier otra cosa: guarda, **pausa las secuencias** (A7), anota y avisa a Carlos por Telegram. `MessageStatus` actualiza `messages.status`. Responde TwiML válido |
| `n8n.php` | `N8N_WEBHOOK_SECRET` | `X-NS-Timestamp` (±5 min) + `X-NS-Signature: sha256=` HMAC de `timestamp.cuerpo`. JSON con `event_id` (8 a 80 caracteres, idempotente), `event` y `lead_id`: `message_sent`, `message_failed`, `sequence_step_sent`, `reminder_sent`, `reply_received`, `bot_finished`, `error` |

### 20.3 API interna para n8n (`public/internal/`, Bearer `INTERNAL_API_TOKEN`)

Mismas protecciones que `/api/lead.php` (10 fallos de autenticación/15 min, `INTERNAL_RATE_LIMIT` 1500/h, sin CORS, 503 sin token) y cuerpos de hasta 16 KB. **Permisos acotados:** solo estas rutas, solo estos campos y solo los datos personales que hacen falta para enviar (el teléfono solo si el canal tiene consentimiento vigente).

```bash
H='Authorization: Bearer '$INTERNAL_API_TOKEN
curl -sS -H "$H" "$B/internal/sequences/due?channel=whatsapp&limit=20"
# {"ok":true,"channel":"whatsapp","items":[{"lead_id":12,"sequence":"a1_welcome","step":0,"template":"a1_welcome","to":"+13055550100","language":"es","basis":"opt_in","free_text_allowed":false,"vars":{"nombre":"Mike","equipo":"Reefer","estado":"FL"},"due_at":"…"}]}
curl -sS -H "$H" "$B/internal/reminders/due"                      # recordatorios de cita por WhatsApp (24 h y 1 h)
curl -sS -H "$H" "$B/internal/leads/by-phone?phone=%2B13055550100"   # {"ok":true,"found":true,"lead_id":12,"language":"es"}
curl -sS -X PATCH -H "$H" -H 'Content-Type: application/json' "$B/internal/leads/12" -d '{"state":"TX","truck_count":3,"equipment_types":["reefer"]}'
# {"ok":true,"updated":["state","truck_count","equipment_types"],"rejected":{},"score":72,"temperature":"hot"}
curl -sS -X POST -H "$H" -H 'Content-Type: application/json' "$B/internal/leads/12/consent-check" -d '{"channel":"whatsapp"}'
# {"ok":true,"allowed":true,"channel":"whatsapp","basis":"inbound_window","reason":null,"free_text_allowed":true,"window_expires_at":"2026-11-06 18:00:00"}
curl -sS -X POST -H "$H" -H 'Content-Type: application/json' "$B/internal/leads/12/events" -d '{"event":"message_sent","channel":"whatsapp","template":"a1_welcome","message_id":"wamid.X","event_id":"a1-12"}'
curl -sS -X POST -H "$H" -H 'Content-Type: application/json' "$B/internal/leads/12/handoff-ready" -d '{"summary":"Quiere hablar hoy"}'
```

- **`PATCH`**: lista blanca `phone`, `email` (solo si el lead no los tiene), `language`, `state`, `city`, `equipment_types`, `truck_count`, `years_experience`, `usdot`, `english_level`, `cdl_type`, `authority_status`, `best_time`, `contact_pref`. Cualquier otro campo → 422 y no se aplica nada. Valida con el mismo normalizador del motor, rechaza campo a campo (`invalid`, `already_set`, `phone_in_use`), recalcula la puntuación y deja el evento `lead_updated` (nombres de campo, sin valores). Un USDOT nuevo deja `fmcsa_pending` y encola la consulta.
- **`sequences/due`**: A1 (`a1_welcome`, leads de los últimos 3 días no descartados), A4 (`a4_booking`, en «Calificado» con enlace de Cal.com configurado), A6 (`a6_d1/d3/d7/d14`) y A12. Solo los pasos que Carlos habilita en Configuración > Canales externos > n8n y solo con consentimiento vigente en ese canal (`consent-check`). `channel=sms` queda vacío salvo permiso explícito (A2P pendiente); `channel=email` da 400 (el correo lo envía el CRM). El avance por WhatsApp (`wa_step`) es independiente del paso de correo.
- **`consent-check`**: `email`/`sms`/`call` = consentimiento vigente sin STOP. `whatsapp` = `opt_in` (consentimiento de canal vigente) o `inbound_window` (el lead escribió primero hace menos de 24 h, solo para responder).
- **`events`** exige `event_id` o cabecera `Idempotency-Key`. **`handoff-ready`** crea la tarea «Llamar a X», el evento y el aviso por Telegram; no cambia la etapa.

### 20.4 WhatsApp entrante en `/api/lead.php`

El lead que escribe primero se crea con `"inbound": {"channel":"whatsapp","text":"…","at":"ISO 8601","message_id":"wamid…"}` (también `sms`). Registra el mensaje, abre la ventana de 24 h y guarda `consents` con canal **`whatsapp_inbound`** (texto y hora del primer mensaje, versión `wa-inbound-1`). **No da consentimiento de marketing** (`isGranted('whatsapp')` sigue en falso). Un segundo mensaje de un lead existente se guarda sin nuevo consentimiento y cuenta como respuesta (A7). Idempotente por `message_id`; la respuesta trae `inbound_recorded`.

### 20.5 Panel

`Configuración > Integraciones`: tarjetas de **Meta, Cal.com, Twilio y n8n** con estado (configurado o no y qué variables faltan), eventos de 7 días, último evento correcto o error, URL del webhook lista para copiar y **«Probar»**: construye un evento de prueba, lo firma con el secreto del `.env` y lo envía por HTTP al **propio endpoint** (`SELF_BASE_URL` o `APP_URL`), sin servicios externos y sin crear datos (Meta: verificación + `leadgen_id` `nstest_…`; Cal.com: `PING`; Twilio: `NSTEST-PING`; n8n: `ping`). `Configuración > Canales externos` (admin, con confirmación reciente): mapeo de campos de Meta, perfil por defecto, texto y canales del consentimiento de Meta, enlace de reserva de Cal.com, texto de HELP y pasos de WhatsApp que n8n puede enviar. `/comunicaciones/reactivacion`: interruptor, elegibles con cada motivo de exclusión y estado de las plantillas.

### 20.6 Reactivación A11 (por correo)

Secuencia `a11_reactivation` sobre el motor de `Sequences` (2 mensajes EN/ES: día 0 y día 10). **Interruptor propio, apagado por defecto** (`settings['reactivation']`), además de las secuencias de correo y la dirección física. Elegibles: perdidos hace ≥ 60 días (30 a 365), pipeline A, con correo y consentimiento de correo vigente. Excluidos: «No llamar más» (teléfono en `dnc_numbers`; los leads no tienen ese motivo de pérdida), STOP/baja, descartados, firmados, rechazados por NorthStar, ya inscritos (una vez por lead). Los textos llevan `[[CONFIRMAR]]`: **no se envía nada hasta que Carlos los edite**. Se pausa sola si el lead se reabre, responde o cambia de etapa, y se detiene sin consentimiento. `process_jobs.php` inscribe en lotes de 25 como máximo una vez por hora. Los recordatorios de cita por correo usan las plantillas `appt_reminder_24h/1h` (variables `{cita_fecha}`, `{cita_enlace}`), sin consumir los topes de las secuencias.

### 20.7 Formatos: qué viene de documentación y qué es supuesto

Ningún formato se probó contra el servicio real; todo se probó con fixtures y servidores simulados.

| Formato | Origen |
|---|---|
| Twilio: `application/x-www-form-urlencoded`, `MessageSid`, `From`, `Body`, `OptOutType`, `MessageStatus`; firma HMAC-SHA1 de URL + parámetros ordenados; TwiML | Documentación de Twilio **de memoria**. Palabras clave STOP/START/HELP: lista estándar de Twilio |
| Meta: verificación `hub.mode/verify_token/challenge`, firma `X-Hub-Signature-256` (HMAC-SHA256 con la clave de la app), estructura `entry[].changes[].value.leadgen_id` | Documentación de Meta **de memoria**; ajustar `MetaLeads::parseWebhook` |
| Graph API: `GET /{leadgen_id}?fields=…&access_token=…` con `field_data[{name,values}]`, `ad_name`, `campaign_name`, `platform`, `custom_disclaimer_responses` | **Supuesto** (nombres de campos y de la casilla del aviso personalizado por confirmar con un lead real). Versión de Graph: `META_GRAPH_VERSION` (vacío = sin versión) |
| Cal.com: `X-Cal-Signature-256` (hex, sin prefijo), `triggerEvent`, `payload.uid/startTime/endTime/attendees/metadata.videoCallUrl/rescheduleUid` | **Supuesto** razonable; el teléfono puede venir en varios sitios (`Appointments::phoneOf`) |
| n8n → CRM (`X-NS-*`, `event_id`, eventos) y todo `/internal/*` | Diseño propio de este proyecto (misma firma que el webhook de entrega de Q3) |
| Flujos de n8n (`docs/n8n/*.json`): nodos y parámetros | **No probados en una instancia real de n8n**; marcadores `REEMPLAZAR_*` en credenciales |

### 20.8 Variables nuevas del `.env`

`META_VERIFY_TOKEN` · `META_APP_SECRET` · `META_PAGE_TOKEN` · `META_GRAPH_VERSION` (opcional) · `CALCOM_WEBHOOK_SECRET` · `TWILIO_TOKEN` · `TWILIO_SID`/`TWILIO_FROM` (reservadas) · `TWILIO_WEBHOOK_URL` (opcional) · `N8N_WEBHOOK_SECRET` · `WEBHOOK_RATE_LIMIT` (1200) · `WEBHOOK_MAX_BODY` (131072) · `WEBHOOK_SIG_FAILS` (15) · `INTERNAL_RATE_LIMIT` (1500) · `SELF_BASE_URL` (solo pruebas) · `META_GRAPH_BASE` (solo pruebas). Detalle en `config/.env.example`.

### 20.9 Pruebas

`php captacion/tests/run.php`: `25_meta_calcom` (firma válida/inválida/ausente/manipulada, repetición, cuerpo enorme, límites, e2e Meta → lead puntuado y alertado, Cal.com → cita, etapa, tarea y recordatorios con reloj simulado, eventos fuera de orden), `26_sms_n8n` (Twilio y n8n.php, STOP/START/HELP, respuesta → pausa y aviso, WhatsApp entrante), `27_internal_api` (autorización, lista blanca, consentimiento, pasos y recordatorios, idempotencia, campana y «Probar»), `28_reactivation` (reloj simulado) y `29_docs_pack` (JSON de n8n válidos y sin secretos). `19_viewer` comprueba que el lector no accede a nada de esto. **No cubierto:** Meta, Cal.com, Twilio, WhatsApp y n8n reales, MySQL 8, `.htaccess` (la regla `^internal(/|$)` no se probó con Apache/LiteSpeed).

---

## 21. Experiencia del panel (Q5a): tema, vistazo, vistas, tablero, paleta, atajos y «Deshacer»

> Nota R1: la identidad visual descrita en esta sección (navy, cian, Barlow, celda de flecha) fue sustituida en la fase R1; la vigente está en §15 y §25. La lógica no cambió.

Migración nueva: **`011_ux_prefs_views.sql`** (§2); en el servidor basta `php captacion/bin/migrate.php` tras el deploy. Sin variables nuevas, sin crons nuevos, sin dependencias. De las dos referencias de Carlos (un editor de políticas con panel lateral y un tablero de proyectos oscuro) se tomaron **componentes y maneras de trabajar**, nunca marca, textos ni colores: panel lateral deslizable con cabecera, acciones y datos clave; barra de pestañas de vistas con filtro y orden; tarjetas con etiquetas, código corto, barras de prioridad y avance; sidebar plegable con contadores y atajo de búsqueda visible; todo con la identidad NorthStar (navy y cian, Barlow, Barlow Condensed y JetBrains Mono).

| Entregable | Qué quedó |
|---|---|
| **A. Tema** | §15 «Tema oscuro». `Services\Prefs` (lista blanca de claves y valores), `POST /ajax/prefs` (CSRF; el lector solo puede cambiar `theme`). |
| **B. Vistazo rápido** | Clic en una fila de la bandeja o una tarjeta del tablero abre un panel a la derecha (≥ 1025 px) o una hoja inferior casi a pantalla completa (móvil) sin salir de la lista: cabecera (nombre, código, perfil, temperatura, etapa, puntuación), Llamar / WhatsApp / Correo, cambiar etapa (mismas reglas: pide motivo, nota o confirmación en el diálogo compartido), datos clave y banderas de FMCSA, hasta 4 tareas abiertas (se completan con un clic), las últimas 5 actividades, formularios de nota, tarea y llamada, y «Abrir ficha completa» (`/leads/{id}` sigue siendo la URL canónica; las alertas de Telegram no cambian). Carga: `POST /ajax/peek {id}` (JSON con CSRF y rol de administrador; nada en la URL salvo `?peek=ID` en la barra, para compartir y recargar), esqueleto de carga, error con «Reintentar», foco al título y de vuelta a la fila al cerrar, `Esc`, trampa de foco y `aria-modal` solo en la hoja móvil. `J`/`K` y flechas recorren la lista visible (el vistazo abierto sigue al cursor), `Enter` y doble clic abren la ficha, `Espacio` abre el vistazo. Sin JS la fila sigue siendo un enlace a la ficha. |
| **C. Vistas** | Pestañas **Lista / Tablero** que comparten filtros (`Kanban::filters` ya acepta todos los de la bandeja salvo la etapa), chips de filtros activos y «Limpiar» en ambas pantallas. Vistas del sistema (en código): Calientes pendientes, Llegaron hoy, Sin tarea, Descartados y Presentados sin respuesta (filtros nuevos `nt`, `op`, `hs`). Vistas del usuario (`saved_views`, privadas: toda operación comprueba `user_id`): guardar filtros y orden con un nombre (único sin importar mayúsculas, 60 caracteres, máximo 30), renombrar, fijar (aparecen en la barra lateral bajo «Vistas» con contador en vivo: se recalcula en cada página y cada 60 s con `POST /ajax/counters`), borrar (lógico) y «Guardar cambios» cuando una vista abierta fue modificada. Todo con formularios (`/vistas/...`) que funcionan sin JS. Densidad (cómoda / compacta) y columnas visibles de la lista (incluye «Próxima tarea»), guardadas por usuario. |
| **D. Tablero** | Encabezado de columna con icono de etapa, conteo y «+» en «Nuevo» (alta manual rápida: nombre, teléfono y perfil, fuente `manual`, pasa por `LeadIngest` con validación, deduplicación y puntuación, sin consentimiento de mensajería ni alertas; si el teléfono ya existe abre el lead existente). Tarjeta: etiquetas de perfil y equipo, nombre, código corto `LD-0142` (también se busca), barra de puntuación de 4 segmentos, fecha de la próxima tarea (roja si venció), avance del pipeline `3/6` con barra fina, antigüedad en la etapa y bandera de FMCSA. Se mantienen arrastrar, «Mover a…» (ahora un botón de icono), «Ver más» por columna y todas las reglas de etapa. Densidad compacta opcional. **No hay responsable** en el modelo de datos (una sola persona opera el CRM), así que no se muestra avatar de responsable; el código ya lo pinta si la fila trae `assignee`. |
| **E. Sidebar y barra superior** | Barra lateral plegable (solo iconos con tooltip; estado por usuario sin parpadeo; en móvil sigue siendo cajón), bloque «Bandeja» (avisos sin leer + tareas vencidas, abre la bandeja de avisos), sección «Vistas» con las fijadas, buscador con `/` y `Ctrl/⌘ K` visibles, selector de tema. La bandeja de avisos es la campana mejorada: lista completa con leído / no leído por aviso, filtro por tipo (Todos, Sin leer, Leads, Tareas, Sistema), «Marcar todo» y acceso a las tareas vencidas. |
| **F. Paleta (`Ctrl/⌘ K`, `/`)** | Resultados agrupados: **Acciones** (Nuevo lead, Nueva tarea, Cambiar tema, Atajos, Plegar la barra lateral y, con un lead abierto en la ficha o en el vistazo, «Mover a …» cada etapa, Registrar llamada, Añadir nota, Añadir tarea y Marcar respondió), **Leads** (búsqueda por nombre, teléfono, correo, USDOT o código), **Recientes** (los últimos abiertos por ese usuario, solo ids en `user_prefs`), **Pantallas** y **Vistas**. Flechas, `Enter`, foco atrapado (diálogo nativo) y `Esc`. El catálogo (`Services\Commands`) se arma en el servidor por rol: solo los administradores lo reciben; el lector no tiene paleta ni buscador. |
| **G. Atajos y «Deshacer»** | `?` abre la hoja de atajos (`/` buscar, `g d`/`g l`/`g p`/`g t` ir a Dashboard, Leads, Pipeline o Tareas, `j`/`k`, `Enter`, `Espacio`, `n` nota, `c` llamada, `m` mover, `Esc`); se desactivan dentro de campos de texto y de diálogos, y nada depende solo del teclado. **«Deshacer»** (aviso de 6 s con acción; sin JS el aviso queda con su botón): cambio de etapa en la ficha, el tablero, el vistazo y la paleta; cambio masivo; completar una tarea; borrar una nota. El servidor emite un token firmado (HMAC con `APP_KEY`, atado al usuario, 10 min) y al aplicarlo vuelve a validar el estado actual con las mismas funciones del panel (`LeadActions::undoStage`, `Tasks::reopen`, `LeadActions::restoreNote`) y deja evento en el historial (`stage_changed` con `undo`) y en `activity_log`. **Límites decididos:** no se ofrece deshacer cuando el cambio genera una entrega a NorthStar (a o desde «Presentado», «Firmado», «Contratado»), ni desde «Perdido» (se reabre); deshacer no restaura la segunda alerta A3 cancelada ni las secuencias pausadas; «añadir o quitar de la selección masiva» se interpretó como el propio cambio masivo (la casilla de selección es estado de pantalla, no un cambio en el servidor). |
| **H. Pulido** | Estados vacíos, de carga (esqueleto del vistazo) y de error (con reintento) en lo nuevo; transiciones solo de `transform` y `opacity`, desactivadas con `prefers-reduced-motion`; 360 a 1920 sin scroll horizontal; foco visible; textos en español. |

### Rutas y endpoints nuevos

| Ruta | Qué es |
|---|---|
| `POST /ajax/prefs {key,value}` | Tema, barra lateral, densidad y columnas. Lista blanca; el lector solo `theme`. |
| `POST /ajax/peek {id}` | Fragmento HTML del vistazo (ya escapado) + etapa y etapas del pipeline. |
| `POST /ajax/lead/act {id,act,...}` | `note`, `task`, `task_done`, `call`, `responded`: las mismas funciones que la ficha. Devuelve el token de «Deshacer» cuando aplica. |
| `POST /ajax/lead/create {name,phone,profile}` | Alta manual rápida por el motor. |
| `POST /ajax/undo {token}` · `POST /undo` | Deshacer (JSON y formulario sin JS; `back` solo admite rutas internas). |
| `POST /ajax/counters` | Contadores de las vistas fijadas y de la bandeja. |
| `POST /ajax/pipeline/card {lead_id}` | La tarjeta actual de un lead (tras deshacer o un cambio hecho fuera del tablero). |
| `POST /vistas/new` · `POST /vistas/{id}/{rename\|pin\|unpin\|delete\|update}` | Vistas guardadas (formularios con CSRF). |

Todo POST con sesión, 2FA, CSRF y rol comprobado en el servidor, con límite de uso por usuario (`ux_peek` 240/min, `ux_act` 120/min, `ux_create` 30/min, `ux_prefs` 90/min). El lector responde 403 sin datos en todo lo anterior salvo `theme`.

### CSS y JS (Q5a)

El presupuesto es **≤ 45 KB de CSS sin comprimir por pantalla** (base + hoja de pantalla + fuentes). El tema oscuro, el vistazo y los avisos no cabían, así que se partió: `panel.css` pasa a ~27 KB (se le quitó lo que solo usan algunas pantallas, que ahora vive en la hoja de cada una; análisis hecho midiendo qué reglas coinciden con el DOM de 45 rutas y verificado con 180 capturas antes y después) y hay dos hojas **diferidas** que el navegador pide fuera del primer pintado: `ux.css` (paleta, atajos, avisos, tooltips, bandeja, menús de vistas y «Mover a…», ~7,7 KB) y `peek.css` (el panel del vistazo, ~4,8 KB), cargadas desde `ux.js` y `peek.js`. Total de la primera pantalla (fonts + panel + hojas): Leads 38,6 KB, Pipeline 41,1, Ficha 43,1 (la más pesada), Dashboard 42,8, Configuración (Puntuación) 39,6. `fonts.css` bajó de 3,5 a 2,7 KB (sin `unicode-range`: ya son subsets latinos). La prueba `17_pulido` comprueba el tope por cada ruta.

JS sin comprimir (gzip): `panel.js` 11,9 KB (3,7), `ux.js` 23,4 KB (7,8; todas las pantallas de administración, no el lector), `peek.js` 9,3 KB (3,3) y `views.js` 2,2 KB (0,9) en Leads y Pipeline, `kanban.js` 17,3 KB (5,7).

### Pruebas (Q5a)

`php captacion/tests/run.php`: nuevo `30_ux_q5a` (preferencias y su aislamiento, tema sin parpadeo y por usuario, vistas guardadas con aislamiento entre usuarios y contadores en vivo, filtros nuevos, vistazo con autorización y datos correctos, acciones rápidas, alta manual por el motor, «Deshacer» de cada acción con caducidad, manipulación y otro usuario, paleta por rol, bandeja leído / no leído, tarjetas del tablero, y la prueba negativa del lector en cada endpoint nuevo). `01_migrations` prueba la 011 sobre una base de Q2b; `12_kanban`, `17_pulido` y `19_viewer` se ajustaron a las pestañas, las hojas por pantalla y las rutas nuevas. No cubierto: el comportamiento del navegador (arrastre, foco, teclado), que se revisó con Chromium a 1440, 1024, 834 y 390 px en claro y oscuro (capturas fuera del repo); Firefox y Safari siguen sin probarse.

---

## 22. Editor de reglas (Q5b): CUANDO / ENTONCES / EXCEPTO con vista previa, simulación y versiones

Migración nueva: **`012_rules.sql`** (§2); en el servidor basta `php captacion/bin/migrate.php` tras el deploy. Sin variables nuevas, sin crons nuevos (la simulación grande y el recálculo corren en la cola que ya existe), sin dependencias. De la referencia de Carlos (un editor de políticas con panel lateral, estado, bloques «cuando / entonces / excepto», vista previa de impacto con muestras, historial y «Guardar borrador» / «Publicar») se tomaron componentes y manera de trabajar, nunca marca, textos ni colores.

**Qué es.** Una capa de reglas legibles que AJUSTA la puntuación del documento (§4) o dispara acciones. La puntuación base no se toca: `Scoring::score()` sigue siendo la del documento y las reglas publicadas se aplican DESPUÉS (`Services\RuleRunner::score()`). **No es un lenguaje**: condiciones, operadores y acciones salen de catálogos cerrados y validados en el servidor (`Services\Rules`); no hay `eval` ni expresiones libres.

### Catálogo

| Condiciones (CUANDO / EXCEPTO) | Tipo y operadores |
|---|---|
| Perfil · Estado de EE. UU. · Fuente · Idioma · Etapa · Temperatura | lista cerrada: es, no es, está en, no está en, tiene dato, está vacío |
| Equipo · Banderas de FMCSA · Consentimiento vigente (llamada, SMS, correo) | varios valores: incluye alguno de, incluye todos, no incluye ninguno de, tiene alguno, no tiene ninguno |
| Años de experiencia · Camiones · Antigüedad de la autoridad (meses) · Horas desde la llegada · Puntuación actual | número: es, no es, mayor que, al menos, menor que, como máximo, entre, tiene dato, está vacío |
| Campaña (utm) · Página de llegada | texto: es, no es, está en, contiene, no contiene, tiene dato, está vacío |
| Tiene USDOT | sí / no |

Un bloque combina condiciones con **Y** u **O** y admite **un nivel de grupos** (un grupo tiene su propio Y/O). EXCEPTO es otro bloque igual: si coincide, la regla no actúa. Los datos ausentes hacen falsa toda comparación salvo las negaciones (no es, no está en, no contiene, vacío).

| Acciones (ENTONCES) | Conjuntos donde se permite |
|---|---|
| Sumar o restar puntos (−30 a 30, con tope opcional: al sumar no pasa de ese valor, al restar no baja de él) · Fijar temperatura mínima (Tibio o Caliente) | Puntuación |
| Avisar por Telegram (urgente o normal, texto con variables) | Alertas |
| Crear una tarea (título con variables, vence en 1 a 720 h, prioridad) · Poner una etiqueta · Enviar a la cola de revisión | Tareas y etiquetas |

Variables permitidas en textos de alertas y tareas (lista blanca, cualquier otra se rechaza): `{nombre} {perfil} {estado} {equipo} {camiones} {anos} {puntuacion} {temperatura} {fuente} {etapa}`. Nunca teléfono ni correo. Límites: 12 conjuntos, 40 reglas por conjunto, 10 condiciones o grupos por bloque, 8 por grupo, 4 acciones por regla, 30 valores por lista, 60 KB de JSON.

### Evaluación (motor puro, `Services\RuleEngine`)

Sin base de datos, red ni hora oculta (el reloj y los datos del lead llegan resueltos): por eso es determinista y sirve igual para el motor real, la vista previa y la simulación. Orden: conjuntos de puntuación, luego alertas, luego tareas y etiquetas (estos ven la puntuación y la temperatura finales); dentro de un conjunto, de arriba abajo, una sola pasada (**no hay ciclos posibles**: una regla nunca activa a otra y una condición sobre la puntuación solo ve lo que sumaron las reglas anteriores). «Detener al coincidir» corta el resto de ESE conjunto. Topes: cada regla ±30, el ajuste TOTAL de todas las reglas ±40 y la puntuación final 0 a 100. Un lead descartado (CDL no domiciliada, sin inglés) no cambia de puntuación ni de temperatura por reglas. La temperatura mínima manda aunque los puntos bajen.

### Equivalencia sin reglas (cómo se garantiza)

1. `RuleRunner::score()` devuelve **el mismo arreglo** que `Scoring::score()` si el interruptor general está apagado (`rules.enabled`), no hay conjuntos publicados o ninguna regla coincide: no añade ni una clave al desglose.
2. Prueba con 400 leads aleatorios (igualdad estricta de arreglos) y con los 120 leads de `bin/seed-demo.php`: recalcular el seed sin reglas deja exactamente lo que dan `Scoring::score` y `Flags::derive` (puntuación, temperatura, desglose, banderas y motivo de descarte), con un evento `scored` solo por cada lead que ya cambiaba. Igual con una regla publicada que no coincide con nadie, con el interruptor apagado y con las plantillas publicadas pero desactivadas.
3. **Restablecer**: «Restablecer a la puntuación del documento» (retira todo lo publicado, queda archivado) y el interruptor «Apagar las reglas».
4. Un fallo de las reglas nunca bloquea la captación: `RuleRunner::score()` y `RuleEffects::persist()` atrapan cualquier excepción, la registran y siguen con la puntuación del documento (prueba con un conjunto publicado corrupto).

### Integración con el motor

`LeadIngest` (alta, reingreso y reapertura, recálculo) llama a `RuleRunner::score()` y a `RuleEffects::persist()`. El desglose guarda `rules` (qué regla influyó y su efecto), `rules_adjust` y `score_base`; la ficha y el vistazo muestran «Reglas que influyeron». Etiquetas y cola de revisión son banderas del lead (`tag:nombre`, `review_queue`; «Por revisar» es una vista del sistema, y `?fl=tag:nombre` filtra la bandeja) y se recalculan solas: si la regla se retira, la bandera se quita. Alertas, tareas y etiquetas se registran **una vez por regla, lead e intento** (`rule_applications.dedupe_key` único): republicar o recalcular no los repite. La alerta de una regla va por la cola (`rule_alert`): las normales esperan al horario de silencio, las urgentes no, hay un tope de 20 por hora (`rules.alert_cap`; la que excede se omite con el evento «Alerta de regla omitida»), y si la regla se retira o se apaga después de encolarse el job no avisa. El texto sale escapado y sin teléfono ni correo. Las importaciones silenciosas y el recálculo masivo ponen banderas y registran puntos, pero **no crean tareas ni alertas** en masa.

### Versiones

Cada conjunto tiene UN borrador y UNA versión publicada como máximo (lo garantizan `draft_slot` y `pub_slot` con índices UNIQUE). «Guardar borrador» crea o actualiza el borrador; «Publicar» archiva la anterior y activa el borrador en una sola transacción (probado con un fallo forzado a mitad: nada cambia); «Revertir» copia una versión archivada como versión NUEVA publicada (la historia no se borra); «Retirar» deja el conjunto sin versión publicada. El historial muestra autor, fecha, nota y la diferencia legible entre versiones (reglas añadidas, quitadas, cambiadas o movidas, con el antes y el después en español). Todo queda en `activity_log` con la diferencia (anterior / nuevo), sin secretos.

### Pantallas y rutas (solo administradores)

Pestaña **Reglas** de `/config`: lista de conjuntos por tipo con estado (Borrador, Publicada, Publicada con borrador, Retirada), versión y fecha; panel lateral deslizable (hoja inferior en móvil, mismo patrón del vistazo de Q5a: foco, `Esc`, `aria-modal` solo en la hoja) con nombre y descripción, bloques CUANDO / ENTONCES / EXCEPTO con selectores en español y la frase de cada regla («Cuando el perfil es Owner-operator y el estado está en TX, TN…»), reordenar con arrastre, flechas del asa o botones, duplicar, desactivar, borrar, validación en línea, vista previa e historial. Las piezas del editor (filas, reglas, acciones) las construye el servidor (`/ajax/rules/row`), así hay una sola implementación. **Sin JS** el mismo formulario funciona con botones que recargan la página (`Services\RuleForm`).

| Ruta | Qué es |
|---|---|
| `POST /config/rules/{newset,starter,save,discard,simcsv}` | Crear conjunto, instalar ejemplos, guardar borrador (y operaciones sin JS, vista previa y simulación sin JS), descartar borrador, descargar el CSV de una simulación. CSRF; no exigen reautenticar. |
| `POST /config/rules/{publish,revert,unpublish,delete,switch,reset,apply}` | Publicar, revertir, retirar, borrar, interruptor general, restablecer y «Aplicar a leads existentes» (recálculo por la cola con recuento antes y después). CSRF + contraseña y 2FA recientes. |
| `POST /ajax/rules/{panel,row,preview,simulate,simstatus}` | JSON con `X-CSRF-Token`, rol admin y límite de uso (vista previa 90/min, simulación 8/hora). El lector recibe 403 y el anónimo 401. |

Paleta (`Ctrl/⌘ K`): «Ir a Reglas», «Nueva regla» y «Simular reglas», solo para administradores (el catálogo se arma en el servidor por rol). Plantillas: «Empezar con ejemplos» crea 3 conjuntos y 5 reglas de ejemplo como BORRADOR, todas desactivadas y marcadas «ejemplo»; sus puntos y listas son ilustrativos y provisionales, no cifras del negocio.

### Vista previa y simulación

**Vista previa** (mientras se edita, hasta 1.500 leads y 3 s; si no alcanza, lo dice): cuántos leads cambiarían de puntuación o temperatura, cuántos pasarían a o dejarían de ser calientes, en cuántos actúa, alertas, tareas y etiquetas que se habrían creado, distribución por temperatura antes y después (SVG) y hasta 10 muestras con antes, después y la regla que actuó. «Antes» es lo que dan hoy las reglas publicadas; «después» lo mismo con el borrador en su lugar. **Simulación**: últimos N leads (hasta 20.000), últimos N días o rango de fechas (máximo 400 días); hasta 400 leads corre en el momento y más por la cola en lotes de 250 (`rule_simulate`) con barra de avance; queda asociada al borrador; descarga CSV con código corto y datos de segmentación (sin nombres, teléfonos ni correos), protección contra fórmulas y registro `rules_sim_export`. **No escribe nada del negocio**: ni leads, ni eventos, ni tareas, ni consentimientos, ni mensajes, ni alertas (prueba con `CHECKSUM TABLE` antes y después, inline y por la cola); solo escribe en `rule_simulations` y `rule_simulation_rows`.

### Rendimiento (medido en local, MariaDB 10.11, 5.000 leads sintéticos)

Vista previa de 1.500 leads: 0,07 s; simulación de 5.000 leads: 0,41 s en 21 lotes (≈ 19 ms por lote, 34 MB de pico); CSV de 2.890 filas: 0,03 s. `EXPLAIN`: los lotes usan la clave primaria; «por días» `idx_leads_created`; el recuento por rango un índice cubriente; los conjuntos publicados `uq_rule_versions_pub` (`eq_ref`). El único `filesort` es el del id mínimo de «últimos N» (recorre N filas del índice).

### CSS y JS

Primera pantalla de la pestaña (fonts 2,7 + panel 27,0 + config 6,1 + `cfg-reglas.css` 4,6 KB) = 40,4 KB sin comprimir, por debajo de los 45 KB; `rules.css` (14,5 KB) se pide al abrir el panel y va enlazada en la propia página solo con `?set=ID`. JS: `rules.js` 21,4 KB (solo en esa pestaña; sin librerías, con nonce). Todo son variables de `panel.css`: sin un solo color fijo, ambos temas, `prefers-reduced-motion`, sin scroll horizontal de 360 a 1920 px.

### Pruebas

`php captacion/tests/run.php`: nuevos `31_rules_engine` (validación de cada condición, operador y acción, frases, evaluación con tabla de casos, grupos Y/O, excepciones, orden, «detener», topes, equivalencia sobre 400 leads y sobre el seed demo), `32_rules_apply` (versiones, atomicidad, diferencias, revertir, aplicación en el motor con reloj simulado, alertas con silencio, deduplicación y tope, tareas, etiquetas, cola de revisión, recálculo por la cola, plantillas, robustez), `33_rules_sim` (vista previa y simulación sin escritura, por la cola, CSV) y `34_rules_panel` (HTTP: autorización por rol en CADA ruta nueva, CSRF, reautenticación, formularios sin JS, piezas del servidor, escapado, límites, auditoría, paleta, ficha, vistazo y «Por revisar»); `01_migrations` prueba la 012 sobre la base de Q5a con datos y sus invariantes; `19_viewer` pide también cada ruta nueva como lector. No cubierto: el comportamiento del navegador (arrastre, foco, teclado), que se revisó con Chromium a 1440, 1024, 834 y 390 px en claro y oscuro; Firefox y Safari siguen sin probarse.

### Límites conocidos

- Las condiciones se evalúan cuando el lead llega, cambia o se recalcula: una condición de «horas desde la llegada» no dispara sola con el paso del tiempo (no hay un cron que reevalúe).
- Las alertas y tareas de reglas no se crean al recalcular en masa ni en importaciones (decisión deliberada).
- «Aplicar a leads existentes» recalcula todos los leads (como el recálculo de Puntuación); no hay recálculo por conjunto.
- Una regla duplicada o nueva tiene un id nuevo: si se quiere que una regla vuelva a disparar su alerta o tarea para leads que ya la recibieron, hay que crearla como regla nueva (la deduplicación es por regla, lead e intento).
- La temperatura mínima puede dejar una temperatura mayor que la que daría la puntuación (así se pidió); el desglose lo dice.

---

## 23. Vistas de trabajo (Q5c): Tabla, Calendario y Cronología, y barrido final de la experiencia

Migración nueva: **`013_lead_modes.sql`** (§2); en el servidor basta `php captacion/bin/migrate.php` tras el deploy. Sin variables nuevas, sin crons nuevos, sin dependencias. Cierra la tanda de la referencia de Carlos (pestañas de vistas Overview / Board / List / Table / Calendar / Timeline con barra de filtro y orden): se tomaron componentes, nunca marca, textos ni colores.

### A. Selector de vistas y modos

La barra de vistas de §21 pasa de dos a cinco pestañas: **Lista · Tablero · Tabla · Calendario · Cronología**. Todas comparten `LeadQuery::filters` (los mismos filtros, chips y «Quitar filtros»), las vistas guardadas, la densidad y el vistazo rápido (clic en una fila, un evento o una tarjeta abre el panel lateral; `data-peek-link` permite que un enlace de un evento abra el vistazo en vez de navegar).

- **El modo va en la RUTA**, no en `?v=`: `v` ya identifica la vista guardada (`sys-…` o su id). Rutas: `/leads`, `/pipeline`, `/tabla`, `/calendario`, `/cronologia`. Por compatibilidad con el pedido, **`/leads?v=tabla|calendario|cronologia` redirige (302) al modo** conservando los demás parámetros.
- **Se recuerda por usuario** (`user_prefs`: `lmode` = último modo usado, `calm` = escala del calendario, `tcols` = columnas de la Tabla). «Leads» de la barra lateral y de la barra inferior lleva al último modo (`Prefs::home`). Escribir una preferencia solo ocurre si cambia.
- **Una vista guardada recuerda también el modo** (`saved_views.mode`): al abrirla desde el menú, la barra lateral o la paleta va a su pantalla; las anteriores a Q5c (sin modo) y las del sistema se abren en la pantalla actual (o en la Lista desde la barra lateral). «Guardar cambios» actualiza el modo. El orden guardado vale para la Lista o la Tabla (`LeadTable::cleanSort`).
- Sin JS cada modo es una página servida por el servidor (enlaces y formularios); el JS solo mejora.

### B. Tabla (`/tabla`)

Para comparar muchos leads: más columnas y sin tarjetas (no se convierte en tarjetas en móvil: se desplaza en horizontal con la **columna del nombre fija**). Columnas disponibles (`LeadTable::COLUMNS`): código, perfil, estado, idioma, equipo, camiones, años, USDOT, teléfono, correo, puntos, temperatura, etapa, fuente, primer contacto, recibido, último cambio y próxima tarea; **elegibles y reordenables** (menú «Columnas», un `<details>` con un formulario `POST /tabla/cols`: marcar, subir, bajar, restablecer; funciona sin JS). **Orden por columna** con lista blanca (`LeadTable::SORTS`): la clave de la URL nunca llega al SQL, siempre cierra con `l.id DESC` (orden estable), los vacíos van al final y la etapa se ordena por su posición en el embudo (no alfabéticamente); claves o direcciones inválidas caen en «Recibido, descendente». Selección masiva con las acciones de siempre (`/leads/bulk`, que ahora acepta volver a `/tabla`), paginación (25/50/100) y **exportación CSV de lo filtrado o de la selección** (`POST /tabla/export`, columnas visibles en su orden, BOM, protección contra fórmulas, tope de 10.000 filas, 20 por hora y usuario, registro `leads_export` con `mode: tabla`).

### C. Calendario (`/calendario`)

Mes, semana y día con: **tareas** (por vencer, vencidas y hechas), **citas** de Cal.com, **primer contacto pendiente** (leads nuevos sin contactar, el día que llegaron), **próximos pasos de secuencias** y **entregas a NorthStar programadas** (`jobs` `handoff_deliver` pendientes). Chips por tipo con color semántico del tema (`ev=` en la URL), hoy resaltado, horas en la zona configurada, navegación con botones y teclado (flechas entre días, RePág/AvPág de mes, Home/End, Intro abre el día), clic en un evento abre el vistazo del lead (una tarea sin lead abre `/tareas` en su semana) y **clic en el hueco de un día crea una tarea** (diálogo con selector de lead; sin JS, `?nueva=AAAA-MM-DD` muestra un formulario). En móvil el mes es una cuadrícula compacta de puntos y al tocar un día se abre su agenda (vista Día); la semana se apila.

- **Reprogramar**: arrastrar una tarea abierta a otro día, `Alt` + flechas con la tarea enfocada, o el menú «Posponer / Mover» de la tarjeta (1 hora, mañana, otro día; formularios sin JS). `Tasks::reschedule` conserva la hora LOCAL (9:00 ET sigue siendo 9:00 ET aunque cambie el horario de verano), valida el día, rechaza tareas hechas o inexistentes y deja evento `task_rescheduled` en la ficha y `task_resched` en Actividad. **«Deshacer» de 6 s** (`Undo::resched`): vuelve a la fecha anterior solo si la tarea sigue abierta y con la fecha que se le puso.
- **Carga por rango visible** (nunca el histórico): la cuadrícula cubre semanas completas; cada tipo de evento tiene un tope de 250 y, si se alcanza, la pantalla avisa («acota con un filtro»). Los límites de día se calculan con `DateTimeImmutable` en la zona de visualización: el día del cambio de horario mide 23 o 25 horas y un evento de las 23:30 locales cae en SU día. Con algún filtro de lead solo entran eventos de leads que lo cumplen (y no las tareas sin lead).

### D. Cronología (`/cronologia`)

Hilo global de actividad (llegadas, etapas, notas, llamadas, tareas, citas, entregas, firmas, mensajes y alertas; la puntuación, FMCSA y similares son ruido y no salen) de lo más nuevo a lo más viejo, **agrupado por día**, con los mismos filtros de lead, chips por tipo (`tp=`), «solo este lead» (`ld=`) y **paginación por cursor** `(created_at, id)`: «Cargar más» (JS, `POST /ajax/timeline`) o, sin JS, un enlace `?cur=`. Estable aunque lleguen eventos nuevos mientras se pagina. Solo datos que el administrador ya ve en la ficha: **sin cuerpos de mensajes, sin texto de notas, sin teléfonos ni correos**. La consulta corre con tope de tiempo (`Db::withTimeout`: `max_statement_time` de MariaDB o `max_execution_time` de MySQL; si se pasa, la pantalla lo dice y ofrece reintentar).

### E. Revisión transversal

Con la base VACÍA (migrada desde cero, solo el admin) y con la de demostración, a 1440, 1024, 834 y 390 px en claro y oscuro (360 con la base vacía y 384 con la de demostración, fuera del repo) y con un script que mide desbordes, iconos sin símbolo o sin tamaño, controles pequeños, barras de desplazamiento gruesas, contraste y errores de consola. Encontrado y corregido:

| Hallazgo | Corrección |
|---|---|
| **Error de JS** al filtrar sin resultados en la Lista (`[data-clear]` no existe y rompía el resto de `panel.js`: reloj, formulario de etapa, línea de tiempo) | `panel.js` solo enlaza la barra masiva si existe |
| `/tareas` usaba `page-head--kb` pero esa regla solo estaba en `kanban.css` (botones sin la versión compacta de móvil) | regla copiada a `tareas.css` (las dos hojas nunca se cargan juntas) |
| 34 reglas CSS repetidas entre `panel.css` y las hojas de pantalla (comms, config, lead, reportes, tareas) más 5 `.empty*` en `leads.css` | eliminadas; verificado con la comparación de estilos calculados de 29 rutas × 2 anchos × 2 temas, sin diferencias reales (solo carreras de transición) |
| Contraste: tareas hechas atenuadas con `opacity` (2,9:1 en la semana de `/tareas`, ahora también en el calendario), `.seg__n` 4,4:1 | tono propio en lugar de opacidad; ahora ≥ 4,5:1 en las pantallas revisadas (los botones y textos deshabilitados de Configuración bloqueada están exentos) |
| Desborde horizontal a 390 px: `<code>` largo en el estado vacío de Referidos, chip de «Ajustes provisionales», selector de Inscripciones; `.sr` absolutos dentro de `.tw` ensanchaban la página con `overflow-x: clip` desactivado | `overflow-wrap` en `code`, `flex-wrap` en `.card__h`, `max-width` del selector y `.tw { position: relative }` |
| Barras de desplazamiento gruesas (tablero, textareas) | `scrollbar-width: thin` con el color de la línea |
| Controles táctiles de 24–26 px (botón «i» de los KPI, URLs de webhooks, teléfono y correo de la ficha) | área de 44 px en móvil, campo de 40 px |
| Textos: «Limpiar» (filtros), «Quitar los filtros» y «Quitar todos» conviven; «Limpiar» también deseleccionaba filas | «Quitar filtros» en botones y estados vacíos, «Quitar todos» en los chips y «Deseleccionar» en la barra masiva |

**Prueba automática `36_css_classes`**: renderiza 42 pantallas con la base vacía y comprueba que cada clase del HTML está definida en las hojas que esa pantalla carga (`fonts` + `panel` + su hoja + las diferidas `ux`, `peek`, `etiquetas` y `rules`); falla y dice qué clase falta en qué pantalla. Las clases sin regla A PROPÓSITO (ganchos, 15) están listadas con su motivo en la propia prueba. Las clases que solo aparecen con datos no se cubren (la prueba usa la base vacía, como se pidió).

### F. Atajos y paleta

`g b` Tabla, `g c` Calendario, `g r` Cronología (se conservan `g d`, `g l`, `g p` y `g t`; `c` y `n` solo actúan con un lead abierto, así que no chocan). La paleta (`Ctrl/⌘ K`) ofrece «Ir a Tabla», «Ir a Calendario» e «Ir a Cronología» (solo administradores). La hoja de atajos (`?`) trae el grupo «Calendario» (flechas, RePág/AvPág, `Alt` + flechas).

### Rutas nuevas (solo administradores; el lector responde 403 en todas, con prueba negativa en `19_viewer` y `35_modes_q5c`)

| Ruta | Qué es |
|---|---|
| `GET /tabla` · `/calendario` · `/cronologia` | Las tres pantallas |
| `POST /tabla/export` · `POST /tabla/cols` | CSV de la Tabla (CSRF, 20/h) · columnas (CSRF, funciona sin JS) |
| `POST /calendario/move` | Mover una tarea de día sin JS (CSRF; vuelve al calendario con «Deshacer») |
| `POST /ajax/calendar/move {task_id, ymd \| mode}` | Reprogramar o posponer (JSON, `X-CSRF-Token`, 120/min); devuelve el token de «Deshacer» |
| `POST /ajax/timeline {qs, cursor}` | Siguiente página de la cronología (JSON, 120/min) |

`POST /tareas/new`, `/tareas/{id}/done` y `/tareas/{id}/snooze` aceptan ahora volver a `/calendario?…` (`back` solo admite rutas internas). Sin datos personales en las URLs salvo ids; parámetros de orden, rango, escala, tipos, cursor y filtros con lista blanca.

### Rendimiento (local, MariaDB 10.11, 5.000 leads, 28.000 eventos, 4.000 tareas, 600 citas, 2.500 secuencias)

Mejor de 5 repeticiones: **Tabla** página de 100 filas ordenada por cualquier columna 2 a 4 ms (página 50: 4 ms, con filtros 4 ms); CSV de 5.000 filas 206 ms. **Calendario** mes completo (5 tipos) 38 ms, semana 21 ms, día 6 ms, con filtro de perfil 38 ms. **Cronología** primera página 8 ms (antes de forzar el orden de unión con `STRAIGHT_JOIN` cuando no hay filtros de lead: 60 ms), cursor profundo (página 60) 21 ms, filtro por tipo 22 ms, por perfil 33 ms, un lead 2 ms. `EXPLAIN`: tareas `idx_tasks_due_range` (range), citas `idx_appt_starts`, secuencias `idx_sequences_due`, entregas `idx_jobs_type_run` (índice cubriente), cronología con cursor `idx_events_created` (range) y `eq_ref` por clave primaria a `leads`; los únicos `filesort` son el de ordenar por puntos o por etapa en la Tabla (5.000 filas).

### CSS y JS

Se partió `views.css` (ahora 2,9 KB) en `filtros.css` (1,3 KB: el formulario de filtros, común a Lista, Tabla, Calendario y Cronología) y `etiquetas.css` (1,6 KB: etiquetas, barra de puntuación y avance; Pipeline la carga y las demás la piden junto con `peek.css`). Primera pantalla (fonts + panel + hojas, sin comprimir): Lista 40,1 KB, **Tabla 43,2**, **Calendario 44,3**, **Cronología 38,7**, Pipeline 44,6, Dashboard 44,9, Ficha 44,7 (todas ≤ 45 KB; `17_pulido` lo comprueba por ruta). Los `.lp*` del selector de lead pasaron de `tareas.css` a `ux.css` (diferida). JS (gzip): `calendario.js` 9,3 KB (3,3), `cronologia.js` 2,4 KB (1,1); en el calendario también se carga `tareas.js` (selector de lead). Sin librerías, con nonce, sin estilos en línea.

### Pruebas

`php captacion/tests/run.php` (3.073 pruebas, todas verdes): nuevos **`35_modes_q5c`** (preferencias, vistas con modo, orden y columnas de la Tabla con inyección, CSV, calendario por rango con cambio de horario de verano en ET, fin de mes y tope, reprogramar con «Deshacer», cronología con cursor, filtros y manipulación, tope de tiempo, atajos, y la prueba negativa del lector en cada ruta nueva) y **`36_css_classes`**; `01_migrations` prueba la 013 sobre una base de Q5b con datos; `17_pulido` y `19_viewer` incluyen las rutas nuevas. No cubierto: el comportamiento del navegador (arrastre, teclado, foco), revisado con Chromium a 1440, 1024, 834 y 390 px en claro y oscuro; Firefox y Safari siguen sin probarse (el arrastre usa la API nativa de HTML5, que no funciona con el dedo: en móvil quedan «Posponer / Mover» y la vista Día).

### Límites conocidos

- El primer contacto pendiente se pinta el día de llegada del lead (no hay un SLA propio que lo mueva) y desaparece al contactarlo.
- Las entregas programadas casi siempre son inmediatas (el cron las toma en un minuto): solo se ven en el calendario mientras esperan o si reintentan.
- Una tarea sin lead abre `/tareas` en su semana (no hay diálogo de edición en el calendario).

## 24. Dashboard vivo (Q6): narrativa, medidores, gráfico anotado, siguiente mejor acción y refresco

> Nota R1: la identidad visual descrita en esta sección (navy, cian, Barlow, celda de flecha) fue sustituida en la fase R1; la vigente está en §15 y §25. La lógica no cambió.

Se aplicó la skill «dashboards-vivos» al dashboard (`/`) **con la identidad de NorthStar** (mandan sobre la skill: tokens navy y cian de §15 en ambos temas, un solo acento = el cian, Barlow, Barlow Condensed y JetBrains Mono; no se añadió ningún color ni fuente). De la skill se tomó: narrativa antes que números, anotaciones, KPI con contexto, números protagonistas (Barlow 500 tabular, decimales y unidades atenuados; los titulares siguen en Barlow Condensed), microvisualizaciones propias en SVG/CSS, etiquetas directas, movimiento que demuestra datos vivos y estados vacíos cuidados. Se descartó: los neutros cálidos y los acentos de la skill (colisionan con el navy/cian), la barra de progreso de meta (no existe ningún ajuste de meta: no se inventa), el sidebar de la skill (ya hay uno) y las sombras/`glass`.

### Datos reales, nunca inventados

- **Base mínima.** `Dashboard::MIN_BASE = 5`: con menos de 5 leads en el periodo previo NO hay porcentaje de variación; `Dashboard::deltaCount()` dice el conteo («+4 leads», «+7 nuevos» sin base). La comparación de calificados en puntos exige 5 o más en AMBOS periodos y la de la mediana de respuesta 3 contactados en cada uno; si no, «Pocos datos». `Dashboard::delta()` (porcentajes) sigue siendo el de los reportes.
- **Subtítulo-conclusión** (`Services/DashboardNarrative`, sin IA): 1) «{Hoy | En los últimos 7 días | En los últimos 30 días} llegaron **N leads**» + calientes (o tibios) del periodo, con «(+N% frente a …)» solo si el periodo previo tiene 5 o más; 2) fuente: con dos o más fuentes de 5 o más leads cada una y 15 puntos o más de diferencia en % de calificados, «**X** califica mejor (**62%** calificados)»; si no, «Vino de X» (1 lead), «Todos vienen de X» o «X es la fuente principal (a de n)» (más de la mitad); 3) «**N calientes** esperan contacto (el más antiguo, desde hace …)» o «Ningún caliente espera contacto.». Base vacía: «Aún no hay leads. Cuando llegue el primero aparecerá aquí.»; periodo sin leads: «Aún no hay leads en los últimos 7 días; en los 7 días previos llegaron N leads.» La negrita va solo en cifras y sustantivos clave.
- **Gráfico.** Una anotación (`Dashboard::annotate`): el pico, o «+N leads en total frente al periodo anterior» si el periodo en curso ya es el máximo; solo con 8 leads o más repartidos en 3 periodos o más. Línea punteada de promedio solo con 4 periodos completos con datos; el último periodo (en curso, marcado «· en curso») no entra en el promedio.
- **Mini barras y variación por fuente**: los últimos 30 días contra los 30 previos, solo con 5 leads o más entre los dos.

### Siguiente mejor acción (`Services/DashboardActions`)

Hasta 3 acciones por impacto (0 a 100, medidor de 20 rayas). Reglas fijas; orden por impacto y, con empate, el orden de esta tabla. Sin acciones: «Todo al día».

| Regla | Cuenta | Impacto | Botón |
|---|---|---|---|
| `hot` | calientes en «Nuevo» sin primer contacto | 60 + 6·min(n,5) + 10 si el más antiguo espera más de 15 min | `/leads?t=hot&ct=none` |
| `failed` | trabajos fallidos en la cola | 55 + 5·min(n,5) | `/#cola` |
| `overdue` | tareas vencidas | 30 + 4·min(n,10) + 10 si la más antigua tiene más de 2 días | `/tareas?tab=overdue` |
| `presented` | presentados a NorthStar sin respuesta | 25 + 5·min(n,5) | `/leads?hs=wait` |
| `followups` | seguimientos de prospectos de hoy o vencidos | 25 + 3·min(n,8) | `/prospectos` |
| `no_task` | leads abiertos sin ninguna tarea | 20 + 2·min(n,10) | `/leads?nt=1&op=1` |
| `referrals` | bonos de referidos por aprobar | 20 + 2·min(n,5) | `/referidos` |
| `review` | leads en la cola de revisión de las reglas | 15 + 2·min(n,10) | `/leads?fl=review_queue` |

Nivel: alto (70 o más, rayas naranja), medio (40 a 69) y bajo. Tope 100.

### Refresco ligero y movimiento

- `POST /ajax/dashboard {p}`: solo administradores (el lector recibe 403 y nada de datos), sesión con 2FA, CSRF en `X-CSRF-Token`, 24 por minuto y usuario (429), tope de 4 s de consulta (503) y **solo agregados** (fragmentos HTML de KPI, subtítulo y acciones; ningún nombre, teléfono, correo ni id de lead; nada personal en la URL). `Dashboard::pulse()` mira 60 días, no 26 semanas. El navegador lo pide cada 60 s solo con la pestaña visible (con retroceso 2×, 4×, 8× si falla), al volver a la pestaña tras más de 60 s, y al cambiar de periodo (Hoy/Semana/Mes cambian en el sitio sin recargar; si falla se cae a la navegación normal).
- `dashboard.js` (nonce, sin librerías; desde Q6-I 8,3 KB, con lo visual en `dashfx.js`) anima con Web Animations solo `transform`, `opacity` y `stroke-dashoffset`: entrada una vez (KPI con 50 ms de desfase, conteo de 800 ms con separadores y decimales, líneas que se dibujan, rayas que se llenan de 15 ms en 15 ms, barras y puntos del mapa que crecen, 10 filas como máximo), pulso del punto «en vivo», pastilla deslizante en los segmentados, rodado del valor y destello de 600 ms del delta al refrescar, esqueleto solo en el refresco, estado de error con «Reintentar». El gráfico tiene cruce y tooltip oscuros que siguen al cursor con suavizado, teclado (flechas, Inicio, Fin) y la tabla `sr` como alternativa textual. El texto accesible de cada número es un `.sr` fijo (el conteo vive en un span `aria-hidden`).
- Con `prefers-reduced-motion` o sin JS la pantalla es idéntica y completa: todo viene en el HTML.

### CSS y rendimiento

- Primera pantalla de `/`: 44.996 → **44.618 B** (tope 45.000, sin subirlo): se movieron los diálogos de `panel.css` a `ux.css` (diferida), las reglas de acceso a `auth.css`, `.legend` a `reportes.css`, se quitaron donut/barras/insight del dashboard y se unificaron `.strip` y `.duo`. `dashlive.css` (3,2 KB, 1,3 KB gzip, diferida y pedida por `dashboard.js`) lleva tooltips al pasar el ratón, pulso, pastilla, destellos y esqueletos.
- `Dashboard::build` con 5.000 leads: 2.437 → **~90 ms** (el mapa de calor recalculaba el inicio de semana por cada lead); con 120 leads 135 → ~20 ms. `pulse`: ~17 ms con 5.000 leads. Las consultas nuevas usan índice (`idx_tasks_open_due`, `idx_jobs_status_run`, `idx_referrals_bonus`, `idx_leads_hot_contact`).

### Q6-I: `/resultados` (lector de NorthStar) con las mismas piezas

La vista del rol `viewer` recibió las piezas visuales y de movimiento reutilizables, **solo sobre los agregados que ya mostraba** (`Reports::forViewer`): ninguna función nueva consulta la base, así que nada puede tocar un nombre, teléfono, correo, USDOT ni id de lead. No recibe «siguiente mejor acción» ni nada del administrador (ni KPI de calientes sin contactar o primera respuesta).

- **Qué lleva.** Subtítulo-conclusión (`Services/ResultadosBoard::headline`), cinco tarjetas con número protagonista (decimal/unidad atenuados, conteo de 800 ms; el texto accesible final vive en `.sr`), medidor de 28 rayas (calificados y presentados sobre los recibidos), microlínea (recibidos y firmados por día o semana), variación con base mínima, gráfico de tendencia con etiquetas directas en lugar de leyenda, **una anotación calculada** (el pico real de los recibidos, o «+N frente al día/semana anterior» si el periodo en curso ya es el máximo; con 8 leads o más repartidos en 3 periodos), línea punteada de promedio solo con 4 periodos con datos (si el rango llega hasta hoy, el último no entra), cruce con tooltip (ratón, toque y teclado) con la variación de los recibidos, nota del embudo («Mayor caída: de X a Y (−N leads)», en conteos), entrada coreografiada una sola vez (tarjetas con 50 ms de desfase, barras que crecen, línea que se dibuja, filas con fundido, máximo 10) y estados vacíos con el mismo lenguaje (estrella + titular + una frase) en el embudo, la tendencia, los canales y las pérdidas.
- **Base mínima (5).** Variación de recibidos, calificados, presentados y firmados con `Dashboard::deltaCount` (con menos de 5 en el periodo previo, «−2 firmados», nunca «−50 %»); el costo por firmado compara en % solo con 5 o más firmados en AMBOS periodos («Pocos firmados para comparar»); «% de los recibidos» pasa a «3 de 4 recibidos» con menos de 5 recibidos; el embudo dice «3 de 4 de la etapa anterior» cuando la etapa previa tiene menos de 5 y calla el % del total con menos de 5 recibidos; los motivos de pérdida no muestran % con menos de 5 pérdidas. `/reportes` y los informes NO cambian (los parciales reciben `$minBase`, que por omisión es 0).
- **Reglas del subtítulo** (cada frase es opcional): 1) «El mes pasado llegaron **N leads**», con «(+12.5% frente al mes anterior)» solo con 5 o más en el periodo previo; sin leads, «Aún no hay leads del mes pasado; en el periodo anterior llegaron N» o, sin nada, «Cuando llegue el primero aparecerá aquí»; 2) «De ellos, **21 calificados**, **7 presentados** y **2 firmados**» (ceros dichos con palabras: «ninguno firmado»); 3) canal: «**X** califica mejor (**62%** calificados)» con 2 o más canales de 5 o más leads y 15 puntos o más de diferencia, o «Todos vienen de **X**» / «**X** es el canal principal (**a de n**)»; 4) una sola de: «El costo por firmado fue de **$N**» o el motivo de pérdida más frecuente (2 o más y sin empate).
- **Sin refresco y sin endpoint propio (decisión).** `/resultados` es un informe por rango (30 días, mes pasado, 90 días, año), no un tablero en vivo: un refresco no aporta valor y abriría una superficie nueva para el lector. `resultados.js` y `dashfx.js` no hacen ninguna petición ni usan almacenamiento (lo comprueba la prueba 38); el 403 del lector en `/ajax/dashboard` sigue siendo lo único que ve si lo intenta.
- **JS.** `dashboard.js` se partió: lo visual (conteo, rayas, microlínea, línea que se dibuja, cruce con tooltip, pastilla, `grow`, `rowsIn`, carga de la hoja diferida con su propio cargador porque el lector no tiene `ux.js`) vive en `dashfx.js` (expone `NS.fx`, sin red, 11,2 KB, 4,4 KB gzip); `dashboard.js` conserva la coreografía y el refresco del administrador (17,2 a 8,3 KB; 6,1 a 3,3 KB gzip) y `resultados.js` (1,6 KB, 0,9 KB gzip) la de esta pantalla. El administrador carga `peek, dashfx, dashboard`; el lector, `dashfx, resultados`. Con nonce, sin librerías y sin estilos en línea.
- **CSS.** Nueva `viz.css` (3,5 KB, 1,4 KB gzip) con lo que ya compartían: botón «i», microlínea, subtítulo, rayas, tarjetas KPI y gráfico anotado, movido desde `dashboard.css` sin cambiarlo. Primera pantalla de `/`: **44.718 B** (antes 44.618; tope 45.000, sin subirlo) y de `/resultados`: **42.143 B** (antes 38.628). Las clases nuevas de la pantalla (`.kpis--rs`, `.kpi__s`, `.kpi__v--na`, `.fn-note`, `.rs-meta`) están en `reportes.css`; el movimiento y los tooltips usan la misma hoja diferida `dashlive.css` (`.lc--d .lc__tip .sw--ops` se arregló: la muestra del primer color era invisible sobre el tooltip oscuro). `/reportes`, que no carga `viz.css`, quedó igual (38.628 B).
- **Pruebas** (`tests/cases/38_resultados_q6i.php`, 67): subtítulo con todas las variantes (base vacía, un solo lead, ceros, una sola fuente, canales parejos o de menos de 5, empate de pérdidas, rango desconocido), tarjetas con base mínima, anotación y promedio (rango cerrado y en curso), nota del embudo, lector sin ninguna cadena personal en el HTML ni en el JSON del gráfico, sin enlaces a leads, 403 en `/ajax/dashboard`, HTML completo sin JS con la base vacía y con datos, hojas y tope de CSS, JS sin red ni estilos en línea.

### Pendiente

- Firefox y Safari sin probar. El destello del delta usa `transform` sobre un pseudo-elemento y no se probó con lector de pantalla.
- Q6-I: `/resultados` probado solo en Chromium (a 1440, 1024, 834 y 390, claro y oscuro, con base de demostración y vacía). Con series diarias muy irregulares la anotación del pico puede cruzarse con la línea (se lee gracias al halo del texto); el tooltip no se probó con lector de pantalla.


## 25. Rediseño minimalista del panel (R1): neutros cálidos, un acento y Geist

Carlos pidió un panel «minimalista y limpio», sin glassmorphism y con solo colores y tipografías que tengan sentido en un CRM profesional (autorizó cambiar paleta, fuentes y componentes del PANEL; el sitio público conserva su identidad). R1 cubre los fundamentos y las pantallas clave; R2 pule el resto. **Sin cambios de lógica, seguridad ni datos.** Referencia: skill «dashboards-vivos» (neutros cálidos, un acento, números protagonistas, microvisualizaciones, movimiento sobrio).

### Qué cambió

- **Tokens** (§15): paleta cálida de dos temas, un acento (`--accent`, antes `--navy-2`/`--cyan`), semánticos solo para estados, radios (`--r-card` 14, `--r-ctl` 8, `--r-pill` solo para estados pequeños), escala tipográfica (`--fs-*`) y alto de cabecera `--top-h` (56 px; el vistazo, el panel de reglas y los chips del tablero lo usan para su `top`). Variables renombradas en todas las hojas: `--navy-2`/`--navy-3` → `--accent`/`--accent-hover`, `--cyan*` → `--accent*`, `--on-fill` → `--on-accent`; retiradas `--navy*`, `--on-navy*`, `--urgent-bg` y los 8 pares de color de avatar (un solo `--av`).
- **Fuentes:** Geist y Geist Mono locales; fuera Barlow, Barlow Condensed y JetBrains Mono (72.356 → 52.528 B). La mono quedó solo para IDs (`LD-0142`), metadatos, ejes de gráficos y atajos; cifras, puntuaciones, contadores y paginación pasaron a Geist con `tabular-nums`. Las etiquetas ya no van en mayúsculas mono espaciadas: texto de 13 px en gris (los literales en mayúsculas de las vistas se pasaron a frase normal).
- **Componentes:** botón primario relleno de 40 px (sin la celda circular con flecha, que se retiró del marcado; 44 px en móvil), un solo primario por vista («Nuevo lead»; «Exportar CSV» pasó a secundario), segmented control con pastilla blanca, pestañas de temperatura con tinte del acento al seleccionar, tablas con cabecera de 13 px sin fondo y filas de 56 px (sin fondo de color en filas urgentes: la temperatura y la etapa son un punto de 6 px + texto), chips sin borde ni fondo, etiquetas del tablero como texto gris separado por puntos, KPIs de 20 px de relleno con número de 44 px, avatares neutros, diálogos y menús sólidos con sombra mínima, campos de 40 px con borde ≥ 3:1 y anillo del acento.
- **Shell:** barra lateral de 248 px sobre el lienzo (selector de espacio de trabajo con el isotipo, búsqueda con atajo, grupos con etiquetas pequeñas, contadores grises o del acento si requieren acción, elemento activo con fondo de tarjeta, tarjeta de estado del sistema y usuario); **riel de 68 px** de 761 a 1099 px y al plegar (≥ 1100), **cajón** en ≤ 760 px con barra inferior. La cabecera ya no lleva buscador ancho ni avatar (el usuario vive en la barra lateral; en móvil hay un icono de búsqueda).
- **Pantallas clave rehechas:** dashboard (`/`), lista de leads, tablero, ficha y vistazo rápido, y las pantallas sin sesión (una tarjeta centrada sobre el lienzo, sin panel lateral). El resto hereda los tokens y los componentes y está comprobado sin roturas; su pulido manual es R2.
- **Tablero:** columnas con fondo y sin borde, tarjetas planas con borde de 1 px, puntuación como número con barra de 4 rayas en gris.
- **Gráficos:** serie principal en el acento y la secundaria en gris; barra segmentada con paleta categórica apagada (5 fuentes); mapa de calor de un solo tono; el tooltip oscuro se conserva; el destello de carga ya no usa degradado.

### Qué se mantiene

Toda la lógica, rutas, endpoints, CSP y autorización por rol; el movimiento de Q6 (entrada, conteos, líneas dibujadas, punto «en vivo», refresco cada 60 s) con `prefers-reduced-motion`; el reparto de CSS por pantalla con hojas diferidas.

### Medidas

- Fuentes: 72.356 → 52.528 B (−27 %).
- CSS de la primera pantalla (sin fuentes), antes → después: `/` 41.983 → 42.207 B · `/leads` 35.435 → 35.423 · `/pipeline` 39.973 → 39.855 · `/leads/{id}` 39.651 → 39.921 · `/tabla` 38.576 → 38.476 · `/calendario` 40.199 → 40.451 · `/config` 36.141 → 36.391 (todas ≤ 45.000 con `fonts.css`). `/reportes` carga ahora también `viz.css` (el gráfico con etiquetas finales la necesitaba y sus etiquetas se salían de la tarjeta).
- Accesibilidad: axe-core (reglas WCAG 2 A y AA y 2.1 AA) sin violaciones en `/`, `/leads`, `/pipeline`, `/leads/{id}` y `/login`, claro y oscuro, a 1440 y 390 px. Contraste medido de los pares de §15 y barrido de contraste por texto en las pantallas de administración (solo quedan controles desactivados, exentos). No se pudo correr Lighthouse (no hay binario y el panel exige sesión con 2FA).

### Pruebas

Suite en 3239 pruebas. Se actualizaron las que comprobaban literales en mayúsculas de títulos (`'ESTADO DEL SISTEMA'` → `'Estado del sistema'`, etc.; no se debilitó ninguna comprobación) y dos de clases: `Ui::avatar` ya no emite `av-N` y `Ui::score` solo emite `score--dq` (las pruebas `36_css_classes` y `35_modes_q5c` exigen que toda clase usada tenga regla).

### Pendiente para R2 (priorizado)

1. Calendario: chips de evento con tintes por tipo (`--ev-*`), puntos y cabeceras de semana.
2. Configuración: las 12 pestañas (tarjetas de estado, interruptores, rejillas de campos numéricos) y el editor de reglas (`rules.css`, `cfg-reglas.css`).
3. Reportes y `/resultados`: tarjetas KPI de `reportes.css`, embudo y tablas.
4. Tareas, Prospectos, Referidos, Vacantes, Comunicaciones: filas con iconos en círculos, `steps`, tarjetas de prospecto.
5. Cronología y Actividad: iconos de evento con tinte, píldoras de acción.
6. Páginas públicas (`/c`, `/u`, `/invite`) y `setup.php`: ya heredan la tarjeta centrada; falta revisar textos y estados.
7. Selects nativos (el `<select>` sigue con el aspecto del navegador) y el tema claro de la barra de desplazamiento.
8. Firefox y Safari sin probar; el rail de 761 a 1099 px solo probado en Chromium.
