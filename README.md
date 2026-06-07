# Headcount Events Platform

Community Events & Attendance Management Platform - A self-hosted solution for managing events, tracking attendance, and communicating with members.

## Features

- **Event Management**: Create, edit, and manage events
- **Fast Check-In**: Quick member search and check-in interface
- **Member Database**: Centralized member management with CSV import
- **Email Communications**: Send announcements and reminders via SMTP2GO
- **Payment Processing**: Integrated Stripe payments for paid events
- **Attendance Tracking**: Complete attendance history and reporting
- **Admin Dashboard**: Easy-to-use admin interface

## Requirements

- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite
- Composer
- SSL certificate (recommended for production)

## Installation

1. **Clone or download the project**
   ```bash
   cd /path/to/your/webroot
   git clone <repository-url> Headcount
   ```

2. **Install dependencies**
   ```bash
   cd Headcount
   composer install
   ```

3. **Configure the application**
   - Copy `config/config-sample.php` to `config/config.php`
   - Update database credentials and other settings

4. **Create database**
   - Create a new MySQL database
   - Import the schema: `mysql -u username -p database_name < database/schema.sql`

5. **Set permissions**
   ```bash
   chmod 600 config/config.php
   chmod 755 uploads logs
   ```

6. **Access the installation wizard**
   - Navigate to `http://your-domain/install/` in your browser
   - Follow the setup instructions

## Configuration

Edit `config/config.php` with your settings:

- **Database**: Connection credentials
- **Stripe**: API keys for payment processing
- **SMTP2GO**: API key for email delivery
- **Security**: Encryption keys and session settings

## Directory Structure

```
Headcount/
├── public/              # Web-accessible files
│   ├── admin/          # Admin interface
│   ├── api/            # API endpoints
│   └── assets/         # CSS, JS, images
├── src/                # Application code
│   ├── Controllers/   # Request handlers
│   ├── Models/        # Data models
│   ├── Services/      # Business logic
│   ├── Middleware/    # Request middleware
│   └── Helpers/       # Utility classes
├── templates/          # Email templates
├── database/           # Database schema and migrations
├── config/             # Configuration files
├── logs/               # Application logs
└── uploads/            # User uploads
```

## Usage

### Admin Login

1. Navigate to `/admin/` or `/admin/?page=login`
2. Login with your admin credentials
3. Access the dashboard

### Creating Events

1. Go to Events page
2. Click "Create Event"
3. Fill in event details
4. Save as draft or publish

### Checking In Members

1. Go to Check-In page for an event
2. Search for member by name, email, or phone
3. Click to check in
4. View real-time attendance count

### Importing Members

1. Go to Members page
2. Click "Import CSV"
3. Upload your CSV file
4. Map columns to database fields
5. Review and confirm import

## API Endpoints

### Authentication
- `POST /api/auth/login` - Admin login
- `POST /api/auth/logout` - Logout

### Events
- `GET /api/events` - List events
- `GET /api/events/{id}` - Get event
- `POST /api/events` - Create event
- `PUT /api/events/{id}` - Update event
- `DELETE /api/events/{id}` - Delete event

### Members
- `GET /api/members` - List members
- `GET /api/members/search?q={query}` - Search members
- `POST /api/members` - Create member
- `PUT /api/members/{id}` - Update member

### Attendance
- `POST /api/attendance/search` - Search members for check-in
- `POST /api/attendance/checkin` - Check in member
- `GET /api/attendance/{event_id}` - Get event attendance

## Security

- Passwords are hashed using bcrypt
- CSRF protection on all forms
- SQL injection prevention via prepared statements
- XSS protection via input sanitization
- Secure session management
- HTTPS recommended for production

## Support

For issues, questions, or contributions, please refer to the project documentation or contact support.

## License

[Your License Here]

## Credits

Built with:
- PHP 8.0+
- MySQL
- Stripe API
- SMTP2GO API
- Tailwind CSS
