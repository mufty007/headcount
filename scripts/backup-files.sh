#!/bin/bash

# File Backup Script
# Creates backups of important files and directories

# Configuration
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="${BACKUP_DIR:-/home/user/backups}"
APP_DIR="${APP_DIR:-/home/user/public_html/headcount}"

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

echo "Starting file backup..."

# Backup config file
if [ -f "$APP_DIR/config/config.php" ]; then
    cp "$APP_DIR/config/config.php" "$BACKUP_DIR/config_backup_$DATE.php"
    echo "Config file backed up: config_backup_$DATE.php"
fi

# Backup uploads directory
if [ -d "$APP_DIR/uploads" ]; then
    tar -czf "$BACKUP_DIR/uploads_backup_$DATE.tar.gz" -C "$APP_DIR" uploads/
    echo "Uploads directory backed up: uploads_backup_$DATE.tar.gz"
fi

# Keep last 7 backups
ls -t "$BACKUP_DIR"/config_backup_*.php 2>/dev/null | tail -n +8 | xargs rm -f 2>/dev/null
ls -t "$BACKUP_DIR"/uploads_backup_*.tar.gz 2>/dev/null | tail -n +8 | xargs rm -f 2>/dev/null

echo "File backup completed"
