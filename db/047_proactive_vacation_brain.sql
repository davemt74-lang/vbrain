USE vacation_brain;

-- Vacation Brain v1.39: proactive trip risk/opportunity intelligence, user controls,
-- deduplicated issue ledger, and morning/travel-day briefings.
CREATE TABLE proactive_travel_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  proactive_enabled TINYINT(1) NOT NULL DEFAULT 1,
  urgent_alerts TINYINT(1) NOT NULL DEFAULT 1,
  opportunity_alerts TINYINT(1) NOT NULL DEFAULT 1,
  auto_research TINYINT(1) NOT NULL DEFAULT 1,
  morning_briefing TINYINT(1) NOT NULL DEFAULT 1,
  minimum_severity ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  quiet_start TIME NULL,
  quiet_end TIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_proactive_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_proactive_issues (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  issue_key VARCHAR(160) NOT NULL,
  issue_type ENUM('risk','conflict','readiness','opportunity') NOT NULL,
  severity ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
  title VARCHAR(180) NOT NULL,
  body VARCHAR(700) NOT NULL,
  source_type VARCHAR(48) NOT NULL DEFAULT 'vacation_brain',
  source_state ENUM('live','cached','indicative','manual','inferred','historical','mixed') NOT NULL DEFAULT 'inferred',
  confidence TINYINT UNSIGNED NOT NULL DEFAULT 70,
  target_tab VARCHAR(24) NOT NULL DEFAULT 'overview',
  agent_type VARCHAR(24) NOT NULL DEFAULT 'overview',
  recommended_actions_json JSON NULL,
  metadata_json JSON NULL,
  fingerprint CHAR(64) NOT NULL,
  status ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  notified_at DATETIME NULL,
  auto_research_started_at DATETIME NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_proactive_issue (user_id,dream_trip_id,issue_key),
  KEY idx_trip_proactive_open (user_id,status,severity,last_seen_at),
  KEY idx_trip_proactive_trip (dream_trip_id,status,last_seen_at),
  CONSTRAINT fk_trip_proactive_issue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_proactive_issue_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT chk_trip_proactive_confidence CHECK (confidence BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_proactive_briefings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  briefing_date DATE NOT NULL,
  briefing_kind ENUM('morning','travel_day') NOT NULL DEFAULT 'morning',
  headline VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  payload_json JSON NULL,
  fingerprint CHAR(64) NOT NULL,
  notified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_proactive_briefing (user_id,dream_trip_id,briefing_date,briefing_kind),
  KEY idx_trip_proactive_briefing_user (user_id,briefing_date,created_at),
  CONSTRAINT fk_trip_proactive_brief_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_proactive_brief_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.39')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
