-- 012_rules: fase Q5b (editor de reglas CUANDO / ENTONCES / EXCEPTO con vista previa, simulacion y versiones). Idempotente (CREATE TABLE IF NOT EXISTS).
-- Las reglas AJUSTAN la puntuacion base del documento (§6) o disparan acciones; sin ninguna version publicada el motor se comporta exactamente como antes.

-- Conjuntos de reglas. kind decide que acciones admite (Services\Rules::KINDS): scoring (puntos y temperatura minima), alerts (Telegram), routing_tasks (tareas, etiquetas, cola de revision).
CREATE TABLE IF NOT EXISTS rule_sets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('scoring','alerts','routing_tasks') NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(300) NOT NULL DEFAULT '',
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_rule_sets_kind (deleted_at, kind, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versiones de un conjunto. `rules` es el JSON ya validado y normalizado por Services\Rules. Maximo UN borrador y UNA publicada por conjunto: lo garantizan las columnas
-- draft_slot y pub_slot (valen 1 mientras la version es borrador o publicada y NULL en el resto; dos NULL no chocan en un indice UNIQUE).
CREATE TABLE IF NOT EXISTS rule_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  set_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  rules JSON NOT NULL,
  rule_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(200) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  published_by BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  reverted_from INT UNSIGNED NULL,
  draft_slot TINYINT UNSIGNED NULL,
  pub_slot TINYINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rule_versions_num (set_id, version),
  UNIQUE KEY uq_rule_versions_draft (set_id, draft_slot),
  UNIQUE KEY uq_rule_versions_pub (set_id, pub_slot),
  CONSTRAINT fk_rule_versions_set FOREIGN KEY (set_id) REFERENCES rule_sets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de aplicaciones por lead: que regla influyo y cuanto (puntos, alerta encolada, tarea creada, etiqueta, cola de revision). dedupe_key (UNIQUE) evita repetir un efecto
-- (alerta, tarea, etiqueta) para el mismo lead e intento aunque se republique la version o se recalcule. Sin clave foranea al lead: es un registro, no un dato vivo.
CREATE TABLE IF NOT EXISTS rule_applications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  set_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NOT NULL,
  rule_id VARCHAR(24) NOT NULL,
  rule_name VARCHAR(80) NOT NULL,
  kind ENUM('scoring','alerts','routing_tasks') NOT NULL,
  action VARCHAR(24) NOT NULL,
  points SMALLINT NULL,
  detail JSON NULL,
  dedupe_key VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rule_apps_dedupe (dedupe_key),
  KEY idx_rule_apps_lead (lead_id, created_at),
  KEY idx_rule_apps_rule (set_id, rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Simulaciones: corren sobre una version (normalmente el borrador) y NO tocan leads, eventos, tareas ni alertas; solo escriben aqui. El CSV sale de estas tablas.
CREATE TABLE IF NOT EXISTS rule_simulations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  set_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  params JSON NOT NULL,
  rules JSON NOT NULL,
  status ENUM('running','done','failed') NOT NULL DEFAULT 'running',
  total INT UNSIGNED NOT NULL DEFAULT 0,
  processed INT UNSIGNED NOT NULL DEFAULT 0,
  last_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  summary JSON NULL,
  error VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_rule_sims_set (set_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rule_simulation_rows (
  sim_id BIGINT UNSIGNED NOT NULL,
  lead_id BIGINT UNSIGNED NOT NULL,
  before_score TINYINT UNSIGNED NOT NULL,
  before_temp VARCHAR(14) NOT NULL,
  after_score TINYINT UNSIGNED NOT NULL,
  after_temp VARCHAR(14) NOT NULL,
  rules JSON NULL,
  alerts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  tasks TINYINT UNSIGNED NOT NULL DEFAULT 0,
  tags TINYINT UNSIGNED NOT NULL DEFAULT 0,
  review TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (sim_id, lead_id),
  CONSTRAINT fk_rule_sim_rows_sim FOREIGN KEY (sim_id) REFERENCES rule_simulations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
