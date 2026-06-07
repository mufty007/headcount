-- =============================================================================
-- Backfill attendance for: Think Like a Scholar. Not Just a Scroller
-- Session date: 2026-04-04 (paper sign-in lists)
-- Run in phpMyAdmin against your Headcount database.
--
-- Before running: BACK UP the `attendance` table.
-- If this returns 0 rows affected, check @eid / name spellings in `users`.
-- =============================================================================

SET @eid = (
  SELECT e.id FROM events e
  WHERE e.event_date = '2026-04-04'
    AND (e.title LIKE '%Think Like a Scholar%' OR e.title LIKE '%Not Just a Scroller%')
  ORDER BY (e.parent_event_id IS NOT NULL) DESC, e.id ASC
  LIMIT 1
);
-- If @eid is NULL, set the April 4 instance id manually: SET @eid = 123;

SET @org = (SELECT organization_id FROM events WHERE id = @eid LIMIT 1);
SET @parent = (SELECT parent_event_id FROM events WHERE id = @eid LIMIT 1);
SET @by = (
  SELECT u.id FROM users u
  WHERE u.organization_id = @org AND u.role IN ('admin', 'coordinator')
  ORDER BY u.id ASC LIMIT 1
);

-- Sanity check (run SELECT @eid, @org, @parent, @by; — all should be non-NULL)

INSERT INTO attendance (event_id, user_id, checked_in_at, checked_in_by)
SELECT
  @eid,
  u.id,
  '2026-04-04 12:00:00',
  COALESCE(@by, u.id)
FROM users u
WHERE u.organization_id = @org
  AND (
    LOWER(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name))) IN (
    'abdulbasit mustapha',
    'jawad hindarish',
    'salih salih',
    'ahmed elwali',
    'mirindi kabangu',
    'hamzat ipesa-balogun',
    'ahmed ipesa-balogun',
    'muhammad rashid',
    'mujahid abdus salam',
    'mujahid abus salam',
    'adil majid',
    'halima al khattab',
    'cairiah al shawesh',
    'sherry johnson',
    'gabriella stuart',
    'khalid atiya',
    'widad birama',
    'mariama s. dampha',
    'mariama dampha',
    'bakary fatty',
    'abubakr b. fatty',
    'abubakr fatty',
    'fatema altbeishi',
    'kadija sow',
    'rashidat dawoodu',
    'lois amanda webster',
    'fatima el yahyaoui',
    'hikman sanusi',
    'halima saighani',
    'tasneem siddiqua',
    'asra fatima',
    'ilhaan mohamed',
    'sara altebishi',
    'malak khatib',
    'sara alghaini',
    'ragad alshawesh',
    'arshad habib',
    'talla ahmad'
    )
    -- If first/last split differs in your DB, these catch common alternates:
    OR LOWER(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name))) IN (
    'fatema altebishi',
    'fatima altebishi',
    'lois webster'
    )
  )
  AND NOT EXISTS (
    SELECT 1 FROM attendance a
    WHERE a.user_id = u.id
      AND DATE(a.checked_in_at) = '2026-04-04'
      AND (
        a.event_id = @eid
        OR (@parent IS NOT NULL AND a.event_id = @parent)
      )
  );

-- Optional: show how many rows were inserted (phpMyAdmin shows "X rows affected")
