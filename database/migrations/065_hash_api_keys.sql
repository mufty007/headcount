-- Hash organization API keys at rest (public WordPress / calendar integrations)

SET @dbname = DATABASE();

-- Ensure legacy plaintext column exists for migration source data
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'api_key'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE organizations ADD COLUMN api_key VARCHAR(64) NULL AFTER timezone',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add hash storage columns
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'organizations' AND COLUMN_NAME = 'api_key_hash'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE organizations ADD COLUMN api_key_hash VARCHAR(255) NULL AFTER api_key, ADD COLUMN api_key_prefix VARCHAR(8) NULL AFTER api_key_hash',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index for prefix lookup
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'organizations' AND INDEX_NAME = 'idx_org_api_key_prefix'
);
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_org_api_key_prefix ON organizations (api_key_prefix)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Note: plaintext api_key → hash migration runs via PHP script or admin regenerate.
-- Operators must regenerate API keys in Admin → Settings after deploy if keys existed only in plaintext.
