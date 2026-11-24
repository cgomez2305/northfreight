-- 017_automatizacion: fase AM (frente «automatizacion y correcciones»).
--  1) Reglas por eventos y por tiempo: un tipo nuevo de conjunto, `automation` (disparadores: cambio de etapa, sin primer contacto tras N horas, tarea vencida, consentimiento revocado).
--  2) Avisos de regla en la campana (rule_notices).
--  3) Webhooks salientes genericos: registro de entregas con idempotencia por event_id (outbound_deliveries). La configuracion (URL, secreto cifrado, eventos) vive en settings.
--  4) La simulacion de reglas de eventos cuenta avisos de campana y webhooks (dos columnas nuevas en rule_simulation_rows).

ALTER TABLE rule_sets MODIFY kind ENUM('scoring','alerts','routing_tasks','automation') NOT NULL;
ALTER TABLE rule_applications MODIFY kind ENUM('scoring','alerts','routing_tasks','automation') NOT NULL;

ALTER TABLE rule_simulation_rows ADD COLUMN bells TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE rule_simulation_rows ADD COLUMN hooks TINYINT UNSIGNED NOT NULL DEFAULT 0;

-- Avisos que una regla puso en la campana. Cada aviso es una clave distinta en notification_reads ('rule_notice:<id>'): se marca leido por usuario como los demas.
CREATE TABLE IF NOT EXISTS rule_notices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  set_id BIGINT UNSIGNED NOT NULL,
  rule_id VARCHAR(24) NOT NULL,
  rule_name VARCHAR(80) NOT NULL,
  text VARCHAR(200) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rule_notices_created (created_at),
  KEY idx_rule_notices_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entregas del webhook saliente generico. event_id (UNIQUE) = idempotencia: un mismo evento no se encola dos veces. El cuerpo se guarda tal como se firmo (los reintentos reenvian
-- exactamente lo mismo). Sin datos de contacto salvo que la configuracion tenga «incluir contacto»; se purga a los 30 dias.
CREATE TABLE IF NOT EXISTS outbound_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(40) NOT NULL,
  event VARCHAR(30) NOT NULL,
  lead_id BIGINT UNSIGNED NULL,
  payload JSON NOT NULL,
  status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  http_status SMALLINT UNSIGNED NULL,
  last_error VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outbound_event (event_id),
  KEY idx_outbound_created (created_at),
  KEY idx_outbound_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
