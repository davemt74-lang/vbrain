USE vacation_brain;

-- Vacation Brain v1.44: Booking Inbox + Automatic Trip Import.
-- Private source confirmations are encrypted by the application before storage.
CREATE TABLE trip_booking_imports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NULL,
  booking_id BIGINT UNSIGNED NULL,
  status ENUM('imported','parsed','matched','needs_review','verified','rejected') NOT NULL DEFAULT 'imported',
  source_type ENUM('paste','file','email') NOT NULL DEFAULT 'paste',
  original_filename VARCHAR(255) NULL,
  mime_type VARCHAR(120) NULL,
  source_size INT UNSIGNED NOT NULL DEFAULT 0,
  source_hash CHAR(64) NOT NULL,
  source_encrypted LONGTEXT NOT NULL,
  parsed_json JSON NULL,
  sensitive_encrypted LONGTEXT NULL,
  parser_mode ENUM('local','ai','local_ai') NOT NULL DEFAULT 'local',
  ai_used TINYINT(1) NOT NULL DEFAULT 0,
  match_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
  match_reason VARCHAR(500) NULL,
  booking_fingerprint CHAR(64) NULL,
  error_message VARCHAR(700) NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_import_source (user_id,source_hash),
  UNIQUE KEY uq_booking_import_fingerprint (user_id,booking_fingerprint),
  KEY idx_booking_import_queue (user_id,status,updated_at),
  KEY idx_booking_import_trip (user_id,dream_trip_id,status),
  KEY idx_booking_import_booking (booking_id),
  CONSTRAINT fk_booking_import_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_import_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE SET NULL,
  CONSTRAINT fk_booking_import_booking FOREIGN KEY (booking_id) REFERENCES trip_bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_booking_import_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('received','parsed','matched','needs_review','booking_linked','verified','rejected','duplicate') NOT NULL,
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_booking_import_event (import_id,id),
  KEY idx_booking_import_user (user_id,created_at),
  CONSTRAINT fk_booking_import_event_import FOREIGN KEY (import_id) REFERENCES trip_booking_imports(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_import_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.44')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
