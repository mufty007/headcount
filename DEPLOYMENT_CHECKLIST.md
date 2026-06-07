# Quick Deployment Checklist

Use this checklist when deploying to your subdomain.

## Pre-Deployment

- [ ] Subdomain created (e.g., `events.yourdomain.com`)
- [ ] Database created
- [ ] Database user created with permissions
- [ ] SSL certificate installed
- [ ] PHP 8.0+ confirmed
- [ ] Required PHP extensions installed

## Package Creation

- [ ] Run `.\package-deploy.ps1` (Windows) or create package manually
- [ ] Verify ZIP file created in `deploy/` folder
- [ ] Check package size is reasonable

## File Upload

- [ ] All files uploaded to subdomain root
- [ ] `.htaccess` files uploaded (may be hidden)
- [ ] File permissions set:
  - [ ] Directories: 755
  - [ ] Files: 644

## Configuration

- [ ] `config/config.php` created from `config-sample.php`
- [ ] Database credentials updated
- [ ] `app.url` set to subdomain (e.g., `https://events.yourdomain.com`)
- [ ] `app.environment` set to `production`
- [ ] `app.debug` set to `false`
- [ ] Encryption key generated and set
- [ ] Stripe keys configured (if using payments)
- [ ] SMTP2GO API key configured
- [ ] Config file permissions set to 600

## Database

- [ ] Database schema imported (`database/schema.sql`)
- [ ] Migrations run (automatic or manual)
- [ ] Database connection tested

## Installation

- [ ] Installation wizard accessed: `https://events.yourdomain.com/install/`
- [ ] System requirements check passed
- [ ] Database connection successful
- [ ] Admin account created
- [ ] Initial setup completed

## Post-Installation

- [ ] Admin login works
- [ ] Dashboard accessible
- [ ] Test event created
- [ ] Member import tested
- [ ] Email sending tested
- [ ] File uploads work
- [ ] Install directory removed or protected

## Cron Jobs

- [ ] Email reminders cron job set (hourly)
- [ ] Send emails cron job set (every 5 minutes)
- [ ] Database backup cron job set (daily)
- [ ] Log cleanup cron job set (weekly)

## Security

- [ ] HTTPS redirect enabled in `.htaccess`
- [ ] HSTS header enabled (if using HTTPS)
- [ ] Sensitive directories protected
- [ ] File permissions verified
- [ ] Config file permissions set to 600

## Testing

- [ ] Admin login/logout works
- [ ] Event creation works
- [ ] Member import works
- [ ] Check-in process works
- [ ] Email sending works
- [ ] Payment processing works (if applicable)
- [ ] Reports generate correctly
- [ ] Mobile responsiveness verified

## Final Steps

- [ ] Remove install directory
- [ ] Update DNS if needed
- [ ] Test from different devices
- [ ] Set up monitoring/backups
- [ ] Document any custom configurations

---

**Deployment Date**: _______________  
**Subdomain**: _______________  
**Deployed By**: _______________
