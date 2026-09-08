SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS vacation_brain
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;
USE vacation_brain;

-- ------------------------------------------------------------
-- USERS / SETTINGS
-- ------------------------------------------------------------
CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  username VARCHAR(80) NULL,
  display_name VARCHAR(120) NULL,
  avatar_url VARCHAR(1000) NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  country_code CHAR(2) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_active_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_status_last_active (status, last_active_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_settings (
  user_id BIGINT UNSIGNED NOT NULL,
  sarcasm_level TINYINT UNSIGNED NOT NULL DEFAULT 2,
  notification_level VARCHAR(32) NOT NULL DEFAULT 'normal',
  location_enabled TINYINT(1) NOT NULL DEFAULT 0,
  personalized_discovery_enabled TINYINT(1) NOT NULL DEFAULT 1,
  merch_personalization_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_sarcasm_level CHECK (sarcasm_level BETWEEN 0 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- TAXONOMY / TRAITS
-- ------------------------------------------------------------
CREATE TABLE tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(100) NOT NULL,
  name VARCHAR(120) NOT NULL,
  tag_group VARCHAR(64) NOT NULL DEFAULT 'general',
  description TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags_slug (slug),
  KEY idx_tags_group_active (tag_group, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE traits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(100) NOT NULL,
  name VARCHAR(120) NOT NULL,
  trait_group VARCHAR(64) NOT NULL DEFAULT 'general',
  description TEXT NULL,
  minimum_score DECIMAL(8,3) NOT NULL DEFAULT 0,
  maximum_score DECIMAL(8,3) NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_traits_slug (slug),
  KEY idx_traits_group_active (trait_group, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_traits (
  user_id BIGINT UNSIGNED NOT NULL,
  trait_id BIGINT UNSIGNED NOT NULL,
  score DECIMAL(8,3) NOT NULL DEFAULT 50,
  confidence DECIMAL(6,3) NOT NULL DEFAULT 0,
  interaction_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, trait_id),
  KEY idx_user_traits_trait_score (trait_id, score),
  CONSTRAINT fk_user_traits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_traits_trait FOREIGN KEY (trait_id) REFERENCES traits(id) ON DELETE CASCADE,
  CONSTRAINT chk_user_traits_score CHECK (score BETWEEN 0 AND 100),
  CONSTRAINT chk_user_traits_confidence CHECK (confidence BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- MASTER CONTENT LIBRARY
-- ------------------------------------------------------------
CREATE TABLE content_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_type VARCHAR(64) NOT NULL,
  slug VARCHAR(180) NULL,
  title VARCHAR(255) NULL,
  body TEXT NOT NULL,
  short_body VARCHAR(500) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  humor_level TINYINT UNSIGNED NOT NULL DEFAULT 2,
  sarcasm_level TINYINT UNSIGNED NOT NULL DEFAULT 2,
  maturity_rating VARCHAR(16) NOT NULL DEFAULT 'general',
  premium TINYINT(1) NOT NULL DEFAULT 0,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  seasonal TINYINT(1) NOT NULL DEFAULT 0,
  language VARCHAR(16) NOT NULL DEFAULT 'en',
  metadata_json JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  published_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_content_slug (slug),
  KEY idx_content_type_status (content_type, status),
  KEY idx_content_publish (status, published_at),
  KEY idx_content_featured (featured, status),
  CONSTRAINT fk_content_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_content_humor CHECK (humor_level BETWEEN 0 AND 5),
  CONSTRAINT chk_content_sarcasm CHECK (sarcasm_level BETWEEN 0 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE content_tags (
  content_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  weight DECIMAL(6,3) NOT NULL DEFAULT 1,
  PRIMARY KEY (content_id, tag_id),
  KEY idx_content_tags_tag_weight (tag_id, weight),
  CONSTRAINT fk_content_tags_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE content_choices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_id BIGINT UNSIGNED NOT NULL,
  choice_key VARCHAR(32) NOT NULL,
  label VARCHAR(255) NOT NULL,
  description TEXT NULL,
  image_url VARCHAR(1000) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_content_choice_key (content_id, choice_key),
  KEY idx_choices_content_sort (content_id, sort_order),
  CONSTRAINT fk_choices_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE choice_trait_effects (
  choice_id BIGINT UNSIGNED NOT NULL,
  trait_id BIGINT UNSIGNED NOT NULL,
  score_delta DECIMAL(8,3) NOT NULL DEFAULT 0,
  confidence_delta DECIMAL(8,3) NOT NULL DEFAULT 1,
  PRIMARY KEY (choice_id, trait_id),
  KEY idx_choice_effects_trait (trait_id),
  CONSTRAINT fk_choice_effects_choice FOREIGN KEY (choice_id) REFERENCES content_choices(id) ON DELETE CASCADE,
  CONSTRAINT fk_choice_effects_trait FOREIGN KEY (trait_id) REFERENCES traits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE swipe_decks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(120) NOT NULL,
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  cover_image_url VARCHAR(1000) NULL,
  deck_type VARCHAR(64) NOT NULL DEFAULT 'standard',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  premium TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_swipe_decks_slug (slug),
  KEY idx_decks_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE swipe_deck_items (
  deck_id BIGINT UNSIGNED NOT NULL,
  content_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  weight DECIMAL(6,3) NOT NULL DEFAULT 1,
  PRIMARY KEY (deck_id, content_id),
  KEY idx_deck_items_sort (deck_id, sort_order),
  CONSTRAINT fk_deck_items_deck FOREIGN KEY (deck_id) REFERENCES swipe_decks(id) ON DELETE CASCADE,
  CONSTRAINT fk_deck_items_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE content_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  title VARCHAR(255) NULL,
  body TEXT NOT NULL,
  metadata_json JSON NULL,
  changed_by BIGINT UNSIGNED NULL,
  change_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_content_version (content_id, version_number),
  CONSTRAINT fk_versions_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_versions_changed_by FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- DESTINATIONS / PLACES / WEATHER
-- ------------------------------------------------------------
CREATE TABLE destinations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(180) NOT NULL,
  city VARCHAR(160) NOT NULL,
  region VARCHAR(160) NULL,
  country VARCHAR(160) NOT NULL,
  country_code CHAR(2) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  timezone VARCHAR(64) NULL,
  description TEXT NULL,
  hero_image_url VARCHAR(1000) NULL,
  climate_json JSON NULL,
  metadata_json JSON NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_destinations_slug (slug),
  KEY idx_destinations_country_region (country_code, region),
  KEY idx_destinations_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE destination_tags (
  destination_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  weight DECIMAL(6,3) NOT NULL DEFAULT 1,
  PRIMARY KEY (destination_id, tag_id),
  CONSTRAINT fk_destination_tags_destination FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE,
  CONSTRAINT fk_destination_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE providers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_name VARCHAR(255) NOT NULL,
  provider_type VARCHAR(64) NOT NULL,
  contact_name VARCHAR(180) NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  website_url VARCHAR(1000) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  verified TINYINT(1) NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_providers_type_status (provider_type, status),
  KEY idx_providers_verified (verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE places (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  place_type VARCHAR(64) NOT NULL,
  description TEXT NULL,
  address VARCHAR(500) NULL,
  city VARCHAR(160) NULL,
  region VARCHAR(160) NULL,
  country VARCHAR(160) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  website_url VARCHAR(1000) NULL,
  booking_url VARCHAR(1000) NULL,
  price_level TINYINT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_places_type_city (place_type, city),
  KEY idx_places_provider (provider_id),
  CONSTRAINT fk_places_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  CONSTRAINT chk_places_price_level CHECK (price_level IS NULL OR price_level BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE place_tags (
  place_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  weight DECIMAL(6,3) NOT NULL DEFAULT 1,
  PRIMARY KEY (place_id, tag_id),
  CONSTRAINT fk_place_tags_place FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE,
  CONSTRAINT fk_place_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE weather_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  location_type VARCHAR(32) NOT NULL,
  destination_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  temperature DECIMAL(6,2) NULL,
  condition_text VARCHAR(160) NULL,
  humidity DECIMAL(6,2) NULL,
  weather_json JSON NULL,
  observed_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_weather_destination_observed (destination_id, observed_at),
  KEY idx_weather_user_observed (user_id, observed_at),
  CONSTRAINT fk_weather_destination FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE,
  CONSTRAINT fk_weather_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- VACATION BREAKS / SUBSTITUTIONS
-- ------------------------------------------------------------
CREATE TABLE vacation_break_details (
  content_id BIGINT UNSIGNED NOT NULL,
  destination_id BIGINT UNSIGNED NULL,
  duration_seconds INT UNSIGNED NOT NULL DEFAULT 60,
  audio_url VARCHAR(1000) NULL,
  video_url VARCHAR(1000) NULL,
  image_url VARCHAR(1000) NULL,
  ambient_audio_url VARCHAR(1000) NULL,
  activity_text TEXT NULL,
  ending_text TEXT NULL,
  metadata_json JSON NULL,
  PRIMARY KEY (content_id),
  KEY idx_break_destination (destination_id),
  CONSTRAINT fk_break_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_break_destination FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE substitution_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(180) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  destination_id BIGINT UNSIGNED NULL,
  theme VARCHAR(120) NOT NULL,
  duration_minutes INT UNSIGNED NULL,
  budget_level TINYINT UNSIGNED NULL,
  content_id BIGINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_substitution_slug (slug),
  KEY idx_substitution_theme_status (theme, status),
  CONSTRAINT fk_substitution_destination FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE SET NULL,
  CONSTRAINT fk_substitution_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL,
  CONSTRAINT chk_substitution_budget CHECK (budget_level IS NULL OR budget_level BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE substitution_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  substitution_id BIGINT UNSIGNED NOT NULL,
  step_number INT UNSIGNED NOT NULL,
  activity_type VARCHAR(100) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  duration_minutes INT UNSIGNED NULL,
  recommended_time TIME NULL,
  required_tags_json JSON NULL,
  optional_tags_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_substitution_step (substitution_id, step_number),
  CONSTRAINT fk_substitution_steps_template FOREIGN KEY (substitution_id) REFERENCES substitution_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- DREAMS / INTENT
-- ------------------------------------------------------------
CREATE TABLE dream_trips (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'fantasy',
  destination_id BIGINT UNSIGNED NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  travelers SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  target_budget DECIMAL(12,2) NULL,
  dream_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
  booking_readiness DECIMAL(6,3) NOT NULL DEFAULT 0,
  cover_image_url VARCHAR(1000) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dream_user_status (user_id, status),
  KEY idx_dream_destination (destination_id),
  KEY idx_dream_readiness (booking_readiness),
  CONSTRAINT fk_dream_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dream_destination FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE SET NULL,
  CONSTRAINT chk_dream_level CHECK (dream_level BETWEEN 1 AND 5),
  CONSTRAINT chk_booking_readiness CHECK (booking_readiness BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- PROVIDER SUBSCRIPTIONS / PACKAGES / DISCOVERY / BOOKINGS
-- ------------------------------------------------------------
CREATE TABLE provider_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  package_limit INT UNSIGNED NULL,
  featured_slots INT UNSIGNED NOT NULL DEFAULT 0,
  analytics_level VARCHAR(32) NOT NULL DEFAULT 'basic',
  lead_access TINYINT(1) NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_plans_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE provider_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  billing_provider VARCHAR(64) NULL,
  billing_customer_id VARCHAR(255) NULL,
  billing_subscription_id VARCHAR(255) NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  renews_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_provider_sub_provider_status (provider_id, status),
  UNIQUE KEY uq_billing_subscription (billing_provider, billing_subscription_id),
  CONSTRAINT fk_provider_sub_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  CONSTRAINT fk_provider_sub_plan FOREIGN KEY (plan_id) REFERENCES provider_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travel_packages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NOT NULL,
  destination_id BIGINT UNSIGNED NULL,
  slug VARCHAR(180) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  hero_image_url VARCHAR(1000) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  base_price DECIMAL(12,2) NULL,
  price_per_person DECIMAL(12,2) NULL,
  minimum_travelers SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  maximum_travelers SMALLINT UNSIGNED NULL,
  nights SMALLINT UNSIGNED NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  flexible_dates TINYINT(1) NOT NULL DEFAULT 0,
  deposit_required TINYINT(1) NOT NULL DEFAULT 0,
  deposit_amount DECIMAL(12,2) NULL,
  booking_url VARCHAR(1000) NULL,
  commission_type VARCHAR(32) NOT NULL DEFAULT 'percent',
  commission_value DECIMAL(10,4) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  featured TINYINT(1) NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_travel_packages_slug (slug),
  KEY idx_packages_provider_status (provider_id, status),
  KEY idx_packages_destination_status (destination_id, status),
  KEY idx_packages_featured (featured, status),
  CONSTRAINT fk_packages_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  CONSTRAINT fk_packages_destination FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE package_components (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  component_type VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  quantity DECIMAL(10,2) NULL,
  included TINYINT(1) NOT NULL DEFAULT 1,
  metadata_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_package_components_package_type (package_id, component_type),
  CONSTRAINT fk_package_components_package FOREIGN KEY (package_id) REFERENCES travel_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE package_trait_targets (
  package_id BIGINT UNSIGNED NOT NULL,
  trait_id BIGINT UNSIGNED NOT NULL,
  desired_score DECIMAL(8,3) NOT NULL DEFAULT 50,
  weight DECIMAL(8,3) NOT NULL DEFAULT 1,
  PRIMARY KEY (package_id, trait_id),
  CONSTRAINT fk_package_targets_package FOREIGN KEY (package_id) REFERENCES travel_packages(id) ON DELETE CASCADE,
  CONSTRAINT fk_package_targets_trait FOREIGN KEY (trait_id) REFERENCES traits(id) ON DELETE CASCADE,
  CONSTRAINT chk_package_target_score CHECK (desired_score BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE discovery_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  campaign_type VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  budget DECIMAL(12,2) NULL,
  monthly_fee DECIMAL(12,2) NULL,
  targeting_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_discovery_provider_status (provider_id, status),
  KEY idx_discovery_dates (starts_at, ends_at),
  CONSTRAINT fk_discovery_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NOT NULL,
  package_id BIGINT UNSIGNED NULL,
  external_booking_id VARCHAR(255) NULL,
  booking_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  gross_amount DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  commission_rate DECIMAL(10,4) NULL,
  commission_amount DECIMAL(12,2) NULL,
  commission_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  booked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  travel_start_at DATETIME NULL,
  travel_end_at DATETIME NULL,
  metadata_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_provider_external (provider_id, external_booking_id),
  KEY idx_bookings_user_date (user_id, booked_at),
  KEY idx_bookings_provider_status (provider_id, booking_status),
  KEY idx_bookings_commission (commission_status),
  CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_package FOREIGN KEY (package_id) REFERENCES travel_packages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE discovery_impressions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  content_id BIGINT UNSIGNED NULL,
  package_id BIGINT UNSIGNED NULL,
  place_id BIGINT UNSIGNED NULL,
  placement VARCHAR(100) NOT NULL,
  clicked TINYINT(1) NOT NULL DEFAULT 0,
  saved TINYINT(1) NOT NULL DEFAULT 0,
  booked TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_impressions_campaign_date (campaign_id, created_at),
  KEY idx_impressions_user_date (user_id, created_at),
  CONSTRAINT fk_impressions_campaign FOREIGN KEY (campaign_id) REFERENCES discovery_campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_impressions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_impressions_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_impressions_package FOREIGN KEY (package_id) REFERENCES travel_packages(id) ON DELETE SET NULL,
  CONSTRAINT fk_impressions_place FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE dream_trip_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dream_trip_id BIGINT UNSIGNED NOT NULL,
  item_type VARCHAR(64) NOT NULL,
  destination_id BIGINT UNSIGNED NULL,
  place_id BIGINT UNSIGNED NULL,
  package_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(1000) NULL,
  price DECIMAL(12,2) NULL,
  notes TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dream_items_trip_sort (dream_trip_id, sort_order),
  CONSTRAINT fk_dream_items_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_dream_items_destination FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE SET NULL,
  CONSTRAINT fk_dream_items_place FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE SET NULL,
  CONSTRAINT fk_dream_items_package FOREIGN KEY (package_id) REFERENCES travel_packages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- SCORE / CHECKINS / ACHIEVEMENTS
-- ------------------------------------------------------------
CREATE TABLE score_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  points INT NOT NULL DEFAULT 0,
  daily_limit INT UNSIGNED NULL,
  lifetime_limit INT UNSIGNED NULL,
  cooldown_minutes INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  start_at DATETIME NULL,
  end_at DATETIME NULL,
  metadata_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_score_rules_event_active (event_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_score_summary (
  user_id BIGINT UNSIGNED NOT NULL,
  vacation_brain_score BIGINT UNSIGNED NOT NULL DEFAULT 0,
  current_streak INT UNSIGNED NOT NULL DEFAULT 0,
  longest_streak INT UNSIGNED NOT NULL DEFAULT 0,
  lifetime_checkins INT UNSIGNED NOT NULL DEFAULT 0,
  score_level VARCHAR(64) NOT NULL DEFAULT 'thinking_about_it',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_score_summary_score (vacation_brain_score),
  CONSTRAINT fk_score_summary_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE daily_checkins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  checkin_date DATE NOT NULL,
  response VARCHAR(64) NOT NULL DEFAULT 'yes',
  score_awarded INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_daily_checkin (user_id, checkin_date),
  KEY idx_daily_checkins_date (checkin_date),
  CONSTRAINT fk_daily_checkin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE achievements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(180) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  badge_image_url VARCHAR(1000) NULL,
  content_id BIGINT UNSIGNED NULL,
  rarity VARCHAR(32) NOT NULL DEFAULT 'common',
  active TINYINT(1) NOT NULL DEFAULT 1,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_achievements_slug (slug),
  KEY idx_achievements_active_rarity (active, rarity),
  CONSTRAINT fk_achievements_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE achievement_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  achievement_id BIGINT UNSIGNED NOT NULL,
  rule_type VARCHAR(64) NOT NULL,
  event_type VARCHAR(64) NULL,
  threshold DECIMAL(12,3) NULL,
  time_window VARCHAR(64) NULL,
  filter_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_achievement_rules_event (event_type),
  CONSTRAINT fk_achievement_rules_achievement FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_achievements (
  user_id BIGINT UNSIGNED NOT NULL,
  achievement_id BIGINT UNSIGNED NOT NULL,
  progress DECIMAL(12,3) NOT NULL DEFAULT 0,
  completed TINYINT(1) NOT NULL DEFAULT 0,
  unlocked_at DATETIME NULL,
  metadata_json JSON NULL,
  PRIMARY KEY (user_id, achievement_id),
  KEY idx_user_achievements_completed (user_id, completed),
  CONSTRAINT fk_user_achievements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_achievements_achievement FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- MERCH
-- ------------------------------------------------------------
CREATE TABLE merch_products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_type VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  base_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_merch_products_type_active (product_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE merch_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  template_type VARCHAR(64) NOT NULL,
  design_config_json JSON NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_merch_templates_product_active (product_id, active),
  CONSTRAINT fk_merch_templates_product FOREIGN KEY (product_id) REFERENCES merch_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE merch_trigger_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id BIGINT UNSIGNED NOT NULL,
  trigger_type VARCHAR(64) NOT NULL,
  achievement_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NULL,
  trait_id BIGINT UNSIGNED NULL,
  threshold DECIMAL(12,3) NULL,
  content_id BIGINT UNSIGNED NULL,
  rule_json JSON NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_merch_triggers_type_active (trigger_type, active),
  CONSTRAINT fk_merch_trigger_template FOREIGN KEY (template_id) REFERENCES merch_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_merch_trigger_achievement FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE SET NULL,
  CONSTRAINT fk_merch_trigger_trait FOREIGN KEY (trait_id) REFERENCES traits(id) ON DELETE SET NULL,
  CONSTRAINT fk_merch_trigger_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_merch_designs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  template_id BIGINT UNSIGNED NOT NULL,
  source_type VARCHAR(64) NOT NULL,
  source_id BIGINT UNSIGNED NULL,
  headline VARCHAR(255) NULL,
  subheadline VARCHAR(500) NULL,
  design_config_json JSON NULL,
  preview_url VARCHAR(1000) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_merch_user_status (user_id, status),
  CONSTRAINT fk_user_merch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_merch_template FOREIGN KEY (template_id) REFERENCES merch_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- AI GENERATION / REVIEW / PUBLISH
-- ------------------------------------------------------------
CREATE TABLE ai_prompt_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(180) NOT NULL,
  name VARCHAR(255) NOT NULL,
  content_type VARCHAR(64) NOT NULL,
  system_prompt MEDIUMTEXT NOT NULL,
  prompt_template MEDIUMTEXT NOT NULL,
  output_schema_json JSON NOT NULL,
  model_preferences_json JSON NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_prompt_slug_version (slug, version),
  KEY idx_ai_prompt_content_active (content_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ai_generation_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  requested_by BIGINT UNSIGNED NULL,
  prompt_template_id BIGINT UNSIGNED NOT NULL,
  content_type VARCHAR(64) NOT NULL,
  requested_count INT UNSIGNED NOT NULL DEFAULT 1,
  input_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  model VARCHAR(120) NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  tokens_input INT UNSIGNED NULL,
  tokens_output INT UNSIGNED NULL,
  estimated_cost DECIMAL(12,6) NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_jobs_status_created (status, created_at),
  KEY idx_ai_jobs_content_type (content_type),
  CONSTRAINT fk_ai_jobs_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ai_jobs_prompt FOREIGN KEY (prompt_template_id) REFERENCES ai_prompt_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ai_generated_candidates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id BIGINT UNSIGNED NOT NULL,
  candidate_number INT UNSIGNED NOT NULL,
  content_type VARCHAR(64) NOT NULL,
  generated_json JSON NOT NULL,
  quality_score DECIMAL(6,3) NULL,
  originality_score DECIMAL(6,3) NULL,
  humor_score DECIMAL(6,3) NULL,
  brand_fit_score DECIMAL(6,3) NULL,
  clarity_score DECIMAL(6,3) NULL,
  duplicate_score DECIMAL(6,3) NULL,
  safety_score DECIMAL(6,3) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'generated',
  review_notes TEXT NULL,
  published_content_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_candidate_job_number (job_id, candidate_number),
  KEY idx_candidates_status_quality (status, quality_score),
  KEY idx_candidates_duplicate (duplicate_score),
  CONSTRAINT fk_candidates_job FOREIGN KEY (job_id) REFERENCES ai_generation_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_candidates_published_content FOREIGN KEY (published_content_id) REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE content_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidate_id BIGINT UNSIGNED NOT NULL,
  reviewer_id BIGINT UNSIGNED NULL,
  decision VARCHAR(32) NOT NULL,
  quality_rating TINYINT UNSIGNED NULL,
  funny_rating TINYINT UNSIGNED NULL,
  brand_rating TINYINT UNSIGNED NULL,
  accuracy_rating TINYINT UNSIGNED NULL,
  notes TEXT NULL,
  reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_content_reviews_candidate_date (candidate_id, reviewed_at),
  CONSTRAINT fk_reviews_candidate FOREIGN KEY (candidate_id) REFERENCES ai_generated_candidates(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_reviews_quality CHECK (quality_rating IS NULL OR quality_rating BETWEEN 1 AND 5),
  CONSTRAINT chk_reviews_funny CHECK (funny_rating IS NULL OR funny_rating BETWEEN 1 AND 5),
  CONSTRAINT chk_reviews_brand CHECK (brand_rating IS NULL OR brand_rating BETWEEN 1 AND 5),
  CONSTRAINT chk_reviews_accuracy CHECK (accuracy_rating IS NULL OR accuracy_rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE content_fingerprints (
  content_id BIGINT UNSIGNED NOT NULL,
  normalized_hash CHAR(64) NOT NULL,
  semantic_key VARCHAR(255) NULL,
  embedding_provider VARCHAR(64) NULL,
  embedding_model VARCHAR(120) NULL,
  embedding_ref VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (content_id),
  UNIQUE KEY uq_content_fingerprint_hash (normalized_hash),
  KEY idx_content_semantic_key (semantic_key),
  CONSTRAINT fk_fingerprints_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- UNIVERSAL EVENT STREAM (created late to reference all domains)
-- ------------------------------------------------------------
CREATE TABLE user_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  content_id BIGINT UNSIGNED NULL,
  choice_id BIGINT UNSIGNED NULL,
  deck_id BIGINT UNSIGNED NULL,
  destination_id BIGINT UNSIGNED NULL,
  place_id BIGINT UNSIGNED NULL,
  package_id BIGINT UNSIGNED NULL,
  dream_trip_id BIGINT UNSIGNED NULL,
  achievement_id BIGINT UNSIGNED NULL,
  merch_design_id BIGINT UNSIGNED NULL,
  value_numeric DECIMAL(18,4) NULL,
  value_text VARCHAR(1000) NULL,
  metadata_json JSON NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_user_date (user_id, occurred_at),
  KEY idx_events_user_type_date (user_id, event_type, occurred_at),
  KEY idx_events_type_date (event_type, occurred_at),
  KEY idx_events_content (content_id),
  KEY idx_events_package (package_id),
  KEY idx_events_destination (destination_id),
  CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_choice FOREIGN KEY (choice_id) REFERENCES content_choices(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_deck FOREIGN KEY (deck_id) REFERENCES swipe_decks(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_destination FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_place FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_package FOREIGN KEY (package_id) REFERENCES travel_packages(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_dream_trip FOREIGN KEY (dream_trip_id) REFERENCES dream_trips(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_achievement FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_merch_design FOREIGN KEY (merch_design_id) REFERENCES user_merch_designs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- SEED GENERATION MANIFEST / BATCH TRACKING
-- ------------------------------------------------------------
CREATE TABLE seed_generation_manifest (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_type VARCHAR(64) NOT NULL,
  target_count INT UNSIGNED NOT NULL,
  approved_count INT UNSIGNED NOT NULL DEFAULT 0,
  published_count INT UNSIGNED NOT NULL DEFAULT 0,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
  notes VARCHAR(1000) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seed_manifest_type (content_type),
  KEY idx_seed_manifest_priority (active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
