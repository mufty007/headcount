<?php
// Start output buffering to prevent any accidental output
ob_start();

// Disable error display, we'll handle errors ourselves
ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

// Set error handler to catch any errors and prevent HTML output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Log error but don't output it
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    // Clear any output that might have been generated
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    return true; // Suppress default error handling
});

use Headcount\Helpers\Database;
use Headcount\Helpers\Validator;
use Headcount\Helpers\NotificationHelper;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\MemberService;

try {
    /**
     * Members API Endpoint
     * Handles CRUD operations for members
     * 
     * This is an alternative endpoint. The main API routing in public/api/index.php
     * also handles these operations through the MemberController.
     */

    // Suppress any warnings/notices that might output HTML
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    require_once __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../../src/helpers.php';

    // Load config
    $config = require __DIR__ . '/../../config/config.php';

    // Initialize database
    Database::getInstance($config['database']);

    // Start session if needed
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Clear any output that may have been generated
    ob_clean();
    header('Content-Type: application/json');

    // Check authentication
    AuthMiddleware::check();
    $organizationId = AuthMiddleware::getOrganizationId();
    $db = Database::getInstance();

    // Get request path to determine REST endpoint
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $pathSegments = explode('/', trim($requestUri, '/'));
    $memberId = null;

    // Extract member ID from path (e.g., /api/members/123 or /public/api/members/123)
    // Find the position of 'members' in the path, then check if there's an ID after it
    $membersIndex = array_search('members', $pathSegments);
    if ($membersIndex !== false && isset($pathSegments[$membersIndex + 1])) {
        $potentialId = $pathSegments[$membersIndex + 1];
        // Only treat as ID if it's numeric (not 'stats' or other endpoints)
        if (is_numeric($potentialId)) {
            $memberId = (int)$potentialId;
        }
    }

    // Get action from query string (for backward compatibility)
    $action = $_GET['action'] ?? null;
    $method = $_SERVER['REQUEST_METHOD'];

    // Coordinators may only create members (e.g. from check-in flow); other operations require admin
    $isCreate = ((!$memberId && $method === 'POST') || ($action === 'create' && $method === 'POST'));
    if ($isCreate) {
        AuthMiddleware::requireAdminOrCoordinator();
    } else {
        AuthMiddleware::requireAdmin();
    }

    // POST generate credentials (query: ?action=generate_credentials&id={id})
    if ($action === 'generate_credentials' && $method === 'POST') {
        $id = Validator::getParam('id', 'id', null);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Invalid member ID'], 400);
            exit;
        }
        
        // Ensure clean output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        
        try {
            $memberService = new MemberService();
            $member = $memberService->getMember($id);
            
            // Verify member belongs to organization
            if ($member['organization_id'] != $organizationId) {
                ob_clean();
                jsonResponse(['success' => false, 'message' => 'Member not found'], 404);
                exit;
            }
            
            // Generate credentials
            $result = $memberService->generateCredentials($id);
            
            ob_clean();
            jsonResponse([
                'success' => true,
                'data' => $result,
                'message' => 'Credentials generated successfully'
            ]);
            exit;
            
        } catch (\Exception $e) {
            ob_clean();
            error_log("Generate credentials error: " . $e->getMessage());
            jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
            exit;
        }
    }

    // GET list of members (for dropdowns, e.g. email compose) - ?action=list
    if ($action === 'list' && $method === 'GET') {
        $members = $db->query(
            "SELECT id, first_name, last_name, email FROM users 
             WHERE organization_id = ? AND role = 'member' AND status = 'active' 
             ORDER BY last_name, first_name",
            [$organizationId]
        );
        jsonResponse(['success' => true, 'members' => $members]);
        exit;
    }

    // GET member stats (query: ?action=stats&id={id})
    if ($action === 'stats' && $method === 'GET') {
        $id = Validator::getParam('id', 'id', null);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Invalid member ID'], 400);
            exit;
        }
        
        // Ensure clean output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        
        try {
            $memberService = new MemberService();
            $member = $memberService->getMember($id);
            
            // Verify member belongs to organization
            if ($member['organization_id'] != $organizationId) {
                ob_clean();
                jsonResponse(['success' => false, 'message' => 'Member not found'], 404);
                exit;
            }
            
            // Get stats
            $stats = $memberService->getMemberStats($id);
            
            // Get tags and groups (with error handling in case tables don't exist)
            $tags = [];
            $groups = [];
            
            try {
                // Check if tags table exists
                $tagsTableCheck = $db->query("SHOW TABLES LIKE 'tags'");
                if (!empty($tagsTableCheck)) {
                    $tags = $db->query("
                        SELECT t.* FROM tags t 
                        JOIN member_tags mt ON t.id = mt.tag_id 
                        WHERE mt.user_id = :user_id
                    ", ['user_id' => $id]);
                }
            } catch (\Exception $e) {
                error_log("Tags query failed in stats endpoint: " . $e->getMessage());
                $tags = [];
            }
            
            try {
                // Check if groups table exists
                $groupsTableCheck = $db->query("SHOW TABLES LIKE 'member_groups'");
                if (!empty($groupsTableCheck)) {
                    $groups = $db->query("
                        SELECT mg.* FROM member_groups mg 
                        JOIN group_members gm ON mg.id = gm.group_id 
                        WHERE gm.user_id = :user_id
                    ", ['user_id' => $id]);
                }
            } catch (\Exception $e) {
                error_log("Groups query failed in stats endpoint: " . $e->getMessage());
                $groups = [];
            }
            
            ob_clean();
            jsonResponse([
                'success' => true, 
                'data' => [
                    'member' => $member,
                    'stats' => $stats,
                    'tags' => $tags,
                    'groups' => $groups
                ]
            ]);
            exit;
        } catch (\Exception $e) {
            ob_clean();
            error_log("Stats endpoint error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            jsonResponse(['success' => false, 'message' => 'Failed to load member stats: ' . $e->getMessage()], 500);
            exit;
        } catch (\Throwable $e) {
            ob_clean();
            error_log("Stats endpoint throwable error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            jsonResponse(['success' => false, 'message' => 'Failed to load member stats: ' . $e->getMessage()], 500);
            exit;
        }
    }

    // GET single member (REST: GET /api/members/{id} or query: ?action=get&id={id} or ?id={id})
    if (($memberId || ($action === 'get' && isset($_GET['id'])) || isset($_GET['id'])) && $method === 'GET') {
        $id = $memberId ?: (int)($_GET['id'] ?? null);
        
        try {
            $memberService = new MemberService();
            $member = $memberService->getMember($id);
            
            // Verify member belongs to organization
            if ($member['organization_id'] != $organizationId) {
                jsonResponse(['success' => false, 'message' => 'Member not found'], 404);
            }
            
            jsonResponse(['success' => true, 'data' => $member, 'message' => 'Member retrieved successfully']);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    // CREATE member (REST: POST /api/members or query: ?action=create)
    if ((!$memberId && $method === 'POST') || ($action === 'create' && $method === 'POST')) {
        // Verify CSRF
        CsrfMiddleware::verify();
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON data'], 400);
        }
        
        $errors = [];
        
        // Validate
        if (empty($input['first_name'])) {
            $errors[] = 'First name is required.';
        }
        
        if (empty($input['last_name'])) {
            $errors[] = 'Last name is required.';
        }
        
        if (empty($input['email'])) {
            $errors[] = 'Email is required.';
        } elseif (!Validator::email($input['email'])) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        // Check for duplicate email
        if (!empty($input['email']) && empty($errors)) {
            $existing = $db->queryOne(
                "SELECT id FROM users WHERE email = :email AND organization_id = :org_id AND role = 'member' AND status != 'deleted'",
                ['email' => $input['email'], 'org_id' => $organizationId]
            );
            if ($existing) {
                $errors[] = 'A member with this email already exists.';
            }
        }
        
        // Normalize phone number (trim and set to null if empty)
        $phone = !empty($input['phone']) ? trim($input['phone']) : null;
        if ($phone === '') {
            $phone = null;
        }
        
        // Note: Phone numbers are NOT unique - multiple members can share the same phone (e.g., family members)
        
        if (!empty($errors)) {
            jsonResponse(['success' => false, 'errors' => $errors], 400);
        }
        
        try {
            $memberService = new MemberService();
            
            // Store phone as provided (don't strip formatting, but ensure it's consistent)
            // The validation already checked for duplicates
            
            $memberData = [
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'phone' => $phone,
                'gender' => $input['gender'] ?? null,
                'date_of_birth' => !empty($input['date_of_birth']) ? $input['date_of_birth'] : null,
                'organization_id' => $organizationId,
                'role' => 'member',
                'status' => 'active'
            ];
            
            $member = $memberService->createMember($memberData);
            
            // Tags are automatically assigned in MemberService::createMember()
            
            // Create notification
            try {
                $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                NotificationHelper::newMember($organizationId, $memberName);
            } catch (\Exception $e) {
                // Notification helper might not exist, log but don't fail
                error_log("Notification error: " . $e->getMessage());
            }
            
            // Ensure no output before JSON
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_start();
            
            jsonResponse(['success' => true, 'member_id' => $member['id'], 'member' => $member, 'message' => 'Member added successfully']);
            
        } catch (\PDOException $e) {
            // Handle database errors specifically
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_start();
            
            $errorMessage = $e->getMessage();
            $userMessage = 'Failed to create member';
            $statusCode = 500;
            
            // Check for specific database constraint violations
            if (strpos($errorMessage, 'Duplicate entry') !== false || $e->getCode() == 23000 || strpos($errorMessage, '23000') !== false) {
                $statusCode = 400;
                if (strpos($errorMessage, 'unique_org_email') !== false) {
                    $userMessage = 'A member with this email address already exists.';
                } else {
                    // Phone numbers are no longer unique, so this shouldn't happen
                    // But handle it gracefully if it does
                    $userMessage = 'This member already exists (duplicate entry).';
                }
            }
            
            error_log("Create member PDO error: " . $errorMessage . " in " . $e->getFile() . " on line " . $e->getLine());
            jsonResponse(['success' => false, 'message' => $userMessage, 'errors' => [$userMessage]], $statusCode);
        } catch (\Exception $e) {
            // Ensure no output before JSON
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_start();
            
            $errorMessage = $e->getMessage();
            $userMessage = 'Failed to create member';
            $statusCode = 500;
            
            // Check for specific database constraint violations
            if (strpos($errorMessage, 'Duplicate entry') !== false) {
                $statusCode = 400;
                if (strpos($errorMessage, 'unique_org_email') !== false) {
                    $userMessage = 'A member with this email address already exists.';
                } else {
                    $userMessage = 'This member already exists (duplicate entry).';
                }
            } elseif (strpos($errorMessage, 'Email already exists') !== false || $e->getCode() == 409) {
                $statusCode = 400;
                // Use the exception message directly if it's a 409 (conflict) or contains "already exists"
                if (strpos($errorMessage, 'Email already exists') !== false) {
                    $userMessage = $errorMessage;
                } else {
                    $userMessage = 'A member with this email address already exists.';
                }
            }
            
            error_log("Create member error: " . $errorMessage . " in " . $e->getFile() . " on line " . $e->getLine());
            jsonResponse(['success' => false, 'message' => $userMessage, 'errors' => [$userMessage]], $statusCode);
        } catch (\Throwable $e) {
            // Catch any other errors
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_start();
            
            error_log("Create member throwable error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            jsonResponse(['success' => false, 'message' => 'Failed to create member: ' . $e->getMessage()], 500);
        }
    }

    // UPDATE member (REST: PUT /api/members/{id}, PUT .../members.php?id=, or query: ?action=update)
    if (($memberId && $method === 'PUT') || ($method === 'PUT' && isset($_GET['id'])) || ($action === 'update' && $method === 'POST')) {
        // Verify CSRF
        CsrfMiddleware::verify();
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON data'], 400);
        }
        
        // Get ID from path, query parameter, or input
        $updateId = $memberId ?: ($_GET['id'] ?? ($input['id'] ?? null));
        
        if (!$updateId) {
            jsonResponse(['success' => false, 'message' => 'Member ID required'], 400);
        }
        
        $errors = [];
        
        // Validate
        if (empty($input['first_name'])) {
            $errors[] = 'First name is required.';
        }
        
        if (empty($input['last_name'])) {
            $errors[] = 'Last name is required.';
        }
        
        if (empty($input['email'])) {
            $errors[] = 'Email is required.';
        } elseif (!Validator::email($input['email'])) {
            $errors[] = 'Please enter a valid email address.';
        }
        
        // Check for duplicate email (excluding current member)
        if (!empty($input['email']) && empty($errors)) {
            $existing = $db->queryOne(
                "SELECT id FROM users WHERE email = :email AND organization_id = :org_id AND role = 'member' AND id != :id AND status != 'deleted'",
                ['email' => $input['email'], 'org_id' => $organizationId, 'id' => $updateId]
            );
            if ($existing) {
                $errors[] = 'A member with this email already exists.';
            }
        }
        
        // Note: Phone numbers are NOT unique - multiple members can share the same phone (e.g., family members)
        
        if (!empty($errors)) {
            jsonResponse(['success' => false, 'errors' => $errors], 400);
        }
        
        try {
            // Verify member belongs to organization
            $memberService = new MemberService();
            $existingMember = $memberService->getMember($updateId);
            
            if ($existingMember['organization_id'] != $organizationId) {
                jsonResponse(['success' => false, 'message' => 'Member not found'], 404);
            }
            
            // Normalize phone number (trim and set to null if empty)
            $phone = !empty($input['phone']) ? trim($input['phone']) : null;
            if ($phone === '') {
                $phone = null;
            }
            
            $updateData = [
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'phone' => $phone,
                'gender' => $input['gender'] ?? null,
                'date_of_birth' => !empty($input['date_of_birth']) ? $input['date_of_birth'] : null
            ];
            
            $member = $memberService->updateMember($updateId, $updateData);
            
            jsonResponse(['success' => true, 'message' => 'Member updated successfully']);
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $userMessage = 'Failed to update member';
            
            // Check for specific database constraint violations
            if (strpos($errorMessage, 'Duplicate entry') !== false) {
                if (strpos($errorMessage, 'unique_org_email') !== false) {
                    $userMessage = 'A member with this email address already exists.';
                } else {
                    $userMessage = 'This member already exists (duplicate entry).';
                }
            }
            
            error_log("Update member error: " . $errorMessage . " in " . $e->getFile() . " on line " . $e->getLine());
            jsonResponse(['success' => false, 'message' => $userMessage, 'errors' => [$userMessage]], 400);
        }
    }

    // DELETE member (REST: DELETE /api/members/{id}, DELETE .../members.php?id=, or query: ?action=delete)
    if (($memberId && $method === 'DELETE') || ($method === 'DELETE' && isset($_GET['id'])) || ($action === 'delete' && $method === 'POST')) {
        // Verify CSRF
        CsrfMiddleware::verify();
        
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        
        // Get ID from path, query parameter, or input
        $deleteId = $memberId ?: ($_GET['id'] ?? ($input['id'] ?? null));
        
        if (!$deleteId) {
            jsonResponse(['success' => false, 'message' => 'Member ID required'], 400);
        }
        
        try {
            // Verify member belongs to organization
            $memberService = new MemberService();
            $member = $memberService->getMember($deleteId);
            
            if ($member['organization_id'] != $organizationId) {
                jsonResponse(['success' => false, 'message' => 'Member not found'], 404);
            }
            
            // Check if member has attendance history
            $attendanceCount = $db->queryOne(
                "SELECT COUNT(*) as count FROM attendance WHERE user_id = :user_id",
                ['user_id' => $deleteId]
            );
            
            if ($attendanceCount && (int)$attendanceCount['count'] > 0) {
                // Soft delete - mark as inactive
                $memberService->deleteMember($deleteId);
                jsonResponse(['success' => true, 'message' => 'Member deactivated (has attendance history)']);
            } else {
                // Hard delete
                $memberService->deleteMember($deleteId);
                jsonResponse(['success' => true, 'message' => 'Member deleted successfully']);
            }
            
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to delete member: ' . $e->getMessage()], 500);
        }
    }

    // Invalid action
    // No matching action found - ensure clean output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    jsonResponse(['success' => false, 'message' => 'Invalid action or endpoint'], 400);
    exit;
    
} catch (\PDOException $e) {
    // Handle uncaught PDO exceptions
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    $errorMessage = $e->getMessage();
    $userMessage = 'Database error occurred';
    $statusCode = 500;
    
    // Check for specific database constraint violations
    if (strpos($errorMessage, 'Duplicate entry') !== false || $e->getCode() == 23000) {
        $statusCode = 400;
        if (strpos($errorMessage, 'unique_org_email') !== false) {
            $userMessage = 'A member with this email address already exists.';
        } else {
            $userMessage = 'This member already exists (duplicate entry).';
        }
    }
    
    error_log("Members API PDO exception: " . $errorMessage . " in " . $e->getFile() . " on line " . $e->getLine());
    jsonResponse([
        'success' => false,
        'message' => $userMessage,
        'errors' => [$userMessage]
    ], $statusCode);
    exit;
} catch (\Exception $e) {
    // Clear any output that may have been generated
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    $errorMessage = $e->getMessage();
    $userMessage = 'An error occurred: ' . $errorMessage;
    $statusCode = 500;
    
    // Check for specific error messages
    if (strpos($errorMessage, 'Duplicate entry') !== false) {
        $statusCode = 400;
        if (strpos($errorMessage, 'unique_org_email') !== false) {
            $userMessage = 'A member with this email address already exists.';
        } else {
            $userMessage = 'This member already exists (duplicate entry).';
        }
    } elseif ($e->getCode() == 400 || $e->getCode() == 409) {
        $statusCode = $e->getCode();
        $userMessage = $errorMessage;
    }
    
    error_log("Members API exception: " . $errorMessage . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    jsonResponse([
        'success' => false,
        'message' => $userMessage,
        'errors' => [$userMessage]
    ], $statusCode);
    exit;
} catch (\Error $e) {
    // Clear any output that may have been generated
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    error_log("Members API error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());
    jsonResponse([
        'success' => false,
        'message' => 'An unexpected error occurred: ' . $e->getMessage()
    ], 500);
    exit;
} finally {
    // Clean up output buffer
    ob_end_flush();
}
?>
