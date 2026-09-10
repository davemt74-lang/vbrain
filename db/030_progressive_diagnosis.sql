USE vacation_brain;

SET @deck=(SELECT id FROM swipe_decks WHERE slug='self-diagnosis-v1' LIMIT 1);
UPDATE swipe_decks
SET description='Ten quick choices unlock a Vacation Brain diagnosis. Ten optional follow-up questions sharpen the travel profile.'
WHERE id=@deck;

SET @planning=(SELECT id FROM traits WHERE slug='planning');
SET @budget=(SELECT id FROM traits WHERE slug='budget_sensitivity');
SET @adventure=(SELECT id FROM traits WHERE slug='adventure');
SET @morning=(SELECT id FROM traits WHERE slug='morning_tolerance');
SET @relax=(SELECT id FROM traits WHERE slug='relaxation');
SET @activity=(SELECT id FROM traits WHERE slug='activity_level');
SET @luxury=(SELECT id FROM traits WHERE slug='luxury');
SET @convenience=(SELECT id FROM traits WHERE slug='convenience');
SET @beach=(SELECT id FROM traits WHERE slug='beach');
SET @spont=(SELECT id FROM traits WHERE slug='spontaneity');
SET @pool=(SELECT id FROM traits WHERE slug='pool');
SET @resort=(SELECT id FROM traits WHERE slug='resort_preference');
SET @food=(SELECT id FROM traits WHERE slug='food');
SET @direct=(SELECT id FROM traits WHERE slug='direct_flight_preference');
SET @flight=(SELECT id FROM traits WHERE slug='flight_tolerance');

-- Q11
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-luggage','Luggage Philosophy','Four nights away. What luggage situation feels right?','Your suitcase says more about you than you think.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-luggage');
INSERT IGNORE INTO swipe_deck_items (deck_id,content_id,sort_order,weight) VALUES (@deck,@q,11,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'personal','One personal item. If it does not fit, it stays home.',1,JSON_OBJECT('brain_points',8)),
(@q,'carryon','A proper carry-on. Prepared, but still mobile.',2,JSON_OBJECT('brain_points',7)),
(@q,'checked','Check the bag. I want options.',3,JSON_OBJECT('brain_points',6))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='personal');
SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='carryon');
SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='checked');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@spont,7,3),(@c1,@convenience,4,2),(@c2,@planning,6,3),(@c2,@convenience,6,3),(@c3,@planning,4,2),(@c3,@luxury,3,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q12
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-day-pace','Ideal Vacation Pace','You wake up with a completely open vacation day. What happens?','There are several correct ways to avoid productivity.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-day-pace'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,12,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'packed','Breakfast, museum, neighborhood, dinner, night plan. Let us go.',1,JSON_OBJECT('brain_points',7)),
(@q,'anchor','One good plan, then wander.',2,JSON_OBJECT('brain_points',8)),
(@q,'nothing','Pool chair. Maybe lunch. We will reassess tomorrow.',3,JSON_OBJECT('brain_points',9))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='packed'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='anchor'); SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='nothing');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@activity,9,4),(@c1,@planning,8,3),(@c2,@spont,6,3),(@c2,@adventure,5,3),(@c3,@relax,10,4),(@c3,@pool,7,3),(@c3,@activity,-6,3)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q13
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-hotel-location','Hotel Tradeoff','Which hotel wins when the prices are roughly equal?','Location versus escape. A classic conflict.','published',2,1,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-hotel-location'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,13,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'center','Walkable and right in the middle of everything.',1,JSON_OBJECT('brain_points',7)),
(@q,'resort','Beautiful resort a little outside the action.',2,JSON_OBJECT('brain_points',8)),
(@q,'value','The best value, even if transportation takes more work.',3,JSON_OBJECT('brain_points',6))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='center'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='resort'); SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='value');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@convenience,10,4),(@c1,@activity,4,2),(@c2,@resort,10,4),(@c2,@relax,6,3),(@c2,@luxury,4,2),(@c3,@budget,10,4),(@c3,@flight,2,1)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q14
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-local-food','Local Food Test','You are somewhere famous for food you have never tried. Dinner plan?','The menu may require courage.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-local-food'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,14,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'reservation','Reserve the place everyone says is essential.',1,JSON_OBJECT('brain_points',8)),
(@q,'street','Find the busy local spot and order what they are having.',2,JSON_OBJECT('brain_points',9)),
(@q,'familiar','I am adventurous, but I still want to recognize dinner.',3,JSON_OBJECT('brain_points',5))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='reservation'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='street'); SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='familiar');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@food,10,4),(@c1,@planning,5,2),(@c2,@food,9,4),(@c2,@adventure,7,3),(@c2,@spont,5,2),(@c3,@food,2,1),(@c3,@adventure,-4,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q15
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-landscape','Pick the View','You get one view outside your room for the whole trip. Pick it.','No pressure. This will probably affect everything.','published',2,1,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-landscape'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,15,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'ocean','Ocean. Obviously.',1,JSON_OBJECT('brain_points',9)),
(@q,'mountain','Mountains and somewhere to explore.',2,JSON_OBJECT('brain_points',8)),
(@q,'city','City lights and a great neighborhood downstairs.',3,JSON_OBJECT('brain_points',7))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='ocean'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='mountain'); SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='city');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@beach,10,4),(@c1,@relax,5,2),(@c2,@adventure,9,4),(@c2,@activity,6,3),(@c3,@food,5,2),(@c3,@activity,5,2),(@c3,@convenience,4,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q16
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-flight-time','Departure Time','Three flights cost the same. Which one are you taking?','Airport personality test.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-flight-time'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,16,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'early','6:10 AM. Arrive early and get the whole day.',1,JSON_OBJECT('brain_points',7)),
(@q,'midday','11:30 AM. I would like to remain human.',2,JSON_OBJECT('brain_points',8)),
(@q,'redeye','Red-eye. Sleep badly, wake up somewhere else.',3,JSON_OBJECT('brain_points',9))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='early'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='midday'); SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='redeye');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@morning,10,4),(@c1,@planning,4,2),(@c2,@convenience,7,3),(@c2,@morning,-2,1),(@c3,@flight,8,4),(@c3,@adventure,4,2),(@c3,@morning,-4,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q17
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-weather-tradeoff','Weather Tradeoff','The destination is amazing, but the forecast is imperfect. How imperfect is acceptable?','There is weather, and then there is vacation weather.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-weather-tradeoff'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,17,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'sun','I came for sun. Move the trip if necessary.',1,JSON_OBJECT('brain_points',8)),
(@q,'mixed','A mixed forecast is fine. I can work with that.',2,JSON_OBJECT('brain_points',7)),
(@q,'whatever','Rain, wind, whatever. I am still going.',3,JSON_OBJECT('brain_points',9))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='sun'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='mixed'); SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='whatever');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@beach,8,3),(@c1,@planning,4,2),(@c2,@spont,3,2),(@c2,@planning,2,1),(@c3,@adventure,8,4),(@c3,@spont,6,3)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q18
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-splurge','Vacation Splurge','If you are going to overspend on exactly one thing, what gets the money?','This is a judgment-free financial emergency.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-splurge'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,18,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'hotel','The hotel. I want the room, pool, and view.',1,JSON_OBJECT('brain_points',9)),
(@q,'food','The food. I will remember the meal longer than the room.',2,JSON_OBJECT('brain_points',8)),
(@q,'experience','The experience. Give me the story.',3,JSON_OBJECT('brain_points',9))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='hotel'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='food'); SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='experience');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@luxury,10,4),(@c1,@resort,7,3),(@c1,@pool,4,2),(@c2,@food,10,4),(@c2,@luxury,3,2),(@c3,@adventure,10,4),(@c3,@activity,5,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q19
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-detour','Unexpected Detour','You hear about a place two hours away that was not in the plan.','The itinerary has entered a vulnerable state.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-detour'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,19,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'go','We are going. That is how stories happen.',1,JSON_OBJECT('brain_points',10)),
(@q,'check','Maybe. Let me see what we would have to move.',2,JSON_OBJECT('brain_points',7)),
(@q,'stay','No. We already made a good plan.',3,JSON_OBJECT('brain_points',5))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='go'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='check'); SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='stay');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@spont,10,4),(@c1,@adventure,8,3),(@c2,@planning,5,2),(@c2,@spont,4,2),(@c3,@planning,9,4),(@c3,@spont,-5,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);

-- Q20
INSERT INTO content_items (content_type,slug,title,body,short_body,status,humor_level,sarcasm_level,published_at)
VALUES ('swipe_question','vb-dx-repeat-or-new','Next Trip Instinct','You just had a nearly perfect trip. What is your instinct for next time?','Loyalty versus curiosity.','published',3,2,NOW())
ON DUPLICATE KEY UPDATE body=VALUES(body),short_body=VALUES(short_body),status='published';
SET @q=(SELECT id FROM content_items WHERE slug='vb-dx-repeat-or-new'); INSERT IGNORE INTO swipe_deck_items VALUES (@deck,@q,20,1);
INSERT INTO content_choices (content_id,choice_key,label,sort_order,metadata_json) VALUES
(@q,'repeat','Go back. We already know where the good breakfast is.',1,JSON_OBJECT('brain_points',7)),
(@q,'new','New place. Too much world left.',2,JSON_OBJECT('brain_points',9)),
(@q,'mix','Keep the favorite, but add somewhere new nearby.',3,JSON_OBJECT('brain_points',8))
ON DUPLICATE KEY UPDATE label=VALUES(label),metadata_json=VALUES(metadata_json);
SET @c1=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='repeat'); SET @c2=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='new'); SET @c3=(SELECT id FROM content_choices WHERE content_id=@q AND choice_key='mix');
INSERT INTO choice_trait_effects (choice_id,trait_id,score_delta,confidence_delta) VALUES
(@c1,@relax,6,3),(@c1,@planning,4,2),(@c2,@adventure,10,4),(@c2,@spont,7,3),(@c3,@adventure,5,2),(@c3,@planning,5,2),(@c3,@spont,4,2)
ON DUPLICATE KEY UPDATE score_delta=VALUES(score_delta),confidence_delta=VALUES(confidence_delta);
