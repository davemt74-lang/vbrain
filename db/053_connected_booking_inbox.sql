USE vacation_brain;

-- Vacation Brain v1.45: Connected Booking Inbox + Reservation Change Intelligence.
-- OAuth tokens and retained redacted message source are encrypted by the application.
CREATE TABLE booking_mail_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('gmail') NOT NULL DEFAULT 'gmail',
  account_email VARCHAR(255) NOT NULL,
  status ENUM('connected','paused','error') NOT NULL DEFAULT 'connected',
  access_token_encrypted LONGTEXT NULL,
  refresh_token_encrypted LONGTEXT NULL,
  token_expires_at DATETIME NULL,
  scope VARCHAR(500) NOT NULL DEFAULT 'https://www.googleapis.com/auth/gmail.readonly',
  auto_import TINYINT(1) NOT NULL DEFAULT 1,
  use_ai TINYINT(1) NOT NULL DEFAULT 0,
  scan_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  ignored_senders_json JSON NULL,
  last_sync_at DATETIME NULL,
  next_sync_after DATETIME NULL,
  last_error VARCHAR(700) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_mail_user_provider (user_id,provider),
  KEY idx_booking_mail_due (status,next_sync_after),
  CONSTRAINT fk_booking_mail_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_mail_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  provider_message_id VARCHAR(255) NOT NULL,
  provider_thread_id VARCHAR(255) NULL,
  message_date DATETIME NULL,
  from_address VARCHAR(255) NULL,
  from_name VARCHAR(255) NULL,
  subject VARCHAR(500) NULL,
  snippet VARCHAR(700) NULL,
  classification ENUM('reservation','changed','cancelled','no_change','ignored','unknown') NOT NULL DEFAULT 'unknown',
  status ENUM('new','imported','change_review','applied','dismissed','ignored','error') NOT NULL DEFAULT 'new',
  source_encrypted LONGTEXT NULL,
  import_id BIGINT UNSIGNED NULL,
  booking_id BIGINT UNSIGNED NULL,
  error_message VARCHAR(700) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_mail_provider_message (connection_id,provider_message_id),
  KEY idx_booking_mail_message_queue (user_id,status,updated_at),
  KEY idx_booking_mail_message_booking (booking_id),
  CONSTRAINT fk_booking_mail_message_connection FOREIGN KEY (connection_id) REFERENCES booking_mail_connections(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_mail_message_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_mail_message_import FOREIGN KEY (import_id) REFERENCES trip_booking_imports(id) ON DELETE SET NULL,
  CONSTRAINT fk_booking_mail_message_booking FOREIGN KEY (booking_id) REFERENCES trip_bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_change_proposals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  change_type ENUM('changed','cancelled') NOT NULL,
  status ENUM('needs_review','applied','dismissed','superseded') NOT NULL DEFAULT 'needs_review',
  booking_snapshot_hash CHAR(64) NOT NULL,
  old_json JSON NOT NULL,
  new_json JSON NOT NULL,
  diff_json JSON NOT NULL,
  applied_at DATETIME NULL,
  dismissed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_change_message_booking (message_id,booking_id),
  KEY idx_booking_change_queue (user_id,status,updated_at),
  KEY idx_booking_change_trip (user_id,dream_trip_id,status),
  CONSTRAINT fk_booking_change_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_change_connection FOREIGN KEY (connection_id) REFERENCES booking_mail_connections(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_change_message FOREIGN KEY (message_id) REFERENCES booking_mail_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_change_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_change_booking FOREIGN KEY (booking_id) REFERENCES trip_bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.45')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
