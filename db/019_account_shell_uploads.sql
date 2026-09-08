USE vacation_brain;

-- Vacation Brain v1.12: logged-in sidebar/account shell and local profile-photo uploads.
-- Existing users.avatar_url and travel_match_profile_photos.photo_url columns store the generated local upload URLs,
-- so no additional persistence tables are required for this phase.
INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.12') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
