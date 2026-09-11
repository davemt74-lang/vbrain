USE vacation_brain;

-- Vacation Brain v1.33: execution + approval lifecycle for persistent Trip Agent Next Moves.
CREATE TABLE trip_agent_action_executions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  agent_job_id BIGINT UNSIGNED NULL,
  agent_type ENUM('overview','weather','flights','events','local','itinerary','budget') NOT NULL DEFAULT 'overview',
  status ENUM('queued','agent_working','awaiting_approval','applied','completed','rejected','failed','cancelled') NOT NULL DEFAULT 'queued',
  proposal_type ENUM('planning_task','itinerary_item','booking_handoff','budget_review') NULL,
  proposal_json JSON NULL,
  agent_output MEDIUMTEXT NULL,
  error_message VARCHAR(1000) NULL,
  queued_at DATETIME NULL,
  started_at DATETIME NULL,
  proposed_at DATETIME NULL,
  approved_at DATETIME NULL,
  applied_at DATETIME NULL,
  completed_at DATETIME NULL,
  rejected_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_action_execution_action (action_id),
  KEY idx_trip_action_execution_queue (status,updated_at,id),
  KEY idx_trip_action_execution_user_trip (user_id,dream_trip_id,status,updated_at),
  KEY idx_trip_action_execution_job (agent_job_id),
  CONSTRAINT fk_trip_action_execution_action FOREIGN KEY (action_id) REFERENCES trip_agent_actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_action_execution_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_action_execution_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_action_execution_job FOREIGN KEY (agent_job_id) REFERENCES trip_agent_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_agent_action_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  execution_id BIGINT UNSIGNED NOT NULL,
  action_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('recommended','accepted','queued','agent_working','proposal_ready','proposal_edited','approved','applied','completed','rejected','failed','cancelled','reopened') NOT NULL,
  detail_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_action_event_execution (execution_id,id),
  KEY idx_trip_action_event_trip (user_id,dream_trip_id,created_at),
  CONSTRAINT fk_trip_action_event_execution FOREIGN KEY (execution_id) REFERENCES trip_agent_action_executions(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_action_event_action FOREIGN KEY (action_id) REFERENCES trip_agent_actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_action_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_action_event_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.33')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);