-- Vacation Brain: persistent dashboard agent tabs.
CREATE TABLE IF NOT EXISTS dashboard_agent_tabs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  purpose VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  settings_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dashboard_agent_tabs_user (user_id,sort_order,id),
  CONSTRAINT fk_dashboard_agent_tabs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.24')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
