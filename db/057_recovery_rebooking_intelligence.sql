USE vacation_brain;

-- Vacation Brain v1.49: Recovery & Rebooking Intelligence.
-- Recovery state is derived from canonical trip facts and public-safe provider search
-- snapshots. No payment data, confirmation codes, mailbox secrets, provider tokens,
-- or direct provider mutation state is stored here.
ALTER TABLE dream_trips
  ADD COLUMN recovery_checked_at DATETIME NULL AFTER travel_copilot_checked_at,
  ADD KEY idx_dream_recovery_queue (user_id,recovery_checked_at);

CREATE TABLE trip_recovery_incidents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  incident_key VARCHAR(190) NOT NULL,
  incident_type ENUM('flight_cancelled','flight_delayed','missed_connection_risk','lodging_disruption','transport_disruption','timing_conflict','weather_disruption','traveler_delay','help_requested','general_disruption') NOT NULL,
  source_type VARCHAR(60) NOT NULL,
  source_key VARCHAR(190) NULL,
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('open','reviewed','resolved','dismissed') NOT NULL DEFAULT 'open',
  title VARCHAR(220) NOT NULL,
  summary VARCHAR(1200) NOT NULL,
  starts_at DATETIME NULL,
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_recovery_incident (dream_trip_id,incident_key),
  KEY idx_trip_recovery_open (dream_trip_id,status,severity,last_seen_at),
  CONSTRAINT fk_trip_recovery_incident_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_recovery_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  incident_id BIGINT UNSIGNED NOT NULL,
  option_key VARCHAR(190) NOT NULL,
  option_type ENUM('provider_support','alternate_flight','alternate_lodging','alternate_transport','resequence','protect_next_booking','monitor','local_alternative') NOT NULL,
  status ENUM('active','stale','selected','dismissed') NOT NULL DEFAULT 'active',
  title VARCHAR(220) NOT NULL,
  summary VARCHAR(1400) NOT NULL,
  provider_name VARCHAR(180) NULL,
  source_url VARCHAR(1500) NULL,
  amount DECIMAL(12,2) NULL,
  currency CHAR(3) NULL,
  booking_type ENUM('flight','lodging','transport','event','restaurant','activity','other') NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  transaction_required TINYINT(1) NOT NULL DEFAULT 0,
  provider_observed_at DATETIME NULL,
  expires_at DATETIME NULL,
  prepared_booking_id BIGINT UNSIGNED NULL,
  public_payload_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_recovery_option (incident_id,option_key),
  KEY idx_trip_recovery_option_status (dream_trip_id,status,expires_at),
  CONSTRAINT fk_trip_recovery_option_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_recovery_option_incident FOREIGN KEY (incident_id) REFERENCES trip_recovery_incidents(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_recovery_option_booking FOREIGN KEY (prepared_booking_id) REFERENCES trip_bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_recovery_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  incident_id BIGINT UNSIGNED NULL,
  option_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  event_type ENUM('detected','refreshed','reviewed','resolved','dismissed','option_selected','booking_prepared') NOT NULL,
  event_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_recovery_events (dream_trip_id,created_at),
  CONSTRAINT fk_trip_recovery_event_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_recovery_event_incident FOREIGN KEY (incident_id) REFERENCES trip_recovery_incidents(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_recovery_event_option FOREIGN KEY (option_id) REFERENCES trip_recovery_options(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_recovery_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.49')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
