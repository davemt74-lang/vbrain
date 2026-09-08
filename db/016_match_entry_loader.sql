-- Vacation Brain v1.9: Travel Matching entry preload/loading experience.
-- UI-only release; no schema changes are required.
INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.9') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
