USE vacation_brain;

-- Vacation Brain v1.37: traveler memory graph and reversible learned-preference controls.
CREATE TABLE traveler_preference_controls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  signal_key VARCHAR(64) NOT NULL,
  learning_state ENUM('learn','ignore','suppress') NOT NULL DEFAULT 'learn',
  correction_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_traveler_preference_control (user_id,signal_key),
  KEY idx_traveler_preference_state (user_id,learning_state,updated_at),
  CONSTRAINT fk_traveler_preference_control_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.37')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
