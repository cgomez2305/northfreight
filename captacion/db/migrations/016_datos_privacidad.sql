-- 016_datos_privacidad: fase AM (frente «datos»). Lista de supresion (STOP de numeros que aun no son lead y borrado a peticion), anonimizacion,
-- fusion de duplicados e importacion CSV de leads. SQL estandar valido en MariaDB 10.11 y MySQL 8.

-- leads.anonymized_at: el lead se anonimizo a peticion del titular (irreversible). Se conservan id, fechas, fuente, etapa y puntuacion para las estadisticas.
-- leads.merged_into: este lead se fusiono en otro (tiene deleted_at; la ficha vieja redirige al principal).
ALTER TABLE leads ADD COLUMN anonymized_at DATETIME NULL;
ALTER TABLE leads ADD COLUMN merged_into BIGINT UNSIGNED NULL;
ALTER TABLE leads ADD KEY idx_leads_merged (merged_into);

-- Lista de supresion. NUNCA guarda el telefono ni el correo en claro: solo HMAC-SHA256 (clave derivada de APP_KEY) del valor normalizado
-- (telefono E.164, correo en minusculas). kind: phone | email. reason: sms_stop (el titular respondio STOP por SMS) | erasure (borrado a peticion).
-- Una razon `erasure` nunca se rebaja a sms_stop; START por SMS solo quita las de razon sms_stop.
CREATE TABLE suppression (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(8) NOT NULL,
  value_hash CHAR(64) NOT NULL,
  reason VARCHAR(20) NOT NULL,
  source_lead_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppression (kind, value_hash),
  KEY idx_suppression_reason (reason, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Importaciones CSV de leads (ruta /importar). El archivo vive en storage/imports/<token>.csv (0600) y se borra al terminar.
-- status: uploaded | queued | running | done | failed | cancelled. mapping = columna del CSV -> campo del lead. problems = primeras filas con problema (sin datos personales).
CREATE TABLE lead_imports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  token CHAR(32) NOT NULL,
  filename VARCHAR(160) NOT NULL,
  tag VARCHAR(40) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'uploaded',
  delimiter_char VARCHAR(4) NOT NULL DEFAULT ',',
  encoding VARCHAR(16) NOT NULL DEFAULT 'UTF-8',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  mapping JSON NULL,
  options JSON NULL,
  processed INT UNSIGNED NOT NULL DEFAULT 0,
  created_count INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
  problems JSON NULL,
  error VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_import_token (token),
  KEY idx_imports_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
