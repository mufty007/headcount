<?php

namespace Headcount\Middleware;

use Headcount\Helpers\Utilities;
use Headcount\Services\RememberTokenService;
use Headcount\Helpers\Security;

/**
 * Portal Authentication Middleware
 * Checks if member is authenticated for portal access
 */
class PortalAuthMiddleware
{
    /**
     * Check if member is authenticated
     * 
     * @return bool True if authenticated
     */
    public static function isAuthenticated()
    {
        if (session_status() === PHP_SESSION_NONE) {
            Security::configureSession();
            session_start();
        }

        // Check if logged in via session
        if (isset($_SESSION['portal_logged_in']) && 
            $_SESSION['portal_logged_in'] === true &&
            isset($_SESSION['portal_user_id']) &&
            isset($_SESSION['portal_role']) &&
            $_SESSION['portal_role'] === 'member') {
            return true;
        }

        // Try to authenticate via remember token (but not if we're on login page after logout)
        $isLoginPageAfterLogout = (
            isset($_GET['logged_out']) || 
            (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'login.php?logged_out=1') !== false)
        );
        
        if (!$isLoginPageAfterLogout && isset($_COOKIE['portal_remember_token'])) {
            $rememberTokenService = new RememberTokenService();
            $userData = $rememberTokenService->validateToken($_COOKIE['portal_remember_token'], 'portal');
            
            if ($userData && $userData['role'] === 'member') {
                // Auto-login user
                $_SESSION['portal_user_id'] = $userData['user_id'];
                $_SESSION['portal_user_email'] = $userData['email'];
                $_SESSION['portal_user_name'] = $userData['first_name'] . ' ' . $userData['last_name'];
                $_SESSION['portal_organization_id'] = $userData['organization_id'];
                $_SESSION['portal_role'] = $userData['role'];
                $_SESSION['portal_logged_in'] = true;
                session_regenerate_id(true);
                
                // Update cookie with new token if rotated
                if (isset($userData['new_token'])) {
                    setcookie('portal_remember_token', $userData['new_token'], time() + (30 * 24 * 60 * 60), '/', '', true, true);
                }
                
                return true;
            } else {
                // Invalid token, aggressively clear cookie
                $paths = ['/', '/portal/'];
                foreach ($paths as $path) {
                    setcookie('portal_remember_token', '', time() - 3600, $path, '', true, true);
                    setcookie('portal_remember_token', '', time() - 3600, $path, '', false, true);
                }
                unset($_COOKIE['portal_remember_token']);
            }
        } elseif ($isLoginPageAfterLogout && isset($_COOKIE['portal_remember_token'])) {
            // On login page after logout - clear any remaining cookies
            $paths = ['/', '/portal/'];
            foreach ($paths as $path) {
                setcookie('portal_remember_token', '', time() - 3600, $path, '', true, true);
                setcookie('portal_remember_token', '', time() - 3600, $path, '', false, true);
            }
            unset($_COOKIE['portal_remember_token']);
        }

        return false;
    }

    /**
     * Require authentication (redirect if not authenticated)
     * 
     * @param string|null $redirectUrl Optional redirect URL
     * @return void
     */
    public static function requireAuth($redirectUrl = null)
    {
        if (!self::isAuthenticated()) {
            if ($redirectUrl === null) {
                $baseUrl = self::getBaseUrl();
                $redirectUrl = $baseUrl . '/portal/login.php';
            }
            Utilities::redirect($redirectUrl);
        }
    }

    /**
     * Get current member ID
     * 
     * @return int|null Member ID or null if not authenticated
     */
    public static function getMemberId()
    {
        if (!self::isAuthenticated()) {
            return null;
        }
        return $_SESSION['portal_user_id'] ?? null;
    }

    /**
     * Get current member email
     * 
     * @return string|null Member email or null if not authenticated
     */
    public static function getMemberEmail()
    {
        if (!self::isAuthenticated()) {
            return null;
        }
        return $_SESSION['portal_user_email'] ?? null;
    }

    /**
     * Get current member name
     * 
     * @return string|null Member name or null if not authenticated
     */
    public static function getMemberName()
    {
        if (!self::isAuthenticated()) {
            return null;
        }
        return $_SESSION['portal_user_name'] ?? null;
    }

    /**
     * Get current organization ID
     * 
     * @return int|null Organization ID or null if not authenticated
     */
    public static function getOrganizationId()
    {
        if (!self::isAuthenticated()) {
            return null;
        }
        return $_SESSION['portal_organization_id'] ?? null;
    }

    /**
     * Get current member data
     * 
     * @return array|null Member data or null if not authenticated
     */
    public static function getMember()
    {
        if (!self::isAuthenticated()) {
            return null;
        }

        return [
            'id' => $_SESSION['portal_user_id'] ?? null,
            'email' => $_SESSION['portal_user_email'] ?? null,
            'name' => $_SESSION['portal_user_name'] ?? null,
            'organization_id' => $_SESSION['portal_organization_id'] ?? null,
            'role' => $_SESSION['portal_role'] ?? null
        ];
    }

    /**
     * Get base URL
     */
    private static function getBaseUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $basePath = dirname($scriptName);
        $basePath = str_replace('/public', '', $basePath);
        $basePath = rtrim($basePath, '/');
        
        return $protocol . '://' . $host . $basePath;
    }
}
