-- 007_viewer_reports: fase Q3-B/C/D. Invitaciones de un solo uso (rol viewer de NorthStar) e informes (instantanea de metricas semanal y mensual).
ALTER TABLE users ADD COLUMN invited_by BIGINT UNSIGNED NULL AFTER role;
ALTER TABLE users ADD COLUMN deactivated_at DATETIME NULL AFTER last_login_at;

-- Solo se guarda el hash del token (el enlace en claro se muestra una vez). Caduca a las 48 h y se usa una sola vez.
CREATE TABLE user_invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invite_hash (token_hash),
  KEY idx_invite_user (user_id, used_at),
  CONSTRAINT fk_invite_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kind: weekly | monthly. period: 'AAAA-Www' (semana ISO) o 'AAAA-MM'. ident: identificador legible (INF-2026-10). metrics: instantanea JSON (nunca se recalcula al abrirla).
-- actions: las tres acciones del mes siguiente (editables mientras status = draft). notified_at: aviso a Carlos; sent_at: correo con enlace a los destinatarios al publicar.
CREATE TABLE reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(10) NOT NULL,
  period VARCHAR(10) NOT NULL,
  ident VARCHAR(24) NOT NULL,
  metrics JSON NOT NULL,
  actions JSON NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',
  generated_by BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  published_by BIGINT UNSIGNED NULL,
  notified_at DATETIME NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_period (kind, period),
  UNIQUE KEY uq_report_ident (ident),
  KEY idx_report_pub (kind, status, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
