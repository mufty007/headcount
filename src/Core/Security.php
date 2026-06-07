<?php

namespace Headcount\Core;

/**
 * Security Helper Class
 * Handles password hashing, token generation, CSRF protection, and input sanitization
 */
class Security
{
    private static $config;

    /**
     * Initialize with config
     */
    public static function init($config)
    {
        self::$config = $config;
    }

    /**
     * Hash password using bcrypt
     */
    public static function hashPassword($password)
    {
        $cost = self::$config['bcrypt_cost'] ?? 12;
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    /**
     * Verify password against hash
     */
    public static function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }

    /**
     * Validate password meets requirements
     */
    public static function validatePassword($password)
    {
        $errors = [];

        if (strlen($password) < (self::$config['password_min_length'] ?? 8)) {
            $errors[] = 'Password must be at least ' . (self::$config['password_min_length'] ?? 8) . ' characters';
        }

        if ((self::$config['password_require_uppercase'] ?? true) && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if ((self::$config['password_require_number'] ?? true) && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        return $errors;
    }

    /**
     * Generate secure random token
     */
    public static function generateToken($length = 32)
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Sanitize input string
     */
    public static function sanitizeInput($input)
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken()
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCSRFToken($token)
    {
        return isset($_SESSION['csrf_token']) &&
               hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Encrypt sensitive data (for API keys, etc.)
     */
    public static function encrypt($data, $key = null)
    {
        if ($key === null) {
            $key = self::getEncryptionKey();
        }

        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive data
     */
    public static function decrypt($data, $key = null)
    {
        if ($key === null) {
            $key = self::getEncryptionKey();
        }

        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    /**
     * Get encryption key from config or generate one
     */
    private static function getEncryptionKey()
    {
        // In production, this should be stored securely in config
        // For dev, we'll use a default key (should be changed in production)
        return hash('sha256', 'headcount_dev_key_change_in_production', true);
    }
}
