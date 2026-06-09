<?php
/**
 * Profile API — lets the logged-in admin OR coordinator manage their own account
 * (name, email, phone, password). Operates only on the authenticated user's row.
 */

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

$config = require __DIR__ . '/../../config/config.php';

if (!defined('SRC_PATH')) {
    define('SRC_PATH', __DIR__ . '/../../src');
}
require_once SRC_PATH . '/Helpers/Database.php';
require_once SRC_PATH . '/Helpers/Auth.php';
require_once SRC_PATH . '/Helpers/Security.php';
require_once SRC_PATH . '/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Auth;
use Headcount\Helpers\Security;
use Headcount\Middleware\AuthMiddleware;

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

header('Content-Type: application/json');

// Admins and coordinators may manage their own profile.
AuthMiddleware::requireAdminOrCoordinator();

$db = Database::getInstance($config['database']);
$userId = (int) AuthMiddleware::getUserId();
$organizationId = (int) AuthMiddleware::getOrganizationId();

// Parse JSON body + CSRF for POSTs
$requestJsonBody = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode($rawBody, true);
    $requestJsonBody = is_array($decoded) ? $decoded : [];

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($requestJsonBody['csrf_token'] ?? null);
    if (!$token || !Security::verifyCSRFToken($token)) {
        jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
    }
}

$action = get('action', '');

// GET own profile
if ($action === 'get_profile') {
    $me = $db->queryOne(
        'SELECT id, first_name, last_name, email, phone, role FROM users WHERE id = ? LIMIT 1',
        [$userId]
    );
    if (!$me) {
        jsonResponse(['success' => false, 'message' => 'Profile not found'], 404);
    }
    jsonResponse(['success' => true, 'profile' => $me]);
}

// UPDATE own profile (name, email, phone)
if ($action === 'update_profile' && isPost()) {
    $input = $requestJsonBody;
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName  = trim((string) ($input['last_name'] ?? ''));
    $email     = trim((string) ($input['email'] ?? ''));
    $phone     = trim((string) ($input['phone'] ?? ''));

    if ($firstName === '' || $lastName === '') {
        jsonResponse(['success' => false, 'message' => 'First and last name are required'], 400);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'A valid email address is required'], 400);
    }

    // Email must stay unique within the organization (exclude self).
    $dupe = $db->queryOne(
        'SELECT id FROM users WHERE organization_id = ? AND email = ? AND id <> ? LIMIT 1',
        [$organizationId, $email, $userId]
    );
    if ($dupe) {
        jsonResponse(['success' => false, 'message' => 'That email is already in use by another account'], 409);
    }

    try {
        $db->execute(
            'UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?',
            [$firstName, $lastName, $email, ($phone !== '' ? $phone : null), $userId]
        );
        // Keep the session display values fresh.
        $_SESSION['name'] = trim($firstName . ' ' . $lastName);
        $_SESSION['email'] = $email;
        $_SESSION['user_email'] = $email;
        jsonResponse(['success' => true, 'message' => 'Profile updated']);
    } catch (\Throwable $e) {
        error_log('profile update_profile error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update profile'], 500);
    }
}

// CHANGE own password
if ($action === 'change_password' && isPost()) {
    $input = $requestJsonBody;
    $current = (string) ($input['current'] ?? '');
    $new     = (string) ($input['new'] ?? '');
    $confirm = (string) ($input['confirm'] ?? '');

    if (strlen($new) < 8) {
        jsonResponse(['success' => false, 'message' => 'New password must be at least 8 characters'], 400);
    }
    if ($new !== $confirm) {
        jsonResponse(['success' => false, 'message' => 'New password and confirmation do not match'], 400);
    }

    $me = $db->queryOne('SELECT password_hash FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!$me || !password_verify($current, (string) $me['password_hash'])) {
        jsonResponse(['success' => false, 'message' => 'Current password is incorrect'], 400);
    }

    try {
        $db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [Auth::hashPassword($new), $userId]);
        jsonResponse(['success' => true, 'message' => 'Password changed successfully']);
    } catch (\Throwable $e) {
        error_log('profile change_password error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to change password'], 500);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
