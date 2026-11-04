-- 004_panel: tablas y columnas del panel Q2a (notas, avisos leidos, llamadas manuales, indices de bandeja y dashboard).

CREATE TABLE lead_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_notes_lead (lead_id, deleted_at, created_at),
  CONSTRAINT fk_notes_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avisos de la campana marcados como leidos por usuario. notif_key lleva el estado (p. ej. 'failed_jobs:3'): si el estado cambia, el aviso vuelve a salir.
CREATE TABLE notification_reads (
  user_id BIGINT UNSIGNED NOT NULL,
  notif_key VARCHAR(120) NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, notif_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE messages ADD COLUMN duration_sec SMALLINT UNSIGNED NULL AFTER status;
ALTER TABLE messages ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER duration_sec;

-- 'cancelled': job anulado a mano o por una accion (p. ej. la segunda alerta A3 cuando el lead ya fue contactado)
ALTER TABLE jobs MODIFY COLUMN status ENUM('pending','running','done','failed','parked','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE tasks ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER priority;
ALTER TABLE tasks ADD KEY idx_tasks_lead_done (lead_id, done_at);

ALTER TABLE leads ADD KEY idx_leads_created (deleted_at, created_at);
ALTER TABLE leads ADD KEY idx_leads_hot_contact (temperature, first_contact_at, deleted_at);
ALTER TABLE leads ADD KEY idx_leads_profile_created (profile, created_at);
