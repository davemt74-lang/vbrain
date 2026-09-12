USE vacation_brain;

-- Vacation Brain v1.53: Live Trip Spend + Budget Execution.
-- Extends the owner-only Trip Cost Intelligence ledger with execution metadata.
-- No card credentials, bank tokens, provider secrets, confirmation codes, or automatic
-- financial actions are stored or executed. Receipt references are owner-private labels.
CREATE TABLE trip_spend_execution_settings (
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  alert_spend_pct DECIMAL(5,2) NOT NULL DEFAULT 85.00,
  daily_allowance_override DECIMAL(12,2) NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,dream_trip_id),
  KEY idx_trip_spend_settings_trip (dream_trip_id,user_id),
  CONSTRAINT fk_trip_spend_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_spend_settings_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_spend_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_trip_spend_alert_pct CHECK (alert_spend_pct BETWEEN 25 AND 100),
  CONSTRAINT chk_trip_spend_daily_override CHECK (daily_allowance_override IS NULL OR daily_allowance_override >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_spend_entry_meta (
  trip_cost_entry_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  receipt_status ENUM('none','captured','missing') NOT NULL DEFAULT 'none',
  receipt_reference VARCHAR(255) NULL,
  reconciliation_status ENUM('unreconciled','booking_linked','owner_confirmed','cash_confirmed','other_confirmed') NOT NULL DEFAULT 'unreconciled',
  shared_scope ENUM('private','trip_shared') NOT NULL DEFAULT 'private',
  attributed_user_id BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (trip_cost_entry_id),
  KEY idx_trip_spend_meta_trip (user_id,dream_trip_id,shared_scope),
  KEY idx_trip_spend_meta_attribution (dream_trip_id,attributed_user_id,shared_scope),
  CONSTRAINT fk_trip_spend_meta_entry FOREIGN KEY (trip_cost_entry_id) REFERENCES trip_cost_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_spend_meta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_spend_meta_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_spend_meta_attributed FOREIGN KEY (attributed_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_spend_meta_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_spend_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('settings_saved','expense_captured','expense_meta_updated','expense_voided') NOT NULL,
  event_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_spend_events (user_id,dream_trip_id,created_at,id),
  CONSTRAINT fk_trip_spend_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_spend_event_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.53')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
