USE vacation_brain;

INSERT INTO swipe_decks (slug,name,description,deck_type,status,premium,sort_order)
VALUES ('self-diagnosis-v1','Vacation Brain Self-Diagnosis','Ten quick choices to determine how mentally checked out you already are.','diagnosis','published',0,1)
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), status='published';
SET @deck = (SELECT id FROM swipe_decks WHERE slug='self-diagnosis-v1');

-- Q1
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-flight-tabs','Workplace Flight Research','It is 2:17 PM on a workday and you accidentally open a flight-search tab. What happens next?','A completely normal workplace situation.','published',4,3,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-flight-tabs');
INSERT IGNORE INTO swipe_deck_items (deck_id,content_id,sort_order,weight) VALUES (@deck,@q,1,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'close','Close it. I am a responsible adult.',1,JSON_OBJECT('brain_points',3)),
(@q,'tabs','Open five more tabs and compare airports.',2,JSON_OBJECT('brain_points',10))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='close');
SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='tabs');
SET @planning=(SELECT id FROM traits WHERE slug='planning'); SET @budget=(SELECT id FROM traits WHERE slug='budget_sensitivity');
INSERT INTO choice_trait_effects VALUES (@c1,@planning,2,2),(@c2,@planning,6,3),(@c2,@budget,3,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q2
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-sunrise','Sunrise Excursion','Your friends booked a sunrise kayaking excursion. Meet in the lobby at 5:20 AM.','5:20 AM. On vacation. Choose carefully.','published',4,3,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-sunrise');
INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,2,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'go','Sounds incredible.',1,JSON_OBJECT('brain_points',6)),
(@q,'sleep','I will enjoy everyone’s photos at breakfast.',2,JSON_OBJECT('brain_points',9))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='go'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='sleep');
SET @adventure=(SELECT id FROM traits WHERE slug='adventure'); SET @morning=(SELECT id FROM traits WHERE slug='morning_tolerance'); SET @relax=(SELECT id FROM traits WHERE slug='relaxation'); SET @activity=(SELECT id FROM traits WHERE slug='activity_level');
INSERT INTO choice_trait_effects VALUES (@c1,@adventure,8,3),(@c1,@morning,8,3),(@c1,@activity,6,2),(@c2,@morning,-10,4),(@c2,@relax,8,3),(@c2,@activity,-4,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q3
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-ocean-view','Ocean View Economics','The ocean-view room is $85 more per night. Your move?','Financial planning, but with palm trees.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-ocean-view'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,3,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'standard','Keep the standard room. The ocean is outside.',1,JSON_OBJECT('brain_points',4)),
(@q,'upgrade','Upgrade. I did not travel here to stare at a parking lot.',2,JSON_OBJECT('brain_points',9))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='standard'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='upgrade');
SET @luxury=(SELECT id FROM traits WHERE slug='luxury'); SET @convenience=(SELECT id FROM traits WHERE slug='convenience');
INSERT INTO choice_trait_effects VALUES (@c1,@budget,8,3),(@c1,@luxury,-4,2),(@c2,@luxury,9,3),(@c2,@convenience,4,2),(@c2,@budget,-5,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q4
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-weather','Weather Envy','How many vacation destinations are currently saved in your weather app?','This answer may be used against your productivity.','published',4,3,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-weather'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,4,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'none','None. I only suffer through my own weather.',1,JSON_OBJECT('brain_points',2)),
(@q,'several','Several. I need to know who is having a better day.',2,JSON_OBJECT('brain_points',10))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='none'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='several');
SET @beach=(SELECT id FROM traits WHERE slug='beach');
INSERT INTO choice_trait_effects VALUES (@c2,@beach,5,2),(@c2,@planning,3,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q5
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-weekend','Unexpected Long Weekend','You suddenly get a free four-day weekend. What is your first thought?','Be truthful. The beach already knows.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-weekend'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,5,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'home','Sleep, errands, maybe clean something.',1,JSON_OBJECT('brain_points',3)),
(@q,'escape','How far can I get before anyone notices?',2,JSON_OBJECT('brain_points',10))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='home'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='escape');
SET @spont=(SELECT id FROM traits WHERE slug='spontaneity');
INSERT INTO choice_trait_effects VALUES (@c1,@planning,3,2),(@c2,@spont,9,3),(@c2,@adventure,4,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q6
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-pool','Pool Schedule','You arrive at a resort at noon. Your room will not be ready until 3 PM.','The room is theoretical. The pool is real.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-pool'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,6,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'wait','Wait in the lobby like civilization intended.',1,JSON_OBJECT('brain_points',4)),
(@q,'pool','Change in a restroom and be poolside in seven minutes.',2,JSON_OBJECT('brain_points',9))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='wait'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='pool');
SET @pool=(SELECT id FROM traits WHERE slug='pool'); SET @resort=(SELECT id FROM traits WHERE slug='resort_preference');
INSERT INTO choice_trait_effects VALUES (@c2,@pool,10,4),(@c2,@resort,6,3),(@c2,@relax,5,2),(@c1,@planning,3,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q7
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-food','First Night Dinner','First night of vacation. How much planning has gone into dinner?','There is no wrong answer. There are only hungry answers.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-food'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,7,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'wing','We will walk around and find something.',1,JSON_OBJECT('brain_points',5)),
(@q,'reservation','I read menus three weeks ago. We have a reservation.',2,JSON_OBJECT('brain_points',8))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='wing'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='reservation');
SET @food=(SELECT id FROM traits WHERE slug='food');
INSERT INTO choice_trait_effects VALUES (@c1,@spont,6,3),(@c1,@food,4,2),(@c2,@planning,8,3),(@c2,@food,9,3)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q8
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-direct-flight','Connection Math','A direct flight costs $180 more. The cheaper option has a three-hour connection.','How much is three hours of airport carpet worth to you?','published',4,3,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-direct-flight'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,8,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'connect','Save the money. I can survive an airport.',1,JSON_OBJECT('brain_points',5)),
(@q,'direct','Pay it. Vacation time is not connection time.',2,JSON_OBJECT('brain_points',9))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='connect'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='direct');
SET @direct=(SELECT id FROM traits WHERE slug='direct_flight_preference'); SET @flight=(SELECT id FROM traits WHERE slug='flight_tolerance');
INSERT INTO choice_trait_effects VALUES (@c1,@budget,7,3),(@c1,@flight,5,2),(@c2,@direct,10,4),(@c2,@convenience,8,3),(@c2,@budget,-4,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q9
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-substitution','Vacation Substitute','You cannot leave town this weekend. Someone suggests a hotel pool day pass, tacos, and tropical drinks.','Not Cancun. Not not Cancun.','published',4,3,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-substitution'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,9,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'skip','I will just stay home.',1,JSON_OBJECT('brain_points',3)),
(@q,'fakeit','Book the day pass. We are manufacturing a vacation.',2,JSON_OBJECT('brain_points',10))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='skip'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='fakeit');
INSERT INTO choice_trait_effects VALUES (@c2,@pool,6,3),(@c2,@food,4,2),(@c2,@spont,5,2),(@c2,@relax,5,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q10
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-book-it','The Final Symptom','You have been talking about the same destination for six months. Someone says, “Book it or stop talking about it.”','This is the part where we assess commitment.','published',5,3,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body), status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-book-it'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,10,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'later','Continue researching. Timing is everything.',1,JSON_OBJECT('brain_points',6)),
(@q,'book','Fine. Open the calendar. We are doing this.',2,JSON_OBJECT('brain_points',10))
ON DUPLICATE KEY UPDATE label=VALUES(label), metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='later'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='book');
INSERT INTO choice_trait_effects VALUES (@c1,@planning,5,2),(@c2,@spont,5,2),(@c2,@planning,3,2),(@c2,@adventure,4,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

INSERT INTO achievements (slug,name,description,rarity,active) VALUES
('mentally-checked-out','Mentally Checked Out','Your Vacation Brain Score has reached the point where your calendar should be nervous.','common',1),
('no-activities-before-10','No Activities Before 10AM','Your answers show a firm commitment to protecting vacation mornings.','common',1),
('pool-person','Pool Person','You have demonstrated unusually strong pool-related decision making.','common',1),
('professional-daydreamer','Professional Daydreamer','You have turned thinking about vacation into a repeatable practice.','rare',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),active=1;

SET @a=(SELECT id FROM achievements WHERE slug='mentally-checked-out');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window,filter_json)
SELECT @a,'score_threshold',NULL,700,'current',NULL WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='score_threshold');
SET @a=(SELECT id FROM achievements WHERE slug='no-activities-before-10');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window,filter_json)
SELECT @a,'trait_below',NULL,35,'current',JSON_OBJECT('trait_slug','morning_tolerance') WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='trait_below');
SET @a=(SELECT id FROM achievements WHERE slug='pool-person');
INSERT INTO achievement_rules (achievement_id,rule_type,event_type,threshold,time_window,filter_json)
SELECT @a,'trait_above',NULL,58,'current',JSON_OBJECT('trait_slug','pool') WHERE NOT EXISTS (SELECT 1 FROM achievement_rules WHERE achievement_id=@a AND rule_type='trait_above');
