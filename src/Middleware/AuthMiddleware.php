<?php

namespace Headcount\Middleware;

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Services\RememberTokenService;
use Headcount\Helpers\Security;

/**
 * Authentication Middleware
 * Checks if user is authenticated
 */
class AuthMiddleware
{
    /**
     * Check if user is authenticated
     */
    public static function check()
    {
        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            Security::configureSession();
            session_start();
        }

        // Check if user is logged in via session
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['organization_id'])) {
            // Try to authenticate via remember token
            if (isset($_COOKIE['remember_token'])) {
                $rememberTokenService = new RememberTokenService();
                $userData = $rememberTokenService->validateToken($_COOKIE['remember_token'], 'admin');
                
                if ($userData) {
                    // Auto-login user
                    $_SESSION['user_id'] = $userData['user_id'];
                    $_SESSION['organization_id'] = $userData['organization_id'];
                    $_SESSION['role'] = $userData['role'];
                    $_SESSION['email'] = $userData['email'];
                    $_SESSION['name'] = $userData['first_name'] . ' ' . $userData['last_name'];
                    
                    // Update cookie with new token if rotated
                    if (isset($userData['new_token'])) {
                        setcookie('remember_token', $userData['new_token'], time() + (30 * 24 * 60 * 60), '/', '', true, true);
                    }
                    
                    return true;
                } else {
                    // Invalid token, clear cookie
                    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
                }
            }
            
            // Not authenticated
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, 'Authentication required', [], 401);
            } else {
                Utilities::redirect('/admin/login.php');
            }
            exit;
        }

        return true;
    }

    /**
     * Check if user is admin
     */
    public static function requireAdmin()
    {
        self::check();

        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, 'Admin access required', [], 403);
            } else {
                http_response_code(403);
                die('Access denied. This page is for administrators only.');
            }
            exit;
        }

        return true;
    }

    /**
     * Check if user is admin or coordinator (for check-in, attendance, add member)
     */
    public static function requireAdminOrCoordinator()
    {
        self::check();

        $role = $_SESSION['role'] ?? null;
        if (!in_array($role, ['admin', 'coordinator'], true)) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, 'Admin or coordinator access required', [], 403);
            } else {
                http_response_code(403);
                die('Access denied. This page is for administrators or coordinators.');
            }
            exit;
        }

        return true;
    }

    /**
     * Check if current user is admin or coordinator
     */
    public static function isAdminOrCoordinator()
    {
        $role = $_SESSION['role'] ?? null;
        return in_array($role, ['admin', 'coordinator'], true);
    }

    /**
     * Check if current user is admin (not coordinator)
     */
    public static function isAdmin()
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    /**
     * Whether the user may correct attendance after the event (override check-ins).
     * Admins always can; coordinators only when organizations.coordinators_can_correct_checkins = 1.
     */
    public static function canCorrectCheckins(): bool
    {
        if (!self::isAdminOrCoordinator()) {
            return false;
        }
        if (self::isAdmin()) {
            return true;
        }
        $organizationId = self::getOrganizationId();
        if (!$organizationId) {
            return false;
        }
        try {
            $db = Database::getInstance();
            if (!$db->hasColumn('organizations', 'coordinators_can_correct_checkins')) {
                return false;
            }
            $org = $db->queryOne(
                'SELECT coordinators_can_correct_checkins FROM organizations WHERE id = :id',
                ['id' => $organizationId]
            );
            return !empty($org['coordinators_can_correct_checkins']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Require permission to correct post-event attendance (API or admin page).
     */
    public static function requireCanCorrectCheckins(): void
    {
        self::check();
        if (!self::canCorrectCheckins()) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, 'You do not have permission to correct attendance for this event', [], 403);
            } else {
                http_response_code(403);
                die('Access denied. Only administrators (or coordinators when enabled in Settings) can correct attendance.');
            }
            exit;
        }
    }

    /**
     * Check if request is API request
     */
    private static function isApiRequest()
    {
        $paths = [
            $_SERVER['REQUEST_URI'] ?? '',
            $_SERVER['SCRIPT_NAME'] ?? '',
            $_SERVER['PHP_SELF'] ?? '',
        ];
        foreach ($paths as $p) {
            if ($p !== '' && strpos($p, '/api/') !== false) {
                return true;
            }
        }
        $sf = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if ($sf !== '') {
            $norm = str_replace('\\', '/', $sf);
            if (strpos($norm, '/api/') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get current user ID
     */
    public static function getUserId()
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Get current organization ID
     */
    public static function getOrganizationId()
    {
        return $_SESSION['organization_id'] ?? null;
    }

    /**
     * Get current user role
     */
    public static function getRole()
    {
        return $_SESSION['role'] ?? null;
    }
}
