USE vacation_brain;

-- Vacation Brain v1.48: Travel Day Copilot 2.0.
-- Canonical bookings and itinerary rows remain authoritative. Copilot state stores
-- traveler-declared progress, safe checklist state, deterministic briefings, and
-- derived supervision timestamps only. It never stores payment data or provider secrets.
ALTER TABLE dream_trips
  ADD COLUMN travel_copilot_checked_at DATETIME NULL AFTER itinerary_analyzed_at,
  ADD KEY idx_dream_copilot_queue (user_id,travel_copilot_checked_at);

CREATE TABLE trip_copilot_item_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  item_key VARCHAR(190) NOT NULL,
  traveler_state ENUM('on_time','running_late','skipped','done','need_help') NOT NULL DEFAULT 'on_time',
  delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_copilot_item_state (dream_trip_id,user_id,item_key),
  KEY idx_trip_copilot_state_user (user_id,dream_trip_id,traveler_state,updated_at),
  CONSTRAINT fk_trip_copilot_state_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_copilot_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_trip_copilot_delay CHECK (delay_minutes BETWEEN 0 AND 360)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_copilot_checklist_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  checklist_date DATE NOT NULL,
  checklist_key VARCHAR(120) NOT NULL,
  category ENUM('documents','departure','arrival','lodging','transport','event','personal') NOT NULL DEFAULT 'personal',
  label VARCHAR(180) NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  source_type ENUM('system','booking','itinerary','manual') NOT NULL DEFAULT 'system',
  due_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_copilot_checklist (dream_trip_id,user_id,checklist_date,checklist_key),
  KEY idx_trip_copilot_checklist_day (user_id,dream_trip_id,checklist_date,completed_at,required),
  CONSTRAINT fk_trip_copilot_checklist_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_copilot_checklist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_copilot_briefings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  briefing_date DATE NOT NULL,
  briefing_type ENUM('morning','pre_departure','arrival','end_of_day') NOT NULL,
  title VARCHAR(180) NOT NULL,
  body VARCHAR(1200) NOT NULL,
  source_fingerprint CHAR(64) NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_copilot_briefing (dream_trip_id,user_id,briefing_date,briefing_type),
  KEY idx_trip_copilot_briefing_user (user_id,dream_trip_id,briefing_date,generated_at),
  CONSTRAINT fk_trip_copilot_briefing_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_copilot_briefing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.48')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
