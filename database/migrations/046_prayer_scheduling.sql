-- Prayer scheduling + org location for Aladhan.
--
-- organizations.timezone already exists in schema.sql (and many installs). Do NOT add it again
-- or MySQL will error #1060 and stop the script before city/country are added.
--
-- Run statements one at a time if you get "Duplicate column": skip lines that already applied.

ALTER TABLE organizations ADD COLUMN city VARCHAR(100) DEFAULT NULL;
ALTER TABLE organizations ADD COLUMN country VARCHAR(100) DEFAULT NULL;

ALTER TABLE events ADD COLUMN prayer_name VARCHAR(20) DEFAULT NULL;
ALTER TABLE events ADD COLUMN prayer_offset INT DEFAULT 0;

ALTER TABLE programs ADD COLUMN prayer_name VARCHAR(20) DEFAULT NULL;
ALTER TABLE programs ADD COLUMN prayer_offset INT DEFAULT 0;
