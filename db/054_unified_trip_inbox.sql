USE vacation_brain;

-- Vacation Brain v1.46: Unified Trip Communications + Action Inbox.
-- This is a normalized projection/state layer over canonical trip systems; it never
-- replaces booking, agent, collaboration, proactive, or travel-operation ledgers.
CREATE TABLE trip_inbox_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NULL,
  source_type VARCHAR(48) NOT NULL,
  source_key VARCHAR(190) NOT NULL,
  thread_key VARCHAR(190) NOT NULL,
  item_type ENUM('info','review','approval','risk','booking_change','agent_result','collaboration','reminder') NOT NULL DEFAULT 'info',
  priority TINYINT UNSIGNED NOT NULL DEFAULT 50,
  urgency ENUM('now','today','before_trip','optional') NOT NULL DEFAULT 'optional',
  title VARCHAR(180) NOT NULL,
  body VARCHAR(700) NOT NULL,
  action_label VARCHAR(80) NULL,
  action_url VARCHAR(1500) NULL,
  source_status VARCHAR(48) NULL,
  requires_action TINYINT(1) NOT NULL DEFAULT 0,
  source_fingerprint CHAR(64) NOT NULL,
  due_at DATETIME NULL,
  occurred_at DATETIME NOT NULL,
  read_at DATETIME NULL,
  resolved_at DATETIME NULL,
  snoozed_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_inbox_source (user_id,source_type,source_key),
  KEY idx_trip_inbox_queue (user_id,resolved_at,snoozed_until,priority,occurred_at),
  KEY idx_trip_inbox_trip (user_id,dream_trip_id,resolved_at,priority,occurred_at),
  KEY idx_trip_inbox_thread (user_id,thread_key,occurred_at),
  KEY idx_trip_inbox_due (user_id,due_at,resolved_at),
  CONSTRAINT fk_trip_inbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_inbox_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT chk_trip_inbox_priority CHECK (priority BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_inbox_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trip_inbox_item_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('read','resolved','reopened','snoozed','unsnoozed') NOT NULL,
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_inbox_event_item (trip_inbox_item_id,id),
  KEY idx_trip_inbox_event_user (user_id,created_at),
  CONSTRAINT fk_trip_inbox_event_item FOREIGN KEY (trip_inbox_item_id) REFERENCES trip_inbox_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_inbox_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_inbox_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  delivery_mode ENUM('all','important_only','daily_digest') NOT NULL DEFAULT 'all',
  immediate_disruptions TINYINT(1) NOT NULL DEFAULT 1,
  digest_hour TINYINT UNSIGNED NOT NULL DEFAULT 8,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_trip_inbox_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_trip_inbox_digest_hour CHECK (digest_hour BETWEEN 0 AND 23)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_inbox_trip_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  muted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,dream_trip_id),
  KEY idx_trip_inbox_trip_pref (dream_trip_id,user_id),
  CONSTRAINT fk_trip_inbox_trip_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_inbox_trip_pref_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.46')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
