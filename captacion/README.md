# NorthStar Captación (mini-CRM) — fases 1 y 2

Aplicación PHP **aparte del sitio** (el sitio sigue sin base de datos) que capta, califica y da seguimiento a owner-operators, carriers pequeños y company drivers. La opera una sola persona (Carlos, ClikRank). Documento maestro: `sistema-captacion-choferes-northstar.md` v2.0.

**Qué incluye esta entrega (Q1):** base (estructura, migraciones, usuarios, login con 2FA, sesiones, CSRF, límites, registro de actividad, cola `jobs` + cron), motor de captación (validar, antispam, deduplicar, FMCSA, puntuar, encolar), alertas de Telegram (A2/A3), revocación STOP (A15), integración en proceso con el formulario del sitio y un panel mínimo.
**Qué NO incluye:** panel completo (kanban, ficha, reportes: Q2), WhatsApp/SMS/Cal.com/n8n (Q4), Meta y job boards (Q6), entrega a NorthStar y reportes (Q5).

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

Panel mínimo (captacion.northstarfreightsolutions.us): login + 2FA → estado de la cola, últimos 20 leads, ficha de solo lectura
```

### Estructura

```
captacion/
  public/            RAÍZ DEL SUBDOMINIO. index.php (panel), setup.php, api/lead.php, api/webhooks/ (vacío), assets/panel.css, .htaccess, robots.txt
  src/               bootstrap.php, Env.php, Db.php, Clock.php, Migrator.php, View.php · Controllers/ · Models/ · Services/ · Middleware/   (namespace Captacion\)
  views/             layout, login, twofa, setup, panel, lead, message
  db/                schema.sql (generado) · migrations/NNN_nombre.sql
  bin/               migrate.php · import-jsonl.php        (solo CLI)
  cron/              process_jobs.php (cada minuto) · fmcsa_daily.php (stub documentado)
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

Tipos de job de esta fase: `telegram_alert` (A2; `kind`: `hot`, `signed_reentry`), `telegram_alert_followup` (A3: a los 15 min, solo si no hay `first_contact_at`, el lead sigue caliente y en etapa `new`), `fmcsa_enrich`, `email_notify`.

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
- `activity_log`: altas, logins, fallos, bloqueos, códigos de recuperación usados, logouts. (Exportaciones y cambios de datos se registran cuando existan, en Q2.)
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
| Opcionales | `SESSION_IDLE_SECONDS` (28800) · `API_RATE_LIMIT` (120/h) · `INGEST_RATE_LIMIT` (5/h) · `JOBS_MAX_SECONDS` (50) · `TOTP_ISSUER` · `STORAGE_DIR` (solo pruebas) · `CAPTACION_ENV_FILE` (otra ruta del .env). |

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
   (A futuro, fase 6: `cron/fmcsa_daily.php`, hoy un stub documentado. No lo agregues todavía.)
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
11. **Backups:** el doc exige copia diaria de la base con retención de 30 días y otra fuera de Hostinger (hPanel → Copias de seguridad / `mysqldump` por cron). No está automatizado.

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
- Fase Q2: panel completo (bandeja, kanban, ficha, tareas, notas, motivos de pérdida, configuración de pesos).
