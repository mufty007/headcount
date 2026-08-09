-- Extend facility blocked_times JSON shape (no schema change — JSON column already exists).
-- Supported rule shapes (backward compatible with single-date blocks):
--   once:   { "repeat":"once", "date":"YYYY-MM-DD", "start_time", "end_time", "reason", "block_member", "block_guest" }
--   range:  { "repeat":"range", "start_date", "end_date", "start_time", "end_time", … }
--   weekly: { "repeat":"weekly", "start_date", "end_date", "days_of_week":[1,2,3,4,5], "start_time", "end_time", … }
-- days_of_week: 0=Sun … 6=Sat (e.g. school hours Mon–Fri 08:00–15:00).

ALTER TABLE `facilities`
  MODIFY COLUMN `blocked_times` JSON NULL
  COMMENT 'Array of once|range|weekly blocks: date/start_date/end_date, days_of_week, start_time, end_time, reason, block_member, block_guest';
