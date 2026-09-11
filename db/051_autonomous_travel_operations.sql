USE vacation_brain;

-- Vacation Brain v1.43: opt-in Autonomous Travel Operations.
-- Autonomy is deliberately trip-scoped and disabled by default. The policy may
-- authorize agent research and low-risk non-transactional planning additions only.
-- Provider checkout, booking, cancellation, purchase, ticketing, payment, refunds,
-- and destructive mutations remain outside this standing policy.
CREATE TABLE trip_autonomy_controls (
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  autonomy_mode ENUM('observe','research','planning') NOT NULL DEFAULT 'observe',
  minimum_priority TINYINT UNSIGNED NOT NULL DEFAULT 90,
  max_auto_starts_per_day TINYINT UNSIGNED NOT NULL DEFAULT 3,
  max_auto_applies_per_day TINYINT UNSIGNED NOT NULL DEFAULT 2,
  per_action_estimated_limit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  daily_estimated_limit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  allowed_proposal_types_json JSON NULL,
  pause_on_verification_pending TINYINT(1) NOT NULL DEFAULT 1,
  notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,dream_trip_id),
  UNIQUE KEY uq_trip_autonomy_trip (dream_trip_id),
  KEY idx_trip_autonomy_enabled (enabled,autonomy_mode,updated_at),
  CONSTRAINT fk_trip_autonomy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_autonomy_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT chk_trip_autonomy_priority CHECK (minimum_priority BETWEEN 0 AND 100),
  CONSTRAINT chk_trip_autonomy_starts CHECK (max_auto_starts_per_day BETWEEN 0 AND 24),
  CONSTRAINT chk_trip_autonomy_applies CHECK (max_auto_applies_per_day BETWEEN 0 AND 24),
  CONSTRAINT chk_trip_autonomy_per_action CHECK (per_action_estimated_limit >= 0),
  CONSTRAINT chk_trip_autonomy_daily CHECK (daily_estimated_limit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_autonomy_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  action_id BIGINT UNSIGNED NULL,
  execution_id BIGINT UNSIGNED NULL,
  decision_key CHAR(64) NOT NULL,
  decision_type ENUM('started','applied','blocked') NOT NULL,
  proposal_type VARCHAR(32) NULL,
  reason VARCHAR(700) NOT NULL,
  estimated_amount DECIMAL(10,2) NULL,
  policy_json JSON NULL,
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_autonomy_decision (decision_key),
  KEY idx_trip_autonomy_decision_trip (user_id,dream_trip_id,created_at,id),
  KEY idx_trip_autonomy_decision_action (action_id,created_at),
  KEY idx_trip_autonomy_decision_execution (execution_id,created_at),
  CONSTRAINT fk_trip_autonomy_decision_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_autonomy_decision_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_autonomy_decision_action FOREIGN KEY (action_id) REFERENCES trip_agent_actions(id) ON DELETE SET NULL,
  CONSTRAINT fk_trip_autonomy_decision_execution FOREIGN KEY (execution_id) REFERENCES trip_agent_action_executions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE trip_agent_action_executions
  ADD COLUMN authorization_source ENUM('user','autonomy_policy') NULL AFTER approved_at;

ALTER TABLE trip_agent_action_events
  MODIFY event_type ENUM('recommended','accepted','queued','agent_working','proposal_ready','proposal_edited','approved','policy_authorized','applied','completed','rejected','failed','cancelled','reopened') NOT NULL;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.43')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
