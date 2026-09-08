-- Vacation Brain v1.14 - one-click SQL upgrade tracking
CREATE TABLE IF NOT EXISTS schema_migrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  migration_key VARCHAR(190) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  statement_count INT UNSIGNED NOT NULL DEFAULT 0,
  execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
  app_version_after VARCHAR(40) NULL,
  applied_by BIGINT UNSIGNED NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_schema_migration_key (migration_key),
  KEY idx_schema_migrations_applied_at (applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upgrade_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  migration_key VARCHAR(190) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  statement_count INT UNSIGNED NOT NULL DEFAULT 0,
  execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  run_by BIGINT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  KEY idx_upgrade_runs_started (started_at),
  KEY idx_upgrade_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.14')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
