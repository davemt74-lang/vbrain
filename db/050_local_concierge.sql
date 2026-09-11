USE vacation_brain;

-- Vacation Brain v1.42: Destination & Local Concierge.
-- Exact device coordinates are deliberately NOT persisted. A run records only a
-- coarse anchor label/mode and the provider results selected for that trip.
CREATE TABLE trip_local_concierge_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  anchor_mode ENUM('destination','device') NOT NULL DEFAULT 'destination',
  anchor_label VARCHAR(180) NOT NULL DEFAULT 'Trip destination',
  concierge_window ENUM('now','next_4_hours','tonight','today','tomorrow') NOT NULL DEFAULT 'now',
  interests_json JSON NULL,
  weather_json JSON NULL,
  provider_health_json JSON NULL,
  suggestions_json JSON NULL,
  observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_local_concierge_trip (user_id,dream_trip_id,observed_at,id),
  KEY idx_local_concierge_expiry (expires_at),
  CONSTRAINT fk_local_concierge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_local_concierge_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_local_concierge_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  suggestion_key CHAR(64) NOT NULL,
  action_type ENUM('added','dismissed') NOT NULL,
  dream_trip_item_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_local_concierge_action (run_id,user_id,suggestion_key,action_type),
  KEY idx_local_concierge_action_trip (user_id,dream_trip_id,created_at),
  CONSTRAINT fk_local_concierge_action_run FOREIGN KEY (run_id) REFERENCES trip_local_concierge_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_local_concierge_action_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_local_concierge_action_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_local_concierge_action_item FOREIGN KEY (dream_trip_item_id) REFERENCES dream_trip_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.42')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
