# Backend Setup - Development Mode

## Overview
The backend has been built following the Backend Developer Agent specifications. All core components are in place and ready for development.

## Database Configuration
- **Database Name**: `headcount_dev`
- **Host**: `localhost`
- **Username**: `root`
- **Password**: (empty - default XAMPP)

## Project Structure

```
Headcount/
├── api/
│   └── index.php              # API entry point and router
├── config/
│   ├── config.php              # Development configuration
│   └── config-sample.php       # Sample configuration
├── src/
│   ├── Core/                   # Core utilities
│   │   ├── Bootstrap.php       # Dependency injection container
│   │   ├── Database.php        # Database helper
│   │   ├── Security.php        # Security utilities
│   │   ├── Validator.php       # Input validation
│   │   ├── Logger.php          # Logging
│   │   └── ErrorHandler.php    # Error handling
│   ├── Models/                 # Database models
│   │   ├── Event.php
│   │   ├── User.php
│   │   ├── Attendance.php
│   │   ├── RSVP.php
│   │   └── Payment.php
│   ├── Services/               # Business logic
│   │   ├── EventService.php
│   │   ├── MemberService.php
│   │   ├── AttendanceService.php
│   │   ├── EmailService.php
│   │   └── PaymentService.php
│   ├── Controllers/            # API controllers
│   │   ├── AuthController.php
│   │   ├── EventController.php
│   │   ├── MemberController.php
│   │   ├── AttendanceController.php
│   │   └── PaymentController.php
│   └── Integrations/           # External services
│       ├── StripeService.php
│       └── SMTP2GOService.php
├── logs/                       # Application logs
├── uploads/                    # File uploads
└── vendor/                     # Composer dependencies
```

## API Endpoints

### Authentication
- `POST /api/auth/login` - Admin login
- `POST /api/auth/logout` - Logout
- `POST /api/auth/forgot-password` - Password reset

### Events
- `GET /api/events` - List events
- `POST /api/events` - Create event
- `PUT /api/events/{id}` - Update event
- `POST /api/events/duplicate/{id}` - Duplicate event

### Members
- `GET /api/members/search?q={query}` - Search members
- `POST /api/members` - Create member
- `PUT /api/members/{id}` - Update member
- `DELETE /api/members/{id}` - Delete member

### Attendance
- `GET /api/attendance/search/{eventId}?q={query}` - Search members for check-in
- `POST /api/attendance/checkin` - Check in member
- `POST /api/attendance/bulk-checkin` - Bulk check-in
- `POST /api/attendance/undo` - Undo check-in
- `GET /api/attendance/{eventId}` - Get event attendance

### Payments
- `POST /api/payments/checkout` - Create Stripe checkout
- `POST /api/payments/webhook` - Stripe webhook handler
- `POST /api/payments/refund/{id}` - Process refund

## Setup Instructions

1. **Database Setup**
   - Ensure MySQL is running in XAMPP
   - Create database: `headcount_dev`
   - Import schema: `database/schema.sql`

2. **Composer Dependencies**
   - Already installed: `composer install` has been run
   - Stripe PHP SDK is included

3. **Configuration**
   - Config file created: `config/config.php`
   - Database connection configured for dev mode
   - Update Stripe/SMTP2GO keys when ready

4. **Testing API**
   - Access API at: `http://localhost/Headcount/api/`
   - Use Postman or similar tool to test endpoints
   - All endpoints return JSON

## Next Steps

1. **Database Migration**
   - Run the schema.sql to create tables
   - Create initial admin user manually or via script

2. **Frontend Integration**
   - Frontend can now call these API endpoints
   - All endpoints follow RESTful conventions

3. **Additional Features**
   - Email templates (stored in database)
   - Report generation (to be implemented)
   - CSV import functionality (to be implemented)

## Notes

- All passwords are hashed using bcrypt (cost factor 12)
- Sessions are managed securely
- CSRF protection available via Security class
- Error handling is centralized
- Logging is configured for development

## Development Mode Features

- Debug mode enabled
- Detailed error messages
- Logging at DEBUG level
- No HTTPS requirement for cookies
