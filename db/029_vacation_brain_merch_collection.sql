USE vacation_brain;

-- Vacation Brain v1.22: production Vacation Brain merch catalog with consistent product imagery.
INSERT INTO merch_catalog_products
  (slug,name,short_description,description,product_type,sku,price,compare_at_price,image_url,secondary_image_url,status,is_sample,featured,sort_order,metadata_json)
VALUES
  (
    'palm-springs-drop-001-hoodie',
    'Palm Springs Drop 001 Hoodie',
    'Vintage-cream Vacation Brain hoodie with Palm Springs pool-and-desert artwork.',
    'Cream pullover hoodie from Palm Springs Drop 001. The front carries a small Vacation Brain chest mark; the back carries the full Palm Springs pool, palms, mountains and desert-night artwork.',
    'hoodie','VB-PS-HOOD-001',64.99,NULL,
    '/assets/merch/palm-springs-hoodie-front.webp','/assets/merch/palm-springs-hoodie-back.webp',
    'active',0,1,10,
    JSON_OBJECT('collection','Palm Springs Drop 001','features',JSON_ARRAY('Vintage cream colorway','Separate front and back artwork','Palm Springs pool-and-desert graphic'))
  ),
  (
    'brighter-day-hoodie',
    'A Brighter Day Hoodie',
    'Cream Vacation Brain hoodie with a minimal palm chest mark and oversized back graphic.',
    'An understated front and statement back: a small palm-and-script Vacation Brain mark on the chest with the large twin-palm A Brighter Day is Always a Good Idea artwork on the back.',
    'hoodie','VB-BD-HOOD-001',64.99,NULL,
    '/assets/merch/brighter-day-hoodie-front.webp','/assets/merch/brighter-day-hoodie-back.webp',
    'active',0,1,20,
    JSON_OBJECT('collection','Off Duty Goods','features',JSON_ARRAY('Vintage cream colorway','Separate front and back artwork','Large twin-palm back graphic'))
  ),
  (
    'palm-springs-trucker-hat',
    'Palm Springs Trucker Hat',
    'Cream-and-navy mesh trucker hat with rust brim and Palm Springs Vacation Brain artwork.',
    'Palm Springs Drop 001 trucker hat with a cream front panel, navy mesh back, weathered rust brim, and the full Vacation Brain Palm Springs pool-and-desert graphic.',
    'hat','VB-PS-HAT-001',29.99,NULL,
    '/assets/merch/palm-springs-trucker-hat.webp',NULL,
    'active',0,1,30,
    JSON_OBJECT('collection','Palm Springs Drop 001','features',JSON_ARRAY('Cream front panel','Navy mesh back','Rust vintage-style brim'))
  ),
  (
    'script-vintage-trucker-hat-rust',
    'Script Vintage Trucker Hat — Rust',
    'Vintage Vacation Brain script trucker hat with rust brim and dark mesh back.',
    'A distressed cream trucker with the oversized Vacation Brain script mark, palm detail, dark mesh back and weathered rust brim.',
    'hat','VB-SV-HAT-RUST',29.99,NULL,
    '/assets/merch/vintage-trucker-hat-rust.webp',NULL,
    'active',0,0,40,
    JSON_OBJECT('collection','Off Duty Goods','features',JSON_ARRAY('Distressed script artwork','Dark mesh back','Rust vintage-style brim'))
  ),
  (
    'script-vintage-trucker-hat-navy',
    'Script Vintage Trucker Hat — Navy',
    'Vintage Vacation Brain script trucker hat with navy brim and navy mesh back.',
    'The navy-brim version of the Vacation Brain script trucker: cream front panel, distressed oversized wordmark, palm detail and navy mesh back.',
    'hat','VB-SV-HAT-NAVY',29.99,NULL,
    '/assets/merch/vintage-trucker-hat-navy.webp',NULL,
    'active',0,0,50,
    JSON_OBJECT('collection','Off Duty Goods','features',JSON_ARRAY('Distressed script artwork','Navy mesh back','Navy vintage-style brim'))
  ),
  (
    'palm-springs-embroidered-patch',
    'Palm Springs Embroidered Patch',
    'Vacation Brain Palm Springs patch with pool, palms, mountains and sunset artwork.',
    'A standalone Palm Springs Drop 001 embroidered-style patch featuring the Vacation Brain script, palms, pool, mountain silhouette, desert plants and sunset.',
    'patch','VB-PS-PATCH-001',9.99,NULL,
    '/assets/merch/palm-springs-patch.webp',NULL,
    'active',0,0,60,
    JSON_OBJECT('collection','Palm Springs Drop 001','features',JSON_ARRAY('Palm Springs artwork','Dark embroidered-style border','Pool, palms and desert scene'))
  )
ON DUPLICATE KEY UPDATE
  name=VALUES(name),
  short_description=VALUES(short_description),
  description=VALUES(description),
  product_type=VALUES(product_type),
  sku=VALUES(sku),
  price=VALUES(price),
  compare_at_price=VALUES(compare_at_price),
  image_url=VALUES(image_url),
  secondary_image_url=VALUES(secondary_image_url),
  status='active',
  is_sample=0,
  featured=VALUES(featured),
  sort_order=VALUES(sort_order),
  metadata_json=VALUES(metadata_json);

INSERT INTO app_meta (meta_key,meta_value) VALUES ('app_version','1.22')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
