# Headcount Events Platform - Frequently Asked Questions

## General

### What is Headcount?

Headcount is a self-hosted event and attendance management platform designed for community organizations. It helps you manage events, track attendance, and communicate with members.

### Who is Headcount for?

Headcount is designed for:
- Religious organizations (churches, mosques, temples, synagogues)
- Community centers
- Non-profit organizations
- Social clubs and associations
- Educational groups

### Do I need technical knowledge to use it?

Basic technical knowledge is helpful for setup, but the interface is designed to be user-friendly. You should be able to:
- Use a web browser
- Upload files
- Fill out web forms

## Installation

### How long does setup take?

Initial setup typically takes 30-60 minutes, including:
- File upload
- Database creation
- Configuration
- Creating admin account

### What hosting do I need?

You need:
- PHP 8.0+ hosting
- MySQL 5.7+ database
- Apache with mod_rewrite
- SSL certificate (recommended)

Most shared hosting providers support this.

### Can I use it on my existing website?

Yes! Headcount can be installed in a subdirectory or subdomain of your existing website.

## Features

### How does check-in work?

There are three ways to check in members:
1. **Search**: Type member name/email/phone and click to check in
2. **QR Code**: Scan member's QR code with camera
3. **Family Members**: Check in family members when scanning parent's QR code

### What is a QR code?

QR codes are scannable codes that members can show at events for quick check-in. Each member has a unique QR code that expires after 24 hours for security.

### How do members get QR codes?

Members can view their QR code in the member portal:
1. Log in to member portal
2. Go to "QR Code" page
3. QR code is displayed
4. Screenshot or download to save

### Can family members RSVP?

Yes! When RSVPing for an event, members can select family members to RSVP for. Family members with linked accounts get their own RSVP record.

### How does payment work?

Headcount integrates with Stripe for secure payment processing:
1. Member clicks "Secure Ticket" on paid event
2. Redirected to Stripe checkout
3. Completes payment
4. Automatically RSVPed and receipt sent

### What payment methods are accepted?

Stripe accepts:
- Credit cards (Visa, Mastercard, American Express, etc.)
- Debit cards
- Apple Pay
- Google Pay
- Other methods supported by Stripe

## Member Portal

### How do members register?

Members can self-register:
1. Go to member portal
2. Click "Register"
3. Fill in information
4. Create account
5. Check email for confirmation

### What is a magic link?

A magic link is a passwordless login option:
1. Enter your email
2. Click "Send Magic Link"
3. Check email for link
4. Click link to log in automatically

### Can members stay logged in?

Yes! Check "Remember me" when logging in to stay logged in for 30 days.

### How do members update their profile?

1. Log in to member portal
2. Go to "Profile" page
3. Click "Edit Profile"
4. Update information
5. Save changes

## Admin Features

### How do I import members?

1. Go to Members page
2. Click "Import CSV"
3. Upload CSV file with member data
4. Map columns to database fields
5. Review and confirm import

### What CSV format is needed?

CSV should have columns:
- First Name
- Last Name
- Email
- Phone (optional)
- Gender (optional)

### How do I send emails to members?

1. Go to Email Templates page
2. Select template type
3. Edit template content
4. Use "Send to Members" to send
5. Or use in event announcements

### Can I customize email templates?

Yes! Go to Email Templates page to edit:
- Welcome emails
- RSVP confirmations
- Event reminders
- Magic link emails

## Security

### Is my data secure?

Yes! Headcount includes:
- Password hashing (bcrypt)
- CSRF protection
- SQL injection prevention
- XSS protection
- Secure session management
- HTTPS support

### What about payment security?

All payments are processed through Stripe, which is PCI DSS compliant. Headcount never stores credit card information.

### Can I backup my data?

Yes! You can:
- Export member data as CSV
- Export attendance reports
- Backup database manually
- Set up automated backups

## Troubleshooting

### I forgot my admin password

1. Go to login page
2. Click "Forgot Password"
3. Enter your email
4. Check email for reset link
5. Create new password

Or contact your hosting provider to reset via database.

### Members can't log in

- Check member account is active
- Verify email is correct
- Try password reset
- Check if account is locked

### QR codes not scanning

- Ensure camera permissions granted
- Check QR code is not expired (24 hours)
- Verify QR code is clear and not damaged
- Try generating new QR code

### Emails not sending

- Verify SMTP2GO API key is correct
- Check email logs in admin panel
- Verify "from" email is configured
- Test SMTP connection

### Payments not working

- Verify Stripe keys are correct
- Check if using test vs. production keys
- Verify webhook URL in Stripe dashboard
- Check payment logs

### Search is slow

- Run performance index migration
- Clear cache
- Check database server performance
- Contact hosting provider

## Support

### Where can I get help?

- Check this FAQ
- Review documentation
- Check error logs
- Contact your hosting provider
- Review GitHub issues (if open source)

### How do I report bugs?

Document the issue:
- What you were doing
- What happened
- Error messages
- Browser and version
- Screenshots if helpful

Contact support with this information.
