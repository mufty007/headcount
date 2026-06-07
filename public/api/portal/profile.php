<?php

/**
 * Portal Profile API
 * Handles profile management for members (requires authentication)
 */

// Start output buffering to prevent any accidental output
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Disable error display, we'll handle errors ourselves
ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Log that we're starting
error_log("Profile API - Starting request: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . " " . ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN'));

// Set JSON header early (but check if headers already sent)
if (!headers_sent()) {
    header('Content-Type: application/json');
} else {
    error_log("Profile API - WARNING: Headers already sent!");
}

// Set error handler to catch any errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $msg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($msg);
    if (!(error_reporting() & $errno)) return false;
    
    // Only handle fatal errors, let warnings/notices pass through
    if (!in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        return false;
    }
    
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Internal Server Error']);
    exit;
}, E_ALL & ~E_NOTICE & ~E_WARNING);

// Set exception handler
set_exception_handler(function($exception) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $exception->getMessage()
    ]);
    error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
    exit;
});

// Set shutdown function to catch fatal errors (will be registered after config is loaded)

require_once __DIR__ . '/../../../vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Validator;

// Load config
$configFile = __DIR__ . '/../../../config/config.php';
if (!file_exists($configFile)) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}

$config = require $configFile;

// Register shutdown function now that config is available
register_shutdown_function(function() use ($config) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        try {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
            }
            $errorMsg = 'Fatal Server Error';
            if (isset($config['app']['debug']) && $config['app']['debug']) {
                $errorMsg .= ': ' . $error['message'];
            }
            echo json_encode([
                'success' => false,
                'message' => $errorMsg
            ]);
            error_log("Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
        } catch (\Throwable $e) {
            // If error handler fails, just log it
            error_log("Error in shutdown handler: " . $e->getMessage());
        }
    }
});

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    error_log("Profile API - Database initialization failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database initialization failed']);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

// Check authentication (for API, don't redirect - return JSON error)
try {
    if (!PortalAuthMiddleware::isAuthenticated()) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required'
        ]);
        exit;
    }
} catch (\Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(401);
    error_log("Profile API - Authentication check failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Authentication check failed'
    ]);
    exit;
}

$memberId = PortalAuthMiddleware::getMemberId();
$organizationId = PortalAuthMiddleware::getOrganizationId();

if (!$memberId) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid session'
    ]);
    exit;
}

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

error_log("Profile API - Method: $method, URI: $requestUri, Path: $path");

// Extract action from path
// First, try to use segments passed from the router (more reliable)
$action = '';
if (isset($GLOBALS['portal_api_segments']) && is_array($GLOBALS['portal_api_segments'])) {
    error_log("Profile API - Using router segments: " . print_r($GLOBALS['portal_api_segments'], true));
    // Segments will be ['profile', 'photo'] for /api/portal/profile/photo
    // We want the segment after 'profile', which is index 1
    if (isset($GLOBALS['portal_api_segments'][1])) {
        $action = $GLOBALS['portal_api_segments'][1];
    }
    // If no second segment, action remains empty (base endpoint)
}

// Fallback: parse REQUEST_URI if segments not available
if (empty($action)) {
    $pathSegments = explode('/', trim($path, '/'));
    $profilePos = array_search('profile', $pathSegments);
    if ($profilePos !== false && isset($pathSegments[$profilePos + 1])) {
        $action = $pathSegments[$profilePos + 1];
    }
}

error_log("Profile API - Extracted action: '$action'");

// Get input data (when routed from index.php, $data is already set and php://input is consumed)
if (!isset($input)) {
    $input = json_decode(@file_get_contents('php://input'), true) ?? [];
}
if (!isset($data)) {
    $data = array_merge($_POST, $input);
}

$db = Database::getInstance();

try {
    // GET /api/portal/profile - Get member profile
    if (empty($action) && $method === 'GET') {
        error_log("Profile API - Processing GET request for member ID: " . $memberId);
        try {
            error_log("Profile API - Fetching profile for member ID: " . $memberId);
            
            $member = null;
            $fullQuery = "SELECT id, first_name, last_name, email, phone, gender, date_of_birth, 
                        profile_photo_path, email_preferences, communication_preferences
                 FROM users 
                 WHERE id = :id AND role = 'member' AND status = 'active'";
            try {
                $member = $db->queryOne($fullQuery, ['id' => $memberId]);
            } catch (\Exception $e) {
                // Fallback if migrations 006/009 not run (missing date_of_birth, profile_photo_path, etc.)
                if (strpos($e->getMessage(), 'Unknown column') !== false) {
                    error_log("Profile API - Full profile columns missing, using base columns: " . $e->getMessage());
                    $member = $db->queryOne(
                        "SELECT id, first_name, last_name, email, phone, gender
                         FROM users 
                         WHERE id = :id AND role = 'member' AND status = 'active'",
                        ['id' => $memberId]
                    );
                    if ($member !== false) {
                        $member['date_of_birth'] = null;
                        $member['profile_photo_path'] = null;
                        $member['email_preferences'] = null;
                        $member['communication_preferences'] = null;
                    }
                } else {
                    throw $e;
                }
            }

            if (!$member) {
                while (ob_get_level() > 0) ob_end_clean();
                error_log("Profile API - Member not found for ID: " . $memberId);
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Member not found']);
                exit;
            }
            
            error_log("Profile API - Member found: " . json_encode(array_keys($member)));

            // Decode JSON preferences
            if (!empty($member['email_preferences'])) {
                $decoded = json_decode($member['email_preferences'], true);
                $member['email_preferences'] = $decoded !== null ? $decoded : [
                    'event_announcements' => true,
                    'event_reminders' => true,
                    'rsvp_confirmations' => true,
                    'payment_receipts' => true
                ];
            } else {
                $member['email_preferences'] = [
                    'event_announcements' => true,
                    'event_reminders' => true,
                    'rsvp_confirmations' => true,
                    'payment_receipts' => true
                ];
            }

            if (!empty($member['communication_preferences'])) {
                $decoded = json_decode($member['communication_preferences'], true);
                $member['communication_preferences'] = $decoded !== null ? $decoded : [
                    'email_enabled' => true,
                    'sms_enabled' => false
                ];
            } else {
                $member['communication_preferences'] = [
                    'email_enabled' => true,
                    'sms_enabled' => false
                ];
            }

            // Get tags and groups (if tables exist)
            $tags = [];
            $groups = [];
            
            try {
                $tags = $db->query(
                    "SELECT t.id, t.name, t.color 
                     FROM tags t
                     JOIN member_tags mt ON t.id = mt.tag_id
                     WHERE mt.user_id = :user_id",
                    ['user_id' => $memberId]
                );
            } catch (\Exception $e) {
                // Tags table might not exist - ignore error
                error_log("Profile API - Tags query failed (non-critical): " . $e->getMessage());
            }

            try {
                $groups = $db->query(
                    "SELECT mg.id, mg.name, mg.description
                     FROM member_groups mg
                     JOIN group_members gm ON mg.id = gm.group_id
                     WHERE gm.user_id = :user_id",
                    ['user_id' => $memberId]
                );
            } catch (\Exception $e) {
                // Groups table might not exist - ignore error
                error_log("Profile API - Groups query failed (non-critical): " . $e->getMessage());
            }

            $member['tags'] = $tags;
            $member['groups'] = $groups;

            error_log("Profile API - Successfully retrieved profile data");
            
            // Clean output buffer and send response
            while (ob_get_level() > 0) {
                $output = ob_get_clean();
                if (!empty($output) && $output !== false) {
                    error_log("Profile API - WARNING: Output buffer contained: " . substr($output, 0, 100));
                }
            }
            
            // Ensure headers are set
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            
            $response = [
                'success' => true,
                'member' => $member
            ];
            
            $jsonResponse = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            
            if ($jsonResponse === false) {
                error_log("Profile API - JSON encoding failed: " . json_last_error_msg());
                // Strip non-encodable values and retry with minimal member data
                $safeMember = array_intersect_key($member, array_fill_keys(['id', 'first_name', 'last_name', 'email', 'phone', 'gender', 'date_of_birth', 'profile_photo_path', 'tags', 'groups'], true));
                foreach (['email_preferences', 'communication_preferences'] as $key) {
                    $safeMember[$key] = isset($member[$key]) && is_array($member[$key]) ? $member[$key] : [];
                }
                $jsonResponse = json_encode(['success' => true, 'member' => $safeMember], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($jsonResponse === false) {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Error encoding response']);
                } else {
                    echo $jsonResponse;
                }
            } else {
                echo $jsonResponse;
            }
            exit;
        } catch (\Exception $e) {
            while (ob_get_level() > 0) ob_end_clean();
            error_log("Profile API - Database query error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString());
            http_response_code(500);
            $errorMsg = 'Error retrieving profile data';
            if (isset($config['app']['debug']) && $config['app']['debug']) {
                $errorMsg .= ': ' . $e->getMessage();
            }
            echo json_encode([
                'success' => false,
                'message' => $errorMsg
            ]);
            exit;
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) ob_end_clean();
            error_log("Profile API - Fatal query error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString());
            http_response_code(500);
            $errorMsg = 'Fatal error retrieving profile data';
            if (isset($config['app']['debug']) && $config['app']['debug']) {
                $errorMsg .= ': ' . $e->getMessage();
            }
            echo json_encode([
                'success' => false,
                'message' => $errorMsg
            ]);
            exit;
        }
    }

    // PUT /api/portal/profile - Update profile
    if (empty($action) && $method === 'PUT') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        $errors = [];

        // Validate
        if (empty($data['first_name'])) {
            $errors[] = 'First name is required';
        }

        if (empty($data['last_name'])) {
            $errors[] = 'Last name is required';
        }

        if (empty($data['email'])) {
            $errors[] = 'Email is required';
        } elseif (!Validator::email($data['email'])) {
            $errors[] = 'Invalid email address';
        }

        // Check for duplicate email (excluding current user)
        if (empty($errors)) {
            $existing = $db->queryOne(
                "SELECT id FROM users 
                 WHERE email = :email 
                 AND organization_id = :org_id 
                 AND id != :user_id 
                 AND status != 'deleted'",
                [
                    'email' => $data['email'],
                    'org_id' => $organizationId,
                    'user_id' => $memberId
                ]
            );
            
            if ($existing) {
                $errors[] = 'Email already in use';
            }
        }

        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        // Update profile
        $updateData = [
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'email' => trim(strtolower($data['email'])),
            'phone' => !empty($data['phone']) ? trim($data['phone']) : null,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null
        ];

        $db->update('users', $memberId, $updateData);

        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully'
        ]);
        exit;
    }

    // POST /api/portal/profile/photo - Upload profile photo
    if ($action === 'photo' && $method === 'POST') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        if (empty($_FILES['photo'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            exit;
        }

        $file = $_FILES['photo'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        // Validate file
        if (!in_array($file['type'], $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, and GIF are allowed.']);
            exit;
        }

        if ($file['size'] > $maxSize) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB.']);
            exit;
        }

        // Create uploads directory if it doesn't exist
        $uploadDir = __DIR__ . '/../../../uploads/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . $memberId . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Delete old photo if exists
            $oldMember = $db->queryOne(
                "SELECT profile_photo_path FROM users WHERE id = :id",
                ['id' => $memberId]
            );
            
            if (!empty($oldMember['profile_photo_path'])) {
                $oldPath = __DIR__ . '/../../../' . $oldMember['profile_photo_path'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            // Update database
            $relativePath = 'uploads/profiles/' . $filename;
            $db->update('users', $memberId, ['profile_photo_path' => $relativePath]);

            echo json_encode([
                'success' => true,
                'message' => 'Profile photo uploaded successfully',
                'photo_path' => $relativePath
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
        }
        exit;
    }

    // PUT /api/portal/profile/preferences - Update preferences
    if ($action === 'preferences' && $method === 'PUT') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        $updateData = [];

        if (isset($data['email_preferences'])) {
            $updateData['email_preferences'] = json_encode($data['email_preferences']);
        }

        if (isset($data['communication_preferences'])) {
            $updateData['communication_preferences'] = json_encode($data['communication_preferences']);
        }

        if (!empty($updateData)) {
            $db->update('users', $memberId, $updateData);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Preferences updated successfully'
        ]);
        exit;
    }

    // 404 - Endpoint not found
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Exception $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    $errorMessage = $e->getMessage();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();
    $errorTrace = $e->getTraceAsString();
    
    error_log("Portal profile API error: " . $errorMessage . " in " . $errorFile . ":" . $errorLine);
    error_log("Stack trace: " . $errorTrace);
    
    // Only show detailed error in debug mode
    $userMessage = 'An error occurred. Please try again.';
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        $userMessage = 'An error occurred: ' . $errorMessage . ' in ' . basename($errorFile) . ':' . $errorLine;
    }
    
    echo json_encode([
        'success' => false,
        'message' => $userMessage
    ]);
} catch (\Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    $errorMessage = $e->getMessage();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();
    
    error_log("Portal profile API fatal error: " . $errorMessage . " in " . $errorFile . ":" . $errorLine);
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'A fatal error occurred. Please contact support.'
    ]);
}
