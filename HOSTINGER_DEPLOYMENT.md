# Hostinger Deployment Guide

Complete guide for deploying Headcount to a Hostinger subdomain (e.g., `events.yourdomain.com`).

## Hostinger-Specific Information

### File Structure
- **Main domain root**: `/public_html/`
- **Subdomain root**: `/public_html/events/` (for `events.yourdomain.com`)
- **Database**: Created via hPanel → MySQL Databases
- **PHP Version**: Selectable via hPanel → PHP Configuration

### Access Points
- **File Manager**: hPanel → Files → File Manager
- **Database**: hPanel → Databases → MySQL Databases
- **PHP Settings**: hPanel → Advanced → PHP Configuration
- **Cron Jobs**: hPanel → Advanced → Cron Jobs
- **SSL**: hPanel → SSL → Let's Encrypt (free)

## Pre-Deployment Checklist

- [ ] Subdomain created in hPanel (Domains → Subdomains)
- [ ] Database created via hPanel (Databases → MySQL Databases)
- [ ] Database user created with full permissions
- [ ] SSL certificate installed (SSL → Let's Encrypt)
- [ ] PHP 8.0+ selected (Advanced → PHP Configuration)
- [ ] File Manager access confirmed

## Step 1: Create Deployment Package

### On Your Local Machine (Windows):
```powershell
.\package-deploy.ps1
```

This creates `deploy/headcount-deploy-YYYYMMDD-HHMMSS.zip`

## Step 2: Upload Files via Hostinger File Manager

### Option A: Upload ZIP and Extract (Recommended)

1. **Navigate to Subdomain Directory**:
   - In File Manager, go to `public_html/events/` (or your subdomain folder)
   - If the folder doesn't exist, create it first

2. **Upload ZIP File**:
   - Click "Upload" button (top right)
   - Select your `headcount-deploy-*.zip` file
   - Wait for upload to complete

3. **Extract ZIP**:
   - Right-click on the ZIP file
   - Select "Extract"
   - Choose extraction location (current directory)
   - Click "Extract Files"
   - **Important**: Files should extract directly into `public_html/events/`, not into a subfolder

4. **Verify Structure**:
   - You should see files like `index.php`, `.htaccess`, `config/`, `public/`, etc. directly in `public_html/events/`
   - If files are in a subfolder (e.g., `public_html/events/headcount-deploy-*/`), move them up one level

5. **Delete ZIP File**:
   - Right-click on ZIP → Delete

### Option B: Upload Individual Files (Alternative)

If ZIP extraction doesn't work:
1. Extract ZIP on your local machine
2. Select all files and folders
3. Upload via File Manager (may take longer)

## Step 3: Set File Permissions

### Via Hostinger File Manager:

1. **Select All Files and Folders**:
   - In `public_html/events/`, select all items

2. **Set Permissions**:
   - Right-click → "Change Permissions"
   - **Directories**: Check `755` (or enter: `rwxr-xr-x`)
   - **Files**: Check `644` (or enter: `rw-r--r--`)
   - Click "Change"

3. **Config File** (after creation):
   - Navigate to `config/config.php`
   - Right-click → "Change Permissions"
   - Set to `600` (or `rw-------`)
   - Click "Change"

### Required Directory Permissions:
- `logs/` → 755
- `uploads/` → 755
- `uploadsevent-banners/` → 755
- `config/` → 755
- All PHP files → 644

## Step 4: Install Dependencies

### Option A: Via Hostinger Terminal (if available)

1. **Access Terminal**:
   - hPanel → Advanced → Terminal (if available on your plan)

2. **Navigate and Install**:
   ```bash
   cd public_html/events
   composer install --no-dev --optimize-autoloader
   ```

### Option B: Upload Vendor Directory

If Composer is not available:
1. On your local machine, run: `composer install --no-dev --optimize-autoloader`
2. Upload the entire `vendor/` folder to `public_html/events/vendor/`

## Step 5: Configure PHP Version

1. **Access PHP Configuration**:
   - hPanel → Advanced → PHP Configuration

2. **Select PHP Version**:
   - Choose **PHP 8.0, 8.1, or 8.2** (8.2 recommended)
   - Click "Update"

3. **Verify Extensions**:
   - Required: `mysqli`, `curl`, `gd`, `mbstring`, `openssl`, `json`, `zip`
   - Most are enabled by default on Hostinger

## Step 6: Create Database

1. **Access MySQL Databases**:
   - hPanel → Databases → MySQL Databases

2. **Create Database**:
   - Scroll to "Create New Database"
   - Enter database name (e.g., `u525556582_events`)
   - Click "Create"

3. **Create Database User**:
   - Scroll to "Add New User"
   - Enter username (e.g., `u525556582_events`)
   - Enter strong password (save it!)
   - Click "Create"

4. **Assign User to Database**:
   - Scroll to "Add User to Database"
   - Select your user and database
   - Check "ALL PRIVILEGES"
   - Click "Add"

5. **Note Database Details**:
   - **Host**: Usually `localhost` (check in database details)
   - **Database Name**: `u525556582_events` (your prefix + name)
   - **Username**: `u525556582_events` (your prefix + name)
   - **Password**: (the one you created)

## Step 7: Configure Application

### Create Configuration File:

1. **In File Manager**:
   - Navigate to `public_html/events/config/`
   - Find `config-sample.php`
   - Right-click → "Copy"
   - Right-click in same folder → "Paste"
   - Rename the copy to `config.php`

2. **Edit config.php**:
   - Right-click `config.php` → "Edit" (or "Code Edit")
   - Update the following:

```php
return [
    // Database Configuration
    'database' => [
        'host' => 'localhost', // Usually localhost on Hostinger
        'database' => 'u525556582_events', // Your database name
        'username' => 'u525556582_events', // Your database username
        'password' => 'your_password_here', // Your database password
        'charset' => 'utf8mb4',
    ],

    // Application Configuration
    'app' => [
        'name' => 'Headcount Events',
        'url' => 'https://events.yourdomain.com', // YOUR SUBDOMAIN URL
        'timezone' => 'America/New_York', // Your timezone
        'debug' => false, // Set to false in production
        'environment' => 'production',
    ],

    // Security Configuration
    'security' => [
        'encryption_key' => 'GENERATE_A_RANDOM_32_CHAR_KEY_HERE',
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

    // ... keep other settings as needed
];
```

3. **Generate Encryption Key**:
   - Use an online generator or run this PHP code:
   ```php
   <?php echo bin2hex(random_bytes(16)); ?>
   ```
   - Replace `GENERATE_A_RANDOM_32_CHAR_KEY_HERE` with the generated key

4. **Save and Set Permissions**:
   - Save the file
   - Set permissions to `600` (right-click → Change Permissions)

## Step 8: Import Database Schema

### Via phpMyAdmin:

1. **Access phpMyAdmin**:
   - hPanel → Databases → phpMyAdmin
   - Or direct link from MySQL Databases section

2. **Select Your Database**:
   - Click on your database name in left sidebar

3. **Import Schema**:
   - Click "Import" tab
   - Click "Choose File"
   - Select `database/schema.sql` from your uploaded files
   - Scroll down, click "Go"
   - Wait for import to complete

4. **Verify Tables**:
   - You should see tables like `users`, `events`, `members`, etc.

## Step 9: Run Installation Wizard

1. **Access Installation**:
   - Navigate to: `https://events.yourdomain.com/install/`
   - Replace `events.yourdomain.com` with your actual subdomain

2. **Follow Wizard**:
   - System requirements check
   - Database connection test
   - Create admin account
   - Complete initial setup

## Step 10: Set Up SSL (HTTPS)

1. **Access SSL Settings**:
   - hPanel → SSL → Let's Encrypt

2. **Install Certificate**:
   - Select your subdomain
   - Click "Install" or "Get SSL"
   - Wait for installation (usually instant)

3. **Force HTTPS**:
   - Edit `public_html/events/.htaccess`
   - Uncomment the HTTPS redirect lines:
   ```apache
   RewriteCond %{HTTPS} off
   RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```

4. **Update Config**:
   - Update `app.url` in `config/config.php` to use `https://`

## Step 11: Set Up Cron Jobs

### Via Hostinger Cron Jobs:

1. **Access Cron Jobs**:
   - hPanel → Advanced → Cron Jobs

2. **Add Required Jobs**:

   **Email Reminders** (Every hour):
   ```
   0 * * * * /usr/bin/php /home/u525556582/domains/yourdomain.com/public_html/events/cron/reminders.php
   ```

   **Send Queued Emails** (Every 5 minutes):
   ```
   */5 * * * * /usr/bin/php /home/u525556582/domains/yourdomain.com/public_html/events/cron/send-emails.php
   ```

   **Database Backup** (Daily at 2 AM):
   ```
   0 2 * * * /home/u525556582/domains/yourdomain.com/public_html/events/scripts/backup-db.sh
   ```

   **Log Cleanup** (Weekly on Sunday):
   ```
   0 3 * * 0 /usr/bin/php /home/u525556582/domains/yourdomain.com/public_html/events/cron/cleanup-logs.php
   ```

   **Note**: Replace `/home/u525556582/domains/yourdomain.com/` with your actual path. You can find it in:
   - File Manager → check the path at the top
   - Or use: `pwd` in Terminal

3. **For Each Cron Job**:
   - Click "Add New Cron Job"
   - Enter schedule (Common Settings dropdown)
   - Enter command
   - Click "Add"

## Step 12: Post-Installation

### Remove Install Directory:

1. **Via File Manager**:
   - Navigate to `public_html/events/install/`
   - Select all files
   - Right-click → "Delete"
   - Or rename the folder to `install_disabled`

### Verify Installation:

- [ ] Can access: `https://events.yourdomain.com/admin/`
- [ ] Can log in with admin credentials
- [ ] Dashboard loads correctly
- [ ] Can create a test event
- [ ] Can import members
- [ ] File uploads work
- [ ] Email sending works (test from admin panel)

## Hostinger-Specific Troubleshooting

### Database Connection Failed:

**Check**:
- Database host is `localhost` (usually correct on Hostinger)
- Database name includes your prefix (e.g., `u525556582_events`)
- Username includes your prefix
- Password is correct (no extra spaces)
- User has ALL PRIVILEGES on the database

**Solution**:
- Verify in hPanel → MySQL Databases
- Re-check credentials in `config/config.php`

### 500 Internal Server Error:

**Check**:
- File permissions (directories 755, files 644)
- `.htaccess` file exists and is readable
- PHP version is 8.0+
- Error logs: `public_html/events/logs/app.log`

**Solution**:
- Check PHP error log in hPanel → Advanced → Error Log
- Temporarily enable debug in `config/config.php` to see errors

### Files Not Uploading:

**Check**:
- `uploads/` directory exists and is writable (755)
- PHP `upload_max_filesize` and `post_max_size` settings

**Solution**:
- Create `.htaccess` in `uploads/` with: `php_value upload_max_filesize 10M`
- Or contact Hostinger support to increase PHP limits

### URLs Not Working (404 errors):

**Check**:
- `.htaccess` file is uploaded (may be hidden)
- `mod_rewrite` is enabled (usually is on Hostinger)
- Files are in correct location (`public_html/events/`)

**Solution**:
- In File Manager, enable "Show Hidden Files"
- Verify `.htaccess` exists
- Check that `index.php` is in the root

### Composer Not Available:

**Solution**:
- Install dependencies locally
- Upload `vendor/` folder via File Manager
- Ensure all files uploaded correctly

## Quick Reference

**Subdomain URL**: `https://events.yourdomain.com`  
**Admin Login**: `https://events.yourdomain.com/admin/`  
**Install Wizard**: `https://events.yourdomain.com/install/`  
**File Location**: `public_html/events/`  
**Config File**: `public_html/events/config/config.php`  
**Database**: Created via hPanel → MySQL Databases  
**PHP Version**: Set via hPanel → PHP Configuration  
**Cron Jobs**: Set via hPanel → Advanced → Cron Jobs

## Hostinger Support

If you encounter issues:
1. Check Hostinger Knowledge Base
2. Contact Hostinger Support (24/7 live chat)
3. Check error logs in hPanel
4. Verify all settings match this guide

---

**Last Updated**: 2024  
**Hostinger hPanel Version**: Current
