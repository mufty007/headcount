# Deployment Guide

## Quick Start

### Installation Time Target: < 30 minutes

## Pre-Installation Checklist

- [ ] Subdomain created (e.g., events.yourdomain.com)
- [ ] Database created via cPanel
- [ ] Database user created with permissions
- [ ] SSL certificate installed (Let's Encrypt free)

## Installation Steps

### 1. Upload Files

Upload all files to your web root directory via FTP or cPanel File Manager.

### 2. Set File Permissions

```bash
# Directories
find . -type d -exec chmod 755 {} \;

# Files
find . -type f -exec chmod 644 {} \;

# Config file (more restrictive)
chmod 600 config/config.php

# Executable scripts
chmod 755 scripts/*.sh
chmod 755 cron/*.php
```

### 3. Run Installation Wizard

Navigate to: `https://yourdomain.com/install/`

The wizard will:
1. Check system requirements
2. Configure database connection
3. Create database tables
4. Set up admin account
5. Create configuration file

### 4. Post-Installation

- [ ] Log in to admin dashboard
- [ ] Configure organization settings
- [ ] Upload organization logo (optional)
- [ ] Enter Stripe keys (if using payments)
- [ ] Enter SMTP2GO API key
- [ ] Send test email
- [ ] Create test event
- [ ] Test check-in process

## Server Requirements

### PHP
- Version: 8.0, 8.1, or 8.2
- Extensions: mysqli, curl, gd/imagick, mbstring, openssl, json, session, zip

### Database
- MySQL 5.7+ or MariaDB 10.3+
- UTF-8 support (utf8mb4)

### Web Server
- Apache with mod_rewrite
- mod_headers (for security headers)

### SSL
- HTTPS required (SSL certificate)

## Backup Strategy

### Automated Database Backups

Set up cron job:
```bash
0 2 * * * /path/to/scripts/backup-db.sh
```

Configure in `backup-db.sh`:
- `BACKUP_DIR`: Backup directory path
- `DB_NAME`: Database name
- `DB_USER`: Database username
- `DB_PASS`: Database password
- `DB_HOST`: Database host

### Automated File Backups

Set up cron job:
```bash
0 3 * * * /path/to/scripts/backup-files.sh
```

### Backup Retention

- **Daily backups**: Last 7 days
- **Weekly backups**: Last 4 weeks (created on Mondays)
- **Monthly backups**: Last 12 months (created on 1st of month)

### Manual Backup

```bash
# Database
./scripts/backup-db.sh

# Files
./scripts/backup-files.sh
```

### Restore Backup

```bash
# Restore database
gunzip db_backup_20240101_120000.sql.gz
mysql -u username -p database_name < db_backup_20240101_120000.sql

# Restore files
tar -xzf uploads_backup_20240101_120000.tar.gz -C /path/to/uploads/
cp config_backup_20240101_120000.php config/config.php
```

## Cron Jobs Setup

### Required Cron Jobs

1. **Email Reminders** (Every hour)
   ```
   0 * * * * /usr/bin/php /path/to/cron/reminders.php
   ```

2. **Send Queued Emails** (Every 5 minutes)
   ```
   */5 * * * * /usr/bin/php /path/to/cron/send-emails.php
   ```

3. **Database Backup** (Daily at 2 AM)
   ```
   0 2 * * * /path/to/scripts/backup-db.sh
   ```

4. **File Backup** (Daily at 3 AM)
   ```
   0 3 * * * /path/to/scripts/backup-files.sh
   ```

5. **Log Cleanup** (Weekly on Sunday at 3 AM)
   ```
   0 3 * * 0 /usr/bin/php /path/to/cron/cleanup-logs.php
   ```

### Setting Up in cPanel

1. Navigate to "Cron Jobs" in cPanel
2. Click "Add New Cron Job"
3. Set schedule
4. Enter command
5. Save

## Update Process

### Pre-Update Checklist

- [ ] Backup database
- [ ] Backup config file
- [ ] Backup uploads directory
- [ ] Review update notes
- [ ] Check compatibility

### Update Steps

1. **Backup Everything**
   ```bash
   ./scripts/backup-db.sh
   ./scripts/backup-files.sh
   ```

2. **Download Update Files**
   - Download new version
   - Extract files

3. **Upload New Files**
   - Upload to web root
   - **Preserve** `config/config.php`
   - **Preserve** `uploads/` directory

4. **Run Migrations**
   - Access `/admin/migrate` (if available)
   - Or migrations run automatically on next page load

5. **Clear Cache**
   - Clear any cached data
   - Clear browser cache (optional)

6. **Verify Installation**
   - Test login
   - Test critical features
   - Check for errors

## Health Check

Access system health check at: `/admin/health.php`

Shows:
- PHP version
- MySQL version
- Disk space usage
- Database size
- Configuration status
- Cron job status
- Error log size

## Troubleshooting

### Database Connection Failed

1. Check credentials in `config/config.php`
2. Verify database exists
3. Check user permissions
4. Verify database server is running

### 500 Internal Server Error

1. Check error log: `logs/app.log`
2. Check file permissions
3. Check PHP version
4. Check `.htaccess` syntax

### Emails Not Sending

1. Check SMTP2GO API key in settings
2. Check email configuration
3. Check email logs
4. Verify API quota

### Payments Not Working

1. Check Stripe keys in settings
2. Verify webhook URL
3. Check webhook logs
4. Verify SSL certificate

### Slow Performance

1. Check database indexes
2. Check slow query log
3. Check server resources
4. Optimize queries

## Security Hardening

### File Permissions

- Config files: `600` (read/write owner only)
- Directories: `755`
- Files: `644`
- Executable scripts: `755`

### Directory Protection

Protect sensitive directories with `.htaccess`:

```apache
# config/.htaccess
Order deny,allow
Deny from all
```

### Remove Install Directory

After installation, either:
- Delete the `install/` directory
- Rename it to prevent access
- Protect it with `.htaccess`

## Support

For issues or questions:
- Check documentation
- Review error logs
- Contact support

## Version Information

Current version: See `version.php`
