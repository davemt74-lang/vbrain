USE vacation_brain;

-- Vacation Brain v1.32: persistent, user-controlled next-action queue for trip agents.
CREATE TABLE trip_agent_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  suggestion_key VARCHAR(80) NOT NULL,
  source_key VARCHAR(190) NOT NULL,
  agent_type ENUM('overview','weather','flights','events','local','itinerary','budget') NOT NULL DEFAULT 'overview',
  action_kind VARCHAR(32) NOT NULL DEFAULT 'planning',
  title VARCHAR(180) NOT NULL,
  body VARCHAR(700) NOT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 50,
  target_tab VARCHAR(24) NOT NULL DEFAULT 'overview',
  status ENUM('open','accepted','dismissed','completed','superseded') NOT NULL DEFAULT 'open',
  metadata_json JSON NULL,
  accepted_at DATETIME NULL,
  dismissed_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_agent_action_source (user_id,dream_trip_id,source_key),
  KEY idx_trip_agent_action_queue (user_id,dream_trip_id,status,priority,updated_at),
  KEY idx_trip_agent_action_batch (batch_id),
  KEY idx_trip_agent_action_suggestion (user_id,dream_trip_id,suggestion_key,status),
  CONSTRAINT fk_trip_agent_action_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_agent_action_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_agent_action_batch FOREIGN KEY (batch_id) REFERENCES trip_agent_batches(id) ON DELETE SET NULL,
  CONSTRAINT chk_trip_agent_action_priority CHECK (priority BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE trip_agent_batches
  ADD COLUMN actions_synced_at DATETIME NULL AFTER completed_at,
  ADD KEY idx_trip_agent_batch_actions_sync (actions_synced_at,id);

-- Historical batches predate the action queue. Mark them handled, but leave an in-flight
-- Overview job unsynced so its eventual result can create the first real Next Moves set.
UPDATE trip_agent_batches b
SET b.actions_synced_at=NOW()
WHERE b.actions_synced_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM trip_agent_jobs j
    WHERE j.batch_id=b.id
      AND j.agent_type='overview'
      AND j.status IN ('queued','running')
  );

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.32')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);