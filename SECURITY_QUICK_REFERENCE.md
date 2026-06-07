# Security Quick Reference Guide
## Headcount Events Platform

This is a quick reference guide for developers to ensure security best practices are followed.

---

## Input Validation Checklist

### Before Processing User Input

- [ ] Validate data type (string, integer, email, etc.)
- [ ] Validate data length (min/max)
- [ ] Validate data format (regex patterns)
- [ ] Sanitize input using `Security::sanitizeInput()`
- [ ] Use `Validator` class for common validations

### Example:
```php
use Headcount\Helpers\Validator;
use Headcount\Helpers\Security;

$email = $_POST['email'] ?? '';
if (!Validator::email($email)) {
    // Handle invalid email
}

$name = Security::sanitizeInput($_POST['name'] ?? '');
if (strlen($name) < 2 || strlen($name) > 100) {
    // Handle invalid length
}
```

---

## Output Escaping Checklist

### Before Displaying User Data

- [ ] Use `htmlspecialchars()` for HTML output
- [ ] Use `json_encode()` for JavaScript context
- [ ] Never use string concatenation in JavaScript
- [ ] Escape all user-generated content

### Example:
```php
// HTML output
echo htmlspecialchars($userData['name']);

// JavaScript context
const userName = <?php echo json_encode($userData['name']); ?>;

// JSON response
header('Content-Type: application/json');
echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
```

---

## Database Query Checklist

### Before Executing Queries

- [ ] Always use prepared statements
- [ ] Never concatenate user input into SQL
- [ ] Use named parameters (`:param`) or positional (`?`)
- [ ] Validate dynamic table/column names against whitelist

### Example:
```php
// ✅ CORRECT
$db->query("SELECT * FROM users WHERE email = :email", ['email' => $email]);

// ❌ WRONG
$db->query("SELECT * FROM users WHERE email = '$email'");
```

---

## CSRF Protection Checklist

### For All Forms

- [ ] Include CSRF token in form
- [ ] Verify token on form submission
- [ ] Regenerate token after use (optional)

### Example:
```php
// In form
<input type="hidden" name="csrf_token" value="<?php echo CsrfMiddleware::getToken(); ?>">

// In handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfMiddleware::verify();
}
```

---

## Authentication Checklist

### For Protected Pages/Endpoints

- [ ] Check authentication before processing
- [ ] Verify user role if needed
- [ ] Check organization_id for data isolation
- [ ] Regenerate session ID after login

### Example:
```php
// Require authentication
AuthMiddleware::check();

// Require admin role
AuthMiddleware::requireAdmin();

// Get organization ID
$orgId = AuthMiddleware::getOrganizationId();
```

---

## File Upload Checklist

### Before Processing Uploads

- [ ] Validate file type (MIME type)
- [ ] Validate file size
- [ ] Validate file extension
- [ ] Generate random filename
- [ ] Store outside web root (if possible)
- [ ] Set secure file permissions (0644)
- [ ] Validate file path to prevent traversal

### Example:
```php
use Headcount\Core\FileUpload;

$uploadConfig = [
    'allowed_types' => ['image/jpeg', 'image/png'],
    'max_size' => 5242880, // 5MB
    'upload_path' => __DIR__ . '/../../uploads/'
];

$fileUpload = new FileUpload($uploadConfig);
$result = $fileUpload->upload($_FILES['file'], 'subdirectory');
```

---

## Session Security Checklist

### Session Configuration

- [ ] Use secure session configuration
- [ ] Set HttpOnly cookies
- [ ] Set Secure flag (HTTPS)
- [ ] Set SameSite attribute
- [ ] Regenerate session ID after login
- [ ] Clear session on logout

### Example:
```php
use Headcount\Helpers\Security;

Security::configureSession();
session_start();

// After login
session_regenerate_id(true);
```

---

## API Security Checklist

### For API Endpoints

- [ ] Require authentication (unless public)
- [ ] Verify CSRF token (for state-changing operations)
- [ ] Implement rate limiting
- [ ] Validate all input
- [ ] Return proper HTTP status codes
- [ ] Use JSON responses
- [ ] Don't expose sensitive data in errors

### Example:
```php
// Check authentication
AuthMiddleware::check();

// Verify CSRF for POST/PUT/DELETE
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
    CsrfMiddleware::verify();
}

// Rate limiting
RateLimiter::checkApiRateLimit($_SERVER['REMOTE_ADDR']);
```

---

## Password Security Checklist

### Password Handling

- [ ] Hash passwords with bcrypt (cost 12+)
- [ ] Never store plain text passwords
- [ ] Validate password strength
- [ ] Use `password_verify()` for checking
- [ ] Implement password reset securely

### Example:
```php
use Headcount\Helpers\Security;

// Hash password
$hash = Security::hashPassword($password);

// Verify password
if (Security::verifyPassword($password, $hash)) {
    // Password correct
}

// Validate password strength
$errors = Security::validatePassword($password);
```

---

## Error Handling Checklist

### Error Messages

- [ ] Don't expose sensitive information
- [ ] Use generic messages for users
- [ ] Log detailed errors server-side
- [ ] Don't expose file paths
- [ ] Don't expose SQL queries
- [ ] Don't expose stack traces

### Example:
```php
try {
    // Code that might fail
} catch (\Exception $e) {
    // Log detailed error
    error_log("Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    // Return generic message to user
    jsonResponse(['success' => false, 'message' => 'An error occurred'], 500);
}
```

---

## Security Headers Checklist

### HTTP Headers

- [ ] Set security headers
- [ ] Configure Content-Security-Policy
- [ ] Set X-Frame-Options
- [ ] Set X-Content-Type-Options
- [ ] Set HSTS (if HTTPS)

### Example:
```php
use Headcount\Helpers\Security;

Security::setSecurityHeaders();
```

---

## Common Security Mistakes to Avoid

### ❌ DON'T:

1. **Concatenate user input into SQL**
   ```php
   // ❌ WRONG
   $sql = "SELECT * FROM users WHERE email = '$email'";
   ```

2. **Echo user input without escaping**
   ```php
   // ❌ WRONG
   echo $userInput;
   ```

3. **Store passwords in plain text**
   ```php
   // ❌ WRONG
   $password = $_POST['password'];
   $db->insert('users', ['password' => $password]);
   ```

4. **Skip CSRF validation**
   ```php
   // ❌ WRONG
   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
       // Process without CSRF check
   }
   ```

5. **Trust client-side validation only**
   ```php
   // ❌ WRONG - Always validate server-side too
   ```

6. **Expose sensitive data in errors**
   ```php
   // ❌ WRONG
   die("SQL Error: " . $e->getMessage());
   ```

### ✅ DO:

1. **Use prepared statements**
   ```php
   // ✅ CORRECT
   $db->query("SELECT * FROM users WHERE email = :email", ['email' => $email]);
   ```

2. **Escape all output**
   ```php
   // ✅ CORRECT
   echo htmlspecialchars($userInput);
   ```

3. **Hash passwords**
   ```php
   // ✅ CORRECT
   $hash = Security::hashPassword($password);
   ```

4. **Validate CSRF tokens**
   ```php
   // ✅ CORRECT
   CsrfMiddleware::verify();
   ```

5. **Validate on server-side**
   ```php
   // ✅ CORRECT
   if (!Validator::email($email)) {
       // Handle error
   }
   ```

6. **Log errors, show generic messages**
   ```php
   // ✅ CORRECT
   error_log("Error: " . $e->getMessage());
   echo "An error occurred. Please try again.";
   ```

---

## Security Testing Checklist

### Before Deployment

- [ ] Run security tests
- [ ] Review code for security issues
- [ ] Test authentication and authorization
- [ ] Test input validation
- [ ] Test file uploads
- [ ] Test CSRF protection
- [ ] Review error messages
- [ ] Check security headers
- [ ] Verify HTTPS configuration
- [ ] Review access controls

---

## Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [OWASP PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)

---

**Remember:** Security is everyone's responsibility. When in doubt, ask for review!
