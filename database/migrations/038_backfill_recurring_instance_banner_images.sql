-- Backfill banner_image on recurring child events from their parent row.
-- Safe to re-run: only updates rows where child banner is empty and parent has a path.

UPDATE events child
INNER JOIN events parent ON parent.id = child.parent_event_id
SET child.banner_image = parent.banner_image
WHERE child.parent_event_id IS NOT NULL
  AND (child.banner_image IS NULL OR TRIM(child.banner_image) = '')
  AND parent.banner_image IS NOT NULL
  AND TRIM(parent.banner_image) != '';
