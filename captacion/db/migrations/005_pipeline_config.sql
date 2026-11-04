-- 005_pipeline_config: fase Q2b (kanban, tareas, configuracion, registro de actividad y copias de seguridad).

-- Cuando el lead entro en su etapa actual (antiguedad en la etapa del kanban). NULL = se usa created_at.
ALTER TABLE leads ADD COLUMN stage_changed_at DATETIME NULL AFTER stage;
UPDATE leads l SET stage_changed_at = COALESCE((SELECT MAX(e.created_at) FROM lead_events e WHERE e.lead_id = l.id AND e.type IN ('stage_changed', 'reopened_manual', 'reopened')), l.created_at);
ALTER TABLE leads ADD KEY idx_leads_kanban (pipeline, stage, deleted_at, score);

-- Tareas abiertas por vencimiento (pestanas Hoy / Vencidas / Proximas) y citas por fecha.
ALTER TABLE tasks ADD KEY idx_tasks_open_due (deleted_at, done_at, due_at);
ALTER TABLE appointments ADD COLUMN title VARCHAR(190) NULL AFTER status;
ALTER TABLE appointments ADD KEY idx_appt_starts (deleted_at, starts_at);

-- Registro de actividad: agente resumido ("Chrome 130 · Windows"; nunca el user agent completo) e indice por usuario.
ALTER TABLE activity_log ADD COLUMN agent VARCHAR(80) NULL AFTER ip;
ALTER TABLE activity_log ADD KEY idx_activity_user (user_id, created_at);

-- Copias de seguridad de la base (volcado SQL comprimido hecho en PHP; ver Services/Backup.php).
CREATE TABLE backups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename VARCHAR(120) NULL,
  status ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  trigger_type VARCHAR(10) NOT NULL DEFAULT 'cron',
  size_bytes BIGINT UNSIGNED NULL,
  sha256 CHAR(64) NULL,
  tables_count SMALLINT UNSIGNED NULL,
  rows_count BIGINT UNSIGNED NULL,
  error VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_backups_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
