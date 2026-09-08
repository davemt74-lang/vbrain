USE vacation_brain;

CREATE TABLE IF NOT EXISTS user_auth (
  user_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'user',
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_user_auth_role (role),
  CONSTRAINT fk_user_auth_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS diagnosis_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  diagnosis_type VARCHAR(64) NOT NULL DEFAULT 'self_diagnosis',
  diagnosis_score TINYINT UNSIGNED NOT NULL,
  vacation_brain_score BIGINT UNSIGNED NOT NULL,
  diagnosis_level VARCHAR(64) NOT NULL,
  diagnosis_title VARCHAR(255) NOT NULL,
  summary_text TEXT NOT NULL,
  prescription_json JSON NULL,
  answer_snapshot_json JSON NOT NULL,
  trait_snapshot_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_diagnosis_user_date (user_id, created_at),
  KEY idx_diagnosis_level (diagnosis_level),
  CONSTRAINT fk_diagnosis_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_diagnosis_score CHECK (diagnosis_score BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS professional_assessment_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  email VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'requested',
  diagnosis_result_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  assessor_notes TEXT NULL,
  assessment_result_json JSON NULL,
  assigned_to BIGINT UNSIGNED NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assessment_status_created (status, created_at),
  KEY idx_assessment_email (email),
  CONSTRAINT fk_assessment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_assessment_diagnosis FOREIGN KEY (diagnosis_result_id) REFERENCES diagnosis_results(id) ON DELETE SET NULL,
  CONSTRAINT fk_assessment_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO score_rules (event_type, points, daily_limit, cooldown_minutes)
SELECT 'diagnosis_completed', 0, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM score_rules WHERE event_type='diagnosis_completed');
