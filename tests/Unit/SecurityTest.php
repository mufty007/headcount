<?php

namespace Headcount\Tests\Unit;

use Headcount\Tests\TestCase;
use Headcount\Helpers\Security;
use Headcount\Core\RateLimiter;
use Headcount\Core\SecurityLogger;

/**
 * Security Tests
 * Tests security-related functionality
 */
class SecurityTest extends TestCase
{
    public function testPasswordHashing()
    {
        $password = 'Test123!@#';
        $hash = Security::hashPassword($password);
        
        $this->assertNotEmpty($hash);
        $this->assertNotEquals($password, $hash);
        $this->assertTrue(Security::verifyPassword($password, $hash));
        $this->assertFalse(Security::verifyPassword('wrong', $hash));
    }

    public function testPasswordValidation()
    {
        // Valid password
        $errors = Security::validatePassword('Test123!');
        $this->assertEmpty($errors);

        // Too short
        $errors = Security::validatePassword('Test1');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('8 characters', $errors[0]);

        // Missing uppercase
        $errors = Security::validatePassword('test123!');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('uppercase', $errors[0]);

        // Missing lowercase
        $errors = Security::validatePassword('TEST123!');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('lowercase', $errors[0]);

        // Missing number
        $errors = Security::validatePassword('TestABC!');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('number', $errors[0]);
    }

    public function testCSRFTokenGeneration()
    {
        // Start session for CSRF token
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token1 = Security::generateCSRFToken();
        $token2 = Security::generateCSRFToken();
        
        // Should return same token if already generated
        $this->assertEquals($token1, $token2);
        $this->assertNotEmpty($token1);
        $this->assertEquals(64, strlen($token1)); // 32 bytes = 64 hex chars
    }

    public function testCSRFTokenVerification()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = Security::generateCSRFToken();
        
        $this->assertTrue(Security::verifyCSRFToken($token));
        $this->assertFalse(Security::verifyCSRFToken('invalid_token'));
        $this->assertFalse(Security::verifyCSRFToken(''));
    }

    public function testInputSanitization()
    {
        $malicious = '<script>alert("XSS")</script>';
        $sanitized = Security::sanitizeInput($malicious);
        
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('alert', $sanitized);
    }

    public function testEncryptionDecryption()
    {
        $data = 'sensitive_data_123';
        $key = hash('sha256', 'test_key', true);
        
        $encrypted = Security::encrypt($data, $key);
        $decrypted = Security::decrypt($encrypted, $key);
        
        $this->assertNotEquals($data, $encrypted);
        $this->assertEquals($data, $decrypted);
    }

    public function testRateLimiterLoginAttempts()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $email = 'test@example.com';
        
        // Reset any existing attempts
        RateLimiter::resetLoginAttempts($email);
        
        // Record 4 failed attempts
        for ($i = 0; $i < 4; $i++) {
            RateLimiter::recordFailedLogin($email);
        }
        
        // Should still allow (4 < 5)
        $this->assertTrue(RateLimiter::checkLoginAttempts($email));
        
        // Record 5th attempt
        RateLimiter::recordFailedLogin($email);
        
        // Should throw exception
        $this->expectException(\Exception::class);
        RateLimiter::checkLoginAttempts($email);
    }

    public function testSecurityLogger()
    {
        SecurityLogger::init(__DIR__ . '/../../logs');
        
        // Should not throw exception
        SecurityLogger::log('test_event', ['test' => 'data']);
        SecurityLogger::logFailedLogin('test@example.com');
        SecurityLogger::logSuccessfulLogin(1, 'test@example.com');
        
        $this->assertTrue(true); // If we get here, logging works
    }
}
