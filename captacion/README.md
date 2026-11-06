# NorthStar Captación (mini-CRM) — fases Q1, Q2a, Q2b, Q3, Q4a y Q4b

Aplicación PHP **aparte del sitio** (el sitio sigue sin base de datos) que capta, califica y da seguimiento a owner-operators, carriers pequeños y company drivers. La opera una sola persona (Carlos, ClikRank). Documento maestro: `sistema-captacion-choferes-northstar.md` v2.0.

**Qué incluye esta entrega (Q1):** base (estructura, migraciones, usuarios, login con 2FA, sesiones, CSRF, límites, registro de actividad, cola `jobs` + cron), motor de captación (validar, antispam, deduplicar, FMCSA, puntuar, encolar), alertas de Telegram (A2/A3), revocación STOP (A15), integración en proceso con el formulario del sitio y un panel mínimo.
**Q2a (panel con identidad NorthStar):** sistema de diseño, shell, login/2FA restyled, dashboard con datos reales, bandeja de leads con filtros y acciones masivas, ficha del lead completa (etapas, notas, tareas, llamadas, consentimientos) y datos de demostración locales (ver §15).
**Q2b (panel de trabajo, §17 y §16):** kanban del pipeline con arrastre, tareas y agenda, configuración (puntuación, alertas, costos por canal, integraciones, horario), registro de actividad y copias de seguridad.
**Q3 (entrega, reportes y correo, §18):** entrega de leads a NorthStar (correo y webhook firmado, confirmación sin login), rol de solo lectura para NorthStar, reportes, informes automáticos y plantillas y secuencias de correo.
**Q4a (captación saliente, §19):** prospectos FMCSA (llamadas en frío manuales), referidos y vacantes publicadas en el sitio.
**Q4b (canales externos, §20):** webhooks de Meta Lead Ads, Cal.com, Twilio y n8n, API interna para n8n, WhatsApp entrante, reactivación A11 y paquete de integración (`docs/`).
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

### Paleta (solo en `public/assets/panel.css`, `:root`)

Derivada del navy y el cian del sitio, con neutros de tinte azul. Contrastes medidos (WCAG, texto):

| Par | Contraste |
|---|---|
| Texto `#0A1B2E` / `#3B4D63` / `#586A80` sobre blanco | 17,4 / 8,7 / 5,5:1 |
| Texto terciario `#586A80` sobre el lienzo `#F1F4F8` | 5,0:1 |
| Enlaces y acentos `--cyan-ink #036E8A` sobre blanco / lienzo / tinte cian | 5,8 / 5,3 / 5,2:1 |
| Blanco sobre navy `#0A1D33` (botón primario, píldora activa) | 17,0:1 |
| Navy `#04101F` sobre cian `#06A6CC` (celda de flecha) | 6,7:1 |
| Caliente `#9A3407` / tibio `#045F78` / frío `#394D7C` / descartado `#4E5F73` sobre su fondo | 6,3 / 6,2 / 7,0 / 5,6:1 |
| OK `#17673C`, error `#B42318`, aviso `#8A4B0B` sobre su fondo | 6,1 / 5,8 / 6,1:1 |
| Iniciales de los 8 avatares sobre su fondo | 5,7 a 8,1:1 |

El cian `#06A6CC` solo va en rellenos, puntos y la línea de «company drivers» (`#0691B3`, 3,7:1 sobre blanco para gráficos). Los bordes de campos de formulario usan `#7D8EA5` (3,3:1). Foco: contorno de 2 px `--focus` (`#036E8A`). Significado constante: **temperatura** (caliente = único acento cálido, tibio = cian, frío = azul pizarra, descartado = gris) y **etapa** (punto de color en un chip neutro: gris → azul pizarra → cian → navy → verde al firmar → rojo apagado al perder).

### Tipografía y marca

Las del sitio, copiadas a `public/assets/fonts/` (woff2) con los respaldos de métricas ajustadas (`fonts.css`): Barlow (texto y números de UI), Barlow Condensed (títulos y cifras grandes de KPI), JetBrains Mono (rótulos, cabeceras de tabla, chips, ejes). Isotipo oscuro y claro en `public/assets/`. Botón primario navy con celda circular de flecha cian (solo `transform`), chips píldora, estrella de marca en estados vacíos y login.

### Pantallas y comportamiento

- **Shell**: barra lateral (Principal: Dashboard, Leads; Atajos: calientes pendientes, llegaron hoy, descartados; estado del sistema y usuario abajo), barra superior con migas, **buscador global** (`Ctrl/⌘ K` o `/`, panel de resultados con flechas/Enter, diálogo nativo `<dialog>`), reloj ET, **campana** (leads calientes sin contactar, trabajos fallidos, Telegram sin configurar, FMCSA pendiente/sin llave, cron detenido; la clave de cada aviso incluye su estado, así que uno resuelto y vuelto a ocurrir sale otra vez) y menú del usuario. En ≤ 1023 px la barra lateral es un cajón; en ≤ 760 px hay barra inferior (Inicio, Leads, Buscar, Menú) y la ficha trae su propia barra fija (Llamar, WhatsApp, Correo, Etapa).
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
