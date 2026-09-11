USE vacation_brain;

-- Vacation Brain v1.29: persistent background trip-agent jobs and live work state.
CREATE TABLE trip_agent_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  agent_type ENUM('overview','weather','flights','events','local','itinerary','budget') NOT NULL,
  request_text TEXT NOT NULL,
  status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status_text VARCHAR(255) NULL,
  active_key VARCHAR(190) NULL,
  worker_token CHAR(32) NULL,
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  result_json JSON NULL,
  error_message VARCHAR(1000) NULL,
  queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  heartbeat_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_agent_job_active (user_id,active_key),
  KEY idx_trip_agent_job_queue (status,queued_at,id),
  KEY idx_trip_agent_job_trip (user_id,dream_trip_id,updated_at,id),
  KEY idx_trip_agent_job_agent (user_id,dream_trip_id,agent_type,status),
  KEY idx_trip_agent_job_heartbeat (status,heartbeat_at),
  CONSTRAINT fk_trip_agent_job_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_agent_job_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT chk_trip_agent_job_progress CHECK (progress BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.29')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
