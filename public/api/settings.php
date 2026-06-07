<?php
// Use autoloader if available
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

// Only turn fatals into JSON; warnings/notices are logged and suppressed so they do not
// corrupt the response after a successful operation (e.g. SMTP2GO accepted the message).
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (error_reporting() === 0) {
        return false;
    }
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (in_array($errno, $fatalTypes, true) && !headers_sent()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit;
    }
    return true;
});

// Load config
$config = require __DIR__ . '/../../config/config.php';

// Define paths if not already defined
if (!defined('SRC_PATH')) {
    define('SRC_PATH', __DIR__ . '/../../src');
}

// Load helpers
require_once SRC_PATH . '/Helpers/Database.php';
require_once SRC_PATH . '/Helpers/Auth.php';
require_once SRC_PATH . '/Helpers/Security.php';
require_once SRC_PATH . '/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Auth;
use Headcount\Helpers\Security;
use Headcount\Helpers\Validator;
use Headcount\Core\Cache;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\OrganizationApiKeyService;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

header('Content-Type: application/json');

// Check authentication using AuthMiddleware (consistent with rest of app)
if (!AuthMiddleware::getUserId() || !AuthMiddleware::getOrganizationId()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Verify admin role
if (AuthMiddleware::getRole() !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Admin access required'], 403);
}

// Parse JSON body once per POST (php://input stream is consumed on first read)
$requestJsonBody = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode($rawBody, true);
    $requestJsonBody = is_array($decoded) ? $decoded : [];

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $token = $token ?? ($requestJsonBody['csrf_token'] ?? null);

    if (!$token || !Security::verifyCSRFToken($token)) {
        jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
    }
}

$db = Database::getInstance($config['database']);
$user = [
    'id' => AuthMiddleware::getUserId(),
    'email' => $_SESSION['user_email'] ?? null,
    'role' => AuthMiddleware::getRole(),
    'organization_id' => AuthMiddleware::getOrganizationId()
];
$action = get('action', '');

// Log for debugging (remove in production)
if (empty($action)) {
    error_log("Settings API: No action parameter provided. GET params: " . json_encode($_GET));
}

$invalidateOrgSettingsCache = static function (int $orgId): void {
    Cache::delete('org_settings_' . $orgId);
};

// GET organization
if ($action === 'get_organization') {
    $orgId = (int)($user['organization_id'] ?? 1);
    $org = Cache::remember('org_settings_' . $orgId, function () use ($db, $orgId) {
        return $db->queryOne("SELECT * FROM organizations WHERE id = ?", [$orgId]);
    }, 1800);

    if ($org) {
        if (isset($org['logo_path']) && $org['logo_path'] !== null && $org['logo_path'] !== '') {
            $appUrl = rtrim($config['app']['url'] ?? '', '/');
            if (strpos($org['logo_path'], 'http') === 0) {
                $org['logo_url'] = $org['logo_path'];
            } else {
                // logo_path is stored as e.g. "uploads/organizations/1/logo.jpg"
                // The file lives inside public/, so prefix with /public/
                $logoRelative = ltrim($org['logo_path'], '/');
                if (strpos($logoRelative, 'public/') !== 0) {
                    $logoRelative = 'public/' . $logoRelative;
                }
                $org['logo_url'] = $appUrl . '/' . $logoRelative;
            }
        } elseif (!isset($org['logo_url'])) {
            $org['logo_url'] = null;
        }

        // Decrypt sensitive fields for display (only if they exist)
        if (isset($org['smtp_api_key_encrypted']) && !empty($org['smtp_api_key_encrypted'])) {
            $org['smtp_api_key'] = '***';
        } elseif (isset($org['smtp_api_key']) && !empty($org['smtp_api_key'])) {
            $org['smtp_api_key'] = base64_decode($org['smtp_api_key'], true) ? '***' : '';
        }

        // Indicate Stripe secret exists (for display) - don't send real key
        if (!empty($org['stripe_secret_key_encrypted'])) {
            $org['stripe_secret_key'] = '***';
        } elseif (isset($org['stripe_secret_key']) && !empty($org['stripe_secret_key'])) {
            $org['stripe_secret_key'] = base64_decode($org['stripe_secret_key'], true) ? '***' : '';
        }

        // API key is hashed at rest — expose only whether one is configured
        $org['api_key'] = null;
        $org['api_key_configured'] = OrganizationApiKeyService::hasApiKey($db, (int) ($org['id'] ?? 0));
        unset($org['api_key_hash'], $org['api_key_prefix']);
    }

    jsonResponse(['success' => true, 'organization' => $org ?: []]);
}

$organizationId = (int)($user['organization_id'] ?? 1);

// GET email automation settings (for Admin > Email page)
if ($action === 'get_email_automation') {
    $defaults = [
        'email_reminders_enabled' => true,
        'reminder_1week' => true,
        'reminder_1day' => true,
        'reminder_2hours' => false,
        'custom_schedule' => []
    ];
    try {
        $cols = $db->query("SHOW COLUMNS FROM organizations LIKE 'email_reminders_enabled'");
        if (empty($cols)) {
            jsonResponse(['success' => true, 'automation' => $defaults]);
        }
        $hasCustomCol = $db->query("SHOW COLUMNS FROM organizations LIKE 'reminder_custom_schedule'");
        $selectCustom = !empty($hasCustomCol) ? ', reminder_custom_schedule' : '';
        $org = $db->queryOne(
            "SELECT email_reminders_enabled, reminder_1week, reminder_1day, reminder_2hours" . $selectCustom . " FROM organizations WHERE id = ?",
            [$organizationId]
        );
        if (!$org) {
            jsonResponse(['success' => true, 'automation' => $defaults]);
        }
        $custom = [];
        if (!empty($org['reminder_custom_schedule'])) {
            $decoded = is_string($org['reminder_custom_schedule']) ? json_decode($org['reminder_custom_schedule'], true) : $org['reminder_custom_schedule'];
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (isset($row['value'], $row['unit']) && in_array($row['unit'], ['days', 'hours'], true)) {
                        $custom[] = ['value' => (int) $row['value'], 'unit' => $row['unit']];
                    }
                }
            }
        }
        jsonResponse(['success' => true, 'automation' => [
            'email_reminders_enabled' => (bool)($org['email_reminders_enabled'] ?? true),
            'reminder_1week' => (bool)($org['reminder_1week'] ?? true),
            'reminder_1day' => (bool)($org['reminder_1day'] ?? true),
            'reminder_2hours' => (bool)($org['reminder_2hours'] ?? false),
            'custom_schedule' => $custom
        ]]);
    } catch (\Exception $e) {
        jsonResponse(['success' => true, 'automation' => $defaults]);
    }
}

// UPDATE email automation settings (for Admin > Email page)
if ($action === 'update_email_automation' && isPost()) {
    $input = $requestJsonBody;
    if (!$input || !isset($input['email_reminders_enabled'])) {
        jsonResponse(['success' => false, 'message' => 'Invalid input'], 400);
    }
    try {
        $cols = $db->query("SHOW COLUMNS FROM organizations LIKE 'email_reminders_enabled'");
        if (empty($cols)) {
            jsonResponse(['success' => false, 'message' => 'Email automation columns not installed. Run migration 021_add_email_automation_to_organizations.sql'], 400);
        }
        $customSchedule = [];
        if (isset($input['custom_schedule']) && is_array($input['custom_schedule'])) {
            foreach ($input['custom_schedule'] as $row) {
                if (isset($row['value'], $row['unit']) && in_array($row['unit'], ['days', 'hours'], true)) {
                    $v = (int) $row['value'];
                    if ($v >= 1 && ($row['unit'] === 'hours' ? $v <= 720 : $v <= 365)) {
                        $customSchedule[] = ['value' => $v, 'unit' => $row['unit']];
                    }
                }
            }
        }
        $customJson = json_encode($customSchedule);
        $hasCustomCol = $db->query("SHOW COLUMNS FROM organizations LIKE 'reminder_custom_schedule'");
        if (!empty($hasCustomCol)) {
            $db->execute(
                "UPDATE organizations SET email_reminders_enabled = ?, reminder_1week = ?, reminder_1day = ?, reminder_2hours = ?, reminder_custom_schedule = ? WHERE id = ?",
                [
                    !empty($input['email_reminders_enabled']) ? 1 : 0,
                    !empty($input['reminder_1week']) ? 1 : 0,
                    !empty($input['reminder_1day']) ? 1 : 0,
                    !empty($input['reminder_2hours']) ? 1 : 0,
                    $customJson,
                    $organizationId
                ]
            );
        } else {
            $db->execute(
                "UPDATE organizations SET email_reminders_enabled = ?, reminder_1week = ?, reminder_1day = ?, reminder_2hours = ? WHERE id = ?",
                [
                    !empty($input['email_reminders_enabled']) ? 1 : 0,
                    !empty($input['reminder_1week']) ? 1 : 0,
                    !empty($input['reminder_1day']) ? 1 : 0,
                    !empty($input['reminder_2hours']) ? 1 : 0,
                    $organizationId
                ]
            );
        }
        jsonResponse(['success' => true, 'message' => 'Automation settings saved']);
    } catch (\Exception $e) {
        error_log("Update email automation error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to save: ' . $e->getMessage()], 500);
    }
}

// UPDATE organization
if ($action === 'update_organization' && isPost()) {
    $input = $requestJsonBody;

    if (!is_array($input) || empty($input)) {
        jsonResponse(['success' => false, 'message' => 'Invalid input data'], 400);
    }
    
    try {
        // Check which logo column exists in the database
        $columns = $db->query("SHOW COLUMNS FROM organizations LIKE 'logo%'");
        $logoColumn = 'logo_path'; // Default
        foreach ($columns as $col) {
            if (isset($col['Field'])) {
                $logoColumn = $col['Field'];
                break;
            }
        }

        $orgId = (int)($user['organization_id'] ?? 1);
        $allCols = array_column($db->query("SHOW COLUMNS FROM organizations"), 'Field');
        
        // Build update query dynamically based on available columns
        $updates = [];
        $params = [];
        
        if (isset($input['name'])) {
            $updates[] = "name = ?";
            $params[] = $input['name'];
        }
        
        $logoValue = $input['logo_url'] ?? $input['logo_path'] ?? null;
        if ($logoValue !== null) {
            $updates[] = "$logoColumn = ?";
            $params[] = $logoValue ?: null;
        }
        
        if (isset($input['primary_color'])) {
            $updates[] = "primary_color = ?";
            $params[] = $input['primary_color'];
        }
        
        if (isset($input['timezone']) && in_array('timezone', $allCols, true)) {
            $updates[] = "timezone = ?";
            $params[] = $input['timezone'];
        }
        if (array_key_exists('city', $input) && in_array('city', $allCols, true)) {
            $updates[] = 'city = ?';
            $params[] = trim((string) $input['city']) ?: null;
        }
        if (array_key_exists('country', $input) && in_array('country', $allCols, true)) {
            $updates[] = 'country = ?';
            $params[] = trim((string) $input['country']) ?: null;
        }
        if (array_key_exists('coordinators_can_refund', $input) && in_array('coordinators_can_refund', $allCols, true)) {
            $updates[] = "coordinators_can_refund = ?";
            $params[] = !empty($input['coordinators_can_refund']) ? 1 : 0;
        }
        if (array_key_exists('coordinators_can_correct_checkins', $input) && in_array('coordinators_can_correct_checkins', $allCols, true)) {
            $updates[] = 'coordinators_can_correct_checkins = ?';
            $params[] = !empty($input['coordinators_can_correct_checkins']) ? 1 : 0;
        }
        if (array_key_exists('refund_request_days_after_event', $input) && in_array('refund_request_days_after_event', $allCols, true)) {
            $updates[] = "refund_request_days_after_event = ?";
            $val = isset($input['refund_request_days_after_event']) ? $input['refund_request_days_after_event'] : null;
            $params[] = ($val === '' || $val === null) ? null : (int)$val;
        }
        if (array_key_exists('rsvp_waiver_enabled', $input) && in_array('rsvp_waiver_enabled', $allCols, true)) {
            $updates[] = 'rsvp_waiver_enabled = ?';
            $params[] = !empty($input['rsvp_waiver_enabled']) ? 1 : 0;
        }
        if (array_key_exists('rsvp_waiver_checkbox_label', $input) && in_array('rsvp_waiver_checkbox_label', $allCols, true)) {
            $updates[] = 'rsvp_waiver_checkbox_label = ?';
            $params[] = substr(trim((string) ($input['rsvp_waiver_checkbox_label'] ?? '')), 0, 500) ?: 'I agree to the liability waiver and release';
        }
        if (array_key_exists('rsvp_waiver_full_text', $input) && in_array('rsvp_waiver_full_text', $allCols, true)) {
            $updates[] = 'rsvp_waiver_full_text = ?';
            $params[] = trim((string) ($input['rsvp_waiver_full_text'] ?? '')) ?: null;
        }
        
        if (empty($updates)) {
            jsonResponse(['success' => false, 'message' => 'No fields to update'], 400);
        }
        
        $sql = "UPDATE organizations SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $orgId;
        $db->execute($sql, $params);
        
        $invalidateOrgSettingsCache($organizationId);
        jsonResponse(['success' => true, 'message' => 'Organization updated successfully']);
    } catch (\Exception $e) {
        error_log("Update organization error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        jsonResponse(['success' => false, 'message' => 'Failed to update: ' . $e->getMessage()], 500);
    }
}

// UPLOAD LOGO (Organization Branding) - POST multipart with file + csrf_token
if ($action === 'upload_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$token || !Security::verifyCSRFToken($token)) {
        jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
    }
    $orgId = (int)$organizationId;
    if ($orgId < 1) {
        jsonResponse(['success' => false, 'message' => 'Invalid organization'], 400);
    }
    if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => 'No file uploaded or upload error'], 400);
    }
    $file = $_FILES['logo'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'svg'];
    if (!in_array($ext, $allowed, true)) {
        jsonResponse(['success' => false, 'message' => 'Allowed formats: PNG, JPG, SVG'], 400);
    }
    $maxBytes = 2 * 1024 * 1024; // 2MB
    if ($file['size'] > $maxBytes) {
        jsonResponse(['success' => false, 'message' => 'File must be 2MB or less'], 400);
    }
    $baseDir = defined('PUBLIC_PATH') ? PUBLIC_PATH : (__DIR__ . '/..');
    $uploadDir = $baseDir . '/uploads/organizations/' . $orgId;
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0755, true)) {
            jsonResponse(['success' => false, 'message' => 'Could not create upload directory'], 500);
        }
    }
    $filename = 'logo.' . $ext;
    $path = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        jsonResponse(['success' => false, 'message' => 'Failed to save file'], 500);
    }
    $relativePath = 'uploads/organizations/' . $orgId . '/' . $filename;
    $columns = $db->query("SHOW COLUMNS FROM organizations LIKE 'logo%'");
    $logoColumn = 'logo_path';
    foreach ($columns as $col) {
        if (isset($col['Field'])) {
            $logoColumn = $col['Field'];
            break;
        }
    }
    $db->execute("UPDATE organizations SET $logoColumn = ? WHERE id = ?", [$relativePath, $orgId]);
    $appUrl = rtrim($config['app']['url'] ?? '', '/');
    $logoUrl = $appUrl . '/public/uploads/organizations/' . $orgId . '/' . $filename;
    jsonResponse(['success' => true, 'message' => 'Logo uploaded', 'logo_url' => $logoUrl, 'logo_path' => $relativePath]);
}

// REMOVE LOGO
if ($action === 'remove_logo' && isPost()) {
    $input = $requestJsonBody;
    $orgId = (int)$organizationId;
    $columns = $db->query("SHOW COLUMNS FROM organizations LIKE 'logo%'");
    $logoColumn = 'logo_path';
    foreach ($columns as $col) {
        if (isset($col['Field'])) {
            $logoColumn = $col['Field'];
            break;
        }
    }
    $db->execute("UPDATE organizations SET $logoColumn = NULL WHERE id = ?", [$orgId]);
    $baseDir = defined('PUBLIC_PATH') ? PUBLIC_PATH : (__DIR__ . '/..');
    $uploadDir = $baseDir . '/uploads/organizations/' . $orgId;
    foreach (['logo.png', 'logo.jpg', 'logo.jpeg', 'logo.svg'] as $f) {
        if (file_exists($uploadDir . '/' . $f)) {
            @unlink($uploadDir . '/' . $f);
        }
    }
    jsonResponse(['success' => true, 'message' => 'Logo removed']);
}

// UPDATE stripe
if ($action === 'update_stripe' && isPost()) {
    $input = $requestJsonBody;
    $organizationId = (int)($user['organization_id'] ?? 1);

    try {
        // Build update: always allow publishable_key; only update secret if a real key was provided (not masked placeholder)
        $secretProvided = isset($input['secret_key']) && is_string($input['secret_key'])
            && strlen(trim($input['secret_key'])) > 20
            && (strpos($input['secret_key'], 'sk_live_') === 0 || strpos($input['secret_key'], 'sk_test_') === 0)
            && strpos($input['secret_key'], '****') === false;

        $encryptionKey = null;
        if ($secretProvided) {
            $key = $config['security']['encryption_key'] ?? null;
            if (empty($key)) {
                $dbName = $config['database']['name'] ?? 'headcount_dev';
                $key = hash('sha256', ($config['app']['name'] ?? '') . $dbName);
            }
            if (strlen($key) < 32) {
                $key = hash('sha256', $key . 'headcount_salt');
            }
            $encryptionKey = substr($key, 0, 32);
        }

        if ($secretProvided && $encryptionKey) {
            $encryptedSecret = Security::encrypt(trim($input['secret_key']), $encryptionKey);
            $sql = "UPDATE organizations SET stripe_publishable_key = ?, stripe_secret_key_encrypted = ? WHERE id = ?";
            $db->execute($sql, [$input['publishable_key'] ?? '', $encryptedSecret, $organizationId]);
        } else {
            $sql = "UPDATE organizations SET stripe_publishable_key = ? WHERE id = ?";
            $db->execute($sql, [$input['publishable_key'] ?? '', $organizationId]);
        }

        jsonResponse(['success' => true, 'message' => 'Stripe configuration updated successfully']);
    } catch (\Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to update: ' . $e->getMessage()], 500);
    }
}

// UPDATE email
if ($action === 'update_email' && isPost()) {
    $input = $requestJsonBody;
    
    try {
        // Simple encryption
        $encryptedKey = base64_encode($input['api_key']);
        
        $organizationId = (int)(AuthMiddleware::getOrganizationId() ?: 1);

        $sql = "UPDATE organizations SET 
                smtp_api_key = ?, 
                smtp_from_email = ?,
                smtp_from_name = ?
                WHERE id = ?";
        
        $db->execute($sql, [
            $encryptedKey,
            $input['from_email'],
            $input['from_name'],
            $organizationId
        ]);
        
        jsonResponse(['success' => true, 'message' => 'Email configuration updated successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to update: ' . $e->getMessage()], 500);
    }
}

// ADD category
if ($action === 'add_category' && isPost()) {
    $input = $requestJsonBody;
    
    try {
        // Get organization ID
        $organizationId = AuthMiddleware::getOrganizationId() ?: 1;
        
        // Generate slug from name
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $input['name'])));
        $slug = preg_replace('/-+/', '-', $slug); // Replace multiple dashes with single
        $slug = trim($slug, '-'); // Remove leading/trailing dashes
        
        // Check for duplicate name in same organization
        $existing = $db->queryOne("SELECT id FROM categories WHERE name = ? AND organization_id = ?", [$input['name'], $organizationId]);
        if ($existing) {
            jsonResponse(['success' => false, 'message' => 'Category already exists'], 400);
        }
        
        // Check for duplicate slug in same organization
        $existingSlug = $db->queryOne("SELECT id FROM categories WHERE slug = ? AND organization_id = ?", [$slug, $organizationId]);
        if ($existingSlug) {
            // Append number if slug exists
            $counter = 1;
            $originalSlug = $slug;
            while ($existingSlug) {
                $slug = $originalSlug . '-' . $counter;
                $existingSlug = $db->queryOne("SELECT id FROM categories WHERE slug = ? AND organization_id = ?", [$slug, $organizationId]);
                $counter++;
            }
        }
        
        // Insert category with required fields
        $db->insert('categories', [
            'organization_id' => $organizationId,
            'name' => $input['name'],
            'slug' => $slug,
            'is_active' => 1
        ]);
        
        jsonResponse(['success' => true, 'message' => 'Category added successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to add category: ' . $e->getMessage()], 500);
    }
}

// DELETE category
if ($action === 'delete_category' && isPost()) {
    $input = $requestJsonBody;
    
    try {
        // Get organization ID to ensure user can only delete their own categories
        $organizationId = AuthMiddleware::getOrganizationId() ?: 1;
        
        // Check if category exists and belongs to the organization
        $category = $db->queryOne("SELECT id FROM categories WHERE id = ? AND organization_id = ?", [$input['id'], $organizationId]);
        if (!$category) {
            jsonResponse(['success' => false, 'message' => 'Category not found'], 404);
        }
        
        $db->execute("DELETE FROM categories WHERE id = ? AND organization_id = ?", [$input['id'], $organizationId]);
        jsonResponse(['success' => true, 'message' => 'Category deleted successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to delete category: ' . $e->getMessage()], 500);
    }
}

// ADD admin
if ($action === 'add_admin' && isPost()) {
    $input = $requestJsonBody;
    
    try {
        if (!$input) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON data'], 400);
        }

        $firstName = isset($input['first_name']) ? trim((string) $input['first_name']) : '';
        $lastName = isset($input['last_name']) ? trim((string) $input['last_name']) : '';
        $email = isset($input['email']) ? trim(strtolower((string) $input['email'])) : '';
        $password = $input['password'] ?? '';

        if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
            jsonResponse(['success' => false, 'message' => 'First name, last name, email, and password are required.'], 400);
        }

        if (!Validator::email($email)) {
            jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.'], 400);
        }

        $passwordErrors = Security::validatePassword($password);
        if (!empty($passwordErrors)) {
            jsonResponse(['success' => false, 'message' => implode(' ', $passwordErrors)], 400);
        }

        // Get organization ID from current user
        $organizationId = AuthMiddleware::getOrganizationId() ?: $user['organization_id'] ?: 1;
        
        // Check for duplicate email within the same organization
        $existing = $db->queryOne("SELECT id FROM users WHERE email = ? AND organization_id = ?", [$email, $organizationId]);
        if ($existing) {
            jsonResponse(['success' => false, 'message' => 'Email already exists'], 400);
        }
        
        $passwordHash = Auth::hashPassword($password);
        
        $db->insert('users', [
            'organization_id' => $organizationId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => 'admin',
            'status' => 'active'
        ]);
        
        jsonResponse(['success' => true, 'message' => 'Administrator added successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to add admin: ' . $e->getMessage()], 500);
    }
}

// DELETE admin
if ($action === 'delete_admin' && isPost()) {
    $input = $requestJsonBody;
    
    try {
        // Don't allow deleting yourself
        if ($input['id'] == $user['id']) {
            jsonResponse(['success' => false, 'message' => 'Cannot delete your own account'], 400);
        }
        
        // Don't allow deleting the last admin
        $adminCount = $db->queryOne("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")['count'];
        if ($adminCount <= 1) {
            jsonResponse(['success' => false, 'message' => 'Cannot delete the last administrator'], 400);
        }
        
        $db->execute("DELETE FROM users WHERE id = ? AND role = 'admin'", [$input['id']]);
        jsonResponse(['success' => true, 'message' => 'Administrator removed successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to remove admin: ' . $e->getMessage()], 500);
    }
}

// ADD coordinator (admin only)
if ($action === 'add_coordinator' && isPost()) {
    $input = $requestJsonBody;
    
    try {
        if (!$input) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON data'], 400);
        }

        $firstName = isset($input['first_name']) ? trim((string) $input['first_name']) : '';
        $lastName = isset($input['last_name']) ? trim((string) $input['last_name']) : '';
        $email = isset($input['email']) ? trim(strtolower((string) $input['email'])) : '';
        $password = $input['password'] ?? '';

        if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
            jsonResponse(['success' => false, 'message' => 'First name, last name, email, and password are required.'], 400);
        }

        if (!Validator::email($email)) {
            jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.'], 400);
        }

        $passwordErrors = Security::validatePassword($password);
        if (!empty($passwordErrors)) {
            jsonResponse(['success' => false, 'message' => implode(' ', $passwordErrors)], 400);
        }

        $organizationId = AuthMiddleware::getOrganizationId() ?: $user['organization_id'] ?: 1;
        
        $existing = $db->queryOne("SELECT id FROM users WHERE email = ? AND organization_id = ?", [$email, $organizationId]);
        if ($existing) {
            jsonResponse(['success' => false, 'message' => 'A user with this email already exists in your organization'], 400);
        }
        
        $passwordHash = Auth::hashPassword($password);
        
        $db->insert('users', [
            'organization_id' => $organizationId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => 'coordinator',
            'status' => 'active'
        ]);
        
        jsonResponse(['success' => true, 'message' => 'Coordinator added successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to add coordinator: ' . $e->getMessage()], 500);
    }
}

// DELETE coordinator (admin only)
if ($action === 'delete_coordinator' && isPost()) {
    $input = $requestJsonBody;
    
    try {
        $organizationId = AuthMiddleware::getOrganizationId() ?: $user['organization_id'] ?: 1;
        
        $target = $db->queryOne("SELECT id, role FROM users WHERE id = ? AND organization_id = ?", [$input['id'], $organizationId]);
        if (!$target || $target['role'] !== 'coordinator') {
            jsonResponse(['success' => false, 'message' => 'Coordinator not found'], 404);
        }
        
        $db->execute("DELETE FROM users WHERE id = ? AND role = 'coordinator'", [$input['id']]);
        jsonResponse(['success' => true, 'message' => 'Coordinator removed successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to remove coordinator: ' . $e->getMessage()], 500);
    }
}

// PROMOTE coordinator to administrator (admin only)
if ($action === 'promote_coordinator_to_admin' && isPost()) {
    $input = $requestJsonBody;

    try {
        $organizationId = (int)(AuthMiddleware::getOrganizationId() ?: $user['organization_id'] ?: 0);
        $targetId = isset($input['id']) ? (int)$input['id'] : 0;
        if (!$targetId) {
            jsonResponse(['success' => false, 'message' => 'User ID required'], 400);
        }

        $target = $db->queryOne(
            'SELECT id, role FROM users WHERE id = ? AND organization_id = ?',
            [$targetId, $organizationId]
        );
        if (!$target || ($target['role'] ?? '') !== 'coordinator') {
            jsonResponse(['success' => false, 'message' => 'Coordinator not found'], 404);
        }

        $db->execute(
            "UPDATE users SET role = 'admin' WHERE id = ? AND organization_id = ? AND role = 'coordinator'",
            [$targetId, $organizationId]
        );

        jsonResponse(['success' => true, 'message' => 'This user is now an administrator. They can manage organization settings, events, and members.']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to promote user: ' . $e->getMessage()], 500);
    }
}

// CHANGE password
if ($action === 'change_password' && isPost()) {
    $input = $requestJsonBody;
    
    try {
        // Verify current password
        $currentUser = $db->queryOne("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
        if (!password_verify($input['current'], $currentUser['password_hash'])) {
            jsonResponse(['success' => false, 'message' => 'Current password is incorrect'], 400);
        }
        
        $newPasswordHash = Auth::hashPassword($input['new']);
        $db->execute("UPDATE users SET password_hash = ? WHERE id = ?", [$newPasswordHash, $user['id']]);
        
        jsonResponse(['success' => true, 'message' => 'Password changed successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to change password: ' . $e->getMessage()], 500);
    }
}

// SEND test email
if ($action === 'send_test_email' && isPost()) {
    try {
        $organizationId = (int)(AuthMiddleware::getOrganizationId() ?: 1);

        $org = $db->queryOne(
            "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
            [$organizationId]
        );

        $apiKey = null;
        if (!empty($org['smtp_api_key'])) {
            $decoded = base64_decode($org['smtp_api_key'], true);
            if ($decoded !== false && $decoded !== '') {
                $apiKey = $decoded;
            }
        }
        if (($apiKey === null || $apiKey === '') && !empty($org['smtp_api_key_encrypted'])) {
            $encKey = $config['security']['encryption_key'] ?? null;
            if ($encKey) {
                $decrypted = Security::decrypt($org['smtp_api_key_encrypted'], $encKey);
                if ($decrypted !== false && $decrypted !== '') {
                    $apiKey = $decrypted;
                }
            }
        }

        if (!$org || empty($org['smtp_from_email']) || $apiKey === null || $apiKey === '') {
            jsonResponse(['success' => false, 'message' => 'Email is not configured. Save your SMTP2GO settings in Settings → Email first.'], 400);
        }

        // Get current user's email address
        $userEmail = $user['email'] ?? null;
        if (!$userEmail) {
            // Fallback: get email from database
            $userData = $db->queryOne("SELECT email FROM users WHERE id = ?", [$user['id']]);
            $userEmail = $userData['email'] ?? null;
        }
        
        if (!$userEmail) {
            jsonResponse(['success' => false, 'message' => 'Your email address is not set. Please update your profile.'], 400);
        }
        
        // Use SMTP2GO service
        require_once __DIR__ . '/../../src/Integrations/SMTP2GOService.php';
        $smtpService = new \Headcount\Integrations\SMTP2GOService(
            $apiKey,
            $org['smtp_from_email'],
            $org['smtp_from_name'] ?? null,
            $org['smtp_reply_to'] ?? null
        );
        
        // Send test email to the logged-in user's email address
        try {
            $result = $smtpService->sendEmail(
                $userEmail,
                'SMTP2GO Test Email - Headcount Events',
                '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
                    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                        <h2 style="color: #3B82F6;">Test Email from Headcount Events</h2>
                        <p>This is a test email to verify your SMTP configuration is working correctly.</p>
                        <p>If you received this email, your email settings are configured properly!</p>
                        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
                        <p style="color: #666; font-size: 12px;">Sent from: ' . htmlspecialchars($org['smtp_from_email']) . '</p>
                    </div>
                </body></html>'
            );
            
            jsonResponse(['success' => true, 'message' => 'SMTP2GO accepted the test message for ' . htmlspecialchars($userEmail) . '. Check your inbox shortly; if the SMTP2GO dashboard shows Processing, wait or verify domain and sender settings there.']);
        } catch (\Exception $emailException) {
            error_log("SMTP2GO send error: " . $emailException->getMessage());
            jsonResponse(['success' => false, 'message' => 'Failed to send email: ' . $emailException->getMessage()], 400);
        }
    } catch (Exception $e) {
        error_log("Send test email error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        jsonResponse(['success' => false, 'message' => 'Failed to send test email: ' . $e->getMessage()], 500);
    }
}

// DOWNLOAD backup
if ($action === 'download_backup') {
    try {
        $config = require __DIR__ . '/../../config/config.php';
        $dbConfig = $config['database'];
        
        // Try to use mysqldump if available
        $mysqldumpPath = '';
        $paths = ['mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'];
        
        foreach ($paths as $path) {
            if (is_executable($path) || (PHP_OS_FAMILY === 'Windows' && file_exists($path))) {
                $mysqldumpPath = $path;
                break;
            }
        }
        
        if ($mysqldumpPath) {
            // Use mysqldump for better backup
            $filename = 'headcount-backup-' . date('Y-m-d-His') . '.sql';
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            // Build mysqldump command
            $command = escapeshellarg($mysqldumpPath);
            $command .= ' -h ' . escapeshellarg($dbConfig['host']);
            $command .= ' -u ' . escapeshellarg($dbConfig['username']);
            if (!empty($dbConfig['password'])) {
                $command .= ' -p' . escapeshellarg($dbConfig['password']);
            }
            $command .= ' ' . escapeshellarg($dbConfig['name']);
            
            // Execute and stream output
            passthru($command);
            exit;
        } else {
            // Fallback: Generate SQL dump manually
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="headcount-backup-' . date('Y-m-d') . '.sql"');
            
            echo "-- Headcount Database Backup\n";
            echo "-- Date: " . date('Y-m-d H:i:s') . "\n";
            echo "-- Generated via web interface\n\n";
            echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
            
            // Get all tables
            $tables = $db->query("SHOW TABLES");
            $tableKey = 'Tables_in_' . $dbConfig['name'];
            
            foreach ($tables as $table) {
                $tableName = $table[$tableKey];
                
                // Skip system tables if needed
                if (in_array($tableName, ['migrations'])) {
                    continue;
                }
                
                echo "-- Table: $tableName\n";
                echo "DROP TABLE IF EXISTS `$tableName`;\n";
                
                // Get CREATE TABLE statement
                $createTable = $db->queryOne("SHOW CREATE TABLE `$tableName`");
                if ($createTable) {
                    echo $createTable['Create Table'] . ";\n\n";
                }
                
                // Get table data
                $rows = $db->query("SELECT * FROM `$tableName`");
                if (!empty($rows)) {
                    echo "INSERT INTO `$tableName` VALUES\n";
                    $values = [];
                    foreach ($rows as $row) {
                        $rowValues = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $rowValues[] = 'NULL';
                            } else {
                                $rowValues[] = "'" . addslashes($value) . "'";
                            }
                        }
                        $values[] = '(' . implode(',', $rowValues) . ')';
                    }
                    echo implode(",\n", $values) . ";\n\n";
                }
            }
            
            echo "SET FOREIGN_KEY_CHECKS=1;\n";
            exit;
        }
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to create backup: ' . $e->getMessage()], 500);
    }
}

// CLEAR data
if ($action === 'clear_data' && isPost()) {
    try {
        $db->execute("DELETE FROM attendance");
        $db->execute("DELETE FROM events");
        $db->execute("DELETE FROM email_logs");
        
        jsonResponse(['success' => true, 'message' => 'All event data cleared successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to clear data: ' . $e->getMessage()], 500);
    }
}

// GENERATE API KEY
if ($action === 'generate_api_key' && isPost()) {
    try {
        AuthMiddleware::requireAdmin();
        CsrfMiddleware::verify();
        $orgId = (int) AuthMiddleware::getOrganizationId();
        if ($orgId < 1) {
            jsonResponse(['success' => false, 'message' => 'Invalid organization'], 400);
        }

        $apiKey = OrganizationApiKeyService::generateKey();
        OrganizationApiKeyService::storeKey($db, $orgId, $apiKey);
        if ($db->hasColumn('organizations', 'api_key')) {
            $db->execute('UPDATE organizations SET api_key = NULL WHERE id = ?', [$orgId]);
        }

        jsonResponse(['success' => true, 'api_key' => $apiKey, 'message' => 'API key generated successfully. Copy it now — it will not be shown again.']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Failed to generate API key: ' . $e->getMessage()], 500);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
