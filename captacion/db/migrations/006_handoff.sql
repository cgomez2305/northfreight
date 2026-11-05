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
