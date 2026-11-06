-- 011_ux_prefs_views: fase Q5a (experiencia del panel). Preferencias por usuario (tema, barra lateral, densidad, columnas de la lista, recientes de la paleta)
-- y vistas guardadas por usuario (filtros + orden de la bandeja y del tablero). Idempotente (CREATE TABLE IF NOT EXISTS).

-- Una fila por (usuario, clave). Las claves y valores validos los fija Services\Prefs (lista blanca); la base solo guarda texto corto.
CREATE TABLE IF NOT EXISTS user_prefs (
  user_id BIGINT UNSIGNED NOT NULL,
  pkey VARCHAR(30) NOT NULL,
  pvalue VARCHAR(500) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, pkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vistas guardadas: privadas de cada usuario (las del sistema viven en codigo, Services\SavedViews::SYSTEM). filters = lista blanca de LeadQuery (JSON);
-- sort/dir/pipeline opcionales; pinned = aparece en la barra lateral bajo «Vistas»; position ordena las fijadas. Borrado logico.
CREATE TABLE IF NOT EXISTS saved_views (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  filters JSON NOT NULL,
  sort VARCHAR(10) NULL,
  dir CHAR(4) NULL,
  pipeline CHAR(1) NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_views_user (user_id, deleted_at, pinned, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las preferencias y vistas de un usuario no tienen sentido sin el: se purgan al borrar la cuenta (users usa borrado logico, asi que esto es solo por limpieza manual).
