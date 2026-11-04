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

