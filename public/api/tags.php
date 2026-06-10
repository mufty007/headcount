<?php
/**
 * Tags API Endpoint
 * Handles CRUD operations for member tags
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

// Set error handler to catch any errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $msg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($msg);
    if (!(error_reporting() & $errno)) return false;
    
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'message' => 'Internal Server Error', 'error' => $msg]);
    exit;
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

    // GET all tags
    if ($action === 'list' && $method === 'GET') {
        $tags = $db->query("SELECT * FROM tags WHERE organization_id = :org_id ORDER BY name", ['org_id' => $organizationId]);
        jsonResponse(['success' => true, 'tags' => $tags]);
    }

    // CREATE tag
    if ($action === 'create' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['name'])) {
            jsonResponse(['success' => false, 'message' => 'Tag name is required'], 400);
        }
        
        if (empty($organizationId)) {
            jsonResponse(['success' => false, 'message' => 'Organization ID is missing'], 400);
        }
        
        try {
            $tagData = [
                'organization_id' => $organizationId,
                'name' => trim($input['name']),
                'color' => $input['color'] ?? '#3B82F6'
            ];
            
            $tagId = $db->insert('tags', $tagData);
            jsonResponse(['success' => true, 'tag_id' => $tagId, 'message' => 'Tag created successfully']);
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            error_log("Tag creation error: " . $errorMsg);
            
            if (strpos($errorMsg, 'Duplicate entry') !== false) {
                jsonResponse(['success' => false, 'message' => 'Tag name already exists'], 400);
            }
            jsonResponse(['success' => false, 'message' => 'Failed to create tag: ' . $errorMsg], 500);
        }
    }

    // DELETE tag
    if ($action === 'delete' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['id'])) {
            jsonResponse(['success' => false, 'message' => 'Tag ID required'], 400);
        }
        
        try {
            // Verify tag belongs to organization
            $tag = $db->queryOne("SELECT id FROM tags WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['id'],
                'org_id' => $organizationId
            ]);
            
            if (!$tag) {
                jsonResponse(['success' => false, 'message' => 'Tag not found'], 404);
            }
            
            // Delete will cascade to member_tags
            $db->delete('tags', $input['id'], 'id', false);
            jsonResponse(['success' => true, 'message' => 'Tag deleted successfully']);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to delete tag: ' . $e->getMessage()], 500);
        }
    }

    // BULK ASSIGN tag to members
    if ($action === 'bulk_assign' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['member_ids']) || empty($input['tag_id'])) {
            jsonResponse(['success' => false, 'message' => 'Member IDs and Tag ID required'], 400);
        }
        
        try {
            // Verify tag belongs to organization
            $tag = $db->queryOne("SELECT id FROM tags WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['tag_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$tag) {
                jsonResponse(['success' => false, 'message' => 'Tag not found'], 404);
            }
            
            $memberIds = is_array($input['member_ids']) ? $input['member_ids'] : [$input['member_ids']];
            $assigned = 0;
            
            foreach ($memberIds as $memberId) {
                // Check if member belongs to organization
                $member = $db->queryOne("SELECT id FROM users WHERE id = :id AND organization_id = :org_id", [
                    'id' => $memberId,
                    'org_id' => $organizationId
                ]);
                
                if ($member) {
                    try {
                        $db->insert('member_tags', [
                            'user_id' => $memberId,
                            'tag_id' => $input['tag_id']
                        ]);
                        $assigned++;
                    } catch (\Exception $e) {
                        // Ignore duplicate entries
                    }
                }
            }
            
            jsonResponse(['success' => true, 'assigned' => $assigned, 'message' => "Tag assigned to $assigned members"]);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to assign tag: ' . $e->getMessage()], 500);
        }
    }

    // BULK REMOVE tag from members
    if ($action === 'bulk_remove' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['member_ids']) || empty($input['tag_id'])) {
            jsonResponse(['success' => false, 'message' => 'Member IDs and Tag ID required'], 400);
        }
        
        try {
            // Verify tag belongs to organization
            $tag = $db->queryOne("SELECT id FROM tags WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['tag_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$tag) {
                jsonResponse(['success' => false, 'message' => 'Tag not found'], 404);
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
                        $db->query("DELETE FROM member_tags WHERE user_id = :user_id AND tag_id = :tag_id", [
                            'user_id' => $memberId,
                            'tag_id' => $input['tag_id']
                        ]);
                        $removed++;
                    } catch (\Exception $e) {
                        // Ignore errors (tag might not be assigned)
                        error_log("Error removing tag from member: " . $e->getMessage());
                    }
                }
            }
            
            jsonResponse(['success' => true, 'removed' => $removed, 'message' => "Tag removed from $removed members"]);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to remove tag: ' . $e->getMessage()], 500);
        }
    }

    // GET member tags
    if ($action === 'member_tags' && $method === 'GET') {
        $memberId = $_GET['member_id'] ?? null;
        
        if (empty($memberId)) {
            jsonResponse(['success' => false, 'message' => 'Member ID required'], 400);
        }
        
        try {
            // Check if tags and member_tags tables exist
            $tablesExist = false;
            try {
                $tagsResult = $db->query("SHOW TABLES LIKE 'tags'");
                $memberTagsResult = $db->query("SHOW TABLES LIKE 'member_tags'");
                $tablesExist = !empty($tagsResult) && !empty($memberTagsResult);
            } catch (\Exception $e) {
                error_log("Table check error: " . $e->getMessage());
                $tablesExist = false;
            }
            
            if (!$tablesExist) {
                // Tables don't exist, return empty array
                jsonResponse(['success' => true, 'tags' => []]);
            }
            
            // Verify member belongs to organization
            $member = $db->queryOne("SELECT id FROM users WHERE id = :id AND organization_id = :org_id", [
                'id' => $memberId,
                'org_id' => $organizationId
            ]);
            
            if (!$member) {
                jsonResponse(['success' => false, 'message' => 'Member not found'], 404);
            }
            
            // Get all tags for this member
            try {
                $memberTags = $db->query("
                    SELECT t.* 
                    FROM tags t
                    INNER JOIN member_tags mt ON t.id = mt.tag_id
                    WHERE mt.user_id = :user_id AND t.organization_id = :org_id
                    ORDER BY t.name
                ", [
                    'user_id' => $memberId,
                    'org_id' => $organizationId
                ]);
            } catch (\Exception $queryException) {
                // If query fails (e.g., table doesn't exist), return empty array
                error_log("Member tags query error: " . $queryException->getMessage());
                $memberTags = [];
            }
            
            jsonResponse(['success' => true, 'tags' => $memberTags]);
        } catch (\Exception $e) {
            error_log("Get member tags error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            jsonResponse(['success' => false, 'message' => 'Failed to get member tags: ' . $e->getMessage()], 500);
        } catch (\Throwable $e) {
            error_log("Get member tags throwable error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            jsonResponse(['success' => false, 'message' => 'Failed to get member tags: ' . $e->getMessage()], 500);
        }
    }

    // ASSIGN tag to member
    if ($action === 'assign' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['member_id']) || empty($input['tag_id'])) {
            jsonResponse(['success' => false, 'message' => 'Member ID and Tag ID required'], 400);
        }
        
        try {
            // Verify tag belongs to organization
            $tag = $db->queryOne("SELECT id FROM tags WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['tag_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$tag) {
                jsonResponse(['success' => false, 'message' => 'Tag not found'], 404);
            }
            
            // Verify member belongs to organization
            $member = $db->queryOne("SELECT id FROM users WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['member_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$member) {
                jsonResponse(['success' => false, 'message' => 'Member not found'], 404);
            }
            
            // Check if already assigned
            $existing = $db->queryOne("SELECT id FROM member_tags WHERE user_id = :user_id AND tag_id = :tag_id", [
                'user_id' => $input['member_id'],
                'tag_id' => $input['tag_id']
            ]);
            
            if ($existing) {
                jsonResponse(['success' => false, 'message' => 'Tag already assigned to this member'], 400);
            }
            
            $db->insert('member_tags', [
                'user_id' => $input['member_id'],
                'tag_id' => $input['tag_id']
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Tag assigned successfully']);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to assign tag: ' . $e->getMessage()], 500);
        }
    }

    // REMOVE tag from member
    if ($action === 'remove' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['member_id']) || empty($input['tag_id'])) {
            jsonResponse(['success' => false, 'message' => 'Member ID and Tag ID required'], 400);
        }
        
        try {
            // Verify tag belongs to organization
            $tag = $db->queryOne("SELECT id FROM tags WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['tag_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$tag) {
                jsonResponse(['success' => false, 'message' => 'Tag not found'], 404);
            }
            
            // Verify member belongs to organization
            $member = $db->queryOne("SELECT id FROM users WHERE id = :id AND organization_id = :org_id", [
                'id' => $input['member_id'],
                'org_id' => $organizationId
            ]);
            
            if (!$member) {
                jsonResponse(['success' => false, 'message' => 'Member not found'], 404);
            }
            
            $db->query("DELETE FROM member_tags WHERE user_id = :user_id AND tag_id = :tag_id", [
                'user_id' => $input['member_id'],
                'tag_id' => $input['tag_id']
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Tag removed successfully']);
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to remove tag: ' . $e->getMessage()], 500);
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
