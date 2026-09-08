-- Vacation Brain v1.15 - admin-managed destinations, merch shop, and PWA/branding settings

CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(190) PRIMARY KEY,
  setting_value TEXT NULL,
  setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_site_settings_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS merch_catalog_products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(190) NOT NULL,
  name VARCHAR(255) NOT NULL,
  short_description VARCHAR(500) NULL,
  description TEXT NULL,
  product_type VARCHAR(80) NOT NULL DEFAULT 'shirt',
  sku VARCHAR(120) NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  compare_at_price DECIMAL(10,2) NULL,
  image_url TEXT NULL,
  secondary_image_url TEXT NULL,
  status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_merch_catalog_slug (slug),
  UNIQUE KEY uq_merch_catalog_sku (sku),
  KEY idx_merch_catalog_status_sort (status,featured,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS destination_catalog (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(190) NOT NULL,
  name VARCHAR(255) NOT NULL,
  city VARCHAR(160) NULL,
  region VARCHAR(160) NULL,
  country VARCHAR(160) NULL,
  short_description VARCHAR(500) NULL,
  description TEXT NULL,
  hero_image_url TEXT NULL,
  best_for VARCHAR(500) NULL,
  vibe VARCHAR(255) NULL,
  status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_destination_catalog_slug (slug),
  KEY idx_destination_catalog_status_sort (status,featured,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO merch_catalog_products (slug,name,short_description,product_type,sku,price,status,featured,sort_order) VALUES
('vacation-brain-classic-tee','Vacation Brain Classic Tee','A simple Vacation Brain logo tee for people who are mentally somewhere else.','shirt','VB-TEE-001',29.00,'active',1,10),
('mentally-on-vacation-hoodie','Mentally On Vacation Hoodie','Comfort for the part of you that already checked out.','hoodie','VB-HOOD-001',54.00,'active',1,20),
('vacation-brain-mug','Vacation Brain Mug','Coffee for planning trips you have not booked yet.','mug','VB-MUG-001',19.00,'active',0,30),
('vacation-brain-sticker-pack','Vacation Brain Sticker Pack','A small collection of Vacation Brain travel stickers.','sticker','VB-STICK-001',12.00,'active',0,40);

INSERT IGNORE INTO destination_catalog (slug,name,city,region,country,short_description,best_for,vibe,status,featured,sort_order) VALUES
('cabo-san-lucas','Cabo San Lucas','Cabo San Lucas','Baja California Sur','Mexico','Warm weather, resorts, beaches and a strong case for doing very little.','Beach, resorts, food, nightlife','Warm · Resort · Easy','active',1,10),
('puerto-rico','Puerto Rico','San Juan','Puerto Rico','United States','Beach time, food, history and enough variety to justify extending the trip.','Beach, food, culture, nightlife','Tropical · Social · Flexible','active',1,20),
('maui','Maui','Maui','Hawaii','United States','Big scenery, beach days and expensive decisions that may feel completely reasonable on vacation.','Beach, scenery, couples, relaxation','Scenic · Relaxed · Premium','active',1,30),
('san-diego','San Diego','San Diego','California','United States','A low-friction escape with beaches, food, neighborhoods and suspiciously good weather.','Weekend escapes, beach, food','Easy · Coastal · Local-ish','active',0,40),
('miami','Miami','Miami','Florida','United States','Pool days, late nights, beach energy and very little pressure to pretend you came for museums.','Nightlife, beach, food, luxury','Social · Stylish · Late','active',0,50),
('new-orleans','New Orleans','New Orleans','Louisiana','United States','Food, music, neighborhoods and a vacation schedule that does not require waking up early.','Food, music, nightlife, culture','Food-first · Social · Late','active',0,60);

INSERT INTO site_settings (setting_key,setting_value,setting_group) VALUES
('brand.site_name','Vacation Brain','branding'),
('brand.logo_url','','branding'),
('pwa.enabled','0','pwa'),
('pwa.app_name','Vacation Brain','pwa'),
('pwa.short_name','Vacation Brain','pwa'),
('pwa.description','Vacation Brain — vacation daydreaming, travel matching, and escape planning.','pwa'),
('pwa.theme_color','#ffffff','pwa'),
('pwa.background_color','#ffffff','pwa'),
('pwa.display','standalone','pwa'),
('pwa.icon_url','','pwa'),
('pwa.splash_url','','pwa')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.15')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
