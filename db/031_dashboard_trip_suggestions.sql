-- Vacation Brain v1.24 - logged-in dashboard trip suggestions

CREATE TABLE IF NOT EXISTS dashboard_trip_suggestions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(190) NOT NULL,
  category VARCHAR(24) NOT NULL,
  name VARCHAR(255) NOT NULL,
  location_text VARCHAR(255) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  drive_minutes INT UNSIGNED NULL,
  duration_text VARCHAR(80) NULL,
  subtitle VARCHAR(255) NULL,
  rating DECIMAL(3,2) NULL,
  review_count INT UNSIGNED NULL,
  image_url TEXT NULL,
  search_query VARCHAR(255) NULL,
  status ENUM('draft','active','archived') NOT NULL DEFAULT 'active',
  is_sample TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dashboard_trip_slug (slug),
  KEY idx_dashboard_trip_category (category,status,is_sample,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dashboard_trip_suggestions
(slug,category,name,location_text,latitude,longitude,drive_minutes,duration_text,subtitle,rating,review_count,image_url,search_query,status,is_sample,sort_order,metadata_json)
VALUES
('sedona-red-rock-trails','local','Sedona Red Rock Trails','Sedona, Arizona',34.8697000,-111.7609000,110,'1h 50m','Hiking · Views · Vibes',4.80,1200,'/assets/sample/destinations/sedona.webp','Sedona, Arizona','active',1,10,JSON_OBJECT('sample',true,'anchor_market','Phoenix')),
('lake-pleasant','local','Lake Pleasant','Peoria, Arizona',33.8474000,-112.2657000,45,'45m','Boating · Swimming · Relaxing',4.60,892,'/assets/sample/destinations/maui.webp','Lake Pleasant, Arizona','active',1,20,JSON_OBJECT('sample',true,'anchor_market','Phoenix')),
('cave-creek','local','Cave Creek','Cave Creek, Arizona',33.8333000,-111.9508000,35,'35m','Food · Shops · Western Fun',4.50,614,'/assets/sample/destinations/sedona.webp','Cave Creek, Arizona','active',1,30,JSON_OBJECT('sample',true,'anchor_market','Phoenix')),
('tonto-natural-bridge','local','Tonto Natural Bridge','Pine, Arizona',34.3226000,-111.4497000,100,'1h 40m','Hiking · Waterfall · Nature',4.70,530,'/assets/sample/destinations/costa-rica.webp','Tonto Natural Bridge State Park, Arizona','active',1,40,JSON_OBJECT('sample',true,'anchor_market','Phoenix')),
('flagstaff-weekend','weekend','Flagstaff','Flagstaff, Arizona',35.1983000,-111.6513000,135,'2h 15m','Breweries · Nature',4.60,1100,'/assets/sample/destinations/aspen.webp','Flagstaff, Arizona','active',1,10,JSON_OBJECT('sample',true,'anchor_market','Phoenix')),
('scottsdale-weekend','weekend','Scottsdale','Scottsdale, Arizona',33.4942000,-111.9261000,30,'30m','Resorts · Dining · Nightlife',4.50,980,'/assets/sample/destinations/tulum.webp','Scottsdale, Arizona','active',1,20,JSON_OBJECT('sample',true,'anchor_market','Phoenix')),
('las-vegas-weekend','weekend','Las Vegas','Las Vegas, Nevada',36.1699000,-115.1398000,270,'4h 30m','Entertainment · Shows',4.40,2300,'/assets/sample/destinations/paris.webp','Las Vegas, Nevada','active',1,30,JSON_OBJECT('sample',true,'anchor_market','Phoenix')),
('palm-springs-weekend','weekend','Palm Springs','Palm Springs, California',33.8303000,-116.5453000,250,'4h 10m','Relax · Pools · Culture',4.30,1700,'/assets/sample/destinations/bali.webp','Palm Springs, California','active',1,40,JSON_OBJECT('sample',true,'anchor_market','Phoenix'))
ON DUPLICATE KEY UPDATE
  category=VALUES(category),name=VALUES(name),location_text=VALUES(location_text),latitude=VALUES(latitude),longitude=VALUES(longitude),drive_minutes=VALUES(drive_minutes),duration_text=VALUES(duration_text),subtitle=VALUES(subtitle),rating=VALUES(rating),review_count=VALUES(review_count),image_url=VALUES(image_url),search_query=VALUES(search_query),status='active',is_sample=1,sort_order=VALUES(sort_order),metadata_json=VALUES(metadata_json);

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.24')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
