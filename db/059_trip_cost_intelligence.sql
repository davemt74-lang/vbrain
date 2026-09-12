USE vacation_brain;

-- Vacation Brain v1.51: Trip Cost Intelligence + True Trip Economics.
-- Owner-entered category plans and actual expense facts supplement canonical bookings,
-- Trip Memory actual spend, and Disruption Resolution. No card/payment credentials,
-- confirmation codes, provider tokens, mailbox content, or automatic financial actions
-- are stored or executed here. Currencies are never converted implicitly.
CREATE TABLE trip_cost_category_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  cost_category ENUM('flight','lodging','transport','food','activity','event','shopping','fees','other') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_cost_plan (dream_trip_id,cost_category,currency),
  KEY idx_trip_cost_plan_user (user_id,dream_trip_id,currency),
  CONSTRAINT fk_trip_cost_plan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_cost_plan_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_cost_plan_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_trip_cost_plan_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_cost_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  cost_category ENUM('flight','lodging','transport','food','activity','event','shopping','fees','other') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  merchant_name VARCHAR(180) NULL,
  source_booking_id BIGINT UNSIGNED NULL,
  occurred_at DATETIME NULL,
  note VARCHAR(1200) NULL,
  created_by BIGINT UNSIGNED NULL,
  voided_at DATETIME NULL,
  voided_by BIGINT UNSIGNED NULL,
  void_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_cost_entry_trip (user_id,dream_trip_id,currency,cost_category,voided_at),
  KEY idx_trip_cost_entry_booking (source_booking_id,voided_at),
  CONSTRAINT fk_trip_cost_entry_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_cost_entry_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_cost_entry_booking FOREIGN KEY (source_booking_id) REFERENCES trip_bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_cost_entry_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_cost_entry_voided_by FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_trip_cost_entry_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_cost_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('category_plan_saved','category_plan_cleared','expense_added','expense_voided') NOT NULL,
  event_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_cost_events (user_id,dream_trip_id,created_at,id),
  CONSTRAINT fk_trip_cost_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_cost_event_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.51')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
