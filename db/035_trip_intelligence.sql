USE vacation_brain;

-- Vacation Brain v1.27: live trip intelligence, provider snapshots and structured itinerary scheduling.
ALTER TABLE dream_trips
  ADD COLUMN destination_catalog_id BIGINT UNSIGNED NULL AFTER destination_id,
  ADD COLUMN origin_name VARCHAR(255) NULL AFTER destination_catalog_id,
  ADD COLUMN origin_iata CHAR(3) NULL AFTER origin_name,
  ADD COLUMN destination_iata CHAR(3) NULL AFTER origin_iata,
  ADD COLUMN destination_latitude DECIMAL(10,7) NULL AFTER destination_iata,
  ADD COLUMN destination_longitude DECIMAL(10,7) NULL AFTER destination_latitude,
  ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER destination_longitude,
  ADD COLUMN intelligence_refreshed_at DATETIME NULL AFTER currency,
  ADD KEY idx_dream_destination_catalog (destination_catalog_id),
  ADD KEY idx_dream_trip_dates (start_date,end_date),
  CONSTRAINT fk_dream_destination_catalog FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE SET NULL;

ALTER TABLE dream_trip_items
  ADD COLUMN scheduled_date DATE NULL AFTER notes,
  ADD COLUMN daypart VARCHAR(24) NULL AFTER scheduled_date,
  ADD COLUMN source_provider VARCHAR(64) NULL AFTER daypart,
  ADD COLUMN source_external_id VARCHAR(255) NULL AFTER source_provider,
  ADD COLUMN source_url VARCHAR(1500) NULL AFTER source_external_id,
  ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_dream_items_schedule (dream_trip_id,scheduled_date,daypart,sort_order),
  ADD KEY idx_dream_items_source (source_provider,source_external_id);

CREATE TABLE trip_intelligence_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(64) NOT NULL,
  data_type VARCHAR(40) NOT NULL,
  query_hash CHAR(64) NOT NULL,
  payload_json JSON NULL,
  source_status VARCHAR(24) NOT NULL DEFAULT 'success',
  error_message VARCHAR(500) NULL,
  observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_snapshot_query (dream_trip_id,provider,data_type,query_hash),
  KEY idx_trip_snapshot_current (dream_trip_id,data_type,observed_at),
  KEY idx_trip_snapshot_user (user_id,data_type,observed_at),
  KEY idx_trip_snapshot_expiry (expires_at),
  CONSTRAINT fk_trip_snapshot_dream FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_snapshot_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_agent_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  agent_type VARCHAR(32) NOT NULL,
  role VARCHAR(16) NOT NULL,
  body TEXT NOT NULL,
  fingerprint CHAR(64) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_agent_fingerprint (dream_trip_id,agent_type,fingerprint),
  KEY idx_trip_agent_history (dream_trip_id,user_id,agent_type,id),
  KEY idx_trip_agent_recent (user_id,created_at),
  CONSTRAINT fk_trip_agent_dream FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_agent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE travel_provider_settings (
  provider VARCHAR(64) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  api_key_encrypted TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  settings_json JSON NULL,
  last_test_status VARCHAR(24) NOT NULL DEFAULT 'never',
  last_tested_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (provider),
  KEY idx_travel_provider_enabled (enabled,provider),
  CONSTRAINT fk_travel_provider_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO travel_provider_settings (provider,display_name,enabled,settings_json) VALUES
  ('visual_crossing','Visual Crossing',0,JSON_OBJECT()),
  ('ticketmaster','Ticketmaster Discovery',0,JSON_OBJECT()),
  ('google_places','Google Places',0,JSON_OBJECT()),
  ('skyscanner','Skyscanner',0,JSON_OBJECT('market','US','locale','en-US','currency','USD'))
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name);

-- Match legacy dream destinations to the canonical owner-managed catalog where possible.
UPDATE dream_trips dt
JOIN destination_catalog dc ON LOWER(TRIM(dc.name))=LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(dt.metadata_json,'$.destination_name'))))
SET dt.destination_catalog_id=dc.id,
    dt.destination_latitude=COALESCE(dt.destination_latitude,dc.latitude),
    dt.destination_longitude=COALESCE(dt.destination_longitude,dc.longitude)
WHERE dt.destination_catalog_id IS NULL;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.27')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
