# Headcount Events Platform - Developer Documentation

## Architecture Overview

The Headcount Events Platform follows an MVC (Model-View-Controller) architecture with a Service layer for business logic.

```
Headcount/
├── public/              # Web-accessible files
│   ├── admin/          # Admin interface (PHP views)
│   ├── api/            # API endpoints
│   └── portal/         # Member portal (PHP views)
├── src/                # Application code
│   ├── Controllers/    # Request handlers
│   ├── Models/         # Database models
│   ├── Services/       # Business logic
│   ├── Middleware/     # Request middleware
│   ├── Core/           # Core utilities
│   └── Helpers/        # Helper classes
├── database/           # Database schema and migrations
├── config/             # Configuration files
└── tests/              # Test suite
```

## Database Schema

### Core Tables

- **organizations**: Organization settings
- **users**: Members and admin users
- **events**: Event information
- **rsvps**: RSVP records
- **attendance**: Check-in records
- **payments**: Payment records
- **family_members**: Family member records
- **remember_tokens**: Remember me tokens

### Key Relationships

```
organizations (1) ──< (many) users
users (1) ──< (many) rsvps
users (1) ──< (many) attendance
events (1) ──< (many) rsvps
events (1) ──< (many) attendance
users (1) ──< (many) family_members
users (1) ──< (many) remember_tokens
```

## Service Layer Pattern

Services contain business logic and interact with Models:

```php
// Example: MemberService
class MemberService
{
    private $userModel;
    
    public function createMember($data)
    {
        // Validation
        $errors = Validator::validateMember($data);
        if (!empty($errors)) {
            throw new \Exception('Validation failed');
        }
        
        // Business logic
        // Create member via model
        return $this->userModel->create($data);
    }
}
```

## Authentication Flow

### Admin Authentication

1. User submits login form
2. `AuthController::login()` validates credentials
3. Session created with user data
4. If "remember me" checked, remember token created
5. User redirected to dashboard

### Remember Me Flow

1. Token generated and hashed
2. Stored in `remember_tokens` table
3. Cookie set with plain token
4. On subsequent visit, `AuthMiddleware` checks cookie
5. Token validated against database
6. User auto-logged in if valid

## API Endpoint Structure

```php
// Example: public/api/members.php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\MemberService;

header('Content-Type: application/json');
AuthMiddleware::requireAdmin();

$memberService = new MemberService();
// Handle request...
```

## Database Access

### Using Database Helper

```php
use Headcount\Helpers\Database;

$db = Database::getInstance();

// Query
$users = $db->query("SELECT * FROM users WHERE organization_id = :org_id", [
    'org_id' => 1
]);

// Single row
$user = $db->queryOne("SELECT * FROM users WHERE id = :id", ['id' => 1]);

// Insert
$id = $db->insert('users', [
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => 'john@example.com'
]);

// Update
$db->update('users', $id, ['first_name' => 'Jane']);
```

## Caching

### Using Cache Class

```php
use Headcount\Core\Cache;

// Get from cache
$value = Cache::get('key');

// Set cache (5 min default)
Cache::set('key', $value, 300);

// Remember pattern
$value = Cache::remember('key', function() {
    return expensiveOperation();
}, 300);
```

## Security Best Practices

### Input Validation

```php
use Headcount\Helpers\Validator;

// Validate GET parameter
$id = Validator::getParam('id', 'id', null);

// Validate data
$errors = Validator::validateMember($data);
```

### Output Escaping

```php
// In templates
<?= htmlspecialchars($user['name']) ?>
// Or use helper
<?= e($user['name']) ?>
```

### CSRF Protection

```php
// In form
<input type="hidden" name="csrf_token" value="<?= CsrfMiddleware::getToken() ?>">

// In handler
CsrfMiddleware::verify();
```

## Testing

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific suite
vendor/bin/phpunit tests/Unit

# Run with coverage
vendor/bin/phpunit --coverage-html tests/coverage
```

### Writing Tests

```php
class MyServiceTest extends TestCase
{
    public function testMyMethod()
    {
        $service = new MyService();
        $result = $service->myMethod();
        
        $this->assertNotNull($result);
    }
}
```

## Code Style

- PSR-4 autoloading
- PSR-12 coding standards
- Use type hints where possible
- Document all public methods
- Use prepared statements for all queries

## Deployment

### Production Checklist

1. Update `config/config.php` with production values
2. Set `APP_ENV` to `production`
3. Disable error display
4. Enable error logging
5. Run database migrations
6. Set up SSL certificate
7. Configure SMTP credentials
8. Set up Stripe production keys
9. Test all functionality
10. Set up backups
