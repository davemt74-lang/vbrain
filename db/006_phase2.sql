USE vacation_brain;

CREATE TABLE IF NOT EXISTS app_meta (
  meta_key VARCHAR(120) NOT NULL,
  meta_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO app_meta (meta_key,meta_value) VALUES ('schema_version','2')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);

-- Universal no-API-key content factory prompt. Admin creates a job, copies this
-- structured prompt into any model, then pastes the returned JSON into Vacation Brain.
INSERT INTO ai_prompt_templates
(slug,name,content_type,system_prompt,prompt_template,output_schema_json,model_preferences_json,version,active)
VALUES
(
 'vacation-brain-universal-v1',
 'Vacation Brain Universal Content Generator',
 'generic',
 'You are the Vacation Brain content writer. Create original, concise vacation-daydreaming content with playful sarcasm. Keep the humor warm rather than cruel. Do not make medical claims. A Vacation Brain diagnosis is a fictional entertainment/travel-preference assessment. Return only valid JSON.',
 'Generate {{count}} Vacation Brain records. Content type: {{content_type}}. Theme: {{theme}}. Audience: {{audience}}. Tone: {{tone}}. Additional notes: {{notes}}. For swipe questions, include exactly two strong choices. Each choice must use choice_key, label, brain_points, and trait_effects; trait_effects is an array of objects with trait, score_delta, and confidence_delta. Use existing trait slugs such as beach, pool, luxury, budget_sensitivity, adventure, food, relaxation, planning, spontaneity, morning_tolerance, flight_tolerance, direct_flight_preference, resort_preference, convenience, and activity_level. For substitution prompts, include theme, duration_minutes, budget_level, and a complete steps array; every step should include activity_type, title, description, duration_minutes, recommended_time, and required_tags. Avoid duplicate concepts within the batch.',
 JSON_OBJECT(
   'type','object',
   'required',JSON_ARRAY('items'),
   'properties',JSON_OBJECT(
     'items',JSON_OBJECT(
       'type','array',
       'items',JSON_OBJECT(
         'type','object',
         'required',JSON_ARRAY('title'),
         'properties',JSON_OBJECT(
           'title',JSON_OBJECT('type','string'),
           'body',JSON_OBJECT('type','string'),
           'question',JSON_OBJECT('type','string'),
           'short_body',JSON_OBJECT('type','string'),
           'theme',JSON_OBJECT('type','string'),
           'humor_level',JSON_OBJECT('type','integer','minimum',0,'maximum',5),
           'sarcasm_level',JSON_OBJECT('type','integer','minimum',0,'maximum',5),
           'tags',JSON_OBJECT('type','array'),
           'choices',JSON_OBJECT('type','array'),
           'steps',JSON_OBJECT('type','array'),
           'duration_minutes',JSON_OBJECT('type','integer'),
           'budget_level',JSON_OBJECT('type','integer','minimum',1,'maximum',5),
           'achievement_opportunities',JSON_OBJECT('type','array'),
           'merch_opportunities',JSON_OBJECT('type','array'),
           'scores',JSON_OBJECT('type','object')
         )
       )
     )
   )
 ),
 JSON_OBJECT('preferred','any structured-output capable model'),
 1,1
)
ON DUPLICATE KEY UPDATE system_prompt=VALUES(system_prompt),prompt_template=VALUES(prompt_template),output_schema_json=VALUES(output_schema_json),active=1;

-- Launch Vacation Substitutions. These are generic local-activity templates;
-- later phases can match their required tags to actual local businesses.
INSERT INTO substitution_templates (slug,name,description,theme,duration_minutes,budget_level,status,metadata_json) VALUES
('fake-mexico-day','Fake Mexico Day','A locally assembled warm-weather escape built around tacos, a pool, a market stop and a sunset drink.','Mexico / beach',420,2,'published',JSON_OBJECT('agent_line','Not Cancún. Not not Cancún.')),
('resort-without-the-flight','Resort Without the Flight','Build a one-day resort experience close to home: pool, spa, slow lunch and absolutely unnecessary sunglasses.','resort',390,3,'published',JSON_OBJECT('agent_line','The airport is optional. The pool chair is not.')),
('italian-afternoon','Italian Afternoon','A café-to-dinner local escape with espresso, a walk, aperitivo and pasta.','Italy / food',330,2,'published',JSON_OBJECT('agent_line','We cannot move you to Italy today, but we can become emotionally unavailable over espresso.')),
('beach-brain-day','Beach Brain Day','A water-and-sun local day for people whose current ZIP code is failing them.','beach',360,2,'published',JSON_OBJECT('agent_line','Find water. Add snacks. Ignore email. We have a plan.')),
('spa-reset-day','Spa Reset Day','A deliberately low-effort local vacation made of spa time, a long lunch and nothing resembling productivity.','spa / relaxation',300,4,'published',JSON_OBJECT('agent_line','Your itinerary has been optimized for doing very little, professionally.')),
('cruise-day-no-ship','Cruise Day, No Ship','Pretend you boarded a cruise: breakfast buffet energy, pool time, an activity, a ridiculous drink and dinner out.','cruise',420,3,'published',JSON_OBJECT('agent_line','The ship has been removed for budgetary reasons. The behavior remains.'))
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),theme=VALUES(theme),duration_minutes=VALUES(duration_minutes),budget_level=VALUES(budget_level),status='published',metadata_json=VALUES(metadata_json);

SET @s=(SELECT id FROM substitution_templates WHERE slug='fake-mexico-day');
INSERT INTO substitution_steps (substitution_id,step_number,activity_type,title,description,duration_minutes,recommended_time,required_tags_json) VALUES
(@s,1,'food','Start with brunch or breakfast tacos','Find a local Mexican breakfast spot and order something you would actually photograph on vacation.',75,'10:30:00',JSON_ARRAY('food','local')),
(@s,2,'pool','Find water and a chair','Use a hotel day pass, public pool, resort pool or any legal body of water with lounge-chair potential.',120,'12:00:00',JSON_ARRAY('pool','local')),
(@s,3,'shopping','Wander a market','Find a Mexican market, international grocer or neighborhood shop and buy one unnecessary snack.',45,'14:30:00',JSON_ARRAY('shopping','local')),
(@s,4,'food','Taco stop','This is not the day for a responsible sandwich.',60,'16:00:00',JSON_ARRAY('food','local')),
(@s,5,'nightlife','Sunset drink','Find a patio, rooftop or backyard and make the lighting do most of the work.',75,'18:00:00',JSON_ARRAY('nightlife','local'))
ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),duration_minutes=VALUES(duration_minutes),recommended_time=VALUES(recommended_time),required_tags_json=VALUES(required_tags_json);

SET @s=(SELECT id FROM substitution_templates WHERE slug='resort-without-the-flight');
INSERT INTO substitution_steps (substitution_id,step_number,activity_type,title,description,duration_minutes,recommended_time,required_tags_json) VALUES
(@s,1,'hotel','Check into the day, not the room','Choose a nearby hotel or resort with a pool/day-pass option.',30,'10:30:00',JSON_ARRAY('hotel','pool','local')),
(@s,2,'pool','Do nothing near water','Two hours. No errands disguised as activities.',120,'11:00:00',JSON_ARRAY('pool','relaxation')),
(@s,3,'food','Long lunch','Find somewhere with table service and no reason to hurry.',90,'13:15:00',JSON_ARRAY('food','relaxation')),
(@s,4,'spa','Optional unreasonable upgrade','Massage, sauna, facial, fancy shower—pick the version your budget tolerates.',90,'15:00:00',JSON_ARRAY('spa','luxury')),
(@s,5,'nightlife','One vacation drink','Finish somewhere that makes a Tuesday look less like a Tuesday.',60,'17:00:00',JSON_ARRAY('nightlife','local'))
ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),duration_minutes=VALUES(duration_minutes),recommended_time=VALUES(recommended_time),required_tags_json=VALUES(required_tags_json);

SET @s=(SELECT id FROM substitution_templates WHERE slug='italian-afternoon');
INSERT INTO substitution_steps (substitution_id,step_number,activity_type,title,description,duration_minutes,recommended_time,required_tags_json) VALUES
(@s,1,'food','Espresso first','Find an independent café. Sit down. Do not take the coffee back to your car.',45,'13:00:00',JSON_ARRAY('food','local')),
(@s,2,'activity','Walk somewhere pretty','Historic district, garden, art neighborhood or anything with fewer parking lots.',60,'14:00:00',JSON_ARRAY('culture','local')),
(@s,3,'food','Aperitivo behavior','Small snack. Sparkling water or cocktail. Pretend everyone around you is speaking Italian.',60,'15:15:00',JSON_ARRAY('food','nightlife')),
(@s,4,'food','Pasta dinner','Find the local Italian restaurant you keep saying you should try.',90,'17:00:00',JSON_ARRAY('food','local')),
(@s,5,'food','Gelato is now mandatory','A substitute vacation requires ceremonial dessert.',45,'19:00:00',JSON_ARRAY('food'))
ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),duration_minutes=VALUES(duration_minutes),recommended_time=VALUES(recommended_time),required_tags_json=VALUES(required_tags_json);

SET @s=(SELECT id FROM substitution_templates WHERE slug='beach-brain-day');
INSERT INTO substitution_steps (substitution_id,step_number,activity_type,title,description,duration_minutes,recommended_time,required_tags_json) VALUES
(@s,1,'food','Vacation breakfast','Eat somewhere with a patio if the weather permits.',60,'09:30:00',JSON_ARRAY('food')),
(@s,2,'water','Find the closest acceptable water','Lake, pool, river, water park or resort day pass. We are grading on effort.',150,'11:00:00',JSON_ARRAY('pool','local')),
(@s,3,'relaxation','Read something useless','No professional development materials are permitted.',60,'14:00:00',JSON_ARRAY('relaxation')),
(@s,4,'food','Seafood or tropical dinner','Pick whichever makes your city feel least like itself.',90,'17:30:00',JSON_ARRAY('food'))
ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),duration_minutes=VALUES(duration_minutes),recommended_time=VALUES(recommended_time),required_tags_json=VALUES(required_tags_json);

SET @s=(SELECT id FROM substitution_templates WHERE slug='spa-reset-day');
INSERT INTO substitution_steps (substitution_id,step_number,activity_type,title,description,duration_minutes,recommended_time,required_tags_json) VALUES
(@s,1,'food','Late breakfast','There will be no 7 AM wellness journey.',60,'10:30:00',JSON_ARRAY('food','relaxation')),
(@s,2,'spa','Spa block','Book the treatment, sauna, bathhouse or wellness option that feels most irresponsible.',120,'12:00:00',JSON_ARRAY('spa','relaxation')),
(@s,3,'food','Slow lunch','Sit somewhere comfortable and make lunch take longer than necessary.',90,'14:15:00',JSON_ARRAY('food','relaxation')),
(@s,4,'relaxation','Go home and continue doing nothing','Vacation Brain hereby rejects the temptation to squeeze errands into the return trip.',60,'16:00:00',JSON_ARRAY('relaxation'))
ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),duration_minutes=VALUES(duration_minutes),recommended_time=VALUES(recommended_time),required_tags_json=VALUES(required_tags_json);

SET @s=(SELECT id FROM substitution_templates WHERE slug='cruise-day-no-ship');
INSERT INTO substitution_steps (substitution_id,step_number,activity_type,title,description,duration_minutes,recommended_time,required_tags_json) VALUES
(@s,1,'food','Buffet-energy breakfast','Large breakfast. Multiple small plates are encouraged for thematic accuracy.',75,'09:30:00',JSON_ARRAY('food')),
(@s,2,'pool','Lido deck, approximately','Pool, water park or hotel day pass. Call it Deck 9 if that helps.',120,'11:00:00',JSON_ARRAY('pool')),
(@s,3,'activity','One organized activity','Mini golf, trivia, bowling, museum, tour or anything vaguely cruise-programmable.',75,'13:30:00',JSON_ARRAY('activity')),
(@s,4,'nightlife','Ridiculous drink','Umbrella optional, emotional commitment required.',60,'15:30:00',JSON_ARRAY('nightlife')),
(@s,5,'food','Dinner out','End with dinner somewhere you would have called a specialty restaurant if there were a ship involved.',90,'18:00:00',JSON_ARRAY('food'))
ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),duration_minutes=VALUES(duration_minutes),recommended_time=VALUES(recommended_time),required_tags_json=VALUES(required_tags_json);

