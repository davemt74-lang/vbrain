USE vacation_brain;

-- Vacation Brain v1.30: immutable shared intelligence snapshots for queued trip-agent work.
CREATE TABLE trip_agent_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  scope ENUM('single','all') NOT NULL DEFAULT 'single',
  agent_types_json JSON NOT NULL,
  required_types_json JSON NOT NULL,
  refreshed_types_json JSON NULL,
  snapshot_ids_json JSON NULL,
  context_json JSON NULL,
  status ENUM('queued','refreshing','ready','failed','cancelled') NOT NULL DEFAULT 'queued',
  worker_token CHAR(32) NULL,
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(1000) NULL,
  queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  heartbeat_at DATETIME NULL,
  ready_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_trip_agent_batch_queue (status,queued_at,id),
  KEY idx_trip_agent_batch_trip (user_id,dream_trip_id,updated_at,id),
  KEY idx_trip_agent_batch_heartbeat (status,heartbeat_at),
  CONSTRAINT fk_trip_agent_batch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_agent_batch_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE trip_agent_jobs
  ADD COLUMN batch_id BIGINT UNSIGNED NULL AFTER dream_trip_id,
  ADD KEY idx_trip_agent_job_batch (batch_id,status,id),
  ADD CONSTRAINT fk_trip_agent_job_batch FOREIGN KEY (batch_id) REFERENCES trip_agent_batches(id) ON DELETE SET NULL;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.30')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
