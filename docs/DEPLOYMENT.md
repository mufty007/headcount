# Headcount Events Platform - Deployment Guide

## Prerequisites

- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite enabled
- Composer installed
- SSL certificate (recommended)

## Installation Steps

### 1. Upload Files

Upload all files to your web server, typically to:
- `/public_html/` or
- `/htdocs/` or
- `/www/`

### 2. Set Permissions

```bash
chmod 755 uploads logs cache
chmod 600 config/config.php
```

### 3. Install Dependencies

```bash
cd /path/to/headcount
composer install --no-dev --optimize-autoloader
```

### 4. Configure Application

1. Copy `config/config-sample.php` to `config/config.php`
2. Edit `config/config.php` with your settings:

```php
return [
    'database' => [
        'host' => 'localhost',
        'name' => 'headcount_prod',
        'username' => 'db_user',
        'password' => 'db_password',
        'charset' => 'utf8mb4'
    ],
    'app' => [
        'name' => 'Your Organization',
        'url' => 'https://yourdomain.com',
        'env' => 'production'
    ],
    'stripe' => [
        'publishable_key' => 'pk_live_...',
        'secret_key' => 'sk_live_...'
    ],
    'smtp2go' => [
        'api_key' => 'your_api_key'
    ]
];
```

### 5. Create Database

```sql
CREATE DATABASE headcount_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 6. Import Schema

```bash
mysql -u db_user -p headcount_prod < database/schema.sql
```

### 7. Run Migrations

```bash
php cli_migrate.php
```

Or manually:
```bash
mysql -u db_user -p headcount_prod < database/migrations/016_create_remember_tokens_table.sql
mysql -u db_user -p headcount_prod < database/migrations/017_add_performance_indexes.sql
```

### 8. Create Admin User

```bash
php setup_admin.php
```

Or manually via SQL:
```sql
INSERT INTO organizations (name, slug) VALUES ('Your Org', 'your-org');
INSERT INTO users (organization_id, email, password_hash, first_name, last_name, role, status)
VALUES (1, 'admin@example.com', '$2y$12$...', 'Admin', 'User', 'admin', 'active');
```

### 9. Configure Web Server

#### Apache (.htaccess)

Ensure `.htaccess` in `public/` directory:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

#### Nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 10. Set Up SSL

- Install SSL certificate
- Force HTTPS redirects
- Update `config.php` with HTTPS URL

### 11. Configure Email

1. Sign up for SMTP2GO account
2. Get API key
3. Add to `config/config.php`:

```php
'smtp2go' => [
    'api_key' => 'api-xxxxxxxxxxxxx',
    'from_email' => 'noreply@yourdomain.com',
    'from_name' => 'Your Organization'
]
```

### 12. Configure Stripe

1. Create Stripe account
2. Get API keys (production)
3. Add to `config/config.php`:

```php
'stripe' => [
    'publishable_key' => 'pk_live_...',
    'secret_key' => 'sk_live_...',
    'test_mode' => false
]
```

## Post-Deployment

### 1. Test Functionality

- [ ] Admin login works
- [ ] Member registration works
- [ ] Event creation works
- [ ] Check-in works
- [ ] RSVP works
- [ ] Payments work (test mode first)
- [ ] Emails are sent

### 2. Set Up Backups

Create cron job for daily backups:

```bash
0 2 * * * mysqldump -u db_user -p'password' headcount_prod | gzip > /backups/headcount_$(date +\%Y\%m\%d).sql.gz
```

### 3. Set Up Monitoring

- Monitor error logs
- Set up uptime monitoring
- Configure email alerts

### 4. Performance Optimization

- Enable OPcache in PHP
- Set up CDN for static assets (optional)
- Configure Redis for caching (optional)

## Troubleshooting

### Database Connection Errors

- Verify database credentials in `config/config.php`
- Check database server is running
- Verify user has proper permissions

### Permission Errors

- Check file permissions (755 for directories, 644 for files)
- Verify web server user can write to `uploads/` and `logs/`
- Check `cache/` directory is writable

### Email Not Sending

- Verify SMTP2GO API key is correct
- Check email logs in `logs/`
- Test SMTP connection

### Payment Issues

- Verify Stripe keys are production keys
- Check webhook URL is configured in Stripe
- Review payment logs

### Performance Issues

- Run performance index migration
- Enable caching
- Check database query performance
- Review server resources

## Maintenance

### Regular Tasks

- **Daily**: Check error logs
- **Weekly**: Review security logs
- **Monthly**: Update dependencies
- **Quarterly**: Security audit

### Updates

1. Backup database
2. Backup files
3. Update code
4. Run migrations
5. Test functionality
6. Clear cache

## Security Checklist

- [ ] SSL certificate installed
- [ ] HTTPS enforced
- [ ] Strong admin passwords
- [ ] Database credentials secure
- [ ] File permissions correct
- [ ] Error display disabled in production
- [ ] CSRF protection enabled
- [ ] Input validation on all endpoints
- [ ] Regular security updates
