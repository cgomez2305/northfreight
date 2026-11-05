-- Esquema de referencia del mini-CRM (GENERADO por `php captacion/bin/migrate.php --schema`: concatena db/migrations/*.sql). La fuente de verdad son las migraciones.

-- ===== 001_core.sql =====
-- 001_core: modelo de datos del documento maestro (§13). utf8mb4, borrados logicos (deleted_at), JSON como tipo JSON
-- (en MariaDB 10.11 JSON es un alias de LONGTEXT con CHECK JSON_VALID; en MySQL 8 es nativo: el esquema carga igual en ambos).
-- Fechas en UTC. Sin DEFAULT en columnas JSON (MySQL 8 no los admite sin parentesis).

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret TEXT NULL,
  role ENUM('admin','viewer') NOT NULL DEFAULT 'admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_lead_id VARCHAR(40) NULL,
  profile ENUM('owner_operator','small_carrier','company_driver') NOT NULL,
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  last_name VARCHAR(100) NOT NULL DEFAULT '',
  phone_e164 VARCHAR(20) NULL,
  phone_raw VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  state CHAR(2) NULL,
  city VARCHAR(100) NULL,
  language ENUM('en','es') NOT NULL DEFAULT 'en',
  cdl_class VARCHAR(10) NULL,
  cdl_type ENUM('standard','non_domiciled','unsure') NULL,
  english_level ENUM('yes','basic','no') NULL,
  years_experience SMALLINT UNSIGNED NULL,
  equipment_types JSON NULL,
  truck_count SMALLINT UNSIGNED NULL,
  authority_status VARCHAR(20) NULL,
  usdot VARCHAR(10) NULL,
  authority_age_months SMALLINT UNSIGNED NULL,
  endorsements JSON NULL,
  contact_pref VARCHAR(20) NULL,
  best_time VARCHAR(20) NULL,
  job_slug VARCHAR(80) NULL,
  score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  temperature ENUM('hot','warm','cold','disqualified') NOT NULL DEFAULT 'cold',
  score_breakdown JSON NULL,
  disqualified_reason VARCHAR(80) NULL,
  pipeline CHAR(1) NOT NULL DEFAULT 'A',
  stage VARCHAR(30) NOT NULL DEFAULT 'new',
  lost_reason VARCHAR(60) NULL,
  lost_at DATETIME NULL,
  attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  source VARCHAR(40) NOT NULL DEFAULT 'web',
  utm JSON NULL,
  landing_page VARCHAR(300) NULL,
  referrer VARCHAR(300) NULL,
  referral_code VARCHAR(40) NULL,
  fmcsa_data JSON NULL,
  fmcsa_checked_at DATETIME NULL,
  flags JSON NULL,
  first_contact_at DATETIME NULL,
  handed_off_at DATETIME NULL,
  handoff_status ENUM('pending','signed','rejected') NULL,
  signed_at DATETIME NULL,
  external_crm_id VARCHAR(60) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_leads_site_lead_id (site_lead_id),
  KEY idx_leads_phone (phone_e164),
  KEY idx_leads_email (email),
  KEY idx_leads_stage_temp (stage, temperature),
  KEY idx_leads_source_created (source, created_at),
  KEY idx_leads_usdot (usdot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  payload JSON NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_lead (lead_id, created_at),
  CONSTRAINT fk_events_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE consents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  channel VARCHAR(20) NOT NULL,
  consent_text TEXT NOT NULL,
  consent_version VARCHAR(20) NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  granted_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  revoked_via VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_consents_lead_channel (lead_id, channel),
  CONSTRAINT fk_consents_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prospects_fmcsa (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usdot VARCHAR(10) NOT NULL,
  legal_name VARCHAR(190) NULL,
  dba VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  state CHAR(2) NULL,
  authority_granted_at DATE NULL,
  fleet_size SMALLINT UNSIGNED NULL,
  cargo JSON NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'to_call',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_call_at DATETIME NULL,
  converted_lead_id BIGINT UNSIGNED NULL,
  do_not_call TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prospects_usdot (usdot),
  KEY idx_prospects_status_call (status, last_call_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  lead_id BIGINT UNSIGNED NULL,
  prospect_id BIGINT UNSIGNED NULL,
  due_at DATETIME NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 2,
  done_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_tasks_due (done_at, due_at),
  CONSTRAINT fk_tasks_lead FOREIGN KEY (lead_id) REFERENCES leads (id),
  CONSTRAINT fk_tasks_prospect FOREIGN KEY (prospect_id) REFERENCES prospects_fmcsa (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  calcom_id VARCHAR(60) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'booked',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_appointments_lead (lead_id, starts_at),
  CONSTRAINT fk_appointments_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('whatsapp','email','sms','call') NOT NULL,
  direction ENUM('in','out') NOT NULL,
  body TEXT NULL,
  template_key VARCHAR(60) NULL,
  provider_id VARCHAR(80) NULL,
  status VARCHAR(20) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_lead (lead_id, created_at),
  CONSTRAINT fk_messages_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sequence_enrollments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  sequence_key VARCHAR(60) NOT NULL,
  step SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  next_run_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sequences_due (status, next_run_at),
  CONSTRAINT fk_sequences_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE referral_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_name VARCHAR(120) NOT NULL,
  owner_lead_id BIGINT UNSIGNED NULL,
  code VARCHAR(40) NOT NULL,
  bonus_amount DECIMAL(10,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_referral_code (code),
  CONSTRAINT fk_refcodes_lead FOREIGN KEY (owner_lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE referrals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referral_code_id BIGINT UNSIGNED NOT NULL,
  lead_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  bonus_status VARCHAR(20) NOT NULL DEFAULT 'none',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_referrals_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes (id),
  CONSTRAINT fk_referrals_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_postings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description_html MEDIUMTEXT NULL,
  employment_type VARCHAR(30) NULL,
  location VARCHAR(120) NULL,
  pay_min DECIMAL(10,2) NULL,
  pay_max DECIMAL(10,2) NULL,
  pay_unit VARCHAR(20) NULL,
  requirements JSON NULL,
  valid_through DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_job_postings_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE channel_costs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(40) NOT NULL,
  period CHAR(7) NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_costs (source, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  `key` VARCHAR(100) NOT NULL,
  value JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cola de trabajos (§3). status: pending -> running -> done | pending (reintento) | failed. 'parked' = registrado y NO ejecutado (p. ej. sms_backup_alert, pendiente de Q4).
-- dedupe_key UNIQUE evita encolar dos veces la misma accion (p. ej. 'telegram_alert:42').
CREATE TABLE jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(40) NOT NULL,
  payload JSON NULL,
  status ENUM('pending','running','done','failed','parked') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  run_at DATETIME NOT NULL,
  dedupe_key VARCHAR(120) NULL,
  last_error VARCHAR(500) NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jobs_dedupe (dedupe_key),
  KEY idx_jobs_status_run (status, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id VARCHAR(40) NULL,
  changes JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_created (created_at),
  KEY idx_activity_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 002_auth.sql =====
-- 002_auth: 2FA TOTP (secreto cifrado con sodium y APP_KEY), codigos de recuperacion (solo hash) y registro de intentos de login.

ALTER TABLE users
  ADD COLUMN totp_confirmed_at DATETIME NULL AFTER totp_secret,
  ADD COLUMN totp_last_step BIGINT NULL AFTER totp_confirmed_at,
  ADD COLUMN password_algo VARCHAR(20) NULL AFTER password_hash;

CREATE TABLE recovery_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recovery_user_hash (user_id, code_hash),
  CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kind: password | totp | setup. Solo cuentan los fallos (success = 0) para el limite de 5 intentos cada 15 minutos por IP y por correo.
CREATE TABLE login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'password',
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_attempts_ip (ip, created_at),
  KEY idx_attempts_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 003_settings_seed.sql =====
-- 003_settings_seed: pesos y umbrales de puntuacion (documento maestro §6) y ajustes PROVISIONALES hasta que NorthStar confirme.
-- Se editan desde Configuracion (fase Q2) sin tocar codigo. Los valores de abajo deben coincidir con Captacion\Services\Scoring::DEFAULTS (lo comprueba tests/run.php).
-- Tramos: {"lt": N, "points": P} = "menor que N"; "lt": null = el resto.

INSERT INTO settings (`key`, value) VALUES ('scoring.owner', '{"cdl_standard":20,"english_yes":15,"english_basic":5,"fmcsa_authority_active":15,"fmcsa_insured":10,"authority_age":[{"lt":6,"points":5},{"lt":24,"points":10},{"lt":null,"points":10}],"dispatched_equipment":10,"trucks":[{"lt":2,"points":5},{"lt":6,"points":10},{"lt":null,"points":15}],"experience_min_years":2,"experience":5,"referral":5,"complete":5,"complete_fields":["name","phone","email","state","language","truck_count","equipment_types","years_experience","authority_status","cdl_type","english_level"]}');

INSERT INTO settings (`key`, value) VALUES ('scoring.driver', '{"cdl_a_standard":25,"english_yes":15,"english_basic":5,"experience":[{"lt":1,"points":0},{"lt":3,"points":10},{"lt":null,"points":20}],"hiring_state":15,"endorsement":5,"referral":10,"complete":5,"complete_fields":["name","phone","email","state","language","years_experience","cdl_type","english_level"]}');

INSERT INTO settings (`key`, value) VALUES ('scoring.temperature', '{"hot_min":70,"warm_min":40}');

-- PROVISIONAL (documento maestro §6, [CONFIRMAR CON CLIENTE]): equipos que NorthStar despacha. Sembrado con los 6 equipos del §4.
INSERT INTO settings (`key`, value) VALUES ('dispatched_equipment', '["dry-van","reefer","flatbed","box-truck","hotshot","power-only"]');

-- PROVISIONAL: estados donde NorthStar contrata company drivers (lista vacia = nadie recibe los +15 hasta que el cliente la confirme).
INSERT INTO settings (`key`, value) VALUES ('hiring_states', '[]');

-- PROVISIONAL: experiencia minima (anos) de un company driver; por debajo se marca la bandera below_min_experience (no descarta).
INSERT INTO settings (`key`, value) VALUES ('driver_min_years', '1');

INSERT INTO settings (`key`, value) VALUES ('provisional', '["dispatched_equipment","hiring_states","driver_min_years","scoring.driver.experience"]');

-- ===== 004_panel.sql =====
-- 004_panel: tablas y columnas del panel Q2a (notas, avisos leidos, llamadas manuales, indices de bandeja y dashboard).

CREATE TABLE lead_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_notes_lead (lead_id, deleted_at, created_at),
  CONSTRAINT fk_notes_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avisos de la campana marcados como leidos por usuario. notif_key lleva el estado (p. ej. 'failed_jobs:3'): si el estado cambia, el aviso vuelve a salir.
CREATE TABLE notification_reads (
  user_id BIGINT UNSIGNED NOT NULL,
  notif_key VARCHAR(120) NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, notif_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE messages ADD COLUMN duration_sec SMALLINT UNSIGNED NULL AFTER status;
ALTER TABLE messages ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER duration_sec;

-- 'cancelled': job anulado a mano o por una accion (p. ej. la segunda alerta A3 cuando el lead ya fue contactado)
ALTER TABLE jobs MODIFY COLUMN status ENUM('pending','running','done','failed','parked','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE tasks ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER priority;
ALTER TABLE tasks ADD KEY idx_tasks_lead_done (lead_id, done_at);

ALTER TABLE leads ADD KEY idx_leads_created (deleted_at, created_at);
ALTER TABLE leads ADD KEY idx_leads_hot_contact (temperature, first_contact_at, deleted_at);
ALTER TABLE leads ADD KEY idx_leads_profile_created (profile, created_at);

-- ===== 005_pipeline_config.sql =====
-- 005_pipeline_config: fase Q2b (kanban, tareas, configuracion, registro de actividad y copias de seguridad).

-- Cuando el lead entro en su etapa actual (antiguedad en la etapa del kanban). NULL = se usa created_at.
ALTER TABLE leads ADD COLUMN stage_changed_at DATETIME NULL AFTER stage;
UPDATE leads l SET stage_changed_at = COALESCE((SELECT MAX(e.created_at) FROM lead_events e WHERE e.lead_id = l.id AND e.type IN ('stage_changed', 'reopened_manual', 'reopened')), l.created_at);
ALTER TABLE leads ADD KEY idx_leads_kanban (pipeline, stage, deleted_at, score);

-- Tareas abiertas por vencimiento (pestanas Hoy / Vencidas / Proximas) y citas por fecha.
ALTER TABLE tasks ADD KEY idx_tasks_open_due (deleted_at, done_at, due_at);
ALTER TABLE appointments ADD COLUMN title VARCHAR(190) NULL AFTER status;
ALTER TABLE appointments ADD KEY idx_appt_starts (deleted_at, starts_at);

-- Registro de actividad: agente resumido ("Chrome 130 · Windows"; nunca el user agent completo) e indice por usuario.
ALTER TABLE activity_log ADD COLUMN agent VARCHAR(80) NULL AFTER ip;
ALTER TABLE activity_log ADD KEY idx_activity_user (user_id, created_at);

-- Copias de seguridad de la base (volcado SQL comprimido hecho en PHP; ver Services/Backup.php).
CREATE TABLE backups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename VARCHAR(120) NULL,
  status ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  trigger_type VARCHAR(10) NOT NULL DEFAULT 'cron',
  size_bytes BIGINT UNSIGNED NULL,
  sha256 CHAR(64) NULL,
  tables_count SMALLINT UNSIGNED NULL,
  rows_count BIGINT UNSIGNED NULL,
  error VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_backups_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 006_handoff.sql =====
-- 006_handoff: fase Q3-A. Entrega de leads a NorthStar (doc §11, A9): una fila por entrega (`handoffs`, version 1, 2… si se reenvia) y una por destino (`handoff_deliveries`).
-- confirm_status: NULL = esperando a NorthStar | signed | rejected | superseded (una entrega mas nueva la reemplazo; sus enlaces dejan de valer).
-- nonce + expires_at firman el enlace de confirmacion sin login (/c/{token}); el enlace se usa una sola vez (confirm_status deja de ser NULL).
CREATE TABLE handoffs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  reason VARCHAR(12) NOT NULL DEFAULT 'stage',
  created_by BIGINT UNSIGNED NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  nonce CHAR(32) NOT NULL,
  expires_at DATETIME NOT NULL,
  confirm_status VARCHAR(12) NULL,
  confirmed_at DATETIME NULL,
  confirmed_via VARCHAR(10) NULL,
  reject_reason VARCHAR(60) NULL,
  reject_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_handoff_version (lead_id, attempt, version),
  KEY idx_handoff_open (confirm_status, created_at),
  CONSTRAINT fk_handoff_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status: pending | retrying | sent | failed | skipped
CREATE TABLE handoff_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  handoff_id BIGINT UNSIGNED NOT NULL,
  target VARCHAR(20) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  http_code SMALLINT UNSIGNED NULL,
  error VARCHAR(300) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_delivery_target (handoff_id, target),
  CONSTRAINT fk_delivery_handoff FOREIGN KEY (handoff_id) REFERENCES handoffs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 007_viewer_reports.sql =====
-- 007_viewer_reports: fase Q3-B/C/D. Invitaciones de un solo uso (rol viewer de NorthStar) e informes (instantanea de metricas semanal y mensual).
ALTER TABLE users ADD COLUMN invited_by BIGINT UNSIGNED NULL AFTER role;
ALTER TABLE users ADD COLUMN deactivated_at DATETIME NULL AFTER last_login_at;
-- Reportes (/reportes, informes): firmados por fecha de firma.
ALTER TABLE leads ADD KEY idx_leads_signed (deleted_at, signed_at);

-- Solo se guarda el hash del token (el enlace en claro se muestra una vez). Caduca a las 48 h y se usa una sola vez.
CREATE TABLE user_invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invite_hash (token_hash),
  KEY idx_invite_user (user_id, used_at),
  CONSTRAINT fk_invite_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kind: weekly | monthly. period: 'AAAA-Www' (semana ISO) o 'AAAA-MM'. ident: identificador legible (INF-2026-10). metrics: instantanea JSON (nunca se recalcula al abrirla).
-- actions: las tres acciones del mes siguiente (editables mientras status = draft). notified_at: aviso a Carlos; sent_at: correo con enlace a los destinatarios al publicar.
CREATE TABLE reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(10) NOT NULL,
  period VARCHAR(10) NOT NULL,
  ident VARCHAR(24) NOT NULL,
  metrics JSON NOT NULL,
  actions JSON NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',
  generated_by BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  published_by BIGINT UNSIGNED NULL,
  notified_at DATETIME NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_period (kind, period),
  UNIQUE KEY uq_report_ident (ident),
  KEY idx_report_pub (kind, status, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 008_comms.sql =====
-- 008_comms: fase Q3-E. Plantillas de correo (EN/ES), inscripciones a secuencias (A6 tibios, A12 referidos) y registro de correos enviados.
-- tkey identifica la plantilla (a6_d1, a12_referral…); (tkey, lang) es unico. kind: system (viene de fabrica, se puede restaurar) | custom.
CREATE TABLE email_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tkey VARCHAR(60) NOT NULL,
  lang CHAR(2) NOT NULL DEFAULT 'en',
  name VARCHAR(120) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'custom',
  active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_template (tkey, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sequence_enrollments (001): step = indice del PROXIMO paso; status: active | paused | completed | stopped. blocks cuenta los intentos bloqueados (marcador sin resolver, tope de envios)
-- y entra en la clave del job para poder reintentar. Una inscripcion por lead y secuencia.
ALTER TABLE sequence_enrollments ADD COLUMN paused_reason VARCHAR(30) NULL AFTER status;
ALTER TABLE sequence_enrollments ADD COLUMN blocked_reason VARCHAR(120) NULL AFTER paused_reason;
ALTER TABLE sequence_enrollments ADD COLUMN blocks SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER blocked_reason;
ALTER TABLE sequence_enrollments ADD COLUMN started_by BIGINT UNSIGNED NULL AFTER blocks;
ALTER TABLE sequence_enrollments ADD COLUMN last_sent_at DATETIME NULL AFTER next_run_at;
ALTER TABLE sequence_enrollments ADD COLUMN completed_at DATETIME NULL AFTER last_sent_at;
ALTER TABLE sequence_enrollments ADD UNIQUE KEY uq_seq_lead (lead_id, sequence_key);

-- messages (001): los correos salientes de secuencias y de plantillas llevan asunto y la secuencia; status: sent | logged (sin SMTP) | failed.
ALTER TABLE messages ADD COLUMN subject VARCHAR(190) NULL AFTER template_key;
ALTER TABLE messages ADD COLUMN sequence_key VARCHAR(60) NULL AFTER subject;
ALTER TABLE messages ADD KEY idx_messages_out (channel, direction, created_at);

