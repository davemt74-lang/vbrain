USE vacation_brain;

-- Vacation Brain v1.7: richer Travel Matching discovery UX without loading full profiles in the swipe feed.
ALTER TABLE travel_match_profiles
  ADD COLUMN min_partner_age TINYINT UNSIGNED NOT NULL DEFAULT 18 AFTER partner_gender,
  ADD COLUMN max_partner_age TINYINT UNSIGNED NOT NULL DEFAULT 99 AFTER min_partner_age,
  ADD COLUMN discovery_scope VARCHAR(32) NOT NULL DEFAULT 'anywhere' AFTER max_partner_age,
  ADD COLUMN show_activity_status TINYINT(1) NOT NULL DEFAULT 1 AFTER discovery_scope,
  ADD CONSTRAINT chk_travel_match_age_range CHECK (min_partner_age BETWEEN 18 AND 99 AND max_partner_age BETWEEN 18 AND 99 AND min_partner_age <= max_partner_age);

CREATE TABLE travel_match_profile_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  photo_url VARCHAR(1000) NOT NULL,
  caption VARCHAR(180) NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_match_profile_photos (user_id,sort_order,id),
  CONSTRAINT fk_match_profile_photo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_profile_prompts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  prompt_key VARCHAR(80) NOT NULL,
  answer_text VARCHAR(500) NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_profile_prompt_slot (user_id,sort_order),
  KEY idx_match_profile_prompt_user (user_id,prompt_key),
  CONSTRAINT fk_match_profile_prompt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Preserve existing primary photos as the first gallery image.
INSERT INTO travel_match_profile_photos (user_id,photo_url,caption,sort_order)
SELECT user_id,photo_url,NULL,0
FROM travel_match_profiles
WHERE photo_url IS NOT NULL AND photo_url<>''
  AND NOT EXISTS (SELECT 1 FROM travel_match_profile_photos p WHERE p.user_id=travel_match_profiles.user_id);

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.7') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
