USE vacation_brain;

-- Vacation Brain v1.11: onboarding, profile readiness, smarter discovery, daily picks, unmatch, notification preferences.
ALTER TABLE travel_match_profiles
  ADD COLUMN onboarding_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled,
  ADD COLUMN onboarding_completed_at DATETIME NULL AFTER onboarding_completed,
  ADD COLUMN profile_readiness TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER show_activity_status,
  ADD COLUMN readiness_updated_at DATETIME NULL AFTER profile_readiness,
  ADD KEY idx_match_profile_readiness (enabled,profile_readiness,last_match_active_at);

ALTER TABLE travel_matches
  ADD COLUMN unmatched_by_user_id BIGINT UNSIGNED NULL AFTER status,
  ADD COLUMN unmatched_at DATETIME NULL AFTER unmatched_by_user_id,
  ADD COLUMN unmatch_reason VARCHAR(64) NULL AFTER unmatched_at,
  ADD KEY idx_travel_matches_unmatched (unmatched_by_user_id,unmatched_at),
  ADD CONSTRAINT fk_travel_match_unmatched_by FOREIGN KEY (unmatched_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE travel_match_quality_feedback (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  target_user_id BIGINT UNSIGNED NOT NULL,
  context_type VARCHAR(24) NOT NULL,
  reason VARCHAR(48) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_match_quality_actor (actor_user_id,context_type,reason,created_at),
  KEY idx_match_quality_target (target_user_id,created_at),
  CONSTRAINT fk_match_quality_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_quality_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_match_quality_self CHECK (actor_user_id <> target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_daily_picks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  target_user_id BIGINT UNSIGNED NOT NULL,
  pick_date DATE NOT NULL,
  rank_score DECIMAL(7,2) NOT NULL DEFAULT 0,
  rank_reasons_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_daily_pick (user_id,target_user_id,pick_date),
  KEY idx_match_daily_pick_user (user_id,pick_date,rank_score),
  CONSTRAINT fk_match_daily_pick_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_daily_pick_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_match_daily_pick_self CHECK (user_id <> target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_notification_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  new_matches TINYINT(1) NOT NULL DEFAULT 1,
  new_messages TINYINT(1) NOT NULL DEFAULT 1,
  daily_match_question TINYINT(1) NOT NULL DEFAULT 1,
  match_discoveries TINYINT(1) NOT NULL DEFAULT 1,
  streak_reminders TINYINT(1) NOT NULL DEFAULT 1,
  daily_checkin TINYINT(1) NOT NULL DEFAULT 1,
  achievements_merch TINYINT(1) NOT NULL DEFAULT 1,
  profile_reactions TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.11') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
