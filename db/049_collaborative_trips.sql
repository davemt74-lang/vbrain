USE vacation_brain;

-- Vacation Brain v1.41: permissioned collaborative trips and travelers.
-- The trip owner remains dream_trips.user_id. Collaborators receive explicit,
-- revocable roles; invitation tokens are stored only as SHA-256 hashes.
CREATE TABLE trip_collaborators (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('co_planner','traveler','viewer') NOT NULL DEFAULT 'traveler',
  rsvp ENUM('unknown','going','maybe','not_going') NOT NULL DEFAULT 'unknown',
  notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','removed') NOT NULL DEFAULT 'active',
  added_by BIGINT UNSIGNED NOT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_collaborator (dream_trip_id,user_id),
  KEY idx_trip_collaborator_user (user_id,status,updated_at),
  KEY idx_trip_collaborator_trip (dream_trip_id,status,role),
  CONSTRAINT fk_trip_collaborator_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_collaborator_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_collaborator_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_collaboration_invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  invited_by BIGINT UNSIGNED NOT NULL,
  invited_email VARCHAR(255) NOT NULL,
  role ENUM('co_planner','traveler','viewer') NOT NULL DEFAULT 'traveler',
  token_hash CHAR(64) NOT NULL,
  status ENUM('pending','accepted','revoked','expired') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  accepted_by BIGINT UNSIGNED NULL,
  accepted_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_collaboration_invite_token (token_hash),
  KEY idx_trip_collaboration_invite_trip (dream_trip_id,status,expires_at),
  KEY idx_trip_collaboration_invite_email (invited_email,status,expires_at),
  CONSTRAINT fk_trip_collaboration_invite_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_collaboration_invite_user FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_collaboration_invite_accepted_by FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_collaboration_votes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  dream_trip_item_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  vote ENUM('love','yes','maybe','no') NOT NULL,
  comment VARCHAR(280) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_collaboration_vote (dream_trip_item_id,user_id),
  KEY idx_trip_collaboration_vote_trip (dream_trip_id,dream_trip_item_id,vote),
  CONSTRAINT fk_trip_collaboration_vote_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_collaboration_vote_item FOREIGN KEY (dream_trip_item_id) REFERENCES dream_trip_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_collaboration_vote_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_collaboration_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  event_type ENUM('invited','joined','role_changed','rsvp_changed','item_added','item_removed','vote_changed','member_removed','invite_revoked') NOT NULL,
  subject_user_id BIGINT UNSIGNED NULL,
  dream_trip_item_id BIGINT UNSIGNED NULL,
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_collaboration_event_trip (dream_trip_id,id),
  CONSTRAINT fk_trip_collaboration_event_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_collaboration_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_collaboration_event_subject FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_collaboration_event_item FOREIGN KEY (dream_trip_item_id) REFERENCES dream_trip_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.41')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
