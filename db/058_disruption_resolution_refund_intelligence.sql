USE vacation_brain;

-- Vacation Brain v1.50: Disruption Resolution + Refund/Credit Intelligence.
-- This is an owner-controlled reconciliation ledger around Recovery Intelligence.
-- It records claim/recovery status, user-entered financial events and evidence metadata.
-- It does not store payment-card data, mailbox/provider credentials, confirmation codes,
-- or execute refunds, credits, cancellations, purchases, rebookings or provider actions.
ALTER TABLE dream_trips
  ADD COLUMN resolution_checked_at DATETIME NULL AFTER recovery_checked_at,
  ADD KEY idx_dream_resolution_queue (user_id,resolution_checked_at);

CREATE TABLE trip_resolution_cases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  recovery_incident_id BIGINT UNSIGNED NOT NULL,
  status ENUM('tracking','claim_needed','submitted','awaiting_provider','partially_recovered','resolved','closed_no_recovery') NOT NULL DEFAULT 'tracking',
  provider_name VARCHAR(180) NULL,
  claim_deadline DATETIME NULL,
  next_followup_at DATETIME NULL,
  resolution_summary VARCHAR(1200) NULL,
  closed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_resolution_incident (recovery_incident_id),
  KEY idx_trip_resolution_trip (dream_trip_id,status,claim_deadline,next_followup_at),
  CONSTRAINT fk_trip_resolution_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_resolution_incident FOREIGN KEY (recovery_incident_id) REFERENCES trip_recovery_incidents(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_resolution_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_resolution_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_resolution_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  resolution_case_id BIGINT UNSIGNED NOT NULL,
  entry_type ENUM('replacement_cost','extra_expense','refund_expected','refund_received','credit_expected','credit_received','insurance_expected','insurance_received','other_recovery','writeoff') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  provider_name VARCHAR(180) NULL,
  source_booking_id BIGINT UNSIGNED NULL,
  occurred_at DATETIME NULL,
  note VARCHAR(1200) NULL,
  created_by BIGINT UNSIGNED NULL,
  voided_at DATETIME NULL,
  voided_by BIGINT UNSIGNED NULL,
  void_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_resolution_entry_case (resolution_case_id,voided_at,created_at,id),
  KEY idx_trip_resolution_entry_trip (dream_trip_id,currency,entry_type,voided_at),
  CONSTRAINT fk_trip_resolution_entry_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_resolution_entry_case FOREIGN KEY (resolution_case_id) REFERENCES trip_resolution_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_resolution_entry_booking FOREIGN KEY (source_booking_id) REFERENCES trip_bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_resolution_entry_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_resolution_entry_void_user FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_trip_resolution_entry_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_resolution_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  resolution_case_id BIGINT UNSIGNED NOT NULL,
  evidence_type ENUM('booking','receipt','provider_message','policy','itinerary','note','other') NOT NULL DEFAULT 'note',
  label VARCHAR(220) NOT NULL,
  source_url VARCHAR(1500) NULL,
  source_booking_id BIGINT UNSIGNED NULL,
  note VARCHAR(1200) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_resolution_evidence_case (resolution_case_id,created_at,id),
  CONSTRAINT fk_trip_resolution_evidence_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_resolution_evidence_case FOREIGN KEY (resolution_case_id) REFERENCES trip_resolution_cases(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_resolution_evidence_booking FOREIGN KEY (source_booking_id) REFERENCES trip_bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_resolution_evidence_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_resolution_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  resolution_case_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  event_type ENUM('case_created','status_changed','entry_added','entry_voided','evidence_added','deadline_attention','followup_attention','closed') NOT NULL,
  event_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_resolution_events (dream_trip_id,created_at,id),
  CONSTRAINT fk_trip_resolution_event_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_resolution_event_case FOREIGN KEY (resolution_case_id) REFERENCES trip_resolution_cases(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_resolution_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.50')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
