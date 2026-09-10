-- Vacation Brain v1.25: Photos jobs/albums plus persistent dashboard destination context.
CREATE TABLE IF NOT EXISTS dashboard_destination_context (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  destination_key VARCHAR(190) NOT NULL,
  destination_catalog_id BIGINT UNSIGNED NULL,
  destination_name VARCHAR(255) NOT NULL,
  destination_type VARCHAR(40) NOT NULL DEFAULT 'destination',
  destination_url VARCHAR(1500) NULL,
  metadata_json JSON NULL,
  is_selected TINYINT(1) NOT NULL DEFAULT 1,
  is_watching TINYINT(1) NOT NULL DEFAULT 0,
  selected_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dashboard_destination_context_user_key (user_id,destination_key),
  KEY idx_dashboard_destination_context_selected (user_id,is_selected,updated_at),
  KEY idx_dashboard_destination_context_watching (user_id,is_watching,updated_at),
  CONSTRAINT fk_dashboard_destination_context_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vacation_photo_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  source_type VARCHAR(24) NOT NULL DEFAULT 'upload',
  source_reference_id BIGINT UNSIGNED NULL,
  source_image_url VARCHAR(1500) NULL,
  destination_catalog_id BIGINT UNSIGNED NULL,
  destination_name VARCHAR(255) NOT NULL,
  generation_options_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  generation_id BIGINT UNSIGNED NULL,
  error_message VARCHAR(1000) NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vacation_photo_jobs_user (user_id,created_at),
  KEY idx_vacation_photo_jobs_status (status,updated_at),
  CONSTRAINT fk_vacation_photo_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vacation_photo_jobs_reference FOREIGN KEY (source_reference_id) REFERENCES vacation_photo_references(id) ON DELETE SET NULL,
  CONSTRAINT fk_vacation_photo_jobs_generation FOREIGN KEY (generation_id) REFERENCES vacation_photo_generations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vacation_photo_albums (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  cover_generation_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vacation_photo_albums_user (user_id,updated_at),
  CONSTRAINT fk_vacation_photo_albums_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vacation_photo_albums_cover FOREIGN KEY (cover_generation_id) REFERENCES vacation_photo_generations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vacation_photo_album_items (
  album_id BIGINT UNSIGNED NOT NULL,
  generation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (album_id,generation_id),
  KEY idx_vacation_photo_album_items_user (user_id,created_at),
  KEY idx_vacation_photo_album_items_generation (generation_id),
  CONSTRAINT fk_vacation_photo_album_items_album FOREIGN KEY (album_id) REFERENCES vacation_photo_albums(id) ON DELETE CASCADE,
  CONSTRAINT fk_vacation_photo_album_items_generation FOREIGN KEY (generation_id) REFERENCES vacation_photo_generations(id) ON DELETE CASCADE,
  CONSTRAINT fk_vacation_photo_album_items_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.25')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
