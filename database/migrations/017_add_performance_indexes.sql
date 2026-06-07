-- ============================================
-- ADD PERFORMANCE INDEXES
-- ============================================
-- Adds FULLTEXT index for member search and other performance optimizations

-- Add FULLTEXT index for member search (if not exists)
SET @dbname = DATABASE();

-- Check if FULLTEXT index exists
SET @preparedStatement = CONCAT(
  'SELECT COUNT(*) INTO @index_exists FROM INFORMATION_SCHEMA.STATISTICS ',
  'WHERE table_schema = ''', @dbname, ''' ',
  'AND table_name = ''users'' ',
  'AND index_name = ''idx_fulltext_search'''
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create FULLTEXT index if it doesn't exist
SET @preparedStatement = IF(
  @index_exists > 0,
  'SELECT 1',
  'CREATE FULLTEXT INDEX idx_fulltext_search ON users(first_name, last_name, email, phone)'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add composite index for event queries
SET @preparedStatement = CONCAT(
  'SELECT COUNT(*) INTO @index_exists FROM INFORMATION_SCHEMA.STATISTICS ',
  'WHERE table_schema = ''', @dbname, ''' ',
  'AND table_name = ''events'' ',
  'AND index_name = ''idx_events_org_date_status'''
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @preparedStatement = IF(
  @index_exists > 0,
  'SELECT 1',
  'CREATE INDEX idx_events_org_date_status ON events(organization_id, event_date, status)'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add composite index for attendance queries
SET @preparedStatement = CONCAT(
  'SELECT COUNT(*) INTO @index_exists FROM INFORMATION_SCHEMA.STATISTICS ',
  'WHERE table_schema = ''', @dbname, ''' ',
  'AND table_name = ''attendance'' ',
  'AND index_name = ''idx_attendance_event_checked'''
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @preparedStatement = IF(
  @index_exists > 0,
  'SELECT 1',
  'CREATE INDEX idx_attendance_event_checked ON attendance(event_id, checked_in_at)'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for RSVP queries
SET @preparedStatement = CONCAT(
  'SELECT COUNT(*) INTO @index_exists FROM INFORMATION_SCHEMA.STATISTICS ',
  'WHERE table_schema = ''', @dbname, ''' ',
  'AND table_name = ''rsvps'' ',
  'AND index_name = ''idx_rsvps_event_status'''
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @preparedStatement = IF(
  @index_exists > 0,
  'SELECT 1',
  'CREATE INDEX idx_rsvps_event_status ON rsvps(event_id, status)'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
