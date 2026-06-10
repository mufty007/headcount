<?php

namespace Headcount\Middleware;

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Services\RememberTokenService;
use Headcount\Helpers\Security;
use Headcount\Helpers\Permissions;

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
                    session_regenerate_id(true);
                    
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
                // Use the router URL (works on every deployment layout); the direct
                // /admin/login.php path is not a valid route when the docroot is the
                // project root rather than public/.
                Utilities::redirect('/admin/?page=login');
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
     * Per-request cache of the current user's resolved permission data.
     * Shape: ['user_id'=>int|null, 'super'=>bool, 'user'=>array<string,bool>, 'role'=>array<string,bool>]
     */
    private static $permCache = null;

    /**
     * Load (and cache for this request) the current user's super-admin flag plus
     * per-user and per-role permission overrides. Safe before the permission
     * tables exist (returns empty maps so code defaults apply).
     */
    private static function loadPermissions(): array
    {
        $userId = self::getUserId();

        if (self::$permCache !== null && self::$permCache['user_id'] === $userId) {
            return self::$permCache;
        }

        $cache = ['user_id' => $userId, 'super' => false, 'user' => [], 'role' => []];

        $orgId = self::getOrganizationId();
        if (!$userId || !$orgId) {
            self::$permCache = $cache;
            return $cache;
        }

        try {
            $db = Database::getInstance();
            if ($db) {
                if ($db->hasColumn('users', 'is_super_admin')) {
                    $row = $db->queryOne('SELECT is_super_admin FROM users WHERE id = :id', ['id' => $userId]);
                    $cache['super'] = !empty($row['is_super_admin']);
                }
                if ($db->tableExists('user_permissions')) {
                    foreach ($db->query('SELECT permission_key, granted FROM user_permissions WHERE user_id = :uid', ['uid' => $userId]) as $r) {
                        $cache['user'][$r['permission_key']] = (bool) $r['granted'];
                    }
                }
                $role = self::getRole();
                if ($role && $db->tableExists('role_permissions')) {
                    foreach ($db->query('SELECT permission_key, granted FROM role_permissions WHERE organization_id = :oid AND role = :role', ['oid' => $orgId, 'role' => $role]) as $r) {
                        $cache['role'][$r['permission_key']] = (bool) $r['granted'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Leave defaults; code-level role defaults will apply.
        }

        self::$permCache = $cache;
        return $cache;
    }

    /**
     * Whether the current user holds a given capability (see Permissions catalog).
     * Resolution: super-admin -> per-user override -> per-role override -> code default.
     * Members (portal users) never hold admin-area capabilities.
     */
    public static function can(string $capability): bool
    {
        $role = self::getRole();
        if (!in_array($role, ['admin', 'coordinator'], true)) {
            return false;
        }

        $perms = self::loadPermissions();
        if ($perms['super']) {
            return true;
        }
        if (array_key_exists($capability, $perms['user'])) {
            return $perms['user'][$capability];
        }
        if (array_key_exists($capability, $perms['role'])) {
            return $perms['role'][$capability];
        }
        return Permissions::roleDefault($role, $capability);
    }

    /**
     * Whether the current user is the protected org owner (super-admin).
     */
    public static function isSuperAdmin(): bool
    {
        if (!self::isAdmin()) {
            return false;
        }
        return (bool) self::loadPermissions()['super'];
    }

    /**
     * Require a capability for the current request (API JSON 403 or page 403).
     */
    public static function requireCan(string $capability): void
    {
        self::check();
        if (!self::can($capability)) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, 'You do not have permission to perform this action', [], 403);
            } else {
                http_response_code(403);
                die('Access denied. You do not have permission to access this page.');
            }
            exit;
        }
    }

    /**
     * Whether the user may correct attendance after the event (override check-ins).
     * Backed by the granular permission "attendance.correct".
     */
    public static function canCorrectCheckins(): bool
    {
        return self::can('attendance.correct');
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
