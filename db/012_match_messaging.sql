USE vacation_brain;

-- Vacation Brain v1.5: mutual-match contacts and private 1:1 messaging.
ALTER TABLE travel_matches
  ADD COLUMN user_a_seen_at DATETIME NULL AFTER matched_at,
  ADD COLUMN user_b_seen_at DATETIME NULL AFTER user_a_seen_at,
  ADD COLUMN first_message_at DATETIME NULL AFTER user_b_seen_at,
  ADD COLUMN last_message_at DATETIME NULL AFTER first_message_at,
  ADD KEY idx_travel_matches_activity (status,last_message_at,matched_at);

CREATE TABLE travel_match_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id BIGINT UNSIGNED NOT NULL,
  sender_user_id BIGINT UNSIGNED NOT NULL,
  body VARCHAR(2000) NOT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_travel_match_messages_match (match_id,id),
  KEY idx_travel_match_messages_unread (match_id,read_at,sender_user_id),
  KEY idx_travel_match_messages_sender (sender_user_id,created_at),
  CONSTRAINT fk_travel_match_message_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_match_message_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE travel_match_reports
  ADD COLUMN reported_message_id BIGINT UNSIGNED NULL AFTER reported_user_id,
  ADD KEY idx_travel_match_reports_message (reported_message_id),
  ADD CONSTRAINT fk_travel_match_report_message FOREIGN KEY (reported_message_id) REFERENCES travel_match_messages(id) ON DELETE SET NULL;

INSERT INTO achievements (slug,name,description,rarity,active) VALUES
('said-hello','Said Hello','You sent the first message to a Travel Match. This is now officially more serious than swiping.','common',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),rarity=VALUES(rarity),active=1;

SET @a=(SELECT id FROM achievements WHERE slug='said-hello');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window)
SELECT @a,'event_count','travel_match_first_message',1,'all'
WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count' AND event_type='travel_match_first_message');
