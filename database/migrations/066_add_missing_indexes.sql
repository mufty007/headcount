-- Add missing performance indexes (skip if already present via migration 017)

SET @dbname = DATABASE();

-- idx_events_parent (organization_id, parent_event_id)
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'events' AND INDEX_NAME = 'idx_events_parent'
);
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_events_parent ON events (organization_id, parent_event_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_users_org_email (organization_id, email)
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_org_email'
);
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_users_org_email ON users (organization_id, email)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- idx_categories_org (organization_id)
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'categories' AND INDEX_NAME = 'idx_categories_org'
);
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_categories_org ON categories (organization_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
