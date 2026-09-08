USE vacation_brain;

-- Vacation Brain v1.18: comprehensive user image prompt profile + Over the Top control.
CREATE TABLE vacation_photo_user_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  profile_version INT UNSIGNED NOT NULL DEFAULT 1,
  profile_hash CHAR(64) NOT NULL,
  profile_json JSON NOT NULL,
  prompt_text LONGTEXT NOT NULL,
  rebuilt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_vacation_photo_user_profiles_hash (profile_hash),
  CONSTRAINT fk_vacation_photo_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE vacation_photo_generations
  ADD COLUMN over_the_top_strength TINYINT UNSIGNED NOT NULL DEFAULT 35 AFTER vibe,
  ADD COLUMN prompt_profile_hash CHAR(64) NULL AFTER source_refs_json,
  ADD COLUMN user_profile_snapshot_json JSON NULL AFTER prompt_profile_hash;

INSERT INTO site_settings (setting_key,setting_value,setting_group) VALUES
('vacation_photos.default_over_the_top','35','vacation_photos'),
('vacation_photos.profile_version','1','vacation_photos')
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
