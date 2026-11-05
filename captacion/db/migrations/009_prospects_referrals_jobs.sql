-- 009_prospects_referrals_jobs: fase Q4a (prospectos FMCSA salientes, referidos y vacantes publicadas en el sitio).

-- ---------------------------------------------------------------------------------------------------------------------
-- Prospectos FMCSA (doc §7, A10). La tabla ya existia (usdot UNIQUE); se completa con lo que da el dataset publico.
-- status: to_call | no_answer | no_answer_closed | talked | interested | not_interested | dnc
-- ---------------------------------------------------------------------------------------------------------------------
ALTER TABLE prospects_fmcsa ADD COLUMN mc_number VARCHAR(20) NULL AFTER usdot;
ALTER TABLE prospects_fmcsa ADD COLUMN city VARCHAR(80) NULL AFTER state;
ALTER TABLE prospects_fmcsa ADD COLUMN drivers SMALLINT UNSIGNED NULL AFTER fleet_size;
ALTER TABLE prospects_fmcsa ADD COLUMN completeness TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER cargo;
ALTER TABLE prospects_fmcsa ADD COLUMN fmcsa_data JSON NULL AFTER completeness;
ALTER TABLE prospects_fmcsa ADD COLUMN source VARCHAR(30) NOT NULL DEFAULT 'socrata' AFTER fmcsa_data;
ALTER TABLE prospects_fmcsa ADD COLUMN listed_on DATE NULL AFTER source;
ALTER TABLE prospects_fmcsa ADD COLUMN next_call_at DATETIME NULL AFTER attempts;
ALTER TABLE prospects_fmcsa ADD COLUMN last_result VARCHAR(20) NULL AFTER last_call_at;
ALTER TABLE prospects_fmcsa ADD COLUMN closed_at DATETIME NULL AFTER do_not_call;
ALTER TABLE prospects_fmcsa ADD COLUMN dnc_at DATETIME NULL AFTER closed_at;
ALTER TABLE prospects_fmcsa ADD COLUMN converted_at DATETIME NULL AFTER converted_lead_id;
ALTER TABLE prospects_fmcsa ADD KEY idx_prospects_list (deleted_at, status, authority_granted_at);
ALTER TABLE prospects_fmcsa ADD KEY idx_prospects_listed (listed_on);
ALTER TABLE prospects_fmcsa ADD KEY idx_prospects_phone (phone);

-- Historial de intentos de llamada (un registro por resultado).
CREATE TABLE prospect_calls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prospect_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  result VARCHAR(20) NOT NULL,
  note VARCHAR(500) NULL,
  attempt_no TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_call_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pcalls_prospect (prospect_id, created_at),
  CONSTRAINT fk_pcalls_prospect FOREIGN KEY (prospect_id) REFERENCES prospects_fmcsa (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lista interna de «no llamar»: permanente. Un telefono aqui nunca vuelve a listarse aunque FMCSA entregue otro USDOT con el mismo numero.
CREATE TABLE dnc_numbers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone_e164 VARCHAR(20) NOT NULL,
  usdot VARCHAR(10) NULL,
  prospect_id BIGINT UNSIGNED NULL,
  reason VARCHAR(30) NOT NULL DEFAULT 'prospect',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dnc_phone (phone_e164)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------------
-- Referidos (doc §4, §9.7, A12). referrals.status: referred | qualified | signed. bonus_status: none | pending | approved | paid | cancelled.
-- ---------------------------------------------------------------------------------------------------------------------
ALTER TABLE referral_codes ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER bonus_amount;
ALTER TABLE referral_codes ADD COLUMN note VARCHAR(255) NULL AFTER active;
ALTER TABLE referral_codes ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER note;

ALTER TABLE referrals MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'referred';
UPDATE referrals SET status = 'referred' WHERE status = 'new';
ALTER TABLE referrals ADD COLUMN qualified_at DATETIME NULL AFTER bonus_status;
ALTER TABLE referrals ADD COLUMN signed_at DATETIME NULL AFTER qualified_at;
ALTER TABLE referrals ADD COLUMN bonus_amount DECIMAL(10,2) NULL AFTER signed_at;
ALTER TABLE referrals ADD COLUMN bonus_approved_at DATETIME NULL AFTER bonus_amount;
ALTER TABLE referrals ADD COLUMN bonus_paid_at DATETIME NULL AFTER bonus_approved_at;
ALTER TABLE referrals ADD COLUMN bonus_note VARCHAR(255) NULL AFTER bonus_paid_at;
ALTER TABLE referrals ADD UNIQUE KEY uq_referrals_lead (lead_id);
ALTER TABLE referrals ADD KEY idx_referrals_code (referral_code_id, created_at);
ALTER TABLE referrals ADD KEY idx_referrals_bonus (bonus_status);

-- ---------------------------------------------------------------------------------------------------------------------
-- Vacantes (doc §9.8). Se publican al sitio como storage/jobs.json (ver Services\JobsPublisher). status: draft | active | paused | closed.
-- description_html e is_active se conservan del esquema original (is_active se mantiene igual a status = 'active'); el contenido nuevo es texto plano.
-- ---------------------------------------------------------------------------------------------------------------------
ALTER TABLE job_postings ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'draft' AFTER slug;
ALTER TABLE job_postings ADD COLUMN posted_on DATE NULL AFTER status;
ALTER TABLE job_postings ADD COLUMN summary VARCHAR(500) NULL AFTER posted_on;
ALTER TABLE job_postings ADD COLUMN equipment VARCHAR(80) NULL AFTER summary;
ALTER TABLE job_postings ADD COLUMN responsibilities JSON NULL AFTER requirements;
ALTER TABLE job_postings ADD COLUMN benefits JSON NULL AFTER responsibilities;
ALTER TABLE job_postings ADD COLUMN city VARCHAR(80) NULL AFTER location;
ALTER TABLE job_postings ADD COLUMN region CHAR(2) NULL AFTER city;
ALTER TABLE job_postings ADD COLUMN postal VARCHAR(10) NULL AFTER region;
ALTER TABLE job_postings ADD COLUMN pay_currency CHAR(3) NULL AFTER pay_unit;
ALTER TABLE job_postings ADD COLUMN published_at DATETIME NULL AFTER valid_through;
ALTER TABLE job_postings ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER published_at;
ALTER TABLE job_postings ADD KEY idx_jobs_status (deleted_at, status);
UPDATE job_postings SET status = IF(is_active = 1, 'active', 'draft') WHERE status = 'draft';
