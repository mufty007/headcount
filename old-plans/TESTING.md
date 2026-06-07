# Testing Guide

## Quick Start

### 1. Install Dependencies

```bash
composer install
```

This will install PHPUnit and other testing dependencies.

### 2. Set Up Test Database

Create a test database configuration file:

```bash
cp config/config-test-sample.php config/config-test.php
```

Edit `config/config-test.php` and update the database settings to point to your test database.

### 3. Create Test Database

```sql
CREATE DATABASE headcount_events_test;
```

Run the schema:

```bash
mysql -u root headcount_events_test < database/schema.sql
```

### 4. Run Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suite
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Integration
vendor/bin/phpunit tests/Security
vendor/bin/phpunit tests/Performance

# Run with coverage report
vendor/bin/phpunit --coverage-html tests/coverage
```

## Test Structure

### Unit Tests (`tests/Unit/`)

Test individual functions and classes in isolation:
- `SecurityTest.php` - Password hashing, CSRF, encryption
- `ValidatorTest.php` - Input validation
- `FileUploadTest.php` - File upload security

### Integration Tests (`tests/Integration/`)

Test complete workflows:
- `AuthControllerTest.php` - Authentication flow
- `ApiTest.php` - API endpoints

### Security Tests (`tests/Security/`)

Test security vulnerabilities:
- `SecurityTest.php` - SQL injection, XSS, CSRF, etc.

### Performance Tests (`tests/Performance/`)

Test system performance:
- `PerformanceTest.php` - Query performance, search speed

## Test Coverage Goals

- **Overall**: 80% code coverage
- **Critical Paths**: 100% coverage
- **Security Functions**: 100% coverage
- **API Endpoints**: 90% coverage

## Writing New Tests

### Example Unit Test

```php
<?php
namespace Headcount\Tests\Unit;

use Headcount\Tests\TestCase;

class MyClassTest extends TestCase
{
    public function testMyMethod()
    {
        // Arrange
        $input = 'test';
        
        // Act
        $result = MyClass::myMethod($input);
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Example Integration Test

```php
<?php
namespace Headcount\Tests\Integration;

use Headcount\Tests\TestCase;
use Headcount\Controllers\MyController;

class MyControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Set up test data
    }

    public function testControllerMethod()
    {
        $controller = new MyController();
        $result = $controller->someMethod();
        
        $this->assertTrue($result['success']);
    }
}
```

## Test Data Management

The `TestCase` base class provides helper methods:

- `createTestUser($data)` - Create a test user
- `createTestEvent($data)` - Create a test event
- `cleanupTestData()` - Clean up test data

## Continuous Integration

Tests should be run:
- Before every commit
- In CI/CD pipeline
- Before every release

## Troubleshooting

### Tests Failing

1. Check test database configuration
2. Verify test database exists and schema is loaded
3. Check for test isolation issues
4. Review test logs

### Performance Tests Failing

1. Check database indexes are created
2. Verify query optimization
3. Check system resources
4. Review slow query logs

### Session Issues

If tests fail with session errors, ensure:
- Session is started before tests run
- Session is cleared between tests
- No session conflicts between tests

## Test Execution Checklist

Before running tests:
- [ ] Test database created
- [ ] Test configuration file exists
- [ ] Database schema loaded
- [ ] Dependencies installed (`composer install`)

After running tests:
- [ ] All tests pass
- [ ] Coverage meets goals
- [ ] No warnings or errors
- [ ] Test data cleaned up
