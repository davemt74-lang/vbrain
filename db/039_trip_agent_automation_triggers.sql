USE vacation_brain;

-- Vacation Brain v1.31: durable, cost-bounded watch alerts that wake trip agents.
ALTER TABLE trip_agent_batches
  MODIFY COLUMN scope ENUM('single','all','automation') NOT NULL DEFAULT 'single';

CREATE TABLE trip_agent_automation_triggers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  travel_watch_event_id BIGINT UNSIGNED NOT NULL,
  data_type VARCHAR(32) NOT NULL,
  agent_type ENUM('weather','flights','events','local') NOT NULL,
  status ENUM('pending','dispatching','deferred','dispatched','failed','suppressed') NOT NULL DEFAULT 'pending',
  claim_token CHAR(32) NULL,
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  batch_id BIGINT UNSIGNED NULL,
  error_message VARCHAR(1000) NULL,
  dispatched_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_agent_automation_event (travel_watch_event_id),
  KEY idx_trip_agent_automation_queue (status,next_attempt_at,id),
  KEY idx_trip_agent_automation_trip (user_id,dream_trip_id,status,updated_at),
  KEY idx_trip_agent_automation_batch (batch_id),
  CONSTRAINT fk_trip_agent_automation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_agent_automation_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_agent_automation_event FOREIGN KEY (travel_watch_event_id) REFERENCES travel_watch_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_agent_automation_batch FOREIGN KEY (batch_id) REFERENCES trip_agent_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.31')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
