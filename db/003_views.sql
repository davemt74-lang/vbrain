USE vacation_brain;

CREATE OR REPLACE VIEW v_seed_generation_progress AS
SELECT
  m.content_type,
  m.target_count,
  COUNT(CASE WHEN c.status IN ('approved','published') THEN 1 END) AS approved_candidates,
  COUNT(CASE WHEN c.status = 'published' THEN 1 END) AS published_candidates,
  GREATEST(m.target_count - COUNT(CASE WHEN c.status IN ('approved','published') THEN 1 END), 0) AS remaining_to_approve
FROM seed_generation_manifest m
LEFT JOIN ai_generated_candidates c ON c.content_type = m.content_type
WHERE m.active = 1
GROUP BY m.id, m.content_type, m.target_count;

CREATE OR REPLACE VIEW v_user_vacation_brain_profile AS
SELECT
  u.id AS user_id,
  u.display_name,
  COALESCE(s.vacation_brain_score,0) AS vacation_brain_score,
  COALESCE(s.current_streak,0) AS current_streak,
  COALESCE(s.longest_streak,0) AS longest_streak,
  COALESCE(s.lifetime_checkins,0) AS lifetime_checkins,
  COALESCE(s.score_level,'thinking_about_it') AS score_level,
  COUNT(DISTINCT ut.trait_id) AS learned_traits,
  COALESCE(AVG(ut.confidence),0) AS avg_trait_confidence
FROM users u
LEFT JOIN user_score_summary s ON s.user_id = u.id
LEFT JOIN user_traits ut ON ut.user_id = u.id
GROUP BY u.id, u.display_name, s.vacation_brain_score, s.current_streak, s.longest_streak, s.lifetime_checkins, s.score_level;
