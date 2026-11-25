-- 020_prospeccion: llamadas en frío más ágiles y contacto automático (fase AU, 2026-11-25; Carlos: «enfoquémonos en optimizar al máximo el manejo de los prospectos»).
--  1) prospects_fmcsa.qual: lo que se averigua en la llamada (contacto, correo dado por teléfono, equipo, cuenta de Relay, si ya tiene dispatcher, mejor horario).
--     Pasa al lead al convertirlo.
--  2) prospects_fmcsa.callback_exact: el siguiente intento tiene HORA pedida por el prospecto («llámame a las 3»), no la de por defecto (9:00 a. m. ET).
--  3) prospect_auto_mails: el correo automático tras una llamada sin respuesta (uno por prospecto, como mucho). Estados: queued | sent | skipped | failed.

ALTER TABLE prospects_fmcsa ADD COLUMN qual JSON NULL AFTER fmcsa_data;
ALTER TABLE prospects_fmcsa ADD COLUMN callback_exact TINYINT(1) NOT NULL DEFAULT 0 AFTER next_call_at;

CREATE TABLE prospect_auto_mails (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prospect_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'queued',
  skip_reason VARCHAR(60) NULL,
  error VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pam_prospect (prospect_id),
  KEY idx_pam_sent (status, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
