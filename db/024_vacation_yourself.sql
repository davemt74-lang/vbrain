USE vacation_brain;

-- Vacation Brain v1.17: user-controlled AI vacation photo generation.
CREATE TABLE IF NOT EXISTS vacation_photo_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  ai_photo_consent TINYINT(1) NOT NULL DEFAULT 0,
  consented_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_vacation_photo_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vacation_photo_references (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  photo_url VARCHAR(1500) NOT NULL,
  label VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vacation_photo_references_user (user_id,active,created_at),
  CONSTRAINT fk_vacation_photo_references_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vacation_photo_generations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  destination VARCHAR(255) NOT NULL,
  scene VARCHAR(500) NULL,
  vibe VARCHAR(80) NOT NULL DEFAULT 'realistic',
  size VARCHAR(32) NOT NULL DEFAULT '1024x1024',
  quality VARCHAR(20) NOT NULL DEFAULT 'medium',
  provider VARCHAR(40) NOT NULL DEFAULT 'openai',
  model_name VARCHAR(160) NOT NULL DEFAULT 'gpt-image-2',
  source_refs_json JSON NULL,
  prompt_text TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  image_url VARCHAR(1500) NULL,
  api_request_id VARCHAR(255) NULL,
  error_message VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_vacation_photo_generations_user (user_id,created_at),
  KEY idx_vacation_photo_generations_status (status,created_at),
  CONSTRAINT fk_vacation_photo_generations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (setting_key,setting_value,setting_group) VALUES
('vacation_photos.enabled','1','vacation_photos'),
('vacation_photos.model','gpt-image-2','vacation_photos'),
('vacation_photos.default_quality','medium','vacation_photos'),
('vacation_photos.max_reference_images','4','vacation_photos')
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
