USE vacation_brain;

-- Vacation Brain v1.52: Budget-Aware Trip Planning + Affordability Intelligence.
-- This layer stores planning assumptions and non-destructive comparison scenarios only.
-- It never stores card/payment credentials, provider tokens, confirmation codes, mailbox
-- content, or transaction authorization. Currencies are never converted implicitly.
CREATE TABLE trip_affordability_settings (
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  risk_tolerance ENUM('comfortable','balanced','stretch') NOT NULL DEFAULT 'balanced',
  contingency_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  disruption_reserve_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  alert_overage_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  include_learned_history TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,dream_trip_id),
  KEY idx_trip_affordability_settings_trip (dream_trip_id,user_id),
  CONSTRAINT fk_trip_affordability_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_affordability_settings_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_affordability_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_trip_affordability_contingency CHECK (contingency_pct BETWEEN 0 AND 50),
  CONSTRAINT chk_trip_affordability_disruption CHECK (disruption_reserve_pct BETWEEN 0 AND 50),
  CONSTRAINT chk_trip_affordability_alert CHECK (alert_overage_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_affordability_scenarios (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  travelers SMALLINT UNSIGNED NULL,
  duration_days SMALLINT UNSIGNED NULL,
  target_budget DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  flight_override DECIMAL(12,2) NULL,
  lodging_override DECIMAL(12,2) NULL,
  transport_override DECIMAL(12,2) NULL,
  food_override DECIMAL(12,2) NULL,
  activity_override DECIMAL(12,2) NULL,
  event_override DECIMAL(12,2) NULL,
  shopping_override DECIMAL(12,2) NULL,
  fees_override DECIMAL(12,2) NULL,
  contingency_pct DECIMAL(5,2) NULL,
  disruption_reserve_pct DECIMAL(5,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_affordability_scenarios (user_id,dream_trip_id,updated_at,id),
  CONSTRAINT fk_trip_affordability_scenario_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_affordability_scenario_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT chk_trip_affordability_scenario_travelers CHECK (travelers IS NULL OR travelers BETWEEN 1 AND 30),
  CONSTRAINT chk_trip_affordability_scenario_days CHECK (duration_days IS NULL OR duration_days BETWEEN 1 AND 120),
  CONSTRAINT chk_trip_affordability_scenario_budget CHECK (target_budget IS NULL OR target_budget >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_affordability_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('settings_saved','scenario_saved','scenario_deleted') NOT NULL,
  event_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_affordability_events (user_id,dream_trip_id,created_at,id),
  CONSTRAINT fk_trip_affordability_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_affordability_event_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.52')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
