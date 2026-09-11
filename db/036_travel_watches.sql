USE vacation_brain;

-- Vacation Brain v1.28: destination/trip watches and proactive travel alerts.
CREATE TABLE travel_watches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  target_key VARCHAR(190) NOT NULL,
  target_type ENUM('trip','destination') NOT NULL,
  dream_trip_id BIGINT UNSIGNED NULL,
  destination_catalog_id BIGINT UNSIGNED NULL,
  destination_name VARCHAR(255) NOT NULL,
  destination_latitude DECIMAL(10,7) NULL,
  destination_longitude DECIMAL(10,7) NULL,
  watch_weather TINYINT(1) NOT NULL DEFAULT 1,
  watch_flights TINYINT(1) NOT NULL DEFAULT 0,
  watch_events TINYINT(1) NOT NULL DEFAULT 1,
  watch_places TINYINT(1) NOT NULL DEFAULT 1,
  interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 360,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_checked_at DATETIME NULL,
  next_check_at DATETIME NULL,
  last_change_at DATETIME NULL,
  last_status VARCHAR(32) NOT NULL DEFAULT 'waiting',
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_travel_watch_target (user_id,target_key),
  KEY idx_travel_watch_due (is_active,next_check_at),
  KEY idx_travel_watch_user (user_id,is_active,updated_at),
  KEY idx_travel_watch_trip (dream_trip_id),
  KEY idx_travel_watch_destination (destination_catalog_id),
  CONSTRAINT fk_travel_watch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_watch_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_watch_destination FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE travel_watch_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  watch_id BIGINT UNSIGNED NOT NULL,
  data_type VARCHAR(32) NOT NULL,
  provider VARCHAR(80) NOT NULL,
  source_status VARCHAR(24) NOT NULL DEFAULT 'success',
  payload_json JSON NULL,
  fingerprint CHAR(64) NOT NULL,
  observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_travel_watch_snapshot_history (watch_id,data_type,observed_at,id),
  KEY idx_travel_watch_snapshot_fingerprint (watch_id,data_type,fingerprint),
  CONSTRAINT fk_travel_watch_snapshot_watch FOREIGN KEY (watch_id) REFERENCES travel_watches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE travel_watch_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  watch_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  data_type VARCHAR(32) NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  direction VARCHAR(24) NOT NULL DEFAULT 'changed',
  title VARCHAR(180) NOT NULL,
  body VARCHAR(600) NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  metadata_json JSON NULL,
  notified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_travel_watch_event_fingerprint (watch_id,fingerprint),
  KEY idx_travel_watch_event_user (user_id,created_at),
  KEY idx_travel_watch_event_watch (watch_id,created_at),
  CONSTRAINT fk_travel_watch_event_watch FOREIGN KEY (watch_id) REFERENCES travel_watches(id) ON DELETE CASCADE,
  CONSTRAINT fk_travel_watch_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.28')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
