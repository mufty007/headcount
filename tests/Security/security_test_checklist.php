<?php
/**
 * Security Test Checklist
 * 
 * This script provides automated checks for common security issues.
 * Run this script regularly to ensure security best practices are maintained.
 * 
 * Usage:
 *   php tests/SECURITY/security_test_checklist.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

class SecurityTestChecklist
{
    private $config;
    private $db;
    private $issues = [];
    private $warnings = [];
    private $passed = [];
    
    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/config.php';
        $this->db = Database::getInstance($this->config['database']);
    }
    
    /**
     * Run all security checks
     */
    public function runAll()
    {
        echo "=== Security Test Checklist ===\n\n";
        
        $this->checkPasswordHashing();
        $this->checkSQLInjectionPrevention();
        $this->checkXSSProtection();
        $this->checkCSRFProtection();
        $this->checkSessionSecurity();
        $this->checkFileUploadSecurity();
        $this->checkInputValidation();
        $this->checkErrorHandling();
        $this->checkSecurityHeaders();
        
        $this->generateReport();
    }
    
    /**
     * Check password hashing implementation
     */
    private function checkPasswordHashing()
    {
        echo "Checking password hashing...\n";
        
        $testPassword = 'TestPassword123!';
        $hash = Security::hashPassword($testPassword);
        
        if (password_verify($testPassword, $hash)) {
            $this->passed[] = "Password hashing works correctly";
        } else {
            $this->issues[] = "CRITICAL: Password hashing verification failed";
        }
        
        // Check if hash starts with $2y$ (bcrypt)
        if (strpos($hash, '$2y$') === 0) {
            $this->passed[] = "Using bcrypt for password hashing";
        } else {
            $this->issues[] = "CRITICAL: Not using bcrypt for password hashing";
        }
    }
    
    /**
     * Check SQL injection prevention
     */
    private function checkSQLInjectionPrevention()
    {
        echo "Checking SQL injection prevention...\n";
        
        // Test that prepared statements are used
        $maliciousInput = "'; DROP TABLE users; --";
        
        try {
            $result = $this->db->queryOne(
                "SELECT * FROM users WHERE email = :email LIMIT 1",
                ['email' => $maliciousInput]
            );
            
            // If we get here without exception, prepared statements are working
            $this->passed[] = "Prepared statements prevent SQL injection";
            
            // Verify table still exists
            $tables = $this->db->query("SHOW TABLES LIKE 'users'");
            if (!empty($tables)) {
                $this->passed[] = "Database tables are safe from SQL injection";
            }
        } catch (\Exception $e) {
            $this->warnings[] = "SQL injection test encountered an error: " . $e->getMessage();
        }
    }
    
    /**
     * Check XSS protection
     */
    private function checkXSSProtection()
    {
        echo "Checking XSS protection...\n";
        
        $maliciousInput = '<script>alert("XSS")</script>';
        $sanitized = Security::sanitizeInput($maliciousInput);
        
        if (strpos($sanitized, '<script>') === false && strpos($sanitized, '&lt;') !== false) {
            $this->passed[] = "XSS protection works correctly";
        } else {
            $this->issues[] = "WARNING: XSS protection may not be working correctly";
        }
    }
    
    /**
     * Check CSRF protection
     */
    private function checkCSRFProtection()
    {
        echo "Checking CSRF protection...\n";
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = Security::generateCSRFToken();
        
        if (Security::verifyCSRFToken($token)) {
            $this->passed[] = "CSRF token generation and verification works";
        } else {
            $this->issues[] = "CRITICAL: CSRF token verification failed";
        }
        
        // Test that invalid tokens are rejected
        if (!Security::verifyCSRFToken('invalid_token')) {
            $this->passed[] = "Invalid CSRF tokens are rejected";
        } else {
            $this->issues[] = "CRITICAL: Invalid CSRF tokens are accepted";
        }
    }
    
    /**
     * Check session security
     */
    private function checkSessionSecurity()
    {
        echo "Checking session security...\n";
        
        $sessionConfig = [
            'cookie_httponly' => ini_get('session.cookie_httponly'),
            'cookie_secure' => ini_get('session.cookie_secure'),
            'cookie_samesite' => ini_get('session.cookie_samesite'),
            'use_strict_mode' => ini_get('session.use_strict_mode')
        ];
        
        if ($sessionConfig['cookie_httponly'] == '1') {
            $this->passed[] = "Session cookies are HttpOnly";
        } else {
            $this->warnings[] = "Session cookies are not HttpOnly";
        }
        
        if ($sessionConfig['use_strict_mode'] == '1') {
            $this->passed[] = "Session strict mode is enabled";
        } else {
            $this->warnings[] = "Session strict mode is not enabled";
        }
    }
    
    /**
     * Check file upload security
     */
    private function checkFileUploadSecurity()
    {
        echo "Checking file upload security...\n";
        
        // Check if FileUpload class exists and has security features
        if (class_exists('Headcount\Core\FileUpload')) {
            $this->passed[] = "FileUpload class exists";
            
            // Check if upload directory exists and has correct permissions
            $uploadDir = __DIR__ . '/../../uploads/';
            if (is_dir($uploadDir)) {
                $perms = substr(sprintf('%o', fileperms($uploadDir)), -4);
                if ($perms >= '0755') {
                    $this->passed[] = "Upload directory has secure permissions";
                } else {
                    $this->warnings[] = "Upload directory permissions may be too permissive: $perms";
                }
            }
        } else {
            $this->warnings[] = "FileUpload class not found";
        }
    }
    
    /**
     * Check input validation
     */
    private function checkInputValidation()
    {
        echo "Checking input validation...\n";
        
        // Test email validation
        $validEmail = 'test@example.com';
        $invalidEmail = 'not-an-email';
        
        if (filter_var($validEmail, FILTER_VALIDATE_EMAIL)) {
            $this->passed[] = "Email validation works for valid emails";
        }
        
        if (!filter_var($invalidEmail, FILTER_VALIDATE_EMAIL)) {
            $this->passed[] = "Email validation rejects invalid emails";
        }
    }
    
    /**
     * Check error handling
     */
    private function checkErrorHandling()
    {
        echo "Checking error handling...\n";
        
        $displayErrors = ini_get('display_errors');
        $logErrors = ini_get('log_errors');
        
        if ($displayErrors == '0' || $displayErrors == 'Off') {
            $this->passed[] = "Error display is disabled (good for production)";
        } else {
            $this->warnings[] = "Error display is enabled - should be disabled in production";
        }
        
        if ($logErrors == '1' || $logErrors == 'On') {
            $this->passed[] = "Error logging is enabled";
        } else {
            $this->warnings[] = "Error logging is disabled - should be enabled";
        }
    }
    
    /**
     * Check security headers
     */
    private function checkSecurityHeaders()
    {
        echo "Checking security headers...\n";
        
        // This would need to be tested via HTTP request
        // For now, we check if the Security class has the method
        if (method_exists('Headcount\Helpers\Security', 'setSecurityHeaders')) {
            $this->passed[] = "Security headers method exists";
        } else {
            $this->warnings[] = "Security headers method not found";
        }
    }
    
    /**
     * Generate report
     */
    private function generateReport()
    {
        echo "\n=== Security Test Report ===\n\n";
        
        echo "✅ Passed Checks: " . count($this->passed) . "\n";
        foreach ($this->passed as $check) {
            echo "   ✓ $check\n";
        }
        
        if (!empty($this->warnings)) {
            echo "\n⚠️  Warnings: " . count($this->warnings) . "\n";
            foreach ($this->warnings as $warning) {
                echo "   ⚠ $warning\n";
            }
        }
        
        if (!empty($this->issues)) {
            echo "\n❌ Issues: " . count($this->issues) . "\n";
            foreach ($this->issues as $issue) {
                echo "   ✗ $issue\n";
            }
        }
        
        echo "\n";
        
        if (empty($this->issues) && empty($this->warnings)) {
            echo "✅ All security checks passed!\n";
        } elseif (empty($this->issues)) {
            echo "⚠️  Some warnings found, but no critical issues.\n";
        } else {
            echo "❌ Critical issues found. Please review and fix.\n";
            exit(1);
        }
        
        // Save report
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'passed' => $this->passed,
            'warnings' => $this->warnings,
            'issues' => $this->issues
        ];
        
        $reportFile = __DIR__ . '/security_report_' . date('Y-m-d_His') . '.json';
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
        echo "\nReport saved to: $reportFile\n";
    }
}

// Run if executed from command line
if (php_sapi_name() === 'cli') {
    $checklist = new SecurityTestChecklist();
    $checklist->runAll();
}
