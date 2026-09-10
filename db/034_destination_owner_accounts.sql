USE vacation_brain;

-- Vacation Brain v1.26: destination-owner accounts, listing ownership, trip placement, claims and audit history.
ALTER TABLE users
  ADD COLUMN account_type VARCHAR(32) NOT NULL DEFAULT 'traveler' AFTER status,
  ADD KEY idx_users_account_type_status (account_type,status);

ALTER TABLE destination_catalog
  ADD COLUMN publication_status VARCHAR(32) NOT NULL DEFAULT 'published' AFTER status,
  ADD COLUMN address VARCHAR(500) NULL AFTER country,
  ADD COLUMN typical_duration VARCHAR(120) NULL AFTER vibe,
  ADD COLUMN price_range VARCHAR(120) NULL AFTER typical_duration,
  ADD COLUMN best_season VARCHAR(255) NULL AFTER price_range,
  ADD COLUMN website_url VARCHAR(1000) NULL AFTER best_season,
  ADD COLUMN booking_url VARCHAR(1000) NULL AFTER website_url,
  ADD COLUMN contact_email VARCHAR(255) NULL AFTER booking_url,
  ADD COLUMN contact_phone VARCHAR(80) NULL AFTER contact_email,
  ADD COLUMN transportation_notes TEXT NULL AFTER contact_phone,
  ADD COLUMN official_highlights TEXT NULL AFTER transportation_notes,
  ADD COLUMN owner_notes TEXT NULL AFTER official_highlights,
  ADD COLUMN owner_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_notes,
  ADD COLUMN published_at DATETIME NULL AFTER owner_verified,
  ADD KEY idx_destination_publication (publication_status,status,featured,sort_order);

UPDATE destination_catalog
SET publication_status=CASE WHEN status='active' THEN 'published' ELSE 'draft' END,
    published_at=CASE WHEN status='active' THEN COALESCE(published_at,created_at) ELSE published_at END;

CREATE TABLE destination_trip_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  destination_catalog_id BIGINT UNSIGNED NOT NULL,
  trip_type VARCHAR(24) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_destination_trip_type (destination_catalog_id,trip_type),
  KEY idx_destination_trip_type_lookup (trip_type,is_primary,destination_catalog_id),
  CONSTRAINT fk_destination_trip_type_destination FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE destination_memberships (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  destination_catalog_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  member_role VARCHAR(24) NOT NULL DEFAULT 'manager',
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_destination_membership (destination_catalog_id,user_id),
  KEY idx_destination_membership_user (user_id,status,member_role),
  KEY idx_destination_membership_destination (destination_catalog_id,status,member_role),
  CONSTRAINT fk_destination_membership_destination FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE CASCADE,
  CONSTRAINT fk_destination_membership_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_destination_membership_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE destination_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  destination_catalog_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  business_name VARCHAR(255) NULL,
  business_url VARCHAR(1000) NULL,
  proof_text TEXT NULL,
  resolution_note TEXT NULL,
  resolved_by BIGINT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_destination_claim_state (destination_catalog_id,user_id,status),
  KEY idx_destination_claim_status (status,created_at),
  KEY idx_destination_claim_user (user_id,status,created_at),
  CONSTRAINT fk_destination_claim_destination FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE CASCADE,
  CONSTRAINT fk_destination_claim_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_destination_claim_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE destination_change_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  destination_catalog_id BIGINT UNSIGNED NOT NULL,
  changed_by BIGINT UNSIGNED NULL,
  action_name VARCHAR(64) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_destination_change_destination (destination_catalog_id,created_at),
  KEY idx_destination_change_actor (changed_by,created_at),
  CONSTRAINT fk_destination_change_destination FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE CASCADE,
  CONSTRAINT fk_destination_change_actor FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE destination_engagement_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  destination_catalog_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  metadata_json JSON NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_destination_engagement_destination (destination_catalog_id,event_type,occurred_at),
  KEY idx_destination_engagement_user (user_id,event_type,occurred_at),
  CONSTRAINT fk_destination_engagement_destination FOREIGN KEY (destination_catalog_id) REFERENCES destination_catalog(id) ON DELETE CASCADE,
  CONSTRAINT fk_destination_engagement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing catalog entries remain visible and enter the owner system as multi-day destinations by default.
INSERT IGNORE INTO destination_trip_types (destination_catalog_id,trip_type,is_primary,sort_order)
SELECT id,'multi_day',1,30 FROM destination_catalog WHERE status='active';

-- The existing San Diego example is also a natural weekend/day-trip placement.
UPDATE destination_trip_types dt
JOIN destination_catalog d ON d.id=dt.destination_catalog_id
SET dt.is_primary=0
WHERE d.slug='san-diego' AND dt.trip_type='multi_day';
INSERT IGNORE INTO destination_trip_types (destination_catalog_id,trip_type,is_primary,sort_order)
SELECT id,'weekend',1,20 FROM destination_catalog WHERE slug='san-diego';
INSERT IGNORE INTO destination_trip_types (destination_catalog_id,trip_type,is_primary,sort_order)
SELECT id,'day_trip',0,10 FROM destination_catalog WHERE slug='san-diego';

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.26')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
