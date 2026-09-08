USE vacation_brain;

INSERT INTO tags (slug, name, tag_group) VALUES
('airport','Airport','scenario'),('beach','Beach','environment'),('pool','Pool','environment'),
('cruise','Cruise','travel_style'),('resort','Resort','travel_style'),('luxury','Luxury','budget'),
('budget','Budget','budget'),('food','Food','activity'),('nightlife','Nightlife','activity'),
('couples','Couples','traveler_type'),('family','Family','traveler_type'),('friends','Friends','traveler_type'),
('work','Work','scenario'),('packing','Packing','scenario'),('early_morning','Early Morning','scenario'),
('road_trip','Road Trip','travel_style'),('spa','Spa','activity'),('adventure','Adventure','activity'),
('relaxation','Relaxation','activity'),('local','Local','discovery'),('weather','Weather','scenario'),
('flight','Flight','travel'),('hotel','Hotel','travel'),('destination','Destination','travel'),
('merch','Merch','commerce'),('vacation_substitution','Vacation Substitution','content'),
('vacation_break','Vacation Break','content'),('sarcastic','Sarcastic','tone'),('ridiculous','Ridiculous','tone')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO traits (slug, name, trait_group, description) VALUES
('beach','Beach','destination','Preference for beach vacations'),
('pool','Pool','activity','Preference for pools and resort pool time'),
('nightlife','Nightlife','activity','Preference for nightlife'),
('luxury','Luxury','budget','Tolerance for premium/luxury spending'),
('budget_sensitivity','Budget Sensitivity','budget','Sensitivity to price'),
('adventure','Adventure','activity','Interest in adventurous experiences'),
('food','Food','activity','Importance of food while traveling'),
('culture','Culture','activity','Interest in culture, history, museums and local traditions'),
('shopping','Shopping','activity','Interest in vacation shopping'),
('relaxation','Relaxation','pace','Preference for relaxing vacations'),
('planning','Planning','behavior','Preference for planning and structure'),
('spontaneity','Spontaneity','behavior','Preference for spontaneous travel'),
('morning_tolerance','Morning Tolerance','behavior','Tolerance for early activities'),
('flight_tolerance','Flight Tolerance','travel','Tolerance for long or difficult flights'),
('direct_flight_preference','Direct Flight Preference','travel','Preference for direct flights'),
('resort_preference','Resort Preference','lodging','Preference for resort stays'),
('city_preference','City Preference','destination','Preference for city travel'),
('cruise_preference','Cruise Preference','travel_style','Preference for cruises'),
('family_travel','Family Travel','traveler_type','Preference for family-oriented travel'),
('romance','Romance','traveler_type','Preference for romantic trips'),
('convenience','Convenience','behavior','Preference for convenience over savings'),
('activity_level','Activity Level','pace','Desired vacation activity intensity')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO score_rules (event_type, points, daily_limit, cooldown_minutes) VALUES
('daily_checkin',10,1,NULL),
('choice_selected',2,50,NULL),
('deck_completed',20,5,NULL),
('destination_saved',5,20,NULL),
('dream_trip_created',25,5,NULL),
('dream_trip_viewed',2,20,60),
('vacation_break_completed',5,5,NULL),
('friend_invited',20,10,NULL),
('package_saved',10,20,NULL),
('booking_completed',100,NULL,NULL),
('substitution_completed',15,5,NULL);

INSERT INTO provider_plans (name, monthly_price, package_limit, featured_slots, analytics_level, lead_access) VALUES
('Free',0.00,1,0,'basic',0),
('Discovery',29.00,5,0,'basic',0),
('Pro',79.00,25,2,'advanced',1),
('Featured',199.00,NULL,8,'advanced',1)
ON DUPLICATE KEY UPDATE monthly_price = VALUES(monthly_price), package_limit = VALUES(package_limit), featured_slots = VALUES(featured_slots);

INSERT INTO seed_generation_manifest (content_type, target_count, priority, notes) VALUES
('swipe_question',500,1,'Primary preference-learning interaction inventory'),
('would_you_rather',200,1,'Ridiculous vacation choice inventory'),
('this_or_that',150,2,'Fast binary preference cards'),
('destination_prompt',200,2,'Destination discovery and affinity cards'),
('vacation_excuse',100,3,'Vacation excuse generator'),
('out_of_office',100,3,'Out-of-office generator'),
('agent_comment',250,1,'Sarcastic/playful agent commentary'),
('achievement_copy',100,2,'Achievement titles/descriptions and unlock copy'),
('merch_slogan',200,2,'Merch slogans linked to behavior and traits'),
('vacation_break',50,3,'Short destination fantasy breaks'),
('substitution_prompt',75,2,'Local vacation substitution templates'),
('challenge',100,3,'Vacation challenges and daily dares'),
('prop_bet',100,4,'Group predictions and harmless prop bets'),
('personality_result',75,2,'Profile/personality result language')
ON DUPLICATE KEY UPDATE target_count = VALUES(target_count), priority = VALUES(priority), notes = VALUES(notes);
