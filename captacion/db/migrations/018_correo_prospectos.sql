-- 018_correo_prospectos: correos en lote a prospectos FMCSA por segmentos (fase AQ, 2026-11-25; el cliente lo pidió en la reunión del 2026-11-24).
--  1) prospect_campaigns: una campaña = un segmento (estado, flota, antigüedad de la autoridad, estado del prospecto) + asunto y cuerpo propios.
--     status: draft | sending | paused | done | cancelled. test_sent_at: hace falta una prueba enviada DESPUÉS de la última edición para lanzar.
--  2) prospect_campaign_recipients: la foto de destinatarios al lanzar (uno por prospecto y campaña) y su resultado; opened_at/open_count = píxel de apertura (orientativo).
--  3) prospects_fmcsa: baja de correo (email_opt_out_at) y último correo enviado (last_emailed_at, para no repetir dentro del periodo de espera).

CREATE TABLE IF NOT EXISTS prospect_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  segment JSON NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  paused_reason VARCHAR(190) NULL,
  total INT UNSIGNED NOT NULL DEFAULT 0,
  test_sent_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  launched_by BIGINT UNSIGNED NULL,
  launched_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_pcamp_status (deleted_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospect_campaign_recipients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  prospect_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'queued',
  skip_reason VARCHAR(60) NULL,
  error VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  opened_at DATETIME NULL,
  open_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pcr (campaign_id, prospect_id),
  KEY idx_pcr_status (campaign_id, status),
  KEY idx_pcr_sent (status, sent_at),
  KEY idx_pcr_prospect (prospect_id),
  CONSTRAINT fk_pcr_campaign FOREIGN KEY (campaign_id) REFERENCES prospect_campaigns (id),
  CONSTRAINT fk_pcr_prospect FOREIGN KEY (prospect_id) REFERENCES prospects_fmcsa (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE prospects_fmcsa ADD COLUMN email_opt_out_at DATETIME NULL AFTER dnc_at;
ALTER TABLE prospects_fmcsa ADD COLUMN last_emailed_at DATETIME NULL AFTER email_opt_out_at;
-- La lista de supresión (suppression.reason es VARCHAR(20)) guarda también la baja de correo de un prospecto: reason 'email_unsub' (Suppression::EMAIL_UNSUB).
