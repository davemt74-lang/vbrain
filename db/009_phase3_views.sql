USE vacation_brain;

CREATE OR REPLACE VIEW v_seed_generation_progress AS
SELECT
  m.content_type,
  m.target_count,
  GREATEST(
    m.approved_count,
    (SELECT COUNT(*) FROM ai_generated_candidates c WHERE c.content_type=m.content_type AND c.status IN ('approved','published')),
    (SELECT COUNT(*) FROM content_items ci WHERE ci.content_type=m.content_type AND ci.status='published')
  ) AS approved_candidates,
  GREATEST(
    m.published_count,
    (SELECT COUNT(*) FROM ai_generated_candidates c2 WHERE c2.content_type=m.content_type AND c2.status='published'),
    (SELECT COUNT(*) FROM content_items ci2 WHERE ci2.content_type=m.content_type AND ci2.status='published')
  ) AS published_candidates,
  GREATEST(m.target_count - GREATEST(
    m.approved_count,
    (SELECT COUNT(*) FROM ai_generated_candidates c3 WHERE c3.content_type=m.content_type AND c3.status IN ('approved','published')),
    (SELECT COUNT(*) FROM content_items ci3 WHERE ci3.content_type=m.content_type AND ci3.status='published')
  ),0) AS remaining_to_approve
FROM seed_generation_manifest m
WHERE m.active=1;
