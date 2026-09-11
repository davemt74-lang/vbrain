USE vacation_brain;

-- Vacation Brain v1.36: post-trip memory, structured feedback, and learned personalization.
CREATE TABLE trip_memories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  destination_catalog_id BIGINT UNSIGNED NULL,
  status ENUM('draft','complete') NOT NULL DEFAULT 'draft',
  overall_rating TINYINT UNSIGNED NULL,
  value_rating TINYINT UNSIGNED NULL,
  pace_fit ENUM('too_slow','just_right','too_packed') NULL,
  would_return ENUM('yes','maybe','no') NULL,
  target_budget_snapshot DECIMAL(12,2) NULL,
  booked_spend_snapshot DECIMAL(12,2) NULL,
  actual_spend DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  summary_text VARCHAR(1400) NULL,
  private_notes TEXT NULL,
  favorite_moment VARCHAR(700) NULL,
  biggest_miss VARCHAR(700) NULL,
  learning_enabled TINYINT(1) NOT NULL DEFAULT 1,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_memory_trip (user_id,dream_trip_id),
  KEY idx_trip_memory_learning (user_id,status,learning_enabled,completed_at),
  KEY idx_trip_memory_destination (destination_catalog_id,status),
  CONSTRAINT fk_trip_memory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_memory_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_memory_destination FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE SET NULL,
  CONSTRAINT chk_trip_memory_overall CHECK (overall_rating IS NULL OR overall_rating BETWEEN 1 AND 5),
  CONSTRAINT chk_trip_memory_value CHECK (value_rating IS NULL OR value_rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_memory_signals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  signal_key VARCHAR(64) NOT NULL,
  signal_value TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_memory_signal (user_id,dream_trip_id,signal_key),
  KEY idx_trip_memory_signal_learning (user_id,signal_key,signal_value),
  CONSTRAINT fk_trip_memory_signal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_memory_signal_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT chk_trip_memory_signal_value CHECK (signal_value BETWEEN -2 AND 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_memory_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  source_kind ENUM('itinerary','booking') NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  item_type VARCHAR(40) NOT NULL,
  title VARCHAR(180) NOT NULL,
  rating TINYINT UNSIGNED NULL,
  sentiment ENUM('favorite','good','neutral','skip') NOT NULL DEFAULT 'neutral',
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trip_memory_item (user_id,dream_trip_id,source_kind,source_id),
  KEY idx_trip_memory_item_learning (user_id,item_type,sentiment,rating),
  CONSTRAINT fk_trip_memory_item_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_memory_item_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT chk_trip_memory_item_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Completed operational trips are immediately eligible for a recap; no background job is required.
INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.36')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
