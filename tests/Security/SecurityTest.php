<?php

namespace Headcount\Tests\Security;

use Headcount\Tests\TestCase;
use Headcount\Helpers\Security;
use Headcount\Helpers\Database;

/**
 * Security Testing
 * Tests security vulnerabilities and protections
 */
class SecurityTest extends TestCase
{
    public function testSQLInjectionPrevention()
    {
        // Test that prepared statements prevent SQL injection
        $db = Database::getInstance();
        
        // Malicious input
        $malicious = "'; DROP TABLE users; --";
        
        // Should use prepared statement and not execute malicious SQL
        $sql = "SELECT * FROM users WHERE email = :email LIMIT 1";
        $result = $db->queryOne($sql, ['email' => $malicious]);
        
        // Should not throw exception and should return null (no match)
        $this->assertNull($result);
        
        // Verify table still exists
        $tables = $db->query("SHOW TABLES LIKE 'users'");
        $this->assertNotEmpty($tables);
    }

    public function testXSSPrevention()
    {
        $malicious = '<script>alert("XSS")</script>';
        $sanitized = Security::sanitizeInput($malicious);
        
        // Should escape HTML
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('alert', $sanitized);
        $this->assertStringContainsString('&lt;', $sanitized);
    }

    public function testCSRFProtection()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = Security::generateCSRFToken();
        
        // Valid token should verify
        $this->assertTrue(Security::verifyCSRFToken($token));
        
        // Invalid token should fail
        $this->assertFalse(Security::verifyCSRFToken('invalid_token'));
        $this->assertFalse(Security::verifyCSRFToken(''));
        
        // Token should be different each session
        session_destroy();
        session_start();
        $newToken = Security::generateCSRFToken();
        $this->assertNotEquals($token, $newToken);
    }

    public function testPasswordStrength()
    {
        // Weak passwords should fail validation
        $weakPasswords = [
            'short',           // Too short
            'nouppercase123!', // No uppercase
            'NOLOWERCASE123!', // No lowercase
            'NoNumbers!',      // No numbers
        ];

        foreach ($weakPasswords as $password) {
            $errors = Security::validatePassword($password);
            $this->assertNotEmpty($errors, "Password '$password' should fail validation");
        }

        // Strong password should pass
        $errors = Security::validatePassword('StrongPass123!');
        $this->assertEmpty($errors);
    }

    public function testFileUploadSecurity()
    {
        $uploader = new \Headcount\Core\FileUpload([
            'allowed_types' => ['image/jpeg'],
            'max_size' => 1048576,
            'upload_path' => BASE_PATH . '/tests/uploads'
        ]);

        // Test that PHP files are rejected
        $phpFile = [
            'name' => 'malicious.php',
            'type' => 'application/x-php',
            'size' => 1024,
            'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
            'error' => UPLOAD_ERR_OK
        ];

        file_put_contents($phpFile['tmp_name'], '<?php echo "hack"; ?>');

        $this->expectException(\Exception::class);
        $uploader->upload($phpFile);
    }

    public function testRateLimiting()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $email = 'ratelimit@example.com';
        \Headcount\Core\RateLimiter::resetLoginAttempts($email);

        // Should allow first 4 attempts
        for ($i = 0; $i < 4; $i++) {
            \Headcount\Core\RateLimiter::recordFailedLogin($email);
            $this->assertTrue(\Headcount\Core\RateLimiter::checkLoginAttempts($email));
        }

        // 5th attempt should lock
        \Headcount\Core\RateLimiter::recordFailedLogin($email);
        $this->expectException(\Exception::class);
        \Headcount\Core\RateLimiter::checkLoginAttempts($email);
    }
}
