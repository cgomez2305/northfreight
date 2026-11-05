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
