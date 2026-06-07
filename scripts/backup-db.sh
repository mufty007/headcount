#!/bin/bash

# Database Backup Script
# Creates automated database backups with retention policy

# Configuration
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="${BACKUP_DIR:-/home/user/backups}"
DB_NAME="${DB_NAME:-headcount_events}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-localhost}"

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Backup database
echo "Starting database backup..."
if [ -z "$DB_PASS" ]; then
    mysqldump -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" | gzip > "$BACKUP_DIR/db_backup_$DATE.sql.gz"
else
    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$BACKUP_DIR/db_backup_$DATE.sql.gz"
fi

if [ $? -eq 0 ]; then
    echo "Database backup completed: db_backup_$DATE.sql.gz"
    
    # Keep only last 7 daily backups
    ls -t "$BACKUP_DIR"/db_backup_*.sql.gz 2>/dev/null | tail -n +8 | xargs rm -f 2>/dev/null
    
    # Keep weekly backups (last 4) - run on Monday
    if [ $(date +%u) -eq 1 ]; then
        cp "$BACKUP_DIR/db_backup_$DATE.sql.gz" "$BACKUP_DIR/weekly_backup_$DATE.sql.gz"
        ls -t "$BACKUP_DIR"/weekly_backup_*.sql.gz 2>/dev/null | tail -n +5 | xargs rm -f 2>/dev/null
        echo "Weekly backup created: weekly_backup_$DATE.sql.gz"
    fi
    
    # Keep monthly backups (last 12) - run on 1st of month
    if [ $(date +%d) -eq 1 ]; then
        cp "$BACKUP_DIR/db_backup_$DATE.sql.gz" "$BACKUP_DIR/monthly_backup_$DATE.sql.gz"
        ls -t "$BACKUP_DIR"/monthly_backup_*.sql.gz 2>/dev/null | tail -n +13 | xargs rm -f 2>/dev/null
        echo "Monthly backup created: monthly_backup_$DATE.sql.gz"
    fi
    
    echo "Backup retention policy applied"
else
    echo "ERROR: Database backup failed!"
    exit 1
fi
