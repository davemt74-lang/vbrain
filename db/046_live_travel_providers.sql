USE vacation_brain;

-- Vacation Brain v1.38: provider-neutral live travel connections, lodging intelligence,
-- booked-flight tracking metadata, and lodging watches.
CREATE TABLE travel_provider_settings (
  provider VARCHAR(48) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  capability VARCHAR(32) NOT NULL,
  api_key_encrypted TEXT NULL,
  secondary_secret_encrypted TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  settings_json JSON NULL,
  last_test_status ENUM('never','success','failed') NOT NULL DEFAULT 'never',
  last_tested_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (provider),
  KEY idx_travel_provider_capability (capability,enabled,priority),
  CONSTRAINT fk_travel_provider_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE travel_provider_usage_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(48) NOT NULL,
  data_type VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  dream_trip_id BIGINT UNSIGNED NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  http_status SMALLINT UNSIGNED NULL,
  latency_ms INT UNSIGNED NULL,
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_travel_provider_usage_provider (provider,created_at),
  KEY idx_travel_provider_usage_trip (dream_trip_id,created_at),
  CONSTRAINT fk_travel_provider_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_travel_provider_usage_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO travel_provider_settings (provider,display_name,capability,enabled,priority,settings_json) VALUES
('visual_crossing','Visual Crossing','weather',0,20,JSON_OBJECT()),
('ticketmaster','Ticketmaster Discovery','events',0,20,JSON_OBJECT()),
('google_places','Google Places','places',0,20,JSON_OBJECT()),
('skyscanner','Skyscanner','flights',0,30,JSON_OBJECT()),
('aviationstack','Aviationstack','flights',0,10,JSON_OBJECT()),
('booking_com','Booking.com Demand API','lodging',0,10,JSON_OBJECT('affiliate_id','','booker_country','us','platform','desktop','environment','production'))
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),capability=VALUES(capability);

ALTER TABLE trip_bookings
  ADD COLUMN flight_number VARCHAR(16) NULL AFTER confirmation_code,
  ADD COLUMN departure_iata CHAR(3) NULL AFTER flight_number,
  ADD COLUMN arrival_iata CHAR(3) NULL AFTER departure_iata;

ALTER TABLE travel_watches
  ADD COLUMN watch_lodging TINYINT(1) NOT NULL DEFAULT 0 AFTER watch_flights;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.38')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
