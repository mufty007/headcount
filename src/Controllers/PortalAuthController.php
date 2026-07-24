<?php

namespace Headcount\Controllers;

use Headcount\Services\MagicLinkService;
use Headcount\Services\MemberRegistrationService;
use Headcount\Services\EmailVerificationService;
use Headcount\Services\RememberTokenService;
use Headcount\Services\EmailService;
use Headcount\Helpers\Security;
use Headcount\Helpers\Database;
use Headcount\Helpers\Validator;
use Headcount\Core\RateLimiter;
use Headcount\Core\SecurityLogger;

/**
 * Portal Authentication Controller
 * Handles member authentication for the portal
 */
class PortalAuthController
{
    private $magicLinkService;
    private $registrationService;
    private $db;

    public function __construct()
    {
        try {
            $this->magicLinkService = new MagicLinkService();
            $this->registrationService = new MemberRegistrationService();
            $this->db = Database::getInstance();
            
            // Initialize security logger (don't fail if this errors)
            try {
                SecurityLogger::init();
            } catch (\Exception $e) {
                error_log("SecurityLogger initialization failed: " . $e->getMessage());
                // Continue anyway - logging is not critical
            }
        } catch (\Exception $e) {
            error_log("PortalAuthController construction failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send magic link
     */
    public function sendMagicLink($email, $organizationId = null)
    {
        // Validate email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Invalid email address'
            ];
        }

        // Rate limiting
        try {
            RateLimiter::checkLoginAttempts($email);
        } catch (\Exception $e) {
            SecurityLogger::logRateLimitViolation('magic_link', $email);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        // Generate and send magic link
        $result = $this->magicLinkService->generateAndSend($email, $organizationId);
        
        if ($result['success']) {
            RateLimiter::resetLoginAttempts($email);
        }

        return $result;
    }

    /**
     * Forgot password for members – sends reset link to portal reset page
     */
    public function forgotPassword($email)
    {
        if (!Validator::email($email)) {
            return [
                'success' => false,
                'message' => 'Invalid email address',
                'errors' => [['field' => 'email', 'message' => 'Invalid email format']]
            ];
        }

        $sql = "SELECT * FROM users WHERE email = :email AND role = 'member' AND status = 'active' LIMIT 1";
        $user = $this->db->queryOne($sql, ['email' => $email]);

        if ($user) {
            $token = Security::generateToken();
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);

            try {
                $this->db->insert('password_resets', [
                    'user_id' => $user['id'],
                    'token' => hash('sha256', $token),
                    'expires_at' => $expiresAt
                ]);
            } catch (\Exception $e) {
                error_log('Portal forgot password: failed to store token: ' . $e->getMessage());
                return [
                    'success' => false,
                    'message' => 'Unable to send reset link. Please try again.'
                ];
            }

            $configFile = defined('CONFIG_PATH') ? CONFIG_PATH . '/config.php' : __DIR__ . '/../../config/config.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
                $baseUrl = headcount_portal_base_url($config);
                $resetUrl = $baseUrl . '/portal/reset-password.php?token=' . urlencode($token);
                $orgId = $user['organization_id'] ?? null;
                $emailConfig = null;
                if (!empty($config['smtp2go']['api_key']) && !empty(trim((string) ($config['smtp2go']['from_email'] ?? '')))) {
                    $emailConfig = $config['smtp2go'];
                } elseif ($orgId) {
                    $org = $this->db->queryOne(
                        "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
                        [$orgId]
                    );
                    if ($org && !empty($org['smtp_from_email'])) {
                        $apiKey = null;
                        if (!empty($org['smtp_api_key'])) {
                            $apiKey = base64_decode($org['smtp_api_key'], true);
                        }
                        if (($apiKey === false || $apiKey === null || $apiKey === '') && !empty($org['smtp_api_key_encrypted'])) {
                            $encKey = $config['security']['encryption_key'] ?? null;
                            if ($encKey) {
                                $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $encKey);
                            }
                        }
                        if (!empty($apiKey)) {
                            $emailConfig = [
                                'api_key' => $apiKey,
                                'from_email' => $org['smtp_from_email'],
                                'from_name' => $org['smtp_from_name'] ?? null,
                                'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
                            ];
                        }
                    }
                }
                if ($emailConfig) {
                    try {
                        $emailService = new EmailService($emailConfig);
                        $subject = 'Reset your password';
                        $body = '<h2>Password Reset</h2><p>Click the link below to reset your member portal password. This link expires in 1 hour.</p>'
                            . '<p><a href="' . htmlspecialchars($resetUrl) . '" style="display:inline-block;padding:10px 20px;background:#4F46E5;color:white;text-decoration:none;border-radius:5px;">Reset Password</a></p>'
                            . '<p>If you didn\'t request this, please ignore this email.</p>';
                        $sent = $emailService->sendEmail($user['email'], $subject, $body, $orgId, ['template' => 'password_reset']);
                        if (empty($sent['success'])) {
                            error_log('Portal password reset email failed (SMTP2GO): ' . ($sent['error'] ?? 'unknown error'));
                        }
                    } catch (\Exception $e) {
                        error_log('Portal password reset email failed: ' . $e->getMessage());
                    }
                } else {
                    error_log('Portal forgot password: No SMTP configured. Set config smtp2go api_key and from_email, or organization SMTP in Admin Settings.');
                }
            }
        }

        return [
            'success' => true,
            'message' => 'If that email is registered, a password reset link has been sent.'
        ];
    }

    /**
     * Verify magic link token and login user
     */
    public function verifyMagicLink($token)
    {
        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'Invalid token'
            ];
        }

        // Verify token
        $user = $this->magicLinkService->verifyToken($token);

        if (!$user) {
            SecurityLogger::logFailedLogin('magic_link_token');
            return [
                'success' => false,
                'message' => 'Invalid or expired token'
            ];
        }

        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Regenerate session ID for security
        session_regenerate_id(true);

        // Set session data
        $_SESSION['portal_user_id'] = $user['id'];
        $_SESSION['portal_user_email'] = $user['email'];
        $_SESSION['portal_user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['portal_organization_id'] = $user['organization_id'];
        $_SESSION['portal_role'] = $user['role'];
        $_SESSION['portal_logged_in'] = true;

        // Log successful login
        SecurityLogger::logSuccessfulLogin($user['id'], $user['email']);

        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $_SESSION['portal_user_name']
            ]
        ];
    }

    /**
     * Register new member
     */
    public function register($data)
    {
        // Honeypot: bots that fill hidden "website" field get a fake success
        $honeypot = trim((string) ($data['website'] ?? ''));
        if ($honeypot !== '') {
            error_log('Portal registration honeypot triggered from IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            return [
                'success' => true,
                'message' => 'Registration successful. Please check your email to verify your account.',
                'requires_verification' => true,
                'user' => [
                    'id' => 0,
                    'email' => trim((string) ($data['email'] ?? '')),
                    'first_name' => trim((string) ($data['first_name'] ?? '')),
                    'last_name' => trim((string) ($data['last_name'] ?? '')),
                ]
            ];
        }

        $email = $data['email'] ?? '';
        try {
            RateLimiter::checkRegistrationRateLimit($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        } catch (\Exception $e) {
            SecurityLogger::logRateLimitViolation('registration', $email);
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [$e->getMessage()]
            ];
        }

        $result = $this->registrationService->register($data);

        if ($result['success']) {
            SecurityLogger::log('member_registered', [
                'user_id' => $result['user']['id'] ?? null,
                'email' => $email
            ]);
        }

        return $result;
    }

    /**
     * Verify registration email token
     */
    public function verifyEmail(string $token)
    {
        $service = new EmailVerificationService();
        return $service->verifyToken($token);
    }

    /**
     * Resend verification email (enumeration-safe)
     */
    public function resendVerification(string $email)
    {
        $email = strtolower(trim($email));
        if ($email === '' || !Validator::email($email)) {
            return [
                'success' => false,
                'message' => 'Please enter a valid email address'
            ];
        }

        try {
            RateLimiter::checkVerificationEmailRateLimit($email);
        } catch (\Exception $e) {
            SecurityLogger::logRateLimitViolation('verification_email', $email);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        $service = new EmailVerificationService();
        return $service->resendByEmail($email);
    }

    /**
     * Login with email and password
     */
    public function login($email, $password, $rememberMe = false)
    {
        // Validate input
        if (empty($email) || empty($password)) {
            SecurityLogger::logFailedLogin($email);
            return [
                'success' => false,
                'message' => 'Email and password are required'
            ];
        }

        // Rate limiting
        try {
            RateLimiter::checkLoginAttempts($email);
        } catch (\Exception $e) {
            SecurityLogger::logRateLimitViolation('login', $email);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        // Find user
        try {
            $sql = "SELECT * FROM users 
                    WHERE email = :email 
                    AND role = 'member' 
                    AND status = 'active' 
                    LIMIT 1";
            $user = $this->db->queryOne($sql, ['email' => $email]);
        } catch (\Exception $e) {
            error_log("Database query error during login: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error. Please try again later.'
            ];
        }

        if (!$user || empty($user['password_hash'])) {
            try {
                RateLimiter::recordFailedLogin($email);
                SecurityLogger::logFailedLogin($email);
            } catch (\Exception $e) {
                error_log("Error logging failed login: " . $e->getMessage());
            }
            return [
                'success' => false,
                'message' => 'Invalid email or password'
            ];
        }

        // Verify password
        try {
            $passwordValid = Security::verifyPassword($password, $user['password_hash']);
        } catch (\Exception $e) {
            error_log("Password verification error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error verifying password. Please try again.'
            ];
        }
        
        if (!$passwordValid) {
            try {
                RateLimiter::recordFailedLogin($email);
                SecurityLogger::logFailedLogin($email);
            } catch (\Exception $e) {
                error_log("Error logging failed login: " . $e->getMessage());
            }
            return [
                'success' => false,
                'message' => 'Invalid email or password'
            ];
        }

        // Hard-block unverified self-registered members
        if (empty($user['email_verified_at'])) {
            return [
                'success' => false,
                'message' => 'Please verify your email before logging in. Check your inbox for the verification link.',
                'requires_verification' => true,
                'email' => $user['email']
            ];
        }

        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            Security::configureSession();
            session_start();
        }

        // Regenerate session ID
        try {
            session_regenerate_id(true);
        } catch (\Exception $e) {
            error_log("Session regeneration failed: " . $e->getMessage());
            // Continue anyway - session is already started
        }

        // Set session data
        $_SESSION['portal_user_id'] = $user['id'];
        $_SESSION['portal_user_email'] = $user['email'];
        $_SESSION['portal_user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['portal_organization_id'] = $user['organization_id'];
        $_SESSION['portal_role'] = $user['role'];
        $_SESSION['portal_logged_in'] = true;

        // Set remember me cookie if requested
        if ($rememberMe) {
            try {
                $rememberTokenService = new RememberTokenService();
                $rememberToken = $rememberTokenService->createToken($user['id'], 'portal');
                if ($rememberToken) {
                    setcookie('portal_remember_token', $rememberToken, time() + (30 * 24 * 60 * 60), '/', '', true, true);
                }
            } catch (\Exception $e) {
                error_log("Error creating remember token: " . $e->getMessage());
                // Continue anyway - remember me is optional
            }
        }

        // Reset failed login attempts
        try {
            RateLimiter::resetLoginAttempts($email);
        } catch (\Exception $e) {
            error_log("RateLimiter::resetLoginAttempts failed: " . $e->getMessage());
            // Continue anyway
        }
        
        try {
            SecurityLogger::logSuccessfulLogin($user['id'], $email);
        } catch (\Exception $e) {
            error_log("SecurityLogger::logSuccessfulLogin failed: " . $e->getMessage());
            // Continue anyway - logging is not critical
        }

        // Update last login (don't fail if this errors)
        try {
            $this->db->execute(
                "UPDATE users SET last_login_at = NOW() WHERE id = :id",
                ['id' => $user['id']]
            );
        } catch (\Exception $e) {
            error_log("Failed to update last_login_at: " . $e->getMessage());
            // Continue anyway - this is not critical
        }

        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $_SESSION['portal_user_name']
            ]
        ];
    }

    /**
     * Logout
     */
    public function logout()
    {
        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Store user ID before clearing session (needed for token revocation)
        $userId = $_SESSION['portal_user_id'] ?? null;
        $email = $_SESSION['portal_user_email'] ?? '';
        
        // Log activity if user was logged in (don't fail logout if this errors)
        if ($userId) {
            try {
                SecurityLogger::log('member_logout', ['user_id' => $userId, 'email' => $email]);
            } catch (\Exception $e) {
                // Log error but don't fail logout
                error_log("Error logging member logout: " . $e->getMessage());
            }
        }

        // Clear remember me cookie and revoke token BEFORE clearing session
        if (isset($_COOKIE['portal_remember_token'])) {
            $rememberTokenService = new RememberTokenService();
            $rememberTokenService->revokeToken($_COOKIE['portal_remember_token']);
            
            // Aggressively clear cookie with multiple path/domain combinations
            $paths = ['/', '/portal/'];
            $domains = ['', null];
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (!empty($host)) {
                $domains[] = $host;
                $domains[] = '.' . $host;
            }
            
            foreach ($paths as $path) {
                foreach ($domains as $domain) {
                    // Try with secure flag
                    setcookie('portal_remember_token', '', time() - 3600, $path, $domain, true, true);
                    // Try without secure flag (in case it was set without it)
                    setcookie('portal_remember_token', '', time() - 3600, $path, $domain, false, true);
                }
            }
            
            unset($_COOKIE['portal_remember_token']);
        }
        
        // Revoke all tokens for this user if user_id is available
        if ($userId) {
            $rememberTokenService = new RememberTokenService();
            $rememberTokenService->revokeAllUserTokens($userId);
        }

        // Clear all session data
        $_SESSION = [];

        // Delete session cookie with proper parameters
        $sessionName = session_name();
        if (isset($_COOKIE[$sessionName])) {
            $params = session_get_cookie_params();
            // Clear with original parameters
            setcookie(
                $sessionName, 
                '', 
                time() - 3600,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
            // Also try clearing with root path
            setcookie(
                $sessionName, 
                '', 
                time() - 3600,
                '/',
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
            unset($_COOKIE[$sessionName]);
        }

        // Destroy session
        session_destroy();

        return [
            'success' => true,
            'message' => 'Logged out successfully'
        ];
    }
}
