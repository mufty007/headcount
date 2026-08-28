<?php

namespace Headcount\Middleware;

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Services\RememberTokenService;
use Headcount\Helpers\Security;
use Headcount\Helpers\Permissions;
use Headcount\Services\EventRequestService;
use Headcount\Services\ProgramRequestService;

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
     * Staff (admin/coordinator) or presenter (attendance-only).
     */
    public static function requireAdminCoordinatorOrPresenter(): bool
    {
        self::check();
        $role = $_SESSION['role'] ?? null;
        if (!in_array($role, ['admin', 'coordinator', 'presenter'], true)) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, 'Staff access required', [], 403);
            } else {
                http_response_code(403);
                die('Access denied.');
            }
            exit;
        }
        return true;
    }

    public static function isPresenter(): bool
    {
        return ($_SESSION['role'] ?? null) === 'presenter';
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

        $cache = ['user_id' => $userId, 'super' => false, 'can_approve' => false, 'user' => [], 'role' => []];

        $orgId = self::getOrganizationId();
        if (!$userId || !$orgId) {
            self::$permCache = $cache;
            return $cache;
        }

        try {
            $db = Database::getInstance();
            if ($db) {
                if ($db->hasColumn('users', 'is_super_admin')) {
                    $cols = 'is_super_admin';
                    $hasApprover = $db->hasColumn('users', 'can_approve_requests');
                    if ($hasApprover) {
                        $cols .= ', can_approve_requests';
                    }
                    $row = $db->queryOne("SELECT $cols FROM users WHERE id = :id", ['id' => $userId]);
                    $cache['super'] = !empty($row['is_super_admin']);
                    $cache['can_approve'] = $cache['super'] && (
                        $hasApprover ? !empty($row['can_approve_requests']) : true
                    );
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
     * Request-approval keys are an exception: only selected owners (can_approve_requests).
     * Members (portal users) never hold admin-area capabilities.
     */
    public static function can(string $capability): bool
    {
        $role = self::getRole();
        if ($role === 'presenter') {
            return $capability === 'programs.take_attendance';
        }
        if (!in_array($role, ['admin', 'coordinator'], true)) {
            return false;
        }

        $perms = self::loadPermissions();
        if (Permissions::isOwnerApproverKey($capability)) {
            return !empty($perms['super']) && !empty($perms['can_approve']);
        }
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
     * Clear the per-request permission cache (tests).
     */
    public static function resetPermissionCache(): void
    {
        self::$permCache = null;
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
     * Admins may keep editing events that already exist (including those published
     * before the request workflow). Creating new events still needs events.manage.
     * Coordinators may only finish a draft from their own approved request.
     */
    public static function canMaintainExistingEvent(int $organizationId, int $eventId): bool
    {
        if ($eventId <= 0) {
            return false;
        }
        if (self::isAdmin()) {
            return true;
        }
        try {
            $svc = new EventRequestService();
            return $svc->tablesExist()
                && $svc->userCanCompleteRequestEvent($organizationId, (int) self::getUserId(), $eventId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Same rule as events: admins maintain existing programs; create stays owners-only.
     */
    public static function canMaintainExistingProgram(int $organizationId, int $programId): bool
    {
        if ($programId <= 0) {
            return false;
        }
        if (self::isAdmin()) {
            return true;
        }
        try {
            $svc = new ProgramRequestService();
            return $svc->tablesExist()
                && $svc->userCanCompleteRequestProgram($organizationId, (int) self::getUserId(), $programId);
        } catch (\Throwable $e) {
            return false;
        }
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
