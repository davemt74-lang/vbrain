USE vacation_brain;

-- Vacation Brain v1.35: travel-day operations, live itinerary state, and disruption audit.
ALTER TABLE dream_trips
  ADD COLUMN operational_state ENUM('planning','booking','ready','traveling','completed') NOT NULL DEFAULT 'planning' AFTER status,
  ADD COLUMN travel_mode_started_at DATETIME NULL AFTER operational_state,
  ADD COLUMN travel_mode_completed_at DATETIME NULL AFTER travel_mode_started_at,
  ADD COLUMN last_operations_checked_at DATETIME NULL AFTER travel_mode_completed_at,
  ADD KEY idx_dream_trip_operational (user_id,operational_state,start_date,end_date);

ALTER TABLE trip_bookings
  ADD COLUMN location_name VARCHAR(180) NULL AFTER ends_at,
  ADD COLUMN location_address VARCHAR(300) NULL AFTER location_name,
  ADD COLUMN terminal VARCHAR(80) NULL AFTER location_address,
  ADD COLUMN gate VARCHAR(40) NULL AFTER terminal,
  ADD COLUMN operational_status ENUM('unknown','scheduled','on_time','delayed','cancelled','completed') NOT NULL DEFAULT 'unknown' AFTER gate,
  ADD COLUMN operational_note VARCHAR(500) NULL AFTER operational_status,
  ADD COLUMN status_source ENUM('manual','provider','import') NOT NULL DEFAULT 'manual' AFTER operational_note,
  ADD COLUMN last_status_at DATETIME NULL AFTER status_source,
  ADD COLUMN checkin_url VARCHAR(1500) NULL AFTER last_status_at,
  ADD KEY idx_trip_booking_operations (user_id,dream_trip_id,starts_at,operational_status);

CREATE TABLE trip_operation_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NULL,
  source_key VARCHAR(190) NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  severity ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  title VARCHAR(180) NOT NULL,
  body VARCHAR(700) NOT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_operation_signal (user_id,dream_trip_id,source_key),
  KEY idx_trip_operation_trip (user_id,dream_trip_id,created_at),
  KEY idx_trip_operation_booking (booking_id,created_at),
  CONSTRAINT fk_trip_operation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_operation_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_operation_booking FOREIGN KEY (booking_id) REFERENCES trip_bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill conservatively. A distant booked trip is still in Booking; Ready becomes
-- prominent only as departure approaches, while trips already in progress are Traveling.
UPDATE dream_trips
SET operational_state=CASE
  WHEN status='completed' OR (end_date IS NOT NULL AND end_date<CURDATE()) THEN 'completed'
  WHEN start_date IS NOT NULL AND start_date<=CURDATE() AND (end_date IS NULL OR end_date>=CURDATE()) THEN 'traveling'
  WHEN status='booked' AND start_date IS NOT NULL AND start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) THEN 'ready'
  WHEN status='booked' THEN 'booking'
  ELSE 'planning'
END,
travel_mode_started_at=CASE
  WHEN start_date IS NOT NULL AND start_date<=CURDATE() AND (end_date IS NULL OR end_date>=CURDATE()) THEN NOW()
  ELSE travel_mode_started_at
END,
travel_mode_completed_at=CASE
  WHEN status='completed' OR (end_date IS NOT NULL AND end_date<CURDATE()) THEN NOW()
  ELSE travel_mode_completed_at
END;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.35')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
