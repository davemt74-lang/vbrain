USE vacation_brain;

-- Vacation Brain v1.4: opt-in 18+ Travel Matching / Compare Vacation Brains.
CREATE TABLE travel_match_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  age TINYINT UNSIGNED NULL,
  gender_identity VARCHAR(48) NULL,
  show_me VARCHAR(48) NOT NULL DEFAULT 'everyone',
  match_mode VARCHAR(32) NOT NULL DEFAULT 'either',
  home_city VARCHAR(120) NULL,
  home_region VARCHAR(120) NULL,
  home_country VARCHAR(120) NULL,
  bio VARCHAR(500) NULL,
  favorite_destination VARCHAR(180) NULL,
  travel_pace VARCHAR(32) NOT NULL DEFAULT 'balanced',
  budget_style VARCHAR(32) NOT NULL DEFAULT 'comfortable',
  photo_url VARCHAR(1000) NULL,
  dealbreakers_json JSON NULL,
  last_match_active_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_travel_match_browse (enabled,match_mode,last_match_active_at),
  KEY idx_travel_match_location (enabled,home_city,home_region),
  CONSTRAINT fk_travel_match_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_travel_match_age CHECK (age IS NULL OR age BETWEEN 18 AND 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  target_user_id BIGINT UNSIGNED NOT NULL,
  action_type VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_travel_match_action_pair (actor_user_id,target_user_id),
  KEY idx_travel_match_action_target (target_user_id,action_type),
  CONSTRAINT fk_travel_match_action_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_match_action_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_travel_match_action_self CHECK (actor_user_id <> target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_matches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_a_id BIGINT UNSIGNED NOT NULL,
  user_b_id BIGINT UNSIGNED NOT NULL,
  compatibility_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  matched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_travel_match_pair (user_a_id,user_b_id),
  KEY idx_travel_matches_user_b (user_b_id,status,matched_at),
  CONSTRAINT fk_travel_matches_user_a FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_matches_user_b FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_travel_match_pair_order CHECK (user_a_id < user_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_user_id BIGINT UNSIGNED NOT NULL,
  claimed_user_id BIGINT UNSIGNED NULL,
  invite_token CHAR(40) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_travel_match_invite_token (invite_token),
  KEY idx_travel_match_invite_creator (creator_user_id,status,created_at),
  CONSTRAINT fk_travel_match_invite_creator FOREIGN KEY (creator_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_match_invite_claimed FOREIGN KEY (claimed_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_games (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  creator_user_id BIGINT UNSIGNED NOT NULL,
  partner_user_id BIGINT UNSIGNED NULL,
  invite_token CHAR(40) NOT NULL,
  game_type VARCHAR(48) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_travel_match_game_token (invite_token),
  KEY idx_travel_match_games_creator (creator_user_id,status,created_at),
  KEY idx_travel_match_games_partner (partner_user_id,status,created_at),
  CONSTRAINT fk_travel_match_game_creator FOREIGN KEY (creator_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_match_game_partner FOREIGN KEY (partner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_game_answers (
  game_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  answers_json JSON NOT NULL,
  answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (game_id,user_id),
  CONSTRAINT fk_travel_match_game_answers_game FOREIGN KEY (game_id) REFERENCES travel_match_games(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_match_game_answers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO score_rules (event_type,points,daily_limit,cooldown_minutes,active) VALUES
('travel_match_profile_enabled',10,1,NULL,1),
('travel_match_like',1,20,NULL,1),
('travel_match_mutual',15,10,NULL,1),
('travel_match_compare',2,10,10,1),
('travel_match_game_completed',8,10,NULL,1)
ON DUPLICATE KEY UPDATE points=VALUES(points),daily_limit=VALUES(daily_limit),cooldown_minutes=VALUES(cooldown_minutes),active=1;

INSERT INTO achievements (slug,name,description,rarity,active) VALUES
('travel-match-ready','Travel Match Ready','You enabled Travel Matching. Apparently your vacation standards are now a social filter.','common',1),
('vacation-compatible','Vacation Compatible','You found a mutual Travel Match. Try not to immediately schedule a sunrise excursion.','rare',1),
('airport-compatible','Airport Compatible','You completed a Travel Matching game with another Vacation Brain.','common',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),rarity=VALUES(rarity),active=1;

SET @a=(SELECT id FROM achievements WHERE slug='travel-match-ready');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','travel_match_profile_enabled',1,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count' AND event_type='travel_match_profile_enabled');
SET @a=(SELECT id FROM achievements WHERE slug='vacation-compatible');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','travel_match_mutual',1,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count' AND event_type='travel_match_mutual');
SET @a=(SELECT id FROM achievements WHERE slug='airport-compatible');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','travel_match_game_completed',1,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count' AND event_type='travel_match_game_completed');

CREATE TABLE travel_match_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_user_id BIGINT UNSIGNED NOT NULL,
  reported_user_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(64) NOT NULL,
  details VARCHAR(1000) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_travel_match_reports_status (status,created_at),
  KEY idx_travel_match_reports_reported (reported_user_id,status),
  CONSTRAINT fk_travel_match_report_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_match_report_reported FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_match_report_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_travel_match_report_self CHECK (reporter_user_id <> reported_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
