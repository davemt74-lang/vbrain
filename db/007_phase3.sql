USE vacation_brain;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','3'),('app_version','1.2')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);

INSERT INTO swipe_decks (slug,name,description,deck_type,status,premium,sort_order)
VALUES ('vacation-brain-feed-v1','Vacation Brain Swipe','An endless feed of ridiculous vacation choices that keeps learning what kind of traveler you are.','profile','published',0,2)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),status='published';

INSERT INTO tags (slug,name,tag_group) VALUES
('weather_envy','Weather Envy','content'),('out_of_office','Out of Office','content'),('vacation_excuse','Vacation Excuse','content'),
('achievement','Achievement','content'),('challenge','Challenge','content'),('prop_bet','Prop Bet','content'),('daydream','Daydream','behavior'),
('workplace','Workplace','scenario'),('couple','Couple','traveler_type'),('solo','Solo','traveler_type'),('weekend','Weekend','travel'),
('tropical','Tropical','environment'),('mountains','Mountains','environment'),('city','City','environment'),('foodie','Foodie','activity')
ON DUPLICATE KEY UPDATE name=VALUES(name),tag_group=VALUES(tag_group);

INSERT INTO score_rules (event_type,points,daily_limit,cooldown_minutes,active) VALUES
('vacation_excuse_generated',1,10,NULL,1),('out_of_office_generated',1,10,NULL,1),('vacation_break_completed',5,5,NULL,1),
('merch_viewed',1,5,60,1),('swipe_profile_answered',2,50,NULL,1)
ON DUPLICATE KEY UPDATE points=VALUES(points),daily_limit=VALUES(daily_limit),cooldown_minutes=VALUES(cooldown_minutes),active=1;

INSERT INTO achievements (slug,name,description,rarity,active) VALUES
('seven-day-daydream','Seven-Day Daydream','You checked in for seven straight days. This is no longer casual browsing.','common',1),
('thirty-day-daydream','Thirty Days Mentally Away','Thirty straight check-ins. Your body is now the only thing still at home.','rare',1),
('vacation-break-regular','Vacation Break Regular','You have completed five tiny imaginary escapes.','common',1),
('vacation-break-professional','Professional Escapist','You have completed twenty Vacation Breaks. Please update your résumé accordingly.','rare',1),
('excuse-department','Excuse Department','You generated five vacation excuses. HR has been notified emotionally, not actually.','common',1),
('ooo-expert','Out-of-Office Expert','You generated five out-of-office messages before leaving. Excellent preparation.','common',1),
('local-escape-artist','Local Escape Artist','You completed three Vacation Substitutions without boarding anything.','rare',1),
('swipe-scholar','Vacation Swipe Scholar','You answered fifty Vacation Brain swipe cards. We know things about you now.','common',1),
('swipe-professor','Vacation Swipe Professor','Two hundred swipe decisions. Your travel profile is disturbingly specific.','rare',1),
('basically-at-the-airport','Basically at the Airport','Your Vacation Brain Score reached 1200. Boarding group unknown.','legendary',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),rarity=VALUES(rarity),active=1;

SET @a=(SELECT id FROM achievements WHERE slug='seven-day-daydream');
INSERT INTO achievement_rules (achievement_id,rule_type,threshold,time_window) SELECT @a,'checkin_streak',7,'current' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='checkin_streak');
SET @a=(SELECT id FROM achievements WHERE slug='thirty-day-daydream');
INSERT INTO achievement_rules (achievement_id,rule_type,threshold,time_window) SELECT @a,'checkin_streak',30,'current' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='checkin_streak');
SET @a=(SELECT id FROM achievements WHERE slug='vacation-break-regular');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','vacation_break_completed',5,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count');
SET @a=(SELECT id FROM achievements WHERE slug='vacation-break-professional');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','vacation_break_completed',20,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count');
SET @a=(SELECT id FROM achievements WHERE slug='excuse-department');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','vacation_excuse_generated',5,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count');
SET @a=(SELECT id FROM achievements WHERE slug='ooo-expert');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','out_of_office_generated',5,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count');
SET @a=(SELECT id FROM achievements WHERE slug='local-escape-artist');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','substitution_completed',3,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count');
SET @a=(SELECT id FROM achievements WHERE slug='swipe-scholar');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','choice_selected',50,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count');
SET @a=(SELECT id FROM achievements WHERE slug='swipe-professor');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','choice_selected',200,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count');
SET @a=(SELECT id FROM achievements WHERE slug='basically-at-the-airport');
INSERT INTO achievement_rules (achievement_id,rule_type,threshold,time_window) SELECT @a,'score_threshold',1200,'current' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='score_threshold');

INSERT INTO merch_products (product_type,name,base_price,active,metadata_json) VALUES
('shirt','Classic Vacation Brain T-Shirt',29.00,1,JSON_OBJECT('fulfillment','template_only')),
('hoodie','Vacation Brain Hoodie',49.00,1,JSON_OBJECT('fulfillment','template_only')),
('mug','Vacation Brain Mug',18.00,1,JSON_OBJECT('fulfillment','template_only')),
('sticker','Vacation Brain Sticker',6.00,1,JSON_OBJECT('fulfillment','template_only'))
;

SET @shirt=(SELECT id FROM merch_products WHERE product_type='shirt' ORDER BY id LIMIT 1);
SET @hoodie=(SELECT id FROM merch_products WHERE product_type='hoodie' ORDER BY id LIMIT 1);
SET @mug=(SELECT id FROM merch_products WHERE product_type='mug' ORDER BY id LIMIT 1);

INSERT INTO merch_templates (product_id,name,template_type,design_config_json,active) VALUES
(@shirt,'Mentally Checked Out Tee','achievement',JSON_OBJECT('headline','MENTALLY CHECKED OUT','subheadline','Vacation Brain Certified'),1),
(@shirt,'No Activities Before 10AM Tee','achievement',JSON_OBJECT('headline','NO ACTIVITIES BEFORE 10AM','subheadline','Vacation Policy'),1),
(@shirt,'Pool Person Tee','achievement',JSON_OBJECT('headline','POOL PERSON','subheadline','Plans Optional'),1),
(@shirt,'Professional Daydreamer Tee','achievement',JSON_OBJECT('headline','PROFESSIONAL DAYDREAMER','subheadline','Vacation Brain Department'),1),
(@shirt,'Seven Day Daydream Tee','achievement',JSON_OBJECT('headline','7 DAYS MENTALLY AWAY','subheadline','Still Physically Present'),1),
(@hoodie,'Thirty Day Checked Out Hoodie','achievement',JSON_OBJECT('headline','30 DAYS MENTALLY AWAY','subheadline','Vacation Brain'),1),
(@mug,'Excuse Department Mug','achievement',JSON_OBJECT('headline','VACATION EXCUSE DEPARTMENT','subheadline','Please Hold'),1),
(@shirt,'Vacation Break Regular Tee','achievement',JSON_OBJECT('headline','I TAKE VACATIONS IN 3-MINUTE INCREMENTS','subheadline','Vacation Break Regular'),1),
(@shirt,'Local Escape Artist Tee','achievement',JSON_OBJECT('headline','NO FLIGHT. STILL ON VACATION.','subheadline','Local Escape Artist'),1),
(@shirt,'Swipe Scholar Tee','achievement',JSON_OBJECT('headline','I HAVE OPINIONS ABOUT 50 VACATIONS','subheadline','Vacation Brain Research'),1)
;

SET @t=(SELECT id FROM merch_templates WHERE name='Mentally Checked Out Tee' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='mentally-checked-out');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
SET @t=(SELECT id FROM merch_templates WHERE name='No Activities Before 10AM Tee' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='no-activities-before-10');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
SET @t=(SELECT id FROM merch_templates WHERE name='Pool Person Tee' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='pool-person');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
SET @t=(SELECT id FROM merch_templates WHERE name='Professional Daydreamer Tee' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='professional-daydreamer');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
SET @t=(SELECT id FROM merch_templates WHERE name='Seven Day Daydream Tee' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='seven-day-daydream');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
SET @t=(SELECT id FROM merch_templates WHERE name='Thirty Day Checked Out Hoodie' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='thirty-day-daydream');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
SET @t=(SELECT id FROM merch_templates WHERE name='Excuse Department Mug' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='excuse-department');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
SET @t=(SELECT id FROM merch_templates WHERE name='Vacation Break Regular Tee' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='vacation-break-regular');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
SET @t=(SELECT id FROM merch_templates WHERE name='Local Escape Artist Tee' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='local-escape-artist');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
SET @t=(SELECT id FROM merch_templates WHERE name='Swipe Scholar Tee' ORDER BY id DESC LIMIT 1); SET @a=(SELECT id FROM achievements WHERE slug='swipe-scholar');
INSERT INTO merch_trigger_rules (template_id,trigger_type,achievement_id,active) VALUES (@t,'achievement',@a,1);
