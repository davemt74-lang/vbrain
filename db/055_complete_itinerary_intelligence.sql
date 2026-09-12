USE vacation_brain;

-- Vacation Brain v1.47: Complete Itinerary Intelligence.
-- Canonical bookings and dream_trip_items remain authoritative. The new tables store
-- only trip-level timing preferences and derived itinerary issue state.
ALTER TABLE dream_trips
  ADD COLUMN itinerary_analyzed_at DATETIME NULL AFTER last_operations_checked_at,
  ADD KEY idx_dream_itinerary_analysis (user_id,itinerary_analyzed_at);

ALTER TABLE dream_trip_items
  ADD COLUMN starts_at DATETIME NULL AFTER daypart,
  ADD COLUMN ends_at DATETIME NULL AFTER starts_at,
  ADD COLUMN duration_minutes SMALLINT UNSIGNED NULL AFTER ends_at,
  ADD COLUMN timing_mode ENUM('fixed','flexible','anytime') NOT NULL DEFAULT 'flexible' AFTER duration_minutes,
  ADD COLUMN location_name VARCHAR(180) NULL AFTER timing_mode,
  ADD COLUMN location_address VARCHAR(300) NULL AFTER location_name,
  ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location_address,
  ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
  ADD COLUMN buffer_before_minutes SMALLINT UNSIGNED NULL AFTER longitude,
  ADD COLUMN buffer_after_minutes SMALLINT UNSIGNED NULL AFTER buffer_before_minutes,
  ADD KEY idx_dream_items_exact_schedule (dream_trip_id,starts_at,ends_at,timing_mode),
  ADD KEY idx_dream_items_geo (dream_trip_id,scheduled_date,latitude,longitude);

CREATE TABLE trip_itinerary_preferences (
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  airport_domestic_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  airport_international_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 180,
  station_buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 45,
  venue_buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  default_transfer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  hotel_checkin_hour TINYINT UNSIGNED NOT NULL DEFAULT 15,
  hotel_checkout_hour TINYINT UNSIGNED NOT NULL DEFAULT 11,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (dream_trip_id),
  CONSTRAINT fk_trip_itinerary_pref_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_itinerary_pref_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_itinerary_airport_domestic CHECK (airport_domestic_minutes BETWEEN 30 AND 360),
  CONSTRAINT chk_itinerary_airport_international CHECK (airport_international_minutes BETWEEN 60 AND 480),
  CONSTRAINT chk_itinerary_station_buffer CHECK (station_buffer_minutes BETWEEN 0 AND 180),
  CONSTRAINT chk_itinerary_venue_buffer CHECK (venue_buffer_minutes BETWEEN 0 AND 180),
  CONSTRAINT chk_itinerary_transfer_buffer CHECK (default_transfer_minutes BETWEEN 0 AND 240),
  CONSTRAINT chk_itinerary_checkin_hour CHECK (hotel_checkin_hour BETWEEN 0 AND 23),
  CONSTRAINT chk_itinerary_checkout_hour CHECK (hotel_checkout_hour BETWEEN 0 AND 23)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_itinerary_issues (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  issue_key VARCHAR(190) NOT NULL,
  issue_type VARCHAR(48) NOT NULL,
  severity ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  itinerary_date DATE NULL,
  source_a_key VARCHAR(190) NULL,
  source_b_key VARCHAR(190) NULL,
  title VARCHAR(180) NOT NULL,
  body VARCHAR(700) NOT NULL,
  metadata_json JSON NULL,
  first_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_itinerary_issue (dream_trip_id,issue_key),
  KEY idx_trip_itinerary_issue_open (dream_trip_id,resolved_at,severity,itinerary_date),
  KEY idx_trip_itinerary_issue_date (dream_trip_id,itinerary_date,severity),
  CONSTRAINT fk_trip_itinerary_issue_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.47')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
