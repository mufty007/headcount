<?php

namespace Headcount\Helpers;

/**
 * Security Helper Class
 * Provides security-related functions
 */
class Security
{
    /**
     * Hash password using bcrypt
     */
    public static function hashPassword($password, $cost = 12)
    {
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
     * Generate random token
     */
    public static function generateToken($length = 32)
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Sanitize input to prevent XSS
     */
    public static function sanitizeInput($input)
    {
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
        }
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
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Encrypt sensitive data (AES-256)
     */
    public static function encrypt($data, $key)
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive data
     */
    public static function decrypt($encryptedData, $key)
    {
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    /**
     * Validate password strength
     */
    public static function validatePassword($password)
    {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters long";
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        return $errors;
    }

    /**
     * Set secure session configuration
     * Must be called BEFORE session_start()
     */
    public static function configureSession()
    {
        // Only configure if session hasn't been started yet
        if (session_status() === PHP_SESSION_NONE) {
            $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', $isSecure ? 1 : 0);
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.cookie_path', '/');
            ini_set('session.use_strict_mode', 1);
            ini_set('session.gc_maxlifetime', 86400); // 24 hours
        }
    }

    /**
     * Set security headers
     */
    public static function setSecurityHeaders()
    {
        if (!headers_sent()) {
            header('X-Frame-Options: DENY');
            header('X-Content-Type-Options: nosniff');
            header('X-XSS-Protection: 1; mode=block');
            
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
            
            header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://cdn.tailwindcss.com https://js.stripe.com https://unpkg.com https://cdn.jsdelivr.net https://cdn.quilljs.com; style-src \'self\' \'unsafe-inline\' https://cdn.tailwindcss.com https://fonts.googleapis.com https://cdn.quilljs.com https://cdn.jsdelivr.net; font-src \'self\' https://fonts.gstatic.com; img-src \'self\' data: https:; connect-src \'self\' https://api.stripe.com;');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            // Check if Permissions-Policy header was already set (e.g., by check-in page)
            $headersList = headers_list();
            $permissionsPolicySet = false;
            foreach ($headersList as $header) {
                if (stripos($header, 'Permissions-Policy') !== false) {
                    $permissionsPolicySet = true;
                    break;
                }
            }

            // Only set Permissions-Policy if it hasn't been set yet
            // (allows check-in page to set camera permission first)
            if (!$permissionsPolicySet) {
                // Check if we're on a page that needs camera access (check-in page)
                $requestUri = $_SERVER['REQUEST_URI'] ?? '';
                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                $needsCamera = strpos($requestUri, 'checkin') !== false || 
                              strpos($requestUri, 'qr') !== false ||
                              strpos($scriptName, 'checkin') !== false ||
                              (isset($_GET['page']) && $_GET['page'] === 'checkin');
                
                if ($needsCamera) {
                    // Allow camera and microphone for QR scanning
                    header('Permissions-Policy: geolocation=(), microphone=self, camera=self', true);
                } else {
                    // Default: block camera and microphone
                    header('Permissions-Policy: geolocation=(), microphone=(), camera=()', true);
                }
            }
        }
    }
}
