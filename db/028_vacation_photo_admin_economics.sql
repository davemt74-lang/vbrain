USE vacation_brain;

-- Vacation Brain v1.21: Admin generation policy + planning-cost economics.
CREATE TABLE vacation_photo_user_limits (
  user_id BIGINT UNSIGNED NOT NULL,
  daily_limit INT UNSIGNED NULL,
  monthly_limit INT UNSIGNED NULL,
  lifetime_limit INT UNSIGNED NULL,
  comped_generations INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_vacation_photo_limit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vacation_photo_limit_admin FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (setting_key,setting_value,setting_group) VALUES
('vacation_photos.daily_limit','8','vacation_photos'),
('vacation_photos.monthly_limit','40','vacation_photos'),
('vacation_photos.lifetime_limit','0','vacation_photos'),
('vacation_photos.estimate.low.square','0.0150','vacation_photo_costs'),
('vacation_photos.estimate.low.large','0.0200','vacation_photo_costs'),
('vacation_photos.estimate.medium.square','0.0450','vacation_photo_costs'),
('vacation_photos.estimate.medium.large','0.0600','vacation_photo_costs'),
('vacation_photos.estimate.high.square','0.1500','vacation_photo_costs'),
('vacation_photos.estimate.high.large','0.2200','vacation_photo_costs'),
('vacation_photos.estimate.reference_image','0.0100','vacation_photo_costs'),
('vacation_photos.estimate.label','Planning estimate — editable by Admin','vacation_photo_costs')
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
