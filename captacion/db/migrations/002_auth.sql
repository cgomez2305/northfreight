-- 002_auth: 2FA TOTP (secreto cifrado con sodium y APP_KEY), codigos de recuperacion (solo hash) y registro de intentos de login.

ALTER TABLE users
  ADD COLUMN totp_confirmed_at DATETIME NULL AFTER totp_secret,
  ADD COLUMN totp_last_step BIGINT NULL AFTER totp_confirmed_at,
  ADD COLUMN password_algo VARCHAR(20) NULL AFTER password_hash;

CREATE TABLE recovery_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recovery_user_hash (user_id, code_hash),
  CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- kind: password | totp | setup. Solo cuentan los fallos (success = 0) para el limite de 5 intentos cada 15 minutos por IP y por correo.
CREATE TABLE login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'password',
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_attempts_ip (ip, created_at),
  KEY idx_attempts_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
