<?php
/**
 * Groups API Endpoint
 * Handles CRUD operations for member groups
 */

// Start output buffering to prevent any accidental output
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Disable error display, we'll handle errors ourselves
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json', true, 500);
        echo json_encode([
            'success' => false,
            'message' => 'Fatal Server Error: ' . $error['message'],
            'error' => $error
        ]);
        error_log("Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
    }
});

// Set error handler to catch errors without breaking on simple notices/warnings
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $msg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($msg);
    if (!(error_reporting() & $errno)) return false;
    
    // Only return 500 for critical errors, not for notices/warnings
    $critical = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR, E_USER_ERROR];
    if (in_array($errno, $critical)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'message' => 'Internal Server Error', 'error' => $msg]);
        exit;
    }
    return true; // Continue execution for non-critical errors
}, E_ALL);

// Load autoloader and use statements at top level
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;

try {

    // Load config
    $config = require __DIR__ . '/../../config/config.php';

    // Initialize database
    Database::getInstance($config['database']);

    // Start session if needed (suppress warnings)
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Clear any output that may have been generated
    while (ob_get_level() > 1) {
        ob_end_clean();
    }
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json', true);

    // Check authentication
    AuthMiddleware::requireAdmin();
    $organizationId = AuthMiddleware::getOrganizationId();
    $db = Database::getInstance();

    $action = $_GET['action'] ?? 'list';
    $method = $_SERVER['REQUEST_METHOD'];

    // GET all groups
    if ($action === 'list' && $method === 'GET') {
        try {
            $groups = $db->query("SELECT * FROM member_groups WHERE organization_id = :org_id ORDER BY name", ['org_id' => $organizationId]);
        } catch (\Exception $e) {
            // Table may not exist yet
            $groups = [];
        }
        jsonResponse(['success' => true, 'groups' => $groups]);
    }

    // CREATE group
    if ($action === 'create' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['name'])) {
            jsonResponse(['success' => false, 'message' => 'Group name is required'], 400);
        }
        
        if (empty($organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Organization ID is missing'], 400);
        }
        
        try {
            $groupData = [
                'organization_id' => $organizationId,
                'name' => trim($input['name']),
                'description' => $input['description'] ?? null,
                'color' => $input['color'] ?? '#10B981'
            ];
            
            $groupId = $db->insert('member_groups', $groupData);
            jsonResponse(['success' => true, 'group_id' => $groupId, 'message' => 'Group created successfully']);
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            error_log("Group creation error: " . $errorMsg);
            
            if (strpos($errorMsg, 'Duplicate entry') !== false) {
                jsonResponse(['success' => false, 'message' => 'Group name already exists'], 400);
            }
            jsonResponse(['success' => false, 'message' => 'Failed to create group: ' . $errorMsg], 500);
        }
    }

    // DELETE group
    if ($action === 'delete' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['id'])) {
            jsonResponse(['success' => false, 'message' => 'Group ID required'], 400);
        }
        
        try {
            // Verify group belongs to organization
            $group = $db->queryOne("SELECT id FROM member_groups WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['id'],
                'org_id' => $organizationId
            ]);
            
            if (!$group) {
                jsonResponse(['success' => false, 'message' => 'Group not found'], 404);
            }
            
            // Delete will cascade to group_members
            $db->delete('member_groups', $input['id'], 'id', false);
            jsonResponse(['success' => true, 'message' => 'Group deleted successfully']);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to delete group: ' . $e->getMessage()], 500);
        }
    }

    // BULK ADD members to group
    if ($action === 'bulk_add' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['member_ids']) || empty($input['group_id'])) {
            jsonResponse(['success' => false, 'message' => 'Member IDs and Group ID required'], 400);
        }
        
        try {
            // Verify group belongs to organization
            $group = $db->queryOne("SELECT id FROM member_groups WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['group_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$group) {
                jsonResponse(['success' => false, 'message' => 'Group not found'], 404);
            }
            
            $memberIds = is_array($input['member_ids']) ? $input['member_ids'] : [$input['member_ids']];
            $added = 0;
            
            foreach ($memberIds as $memberId) {
                // Check if member belongs to organization
                $member = $db->queryOne("SELECT id FROM users WHERE id = :id AND organization_id = :org_id", [
                    'id' => $memberId,
                    'org_id' => $organizationId
                ]);
                
                if ($member) {
                    try {
                        $db->insert('group_members', [
                            'user_id' => $memberId,
                            'group_id' => $input['group_id']
                        ]);
                        $added++;
                    } catch (\Exception $e) {
                        // Ignore duplicate entries
                    }
                }
            }
            
            jsonResponse(['success' => true, 'added' => $added, 'message' => "$added members added to group"]);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to add members: ' . $e->getMessage()], 500);
        }
    }

    // BULK REMOVE members from group
    if ($action === 'bulk_remove' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['member_ids']) || empty($input['group_id'])) {
            jsonResponse(['success' => false, 'message' => 'Member IDs and Group ID required'], 400);
        }
        
        try {
            // Verify group belongs to organization
            $group = $db->queryOne("SELECT id FROM member_groups WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['group_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$group) {
                jsonResponse(['success' => false, 'message' => 'Group not found'], 404);
            }
            
            $memberIds = is_array($input['member_ids']) ? $input['member_ids'] : [$input['member_ids']];
            $removed = 0;
            
            foreach ($memberIds as $memberId) {
                // Check if member belongs to organization
                $member = $db->queryOne("SELECT id FROM users WHERE id = :id AND organization_id = :org_id", [
                    'id' => $memberId,
                    'org_id' => $organizationId
                ]);
                
                if ($member) {
                    try {
                        $db->query("DELETE FROM group_members WHERE user_id = :user_id AND group_id = :group_id", [
                            'user_id' => $memberId,
                            'group_id' => $input['group_id']
                        ]);
                        $removed++;
                    } catch (\Exception $e) {
                        // Ignore errors (member might not be in group)
                        error_log("Error removing member from group: " . $e->getMessage());
                    }
                }
            }
            
            jsonResponse(['success' => true, 'removed' => $removed, 'message' => "$removed members removed from group"]);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to remove members: ' . $e->getMessage()], 500);
        }
    }

    // REMOVE member from group
    if ($action === 'remove' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['member_id']) || empty($input['group_id'])) {
            jsonResponse(['success' => false, 'message' => 'Member ID and Group ID required'], 400);
        }
        
        try {
            // Verify group belongs to organization
            $group = $db->queryOne("SELECT id FROM member_groups WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['group_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$group) {
                jsonResponse(['success' => false, 'message' => 'Group not found'], 404);
            }
            
            // Verify member belongs to organization
            $member = $db->queryOne("SELECT id FROM users WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['member_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$member) {
                jsonResponse(['success' => false, 'message' => 'Member not found'], 404);
            }
            
            $db->query("DELETE FROM group_members WHERE user_id = :user_id AND group_id = :group_id", [
                'user_id' => $input['member_id'],
                'group_id' => $input['group_id']
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Member removed from group successfully']);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to remove member: ' . $e->getMessage()], 500);
        }
    }

    // GET current group memberships for selected members
    if ($action === 'current_memberships' && $method === 'GET') {
        $memberIds = $_GET['member_ids'] ?? '';
        
        if (empty($memberIds)) {
            jsonResponse(['success' => false, 'message' => 'Member IDs required'], 400);
        }
        
        try {
            $memberIdsArray = is_array($memberIds) ? $memberIds : explode(',', $memberIds);
            $memberIdsArray = array_map('intval', $memberIdsArray); // Ensure integers
            
            // Build query with named placeholders
            $placeholders = [];
            $params = ['org_id' => $organizationId];
            foreach ($memberIdsArray as $index => $memberId) {
                $key = 'member_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $memberId;
            }
            
            $sql = "SELECT gm.user_id, gm.group_id, mg.id, mg.name, mg.color, mg.description
                    FROM group_members gm
                    INNER JOIN member_groups mg ON gm.group_id = mg.id
                    WHERE gm.user_id IN (" . implode(',', $placeholders) . ") AND mg.organization_id = :org_id";
            
            $memberships = $db->query($sql, $params);
            
            // Group by member_id
            $result = [];
            foreach ($memberships as $membership) {
                $userId = (string)$membership['user_id']; // Convert to string for consistency
                if (!isset($result[$userId])) {
                    $result[$userId] = [];
                }
                $result[$userId][] = [
                    'id' => (int)$membership['group_id'],
                    'name' => $membership['name'],
                    'color' => $membership['color'],
                    'description' => $membership['description']
                ];
            }
            
            jsonResponse(['success' => true, 'memberships' => $result]);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to fetch memberships: ' . $e->getMessage()], 500);
        }
    }

    jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    
} catch (\Exception $e) {
    // Clear any output that might have been generated
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Ensure we're sending JSON
    header('Content-Type: application/json', true);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ];
    
    // Always include error details for debugging
    $response['error'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'message' => $e->getMessage()
    ];
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $response['error']['trace'] = $e->getTraceAsString();
    }
    
    echo json_encode($response);
    exit;
} catch (\Error $e) {
    // Clear any output that might have been generated
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Ensure we're sending JSON
    header('Content-Type: application/json', true);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ];
    
    // Always include error details for debugging
    $response['error'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'message' => $e->getMessage()
    ];
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $response['error']['trace'] = $e->getTraceAsString();
    }
    
    echo json_encode($response);
    exit;
} catch (\Throwable $e) {
    // Catch any other throwable (PHP 7+)
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json', true);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ];
    
    // Always include error details for debugging
    $response['error'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'message' => $e->getMessage()
    ];
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $response['error']['trace'] = $e->getTraceAsString();
    }
    
    echo json_encode($response);
    exit;
}
