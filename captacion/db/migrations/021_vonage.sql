-- 021_vonage: llamadas a prospectos desde el navegador con la Voice API de Vonage (fase AV, 2026-11-25; Carlos: «podemos conectarnos por API a Vonage para manejar las llamadas»).
-- Una fila por llamada: se crea al pulsar «Llamar con Vonage» (status requested, con un ticket de un solo uso que el webhook answer exige), pasa a dialing cuando Vonage
-- pide el NCCO y la actualizan los eventos del tramo al teléfono del prospecto (ringing, answered, completed, busy, unanswered, timeout, rejected, failed, cancelled).
-- duration = segundos del tramo al prospecto (solo si contestó); price y rate como los manda Vonage (EUR según su documentación).

CREATE TABLE prospect_voice_calls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prospect_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  ticket CHAR(32) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'requested',
  conversation_uuid VARCHAR(64) NULL,
  app_uuid VARCHAR(64) NULL,
  pstn_uuid VARCHAR(64) NULL,
  answered_at DATETIME NULL,
  ended_at DATETIME NULL,
  duration INT UNSIGNED NULL,
  price DECIMAL(10,5) NULL,
  rate DECIMAL(10,5) NULL,
  disconnected_by VARCHAR(16) NULL,
  reject_reason VARCHAR(60) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pvc_ticket (ticket),
  KEY idx_pvc_conv (conversation_uuid),
  KEY idx_pvc_prospect (prospect_id, created_at),
  KEY idx_pvc_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
