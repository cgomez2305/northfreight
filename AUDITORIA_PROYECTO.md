# Auditoría del proyecto NorthStar Freight Solutions

Fecha: 2026-11-22 · Modo: AUDITAR_Y_MEJORAR · Rama de trabajo: `claude/tender-brown-canrk9` (commits locales, **sin push ni despliegue**).

## 1. Resumen

El proyecto (sitio público PHP sin framework + mini-CRM de captación en `captacion/`) está en buen estado de seguridad: **no se encontraron vulnerabilidades críticas ni altas**. La revisión confirmó 5 defectos de severidad media o baja (4 en el CRM y 1 preventivo en la exportación del sitio), que quedaron **corregidos y verificados con pruebas de regresión** (comprobadas contra el código anterior: fallan con él y pasan con el nuevo), y dejó documentados otros 14 puntos de menor severidad, de diseño o informativos para decidir. La suite del CRM pasó de 3.247 a **3.263 pruebas, 0 fallos**.

## 2. Alcance real y limitaciones

**Revisado (lectura de código + ejecución local):**
- Sitio público: `index.php` (enrutado, rutas bloqueadas, traversal, redirecciones, csp-report), `app/helpers.php` (cabeceras, CSP, render), `app/forms/{lib,contact,download,lead}.php`, `app/i18n.php` (filtro de traducción), `app/data/jobs.php`, `bin/leads-export.php`, `.htaccess` (raíz y subdominio), JS propio (`assets/js/*.js` sin `dist/`), SRI de GSAP, escaneo de secretos en archivos versionados, `composer audit`.
- Mini-CRM `captacion/` (279 archivos PHP, ~39.000 líneas): por **dos revisores independientes en solo lectura** (A: cuentas, sesión, 2FA, autorización por rol, CSRF, SQL, XSS, copias, `setup.php`; B: webhooks, API interna, SSRF, correo, consentimiento y bajas, colas, puente sitio→CRM). Yo **verifiqué personalmente** cada hallazgo antes de corregirlo (B1, B3, A3, A4); el resto figura como «según revisor» y no se re-verificó.

**Excluido o no verificable:**
- `vendor/` (solo versión): PHPMailer 6.12.0 resuelto en `composer.lock`; `composer audit` del 2026-11-22: «No security vulnerability advisories found».
- Comportamiento del servidor real (Hostinger/LiteSpeed): proxy delante, `REMOTE_ADDR`, `TRUSTED_PROXY_HEADER`, permisos de `storage/`, HSTS, raíz del subdominio. `.htaccess` no se probó con Apache (local usa `php -S`).
- Formatos reales de Meta, Cal.com, Twilio y Graph API (no hay acceso a servicios externos; el propio proyecto lo declara).
- Módulos del CRM con lectura parcial por el revisor B: `ProspectImport`, `Costs`, `Reports`, `ReportJobs`, `HandoffCard`, `EmailTarget`, `DispatchCrmTarget`, `Sequences::reactivationScan`; y `InviteController`/`Invites` por el A.
- JS del panel (`captacion/public/assets`): el servidor escapa el HTML que inserta con `innerHTML`; no se auditó línea a línea.
- Rendimiento: solo mediciones locales (ES vs EN 15 ms vs 12 ms de media); sin pruebas de carga.

Esto **no es una garantía de seguridad total**: es una revisión estática y dinámica local acotada a lo descrito.

## 3. Mapa breve

- **Sitio**: `index.php` (front controller) → `app/routes.php` + `app/helpers.php` → `templates/` ; POST a `app/forms/{contact,download,lead}.php` con CSRF sin sesión (HMAC), honeypot, tiempo mínimo y límite por IP; los leads se guardan en `storage/leads/*.jsonl` y, si `CAPTACION_ENABLED=1`, se entregan en proceso al CRM. Español bajo `/es/` por filtro de traducción de la salida (`app/i18n.php`).
- **CRM** (`captacion/`, subdominio propio): panel con login Argon2id + TOTP, roles admin/viewer, sesiones por archivo, CSRF por sesión; `/api/lead.php` y `/internal/*` con Bearer; webhooks entrantes con HMAC e idempotencia (`webhook_events`); cola de trabajos por cron con `flock`; MariaDB/PDO con sentencias preparadas.
- **Activos sensibles**: datos personales de leads (nombre, teléfono, correo), consentimientos, registro de actividad (IP, correos), copias de la base, secretos de integraciones (cifrados), cuentas admin.

## 4. Línea base (antes de tocar nada)

| Comprobación | Resultado |
|---|---|
| `php -l` de todos los `.php` fuera de `vendor/` | sin errores |
| `php captacion/tests/run.php` | 3.247 ok, 0 fallos |
| `composer audit` | sin avisos |
| SRI de GSAP/ScrollTrigger 3.12.5 frente a cdnjs | coinciden |
| 7 páginas (EN/ES, con JS) con CSP Report-Only | 0 violaciones |
| Recorrido de las rutas EN/ES, sitemap, llms y robots: `storage/error.log` | no se crea (sin errores ni deprecaciones) |
| Escaneo de secretos en archivos versionados | sin coincidencias |
| Pruebas de traversal, `//`, `%00`, CRLF en ruta, métodos, Origin cruzado | todas rechazadas o saneadas |

## 5. Hallazgos

Leyenda de estado: **CV** = corregido y verificado · **P** = propuesto/pendiente de decisión · **I** = informativo.

| ID | Categoría | Sev. | Certeza | Evidencia | Estado |
|---|---|---|---|---|---|
| **B1** | Seguridad (SSRF) | Media | Confirmado (ejecución local) | `captacion/src/Services/Handoff.php:99` (`urlProblem`): `$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) !== $host ? gethostbyname($host) : null)`; si `$ip` queda `null` la URL pasa. Probado en PHP 8.3: `https://[::1]/`, `[::ffff:127.0.0.1]`, `0x7f.1` y dominios sin resolver no se rechazaban; además `FILTER_FLAG_NO_RES_RANGE` no marca `::ffff:127.0.0.1`. | **CV** |
| **B3** | Cumplimiento / fiabilidad | Baja-Media | Confirmado (código + prueba) | `captacion/src/Controllers/PublicController.php` (`unsubscribe`): el límite de 60 peticiones/h por IP se aplicaba **antes** de verificar el token y contaba GET y POST; el `List-Unsubscribe` de un clic y los escáneres salen de IP compartidas → la baja podía devolver 429 (CAN-SPAM). | **CV** |
| **A3** | Seguridad (exfiltración tras robo de sesión) | Baja | Confirmado | `LeadsController::export` y `bulk(do=export)`: hasta 10.000 leads con PII sin límite; `ActividadController::export` (IP y correos) sin `Reauth::fresh()`, a diferencia de copias y retención. | **CV** |
| **A4** | Privacidad | Baja | Confirmado | `captacion/src/Services/Auth.php:75-79`: `substr($email,0,190)` se guardaba en `login_attempts` y `activity_log` aunque no fuera un correo; una contraseña pegada en el campo de correo quedaba en claro (y en copias y CSV). | **CV** |
| S-1 | Seguridad (defensa en profundidad) | Baja | Confirmado | `bin/leads-export.php:73`: no neutralizaba celdas que empiezan por tabulador o retorno (los formularios ya eliminan caracteres de control, por eso es preventivo). | **CV** |
| A1 | Disponibilidad | Baja-Media | Confirmado (revisor, `Auth.php:44-49`) | Bloqueo de login por correo (5 fallos / 15 min desde cualquier IP): un anónimo puede mantener bloqueada a la única cuenta admin. | P |
| A2 | Configuración | Baja | Probable (depende del servidor) | `ClientIp.php:20-24`: con `TRUSTED_PROXY_HEADER=HTTP_X_FORWARDED_FOR` toma el PRIMER valor (controlable por el cliente si el proxy añade en vez de sobrescribir); sin la variable, detrás de un proxy `REMOTE_ADDR` sería la IP del proxy. | P |
| A5 | Abuso de recursos | Baja | Probable | Cada GET anónimo a `/login` crea un archivo de sesión (`AuthController.php:22`, `Session::csrf()`); sin límite por IP. | P |
| A6 | Defensa en profundidad | Informativa | Confirmado | `db/schema.sql:14`: el rol por defecto de `users` es `admin` (hoy todo INSERT fija el rol). | P |
| B2 | Lógica de negocio / legal | Media-Baja | Confirmado (diseño) | `LeadIngest.php:163,225` + `app/forms/lead.php`: el consentimiento (correo/SMS/llamada) se registra con solo marcar la casilla y se asocia por teléfono/correo a un lead existente; sin doble opt-in, un tercero puede registrar un consentimiento «válido» a nombre de otra persona (hoy las secuencias están apagadas por defecto). | P |
| B4 | Fiabilidad | Baja | Confirmado | `WebhookController.php:241`, `InternalController.php:124`: un evento n8n rechazado (`lead_not_found`, etc.) queda reclamado en estado `error` y su reintento con el mismo `event_id` responde `duplicate`. Es una decisión de diseño (traza de auditoría) que conviene revisar. | P |
| B5 | Abuso de recursos | Baja | Confirmado | `RateLimiter.php:32-61`: un archivo por IP y bucket, creado antes de autenticar; limpieza solo al 1 % de los hits. | P |
| B6 | Configuración | Baja-Info | Media | Un único `INTERNAL_API_TOKEN` para `/api/lead.php` y `/internal/*`; secretos de webhook con mínimo de 8 caracteres (`Webhook.php:23`). | P |
| B7 | Lógica de negocio | Baja | Media | `Appointments.php:130,220-228`: una reserva pública de Cal.com con el correo/teléfono de un lead existente mueve su etapa y crea tareas. | P |
| S-2 | Seguridad | Baja | Confirmado | `app/forms/lib.php:127-128`: si `APP_SECRET` no está definido y `storage/` no es escribible, la clave HMAC es derivable (`NS_ROOT`, `php_uname`): tokens CSRF y de «gracias» falsificables (impacto mínimo: son tokens anti-bot). | P |
| S-3 | Abuso de recursos | Baja | Confirmado | `index.php:143-151`: `/csp-report/` acepta POST anónimos (máx. 4 KB y rotación a 1 MB): puede desplazar informes legítimos. | I |
| S-4 | Diseño | Informativa | Confirmado | El «CSRF» de los formularios públicos es un token sin sesión (HMAC con hora): sirve de anti-bot, no de protección CSRF; la protección real es el control de `Origin` en `index.php` y que no hay acciones autenticadas. | I |
| S-5 | Privacidad | Informativa | Confirmado | `storage/mail.log` y `storage/leads/*.jsonl` guardan datos personales sin política de retención (`storage/` no se sirve por web). | P |
| S-6 | Configuración | Informativa | Verificado | CSP en modo Report-Only: 0 violaciones en 7 páginas EN/ES con JS activo; es viable pasar a modo bloqueo. Las 317 entradas antiguas de `storage/csp.log` provienen de estilos inyectados por scripts de prueba. | P |

## 6. Cambios realizados (5 commits locales, sin push)

| Commit | Cambio | Archivos |
|---|---|---|
| B1 | `hostIsInternal()`/`ipIsInternal()`: quita corchetes IPv6, evalúa la IPv4 embebida en `::ffff:*`, rechaza formas numéricas (`0x7f.1`, `127.1`, `2130706433`), resuelve **todas** las IP (A y AAAA) y rechaza lo que no resuelve; el webhook se **revalida al enviar** (`WebhookTarget::send`). | `captacion/src/Services/Handoff.php`, `…/Handoff/WebhookTarget.php`, `tests/cases/18_handoff.php` |
| A4 | Lo que no es un correo se normaliza a `''` antes de contar, registrar o guardar el intento. | `captacion/src/Services/Auth.php`, `tests/cases/08_panel_auth.php` |
| B3 | Se verifica la firma del token **antes** del límite; token válido: techo de 2.000/h/IP (`LIMIT_VALID`); token inventado: 60/h como antes. | `captacion/src/Controllers/PublicController.php`, `tests/cases/21_comms.php` |
| A3 | `/actividad/export` exige `Reauth::fresh()`; las tres salidas CSV de leads comparten un tope de 20/h/usuario (`exportBudget`, mismo tope que `/tabla/export`). | `captacion/src/Controllers/{ActividadController,LeadsController}.php`, `tests/cases/{15_actividad,11_panel_q2a}.php` |
| S-1 | Neutraliza celdas que empiezan por tabulador o retorno. | `bin/leads-export.php` |

**Cambio de comportamiento visible (restricción de un comportamiento inseguro):** exportar el registro de actividad ahora pide confirmar contraseña y código 2FA (como ya ocurría con las copias y la retención); una persona que exporte más de 20 veces por hora los leads verá un aviso. Los usos legítimos (exportar con la identidad confirmada, hasta 20 veces/h) siguen funcionando y están probados.

No se tocaron reglas de negocio, contratos de API ni la interfaz pública del sitio.

## 7. Validación

| Comando | Resultado |
|---|---|
| `php captacion/tests/run.php` (suite completa, MariaDB temporal) | **3.263 ok, 0 fallos** (línea base 3.247; +16 pruebas de regresión) |
| Regresiones **contra el código anterior** (se restauró el archivo del commit previo, se ejecutó el bloque y se volvió al nuevo) | **B1**: 6 fallos (`[::1]`, `[::ffff:127.0.0.1]`, `[::ffff:7f00:1]`, `[fd00::1]`, `0x7f.1`, dominio sin resolver) · **A4**: 1 fallo · **B3**: 1 fallo («un token de baja VÁLIDO sigue funcionando») · **A3**: 3 fallos (2 en actividad, 1 en el tope de exportaciones). Con el código nuevo, todas pasan |
| Corrección de una prueba propia | la primera versión de la prueba A4 **no detectaba el defecto** (el intento caía en el bloqueo por IP de los fallos anteriores y no llegaba a registrarse); se detectó al contrastarla con el código anterior y se corrigió limpiando los intentos previos |
| `php -l` de los archivos modificados | sin errores |
| `bin/leads-export.php` con un JSONL sintético | las celdas `\t=1+1` y `=HYPERLINK(…)` salen con apóstrofo |

**Pendiente de comprobar:** no se probó contra DNS real ni contra servicios externos; no se ejecutó Lighthouse; el `.htaccess` no se probó con Apache. La resolución DNS del arreglo B1 depende de la salida DNS del servidor (si falla, un dominio válido se rechaza al guardar y al enviar; es el comportamiento seguro elegido).

## 8. Riesgos residuales y siguientes acciones (por impacto)

1. **B2 (consentimiento sin verificar)**: decidir con el abogado/TCPA si hace falta doble opt-in por correo antes de encender las secuencias; mientras estén apagadas, el riesgo es bajo. Es una decisión de negocio, no se aplicó.
2. **A1 + A2**: confirmar en el servidor real qué cabecera sobrescribe el proxy, fijar `TRUSTED_PROXY_HEADER` correctamente (si es XFF, tomar el último valor de confianza) y cambiar el bloqueo de login a par (correo, IP) con tope alto por correo.
3. **B1 (residual)**: queda una ventana mínima de *DNS rebinding* entre la comprobación y la conexión; se cierra fijando la IP resuelta con `CURLOPT_RESOLVE` en `Http::request` (cambio de la capa HTTP, no aplicado).
4. **B6**: separar el token de `/api/lead.php` y `/internal/*`, subir el mínimo de los secretos de webhook a 32 y que la API ignore `consent.channels`/`consent.at` del cuerpo.
5. **S-6**: pasar la CSP a modo bloqueo tras una semana sin violaciones en producción.
6. **B4, B5, B7, A5, A6, S-2, S-5**: mejoras de fiabilidad y defensa en profundidad; ninguna es explotable hoy con impacto alto.

## 9. Observaciones sobre estructura (sin cambios, a propósito)

- `app/helpers.php` (≈930 líneas) mezcla rutas, esquemas JSON-LD, cabeceras y render, y las plantillas contienen datos en arreglos. La convención del proyecto (documentada en `CLAUDE.md`) lo asume y las pruebas del CRM no lo cubren: **no se justifica una reorganización ahora**; si el sitio crece, el primer corte natural es separar `app/security.php` (cabeceras y CSP) y `app/schema.php` (JSON-LD).
- El sitio público no tiene pruebas automatizadas propias (solo `bin/i18n.php check/audit` y comprobaciones manuales); las rutas, las plantillas y los formularios solo se cubren indirectamente por la suite del CRM (puente `app/forms/lead.php`). Es la mayor deuda de pruebas del repositorio.
