# Subdomain Deployment Guide

Complete guide for deploying Headcount to a subdomain (e.g., `events.yourdomain.com`).

> **Hostinger Users**: See `HOSTINGER_DEPLOYMENT.md` for Hostinger-specific instructions!

## Pre-Deployment Checklist

- [ ] Subdomain created in your hosting control panel
- [ ] Database created via cPanel/phpMyAdmin
- [ ] Database user created with full permissions
- [ ] SSL certificate installed (Let's Encrypt recommended)
- [ ] PHP 8.0+ enabled
- [ ] Required PHP extensions installed (mysqli, curl, gd, mbstring, openssl, json, zip)

## Step 1: Create Deployment Package

### On Windows:
```powershell
.\package-deploy.ps1
```

This will create a ZIP file in the `deploy/` directory with all necessary files, excluding:
- Development files (node_modules, .git, etc.)
- Log files
- Local configuration
- Test scripts
- Temporary files

### Manual Package Creation:
If you prefer to create the package manually, exclude:
- `node_modules/`
- `.git/`
- `vendor/` (will be regenerated)
- `logs/*.log`
- `config/config.php` (use config-sample.php instead)
- `uploads/*` (keep directory structure)
- All test/check/diagnose PHP files

## Step 2: Upload Files

### Via FTP/SFTP:
1. Connect to your subdomain's root directory
2. Upload all files from the deployment package
3. Ensure `.htaccess` files are uploaded (they may be hidden)

### Via cPanel File Manager:
1. Navigate to your subdomain's directory
2. Upload the ZIP file
3. Extract it in the subdomain root
4. Delete the ZIP file after extraction

**Important:** The subdomain root should be the document root (e.g., `/public_html/events/` or `/subdomains/events/public_html/`)

## Step 3: Set File Permissions

### Via FTP Client:
- **Directories**: 755
- **Files**: 644
- **config/config.php**: 600 (after creation)

### Via cPanel File Manager:
1. Select all files and folders
2. Change permissions:
   - Directories: 755
   - Files: 644

### Via SSH (if available):
```bash
# Navigate to subdomain directory
cd /path/to/subdomain/root

# Set directory permissions
find . -type d -exec chmod 755 {} \;

# Set file permissions
find . -type f -exec chmod 644 {} \;
```

## Step 4: Install Dependencies

### Via SSH:
```bash
cd /path/to/subdomain/root
composer install --no-dev --optimize-autoloader
```

### Via cPanel Terminal:
Same commands as SSH above.

### Manual Installation:
If Composer is not available:
1. Upload the `vendor/` directory from your local development environment
2. Ensure all dependencies are included

## Step 5: Configure Application

### Create Configuration File:
1. Copy `config/config-sample.php` to `config/config.php`
2. Edit `config/config.php` with your settings:

```php
return [
    // Database Configuration
    'database' => [
        'host' => 'localhost', // or your database host
        'database' => 'your_database_name',
        'username' => 'your_db_username',
        'password' => 'your_db_password',
        'charset' => 'utf8mb4',
    ],

    // Application Configuration
    'app' => [
        'name' => 'Headcount Events',
        'url' => 'https://events.yourdomain.com', // IMPORTANT: Update this!
        'timezone' => 'America/New_York', // Your timezone
        'debug' => false, // Set to false in production
        'environment' => 'production',
    ],

    // Security Configuration
    'security' => [
        'encryption_key' => 'YOUR_RANDOM_32_CHAR_KEY', // Generate a secure key
        'session_lifetime' => 86400,
        'password_min_length' => 8,
        'max_login_attempts' => 5,
        'lockout_duration' => 1800,
    ],

    // Stripe Configuration (if using payments)
    'stripe' => [
        'publishable_key' => 'pk_live_...',
        'secret_key' => 'sk_live_...',
        'webhook_secret' => 'whsec_...',
        'test_mode' => false,
    ],

    // SMTP2GO Configuration
    'smtp2go' => [
        'api_key' => 'your_smtp2go_api_key',
        'from_email' => 'noreply@yourdomain.com',
        'from_name' => 'Your Organization',
        'reply_to' => 'support@yourdomain.com',
    ],

    // ... other settings
];
```

### Generate Encryption Key:
You can generate a secure encryption key using:
```php
<?php
echo bin2hex(random_bytes(16)); // 32 character hex string
?>
```

Or use an online generator (32+ characters, random).

### Set Config File Permissions:
```bash
chmod 600 config/config.php
```

## Step 6: Update .htaccess for Subdomain

The `.htaccess` file should already be configured, but verify:

1. **Root .htaccess**: Should NOT have a RewriteBase (or set to `/`)
2. **HTTPS Redirect**: Uncomment HTTPS redirect if you have SSL:

```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

3. **HSTS Header**: Uncomment if using HTTPS:

```apache
Header set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

## Step 7: Create Database

### Via cPanel:
1. Go to MySQL Databases
2. Create a new database (e.g., `yourdomain_events`)
3. Create a database user
4. Add user to database with ALL PRIVILEGES
5. Note the database name, username, and password

### Via phpMyAdmin:
1. Create new database
2. Create new user
3. Grant privileges

## Step 8: Import Database Schema

### Via phpMyAdmin:
1. Select your database
2. Go to Import tab
3. Choose `database/schema.sql` file
4. Click Go

### Via SSH:
```bash
mysql -u username -p database_name < database/schema.sql
```

### Run Migrations:
The application will run migrations automatically on first access, or you can run:
```bash
php cli_migrate.php
```

## Step 9: Run Installation Wizard

1. Navigate to: `https://events.yourdomain.com/install/`
2. Follow the installation wizard:
   - System requirements check
   - Database connection test
   - Admin account creation
   - Initial configuration

## Step 10: Post-Installation

### Remove/Protect Install Directory:
```bash
# Option 1: Delete (recommended)
rm -rf install/

# Option 2: Protect with .htaccess
echo "Deny from all" > install/.htaccess
```

### Verify Installation:
- [ ] Can access admin login page
- [ ] Can log in with admin credentials
- [ ] Dashboard loads correctly
- [ ] Can create a test event
- [ ] Can import members
- [ ] Email sending works (test email)
- [ ] File uploads work

## Step 11: Set Up Cron Jobs

### Required Cron Jobs:

1. **Email Reminders** (Every hour):
```
0 * * * * /usr/bin/php /path/to/subdomain/root/cron/reminders.php
```

2. **Send Queued Emails** (Every 5 minutes):
```
*/5 * * * * /usr/bin/php /path/to/subdomain/root/cron/send-emails.php
```

3. **Database Backup** (Daily at 2 AM):
```
0 2 * * * /path/to/subdomain/root/scripts/backup-db.sh
```

4. **Log Cleanup** (Weekly on Sunday):
```
0 3 * * 0 /usr/bin/php /path/to/subdomain/root/cron/cleanup-logs.php
```

### Setting Up in cPanel:
1. Go to Cron Jobs
2. Add each cron job with the appropriate schedule
3. Use full paths to PHP and scripts

## Step 12: Security Hardening

### File Permissions:
- Config files: `600`
- Directories: `755`
- Files: `644`

### Protect Sensitive Directories:
Create `.htaccess` in these directories:
- `config/` (already protected)
- `logs/` (add: `Deny from all`)
- `uploads/` (already protected)

### SSL/HTTPS:
- Ensure SSL certificate is installed
- Force HTTPS redirect in `.htaccess`
- Update `app.url` in config to use `https://`

## Troubleshooting

### Database Connection Failed:
- Verify credentials in `config/config.php`
- Check database host (may not be `localhost` on shared hosting)
- Verify database user has permissions
- Check if database server is running

### 500 Internal Server Error:
- Check error logs: `logs/app.log`
- Check PHP error log in cPanel
- Verify file permissions
- Check `.htaccess` syntax
- Verify PHP version (8.0+)

### Files Not Uploading:
- Check `uploads/` directory permissions (755)
- Check PHP `upload_max_filesize` and `post_max_size`
- Verify directory is writable

### URLs Not Working:
- Verify `.htaccess` is uploaded
- Check `mod_rewrite` is enabled
- Verify `RewriteBase` is correct (should be `/` for subdomain root)
- Check Apache configuration

### Emails Not Sending:
- Verify SMTP2GO API key
- Check email configuration in admin settings
- Review email logs
- Test email sending from admin panel

## Support

For issues or questions:
- Check error logs: `logs/app.log`
- Review this deployment guide
- Check main README.md
- Contact support if needed

## Quick Reference

**Subdomain URL**: `https://events.yourdomain.com`  
**Admin Login**: `https://events.yourdomain.com/admin/`  
**Install Wizard**: `https://events.yourdomain.com/install/`  
**Config File**: `config/config.php`  
**Database**: Created via cPanel  
**Cron Jobs**: Set up in cPanel Cron Jobs

---

**Last Updated**: 2024
