USE vacation_brain;

-- Vacation Brain v1.10: post-match reactions, richer chat, notifications, and inbox preferences.
ALTER TABLE travel_match_messages
  ADD COLUMN parent_message_id BIGINT UNSIGNED NULL AFTER sender_user_id,
  ADD COLUMN message_type VARCHAR(32) NOT NULL DEFAULT 'text' AFTER parent_message_id,
  ADD COLUMN metadata_json JSON NULL AFTER body,
  ADD KEY idx_travel_match_messages_parent (parent_message_id),
  ADD CONSTRAINT fk_travel_match_message_parent FOREIGN KEY (parent_message_id) REFERENCES travel_match_messages(id) ON DELETE SET NULL;

CREATE TABLE travel_match_message_reactions (
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  reaction_type VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id,user_id,reaction_type),
  KEY idx_match_message_reaction_user (user_id,created_at),
  CONSTRAINT fk_match_message_reaction_message FOREIGN KEY (message_id) REFERENCES travel_match_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_message_reaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_profile_reactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id BIGINT UNSIGNED NOT NULL,
  reactor_user_id BIGINT UNSIGNED NOT NULL,
  target_user_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  target_choice_id BIGINT UNSIGNED NOT NULL,
  reaction_type VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_profile_response_reaction (match_id,reactor_user_id,content_id),
  KEY idx_profile_reaction_target (target_user_id,created_at),
  CONSTRAINT fk_profile_reaction_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE,
  CONSTRAINT fk_profile_reaction_reactor FOREIGN KEY (reactor_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_profile_reaction_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_profile_reaction_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_profile_reaction_choice FOREIGN KEY (target_choice_id) REFERENCES content_choices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  notification_type VARCHAR(48) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  match_id BIGINT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  body VARCHAR(600) NULL,
  action_url VARCHAR(500) NULL,
  unique_key VARCHAR(180) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_notification_key (unique_key),
  KEY idx_user_notifications_unread (user_id,read_at,created_at),
  KEY idx_user_notifications_match (match_id,created_at),
  CONSTRAINT fk_user_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_notification_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_user_notification_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_match_contact_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,match_id),
  KEY idx_match_contact_pref_view (user_id,archived,pinned,updated_at),
  CONSTRAINT fk_match_contact_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_contact_pref_match FOREIGN KEY (match_id) REFERENCES travel_matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.10') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
