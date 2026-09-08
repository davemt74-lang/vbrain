USE vacation_brain;

-- Vacation Brain v1.19: destination prompt vocabulary + Admin AI destination builder.
CREATE TABLE destination_prompt_profiles (
  destination_catalog_id BIGINT UNSIGNED NOT NULL,
  prompt_summary TEXT NULL,
  visual_keywords_json JSON NULL,
  landmark_keywords_json JSON NULL,
  environment_keywords_json JSON NULL,
  activity_keywords_json JSON NULL,
  wardrobe_keywords_json JSON NULL,
  tourist_boost_keywords_json JSON NULL,
  humor_keywords_json JSON NULL,
  avoid_keywords_json JSON NULL,
  default_scenes_json JSON NULL,
  default_vibes_json JSON NULL,
  source_mode VARCHAR(32) NOT NULL DEFAULT 'manual',
  provider VARCHAR(40) NULL,
  model_name VARCHAR(160) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (destination_catalog_id),
  CONSTRAINT fk_destination_prompt_profile_catalog FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE CASCADE,
  CONSTRAINT fk_destination_prompt_profile_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE destination_ai_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  destination_catalog_id BIGINT UNSIGNED NULL,
  requested_by BIGINT UNSIGNED NULL,
  destination_name VARCHAR(255) NOT NULL,
  location_hint VARCHAR(500) NULL,
  admin_notes TEXT NULL,
  job_type VARCHAR(32) NOT NULL DEFAULT 'all',
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  provider VARCHAR(40) NULL,
  model_name VARCHAR(160) NULL,
  input_json JSON NULL,
  output_json LONGTEXT NULL,
  images_requested INT UNSIGNED NOT NULL DEFAULT 0,
  images_created INT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(1500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_destination_ai_jobs_destination (destination_catalog_id,created_at),
  KEY idx_destination_ai_jobs_status (status,created_at),
  CONSTRAINT fk_destination_ai_jobs_catalog FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE SET NULL,
  CONSTRAINT fk_destination_ai_jobs_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE destination_gallery_images
  ADD COLUMN image_role VARCHAR(64) NOT NULL DEFAULT 'gallery' AFTER report_id,
  ADD COLUMN is_ai_generated TINYINT(1) NOT NULL DEFAULT 0 AFTER is_sample,
  ADD COLUMN prompt_text TEXT NULL AFTER source_name,
  ADD COLUMN model_name VARCHAR(160) NULL AFTER prompt_text,
  ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER model_name,
  ADD KEY idx_destination_gallery_role (destination_catalog_id,image_role,sort_order),
  ADD CONSTRAINT fk_destination_gallery_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE vacation_photo_generations
  ADD COLUMN destination_catalog_id BIGINT UNSIGNED NULL AFTER user_id,
  ADD COLUMN destination_prompt_snapshot_json JSON NULL AFTER user_profile_snapshot_json,
  ADD KEY idx_vacation_photo_generation_destination (destination_catalog_id,created_at),
  ADD CONSTRAINT fk_vacation_photo_generation_destination FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE SET NULL;

INSERT INTO site_settings (setting_key,setting_value,setting_group) VALUES
('destination_ai.default_image_quality','medium','destination_ai'),
('destination_ai.default_image_count','4','destination_ai'),
('destination_ai.image_model','gpt-image-2','destination_ai'),
('destination_ai.auto_publish','0','destination_ai')
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
