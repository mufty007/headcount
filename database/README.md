# Database Schema

This directory contains the database schema for the Headcount Events Platform.

## Files

- `schema.sql` - Complete database schema matching Database Architect specifications

## Database Setup

### Development Setup (XAMPP)

1. **Create Database:**
   ```sql
   CREATE DATABASE headcount_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **Import Schema:**
   ```bash
   mysql -u root -p headcount_dev < database/schema.sql
   ```
   
   Or via phpMyAdmin:
   - Select `headcount_dev` database
   - Go to Import tab
   - Select `schema.sql` file
   - Click Go

## Schema Overview

### Core Tables

1. **organizations** - Organization settings and configuration
2. **users** - Members and admin users
3. **events** - Event information
4. **rsvps** - RSVP responses
5. **attendance** - Check-in records
6. **payments** - Payment records

### Supporting Tables

7. **email_logs** - Email tracking
8. **email_templates** - Custom email templates
9. **reminders** - Scheduled reminder configurations
10. **audit_logs** - Administrative action tracking
11. **migrations** - Migration tracking
12. **password_resets** - Password reset tokens

## Key Features

- **Normalized to 3NF** - Reduced data redundancy
- **Foreign Keys** - Referential integrity enforced
- **Indexes** - Optimized for common queries
- **Soft Deletes** - Status field instead of DELETE
- **Timestamps** - created_at, updated_at on all tables
- **ENUM Types** - Fixed value sets
- **UTF-8 Encoding** - Support for international characters

## Indexing Strategy

### Critical Indexes

- **Users**: Email, phone, name searches, organization filtering
- **Events**: Date, status, category filtering
- **Attendance**: Event/user lookups, time sorting
- **RSVPs**: Event/user lookups, status filtering
- **Payments**: Stripe lookups, status filtering

### Composite Indexes

- Member search optimization
- Event attendance queries
- Email log queries by organization and date

## Data Integrity

- **Cascade Rules**: 
  - `ON DELETE CASCADE` - Delete related data when parent deleted
  - `ON DELETE RESTRICT` - Prevent deletion if referenced
  - `ON DELETE SET NULL` - Set to NULL if parent deleted

- **Unique Constraints**:
  - One email per organization
  - One phone per organization
  - One RSVP per user per event
  - One check-in per user per event

## Notes

- All tables use `INT UNSIGNED` for IDs
- All timestamps use `TIMESTAMP` type
- All text fields use `utf8mb4` charset for full Unicode support
- Foreign keys ensure referential integrity
- Indexes optimize common query patterns

## Migration Strategy

The `migrations` table tracks schema changes by **full filename** (e.g. `082_ensure_tags_and_groups_tables.sql`).

- Fresh installs: import `schema.sql`, then run `php cli_migrate.php` (or Admin → Migrate) for any newer migrations.
- Existing installs: run `php cli_migrate.php` only — do not re-import `schema.sql`.
- Naming: `{number}_{description}.sql` using the **next free** number.
- Historical note: a few early prefixes were duplicated (`003`, `004`, `012`, `022`, `030`). Both files still run because tracking uses the full name. **Do not renumber** already-applied files on production.
- Repair / “ensure” migrations (e.g. `082_ensure_…`) must stay idempotent (`CREATE TABLE IF NOT EXISTS`, safe `ALTER`s).

## Backup Recommendations

- Daily full database dumps
- Compress with gzip
- Store outside web root
- Keep last 7 backups
- Rotate weekly/monthly backups
