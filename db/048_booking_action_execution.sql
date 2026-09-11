USE vacation_brain;

-- Vacation Brain v1.40: controlled booking/action execution.
-- Provider secrets and operational booking references are never stored in quote or receipt JSON.
CREATE TABLE trip_booking_action_intents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NULL,
  action_id BIGINT UNSIGNED NULL,
  action_execution_id BIGINT UNSIGNED NULL,
  action_type ENUM('book','reserve','purchase','cancel','modify') NOT NULL,
  provider_slug VARCHAR(48) NOT NULL,
  adapter_mode ENUM('provider_handoff','booking_com_cancel') NOT NULL DEFAULT 'provider_handoff',
  status ENUM('draft','prepared','awaiting_approval','approved','executing','handoff_pending','verification_pending','completed','failed','cancelled') NOT NULL DEFAULT 'draft',
  idempotency_key CHAR(64) NOT NULL,
  provider_state_encrypted MEDIUMTEXT NULL,
  provider_state_hash CHAR(64) NULL,
  current_quote_id BIGINT UNSIGNED NULL,
  provider_url VARCHAR(1500) NULL,
  provider_reference VARCHAR(180) NULL,
  failure_code VARCHAR(80) NULL,
  error_message VARCHAR(1000) NULL,
  approved_at DATETIME NULL,
  execution_started_at DATETIME NULL,
  handoff_opened_at DATETIME NULL,
  executed_at DATETIME NULL,
  verified_at DATETIME NULL,
  completed_at DATETIME NULL,
  failed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_booking_action_idempotency (idempotency_key),
  KEY idx_trip_booking_action_trip (user_id,dream_trip_id,status,updated_at),
  KEY idx_trip_booking_action_booking (booking_id,status,updated_at),
  KEY idx_trip_booking_action_execution (action_execution_id,status),
  CONSTRAINT fk_trip_booking_action_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_booking_action_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_booking_action_booking FOREIGN KEY (booking_id) REFERENCES trip_bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_booking_action_action FOREIGN KEY (action_id) REFERENCES trip_agent_actions(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_booking_action_execution FOREIGN KEY (action_execution_id) REFERENCES trip_agent_action_executions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_booking_action_quotes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  intent_id BIGINT UNSIGNED NOT NULL,
  quote_version INT UNSIGNED NOT NULL DEFAULT 1,
  provider_slug VARCHAR(48) NOT NULL,
  quote_type ENUM('handoff','cancellation') NOT NULL,
  amount DECIMAL(12,2) NULL,
  currency CHAR(3) NULL,
  fee_amount DECIMAL(12,2) NULL,
  terms_summary VARCHAR(1500) NULL,
  source_state ENUM('live','provider_handoff','manual','unavailable') NOT NULL DEFAULT 'manual',
  quote_json JSON NULL,
  quote_digest CHAR(64) NOT NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_booking_action_quote_version (intent_id,quote_version),
  UNIQUE KEY uq_trip_booking_action_quote_digest (intent_id,quote_digest),
  KEY idx_trip_booking_action_quote_expiry (expires_at),
  CONSTRAINT fk_trip_booking_action_quote_intent FOREIGN KEY (intent_id) REFERENCES trip_booking_action_intents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE trip_booking_action_intents
  ADD CONSTRAINT fk_trip_booking_action_current_quote FOREIGN KEY (current_quote_id) REFERENCES trip_booking_action_quotes(id) ON DELETE SET NULL;

CREATE TABLE trip_booking_action_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  intent_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('prepared','approved','handoff_opened','handoff_confirmed','provider_request','provider_success','provider_failure','verified','completed','cancelled') NOT NULL,
  provider_slug VARCHAR(48) NOT NULL,
  provider_request_id VARCHAR(180) NULL,
  receipt_json JSON NULL,
  receipt_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_booking_action_receipt_hash (intent_id,receipt_hash),
  KEY idx_trip_booking_action_receipt_trip (user_id,dream_trip_id,created_at),
  CONSTRAINT fk_trip_booking_action_receipt_intent FOREIGN KEY (intent_id) REFERENCES trip_booking_action_intents(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_booking_action_receipt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_booking_action_receipt_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.40')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
