-- Vacation Brain v1.3 fun-first experience
INSERT INTO score_rules (event_type, points, daily_limit, cooldown_minutes, active) VALUES
('weather_envy_checked',2,5,15,1),
('roast_generated',1,5,10,1),
('dream_item_added',2,20,NULL,1)
ON DUPLICATE KEY UPDATE points=VALUES(points),daily_limit=VALUES(daily_limit),cooldown_minutes=VALUES(cooldown_minutes),active=1;

INSERT INTO achievements (slug,name,description,rarity,active) VALUES
('weather-envy-specialist','Weather Envy Specialist','You checked the weather somewhere better five times. This has not improved your local climate.','common',1),
('serial-dreamer','Serial Dreamer','You created three Dream Trips. Booking any of them remains optional.','common',1),
('reopened-the-tab','Reopened the Tab','You revisited Dream Trips ten times. Casual interest has left the chat.','common',1),
('self-roast-certified','Self-Roast Certified','You asked Vacation Brain to roast you three times and came back for more.','rare',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),rarity=VALUES(rarity),active=1;

SET @a=(SELECT id FROM achievements WHERE slug='weather-envy-specialist');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','weather_envy_checked',5,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count' AND event_type='weather_envy_checked');
SET @a=(SELECT id FROM achievements WHERE slug='serial-dreamer');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','dream_trip_created',3,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count' AND event_type='dream_trip_created');
SET @a=(SELECT id FROM achievements WHERE slug='reopened-the-tab');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','dream_trip_viewed',10,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count' AND event_type='dream_trip_viewed');
SET @a=(SELECT id FROM achievements WHERE slug='self-roast-certified');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window) SELECT @a,'event_count','roast_generated',3,'all' WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='event_count' AND event_type='roast_generated');

CREATE TABLE agent_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(24) NOT NULL,
  body TEXT NOT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_agent_messages_user_id (user_id,id),
  CONSTRAINT fk_agent_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO score_rules (event_type,points,daily_limit,cooldown_minutes,active) VALUES ('agent_message',1,20,NULL,1);
