<?php

namespace Headcount\Controllers;

use Headcount\Models\User;
use Headcount\Helpers\Security;
use Headcount\Helpers\Validator;
use Headcount\Helpers\Utilities;
use Headcount\Core\RateLimiter;
use Headcount\Core\SecurityLogger;
use Headcount\Services\ActivityLogger;
use Headcount\Services\RememberTokenService;
use Headcount\Services\EmailService;

/**
 * Authentication Controller
 */
class AuthController
{
    private $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    /**
     * Handle login
     */
    public function login($email, $password, $rememberMe = false)
    {
        // Initialize security logger
        SecurityLogger::init();
        
        // Validate input
        if (!Validator::email($email) || empty($password)) {
            SecurityLogger::logFailedLogin($email);
            return [
                'success' => false,
                'message' => 'Invalid email or password',
                'errors' => [['field' => 'email', 'message' => 'Invalid credentials']]
            ];
        }

        // Check rate limiting
        try {
            RateLimiter::checkLoginAttempts($email);
        } catch (\Exception $e) {
            SecurityLogger::logRateLimitViolation('login', $email);
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => []
            ];
        }

        // Get organization ID from config or request
        // For now, we'll need to handle this differently
        // This is a simplified version - in production, you'd need to determine org from email domain or other method
        
        // Find user by email (assuming single organization for now)
        // In multi-org setup, you'd need to query differently
        $sql = "SELECT * FROM users WHERE email = :email AND status = 'active' LIMIT 1";
        $user = \Headcount\Helpers\Database::getInstance()->queryOne($sql, ['email' => $email]);
        
        // Map password_hash to password for backward compatibility
        if ($user && isset($user['password_hash'])) {
            $user['password'] = $user['password_hash'];
        }

        if (!$user) {
            RateLimiter::recordFailedLogin($email);
            SecurityLogger::logFailedLogin($email);
            return [
                'success' => false,
                'message' => 'Invalid email or password',
                'errors' => []
            ];
        }

        // Check if account is locked
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            SecurityLogger::log('account_locked', ['user_id' => $user['id'], 'email' => $email]);
            return [
                'success' => false,
                'message' => 'Account is locked. Please try again later.',
                'errors' => []
            ];
        }

        // Verify password (check both password and password_hash for compatibility)
        $passwordHash = $user['password'] ?? $user['password_hash'] ?? null;
        if (empty($passwordHash) || !Security::verifyPassword($password, $passwordHash)) {
            $this->userModel->incrementFailedAttempts($user['id']);
            RateLimiter::recordFailedLogin($email);
            SecurityLogger::logFailedLogin($email);
            
            // Lock account after max attempts
            if ($user['failed_login_attempts'] + 1 >= 5) {
                $this->userModel->lockAccount($user['id']);
                SecurityLogger::log('account_locked_after_attempts', ['user_id' => $user['id'], 'email' => $email]);
            }

            return [
                'success' => false,
                'message' => 'Invalid email or password',
                'errors' => []
            ];
        }

        // Login successful - allow all active users (admins and members)
        $this->userModel->updateLastLogin($user['id']);
        RateLimiter::resetLoginAttempts($email);
        SecurityLogger::logSuccessfulLogin($user['id'], $email);

        // Log activity
        $userName = $user['first_name'] . ' ' . $user['last_name'];
        $activityLogger = new ActivityLogger($user['organization_id'], $user['id']);
        $activityLogger->logLogin($user['id'], $userName);

        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['organization_id'] = $user['organization_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];

        // Set remember me cookie if requested
        if ($rememberMe) {
            $rememberTokenService = new RememberTokenService();
            $token = $rememberTokenService->createToken($user['id'], 'admin');
            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', true, true);
        }

        return [
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user_id' => $user['id'],
                'name' => $user['first_name'] . ' ' . $user['last_name']
            ]
        ];
    }

    /**
     * Handle logout
     */
    public function logout()
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Store user ID and organization ID BEFORE clearing session (needed for token revocation)
        $userId = $_SESSION['user_id'] ?? null;
        $organizationId = $_SESSION['organization_id'] ?? null;
        $userName = $_SESSION['name'] ?? 'Unknown User';
        
        // Log activity before clearing session (don't fail logout if this errors)
        if ($userId && $organizationId) {
            try {
                $activityLogger = new ActivityLogger($organizationId, $userId);
                $activityLogger->logLogout($userId, $userName);
            } catch (\Exception $e) {
                // Log error but don't fail logout
                error_log("Error logging logout activity: " . $e->getMessage());
            }
        }
        
        // Clear remember me cookie and revoke token BEFORE clearing session
        if (isset($_COOKIE['remember_token'])) {
            $rememberTokenService = new RememberTokenService();
            $rememberTokenService->revokeToken($_COOKIE['remember_token']);
            // Clear cookie with multiple path variations
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
            setcookie('remember_token', '', time() - 3600, '/admin/', '', true, true);
            unset($_COOKIE['remember_token']);
        }
        
        // Revoke all tokens for this user if user_id is available (BEFORE destroying session)
        if ($userId) {
            $rememberTokenService = new RememberTokenService();
            $rememberTokenService->revokeAllUserTokens($userId);
        }
        
        // Clear all session data
        $_SESSION = [];
        
        // Delete session cookie
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

    /**
     * Handle forgot password
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

        // Find user
        $sql = "SELECT * FROM users WHERE email = :email AND status = 'active' LIMIT 1";
        $user = \Headcount\Helpers\Database::getInstance()->queryOne($sql, ['email' => $email]);

        if ($user) {
            // Generate reset token
            $token = Security::generateToken();
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            // Store token in database
            $db = \Headcount\Helpers\Database::getInstance();
            $db->insert('password_resets', [
                'user_id' => $user['id'],
                'token' => hash('sha256', $token),
                'expires_at' => $expiresAt
            ]);

            // Send password reset email if SMTP is configured
            $configFile = defined('CONFIG_PATH') ? CONFIG_PATH . '/config.php' : __DIR__ . '/../../config/config.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
                $baseUrl = headcount_app_base_url($config);
                $resetUrl = $baseUrl . '/admin/?page=reset-password&token=' . urlencode($token);

                $orgId = $user['organization_id'] ?? null;
                $emailConfig = null;
                if (!empty($config['smtp2go']['api_key']) && !empty(trim((string) ($config['smtp2go']['from_email'] ?? '')))) {
                    $emailConfig = $config['smtp2go'];
                } elseif ($orgId) {
                    $org = $db->queryOne(
                        'SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?',
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
                        $body = '<h2>Password Reset</h2><p>Click the link below to reset your password. This link expires in 1 hour.</p>'
                            . '<p><a href="' . htmlspecialchars($resetUrl) . '" style="display:inline-block;padding:10px 20px;background:#4F46E5;color:white;text-decoration:none;border-radius:5px;">Reset Password</a></p>'
                            . '<p>If you didn\'t request this, please ignore this email.</p>';
                        $sent = $emailService->sendEmail($user['email'], $subject, $body, $orgId, ['template' => 'password_reset']);
                        if (empty($sent['success'])) {
                            error_log('Password reset email failed (SMTP2GO): ' . ($sent['error'] ?? 'unknown error'));
                        }
                    } catch (\Exception $e) {
                        error_log('Password reset email failed: ' . $e->getMessage());
                    }
                } else {
                    error_log('Admin forgot password: No SMTP configured. Set config smtp2go api_key and from_email, or organization SMTP in Admin Settings. Password reset link was not emailed.');
                }
            }
        }

        // Always return success to prevent email enumeration
        return [
            'success' => true,
            'message' => 'If that email exists, a password reset link has been sent.'
        ];
    }

    /**
     * Reset password using a valid reset token
     */
    public function resetPassword($token, $password, $passwordConfirm = null)
    {
        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'Invalid or expired reset link',
                'errors' => [['field' => 'token', 'message' => 'Token is required']]
            ];
        }

        $passwordErrors = Security::validatePassword($password);
        if (!empty($passwordErrors)) {
            return [
                'success' => false,
                'message' => implode(' ', $passwordErrors),
                'errors' => array_map(function ($msg) {
                    return ['field' => 'password', 'message' => $msg];
                }, $passwordErrors)
            ];
        }

        if ($passwordConfirm !== null && $password !== $passwordConfirm) {
            return [
                'success' => false,
                'message' => 'Passwords do not match',
                'errors' => [['field' => 'password_confirm', 'message' => 'Passwords do not match']]
            ];
        }

        $db = \Headcount\Helpers\Database::getInstance();
        $hashedToken = hash('sha256', $token);
        $sql = "SELECT pr.*, u.id as user_id FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE pr.token = :token AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.status = 'active'
                LIMIT 1";
        $row = $db->queryOne($sql, ['token' => $hashedToken]);

        if (!$row) {
            return [
                'success' => false,
                'message' => 'Invalid or expired reset link',
                'errors' => []
            ];
        }

        $db->update('users', $row['user_id'], [
            'password_hash' => Security::hashPassword($password)
        ]);
        $db->update('password_resets', $row['id'], [
            'used_at' => date('Y-m-d H:i:s')
        ]);

        return [
            'success' => true,
            'message' => 'Password has been reset. You can now log in.'
        ];
    }
}
