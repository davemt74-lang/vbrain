USE vacation_brain;

-- Vacation Brain v1.6: match engagement loop + explicit partner gender preference.
ALTER TABLE travel_match_profiles
  ADD COLUMN enrollment_status VARCHAR(24) NOT NULL DEFAULT 'unenrolled' AFTER enabled;
ALTER TABLE travel_match_profiles
  ADD COLUMN enrolled_at DATETIME NULL AFTER enrollment_status,
  ADD COLUMN unenrolled_at DATETIME NULL AFTER enrolled_at;
ALTER TABLE travel_match_profiles
  ADD COLUMN partner_gender VARCHAR(48) NOT NULL DEFAULT 'everyone' AFTER gender_identity;
UPDATE travel_match_profiles SET partner_gender=show_me WHERE partner_gender='everyone' AND show_me<>'everyone';
UPDATE travel_match_profiles SET enrollment_status=IF(enabled=1,'enrolled','unenrolled'), enrolled_at=IF(enabled=1,COALESCE(enrolled_at,updated_at),enrolled_at), unenrolled_at=IF(enabled=0,COALESCE(unenrolled_at,updated_at),unenrolled_at);

ALTER TABLE travel_match_games
  ADD COLUMN match_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_travel_match_games_match (match_id,status,created_at),
  ADD CONSTRAINT fk_travel_match_games_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE;

CREATE TABLE travel_match_daily_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id BIGINT UNSIGNED NOT NULL,
  question_date DATE NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_daily_question (match_id,question_date),
  KEY idx_match_daily_content (content_id),
  CONSTRAINT fk_match_daily_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_daily_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_daily_answers (
  daily_question_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  choice_id BIGINT UNSIGNED NOT NULL,
  answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (daily_question_id,user_id),
  KEY idx_match_daily_choice (choice_id),
  CONSTRAINT fk_match_daily_answer_question FOREIGN KEY (daily_question_id) REFERENCES travel_match_daily_questions(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_daily_answer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_daily_answer_choice FOREIGN KEY (choice_id) REFERENCES content_choices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_activity_days (
  match_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  activity_date DATE NOT NULL,
  activity_type VARCHAR(48) NOT NULL,
  first_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  activity_count INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (match_id,user_id,activity_date,activity_type),
  KEY idx_match_activity_shared (match_id,activity_date,user_id),
  CONSTRAINT fk_match_activity_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_discoveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id BIGINT UNSIGNED NOT NULL,
  discovery_key VARCHAR(80) NOT NULL,
  title VARCHAR(180) NOT NULL,
  body VARCHAR(700) NOT NULL,
  discovery_type VARCHAR(48) NOT NULL DEFAULT 'insight',
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_discovery (match_id,discovery_key),
  KEY idx_match_discoveries_time (match_id,unlocked_at),
  CONSTRAINT fk_match_discovery_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_achievements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id BIGINT UNSIGNED NOT NULL,
  achievement_key VARCHAR(80) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NOT NULL,
  unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_achievement (match_id,achievement_key),
  KEY idx_match_achievements_time (match_id,unlocked_at),
  CONSTRAINT fk_match_achievement_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_shared_dreams (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL DEFAULT 'Our Completely Hypothetical Trip',
  destination VARCHAR(180) NULL,
  vibe VARCHAR(120) NULL,
  budget_style VARCHAR(32) NOT NULL DEFAULT 'comfortable',
  dream_level TINYINT UNSIGNED NOT NULL DEFAULT 2,
  notes TEXT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_shared_dream (match_id),
  CONSTRAINT fk_match_shared_dream_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_shared_dream_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_match_shared_dream_level CHECK (dream_level BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_shared_dream_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shared_dream_id BIGINT UNSIGNED NOT NULL,
  added_by BIGINT UNSIGNED NOT NULL,
  item_type VARCHAR(32) NOT NULL DEFAULT 'idea',
  title VARCHAR(220) NOT NULL,
  notes VARCHAR(700) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_match_shared_dream_items (shared_dream_id,created_at),
  CONSTRAINT fk_match_shared_item_dream FOREIGN KEY (shared_dream_id) REFERENCES travel_match_shared_dreams(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_shared_item_user FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO score_rules (event_type,points,daily_limit,cooldown_minutes,active) VALUES
('travel_match_daily_answer',2,10,NULL,1),
('travel_match_shared_dream_updated',3,5,30,1)
ON DUPLICATE KEY UPDATE points=VALUES(points),daily_limit=VALUES(daily_limit),cooldown_minutes=VALUES(cooldown_minutes),active=1;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.6') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
