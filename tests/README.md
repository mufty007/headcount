# Test Suite Documentation

## Overview

This test suite provides comprehensive testing for the Headcount Events Platform, covering unit tests, integration tests, security tests, and performance tests.

## Test Structure

```
tests/
├── bootstrap.php          # Test bootstrap file
├── TestCase.php           # Base test case class
├── Unit/                  # Unit tests
│   ├── SecurityTest.php
│   ├── ValidatorTest.php
│   └── FileUploadTest.php
├── Integration/           # Integration tests
│   ├── AuthControllerTest.php
│   └── ApiTest.php
├── Security/              # Security tests
│   └── SecurityTest.php
└── Performance/          # Performance tests
    └── PerformanceTest.php
```

## Running Tests

### Install Dependencies

```bash
composer install
```

### Run All Tests

```bash
vendor/bin/phpunit
```

### Run Specific Test Suite

```bash
# Unit tests only
vendor/bin/phpunit tests/Unit

# Integration tests only
vendor/bin/phpunit tests/Integration

# Security tests only
vendor/bin/phpunit tests/Security

# Performance tests only
vendor/bin/phpunit tests/Performance
```

### Run Specific Test Class

```bash
vendor/bin/phpunit tests/Unit/SecurityTest.php
```

### Run with Coverage

```bash
vendor/bin/phpunit --coverage-html tests/coverage
```

## Test Configuration

### Test Database

Create a test database configuration file at `config/config-test.php`:

```php
<?php
return [
    'database' => [
        'host' => 'localhost',
        'database' => 'headcount_events_test',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    // ... other config
];
```

### Test Data

Tests use the `TestCase` base class which provides:
- `createTestUser()` - Create test users
- `createTestEvent()` - Create test events
- `cleanupTestData()` - Clean up test data

## Test Categories

### Unit Tests

Test individual functions and methods in isolation:
- Security functions (password hashing, CSRF, encryption)
- Validation functions
- Helper functions

### Integration Tests

Test complete workflows and API endpoints:
- Authentication flow
- Event creation
- Member management
- API endpoints

### Security Tests

Test security vulnerabilities and protections:
- SQL injection prevention
- XSS prevention
- CSRF protection
- Password strength
- File upload security
- Rate limiting

### Performance Tests

Test system performance:
- Database query performance
- Search performance
- Password hashing performance

## Writing New Tests

### Example Unit Test

```php
<?php
namespace Headcount\Tests\Unit;

use Headcount\Tests\TestCase;

class MyTest extends TestCase
{
    public function testSomething()
    {
        // Arrange
        $input = 'test';
        
        // Act
        $result = someFunction($input);
        
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

    protected function tearDown(): void
    {
        // Clean up test data
        parent::tearDown();
    }

    public function testControllerMethod()
    {
        $controller = new MyController();
        $result = $controller->someMethod();
        
        $this->assertTrue($result['success']);
    }
}
```

## Test Coverage Goals

- **Overall Coverage**: 80%
- **Critical Paths**: 100%
- **Security Functions**: 100%
- **API Endpoints**: 90%

## Continuous Integration

Tests should be run:
- Before every commit
- In CI/CD pipeline
- Before every release

## Troubleshooting

### Tests Failing

1. Check test database configuration
2. Verify test data setup
3. Check for test isolation issues
4. Review test logs

### Performance Tests Failing

1. Check database indexes
2. Verify query optimization
3. Check system resources
4. Review slow query logs
