USE vacation_brain;

-- Vacation Brain v1.20: diagnosis-origin images, gallery actions, favorites and explicit sharing.
ALTER TABLE vacation_photo_generations
  ADD COLUMN origin VARCHAR(40) NOT NULL DEFAULT 'manual' AFTER destination_prompt_snapshot_json,
  ADD COLUMN origin_id BIGINT UNSIGNED NULL AFTER origin,
  ADD COLUMN favorite TINYINT(1) NOT NULL DEFAULT 0 AFTER image_url,
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER favorite,
  ADD COLUMN dream_trip_id BIGINT UNSIGNED NULL AFTER archived,
  ADD COLUMN share_token CHAR(40) NULL AFTER dream_trip_id,
  ADD COLUMN share_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER share_token,
  ADD COLUMN deleted_at DATETIME NULL AFTER completed_at,
  ADD UNIQUE KEY uq_vacation_photo_share_token (share_token),
  ADD KEY idx_vacation_photo_gallery (user_id,archived,favorite,created_at),
  ADD KEY idx_vacation_photo_dream (dream_trip_id,created_at),
  ADD CONSTRAINT fk_vacation_photo_dream_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE SET NULL;

INSERT INTO site_settings (setting_key,setting_value,setting_group) VALUES
('vacation_photos.gallery_enabled','1','vacation_photos'),
('vacation_photos.sharing_enabled','1','vacation_photos')
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
