<?php

/**
 * Portal Family API
 * Handles family member management (requires authentication)
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Load config
$configFile = __DIR__ . '/../../../config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database initialization failed']);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

// Set JSON header
header('Content-Type: application/json');

// Require authentication
PortalAuthMiddleware::requireAuth();

$memberId = PortalAuthMiddleware::getMemberId();

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract action/ID from path
$pathSegments = explode('/', trim($path, '/'));
$action = $pathSegments[count($pathSegments) - 1] ?? '';

// If action is the script name (with or without .php), then it's a base request
if ($action === 'family' || $action === 'family.php') {
    $action = '';
}

$familyMemberId = null;
if (is_numeric($action)) {
    $familyMemberId = (int)$action;
    $action = '';
}

// Get input data (when routed from index.php, $data is already set and php://input is consumed)
if (!isset($input)) {
    $input = json_decode(@file_get_contents('php://input'), true) ?? [];
}
if (!isset($data)) {
    $data = array_merge($_POST, $input);
}

$db = Database::getInstance();

/**
 * Helper function to create family_members table if it doesn't exist
 */
function ensureFamilyMembersTable($db) {
    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'family_members'");
        if (!empty($tableCheck)) {
            // Table exists, check if linked_user_id column exists
            try {
                $columnCheck = $db->query("SHOW COLUMNS FROM family_members LIKE 'linked_user_id'");
                if (empty($columnCheck)) {
                    // Add linked_user_id column if it doesn't exist
                    $pdo = $db->getConnection();
                    try {
                        $pdo->exec("ALTER TABLE `family_members`
                            ADD COLUMN `linked_user_id` INT UNSIGNED NULL COMMENT 'Link to existing user account if name matches' AFTER `relationship`");
                    } catch (\Exception $e) {
                        // Column might already exist
                        error_log("Family API - Error adding linked_user_id column: " . $e->getMessage());
                    }
                    try {
                        $pdo->exec("ALTER TABLE `family_members` ADD INDEX `idx_linked_user` (`linked_user_id`)");
                    } catch (\Exception $e) {
                        // Index might already exist
                        error_log("Family API - Error adding idx_linked_user index: " . $e->getMessage());
                    }
                    try {
                        $pdo->exec("ALTER TABLE `family_members`
                            ADD CONSTRAINT `fk_family_linked_user` 
                            FOREIGN KEY (`linked_user_id`) 
                            REFERENCES `users` (`id`) 
                            ON DELETE SET NULL");
                    } catch (\Exception $e) {
                        // Constraint might already exist
                        error_log("Family API - Error adding fk_family_linked_user constraint: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                // Error checking column, that's okay
                error_log("Family API - Error checking linked_user_id column: " . $e->getMessage());
            }
            return true;
        }
        
        // Table doesn't exist, create it
        $pdo = $db->getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS `family_members` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `parent_user_id` INT UNSIGNED NOT NULL COMMENT 'The main account holder',
          `first_name` VARCHAR(100) NOT NULL,
          `last_name` VARCHAR(100) NOT NULL,
          `date_of_birth` DATE NULL,
          `relationship` VARCHAR(50) NULL COMMENT 'spouse, child, sibling, parent, other',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          FOREIGN KEY (`parent_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
          INDEX `idx_parent_user` (`parent_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Try to add linked_user_id column
        try {
            $pdo->exec("ALTER TABLE `family_members`
                ADD COLUMN `linked_user_id` INT UNSIGNED NULL COMMENT 'Link to existing user account if name matches' AFTER `relationship`");
        } catch (\Exception $e) {
            // Column might already exist
            error_log("Family API - Error adding linked_user_id column: " . $e->getMessage());
        }
        try {
            $pdo->exec("ALTER TABLE `family_members` ADD INDEX `idx_linked_user` (`linked_user_id`)");
        } catch (\Exception $e) {
            // Index might already exist
            error_log("Family API - Error adding idx_linked_user index: " . $e->getMessage());
        }
        try {
            $pdo->exec("ALTER TABLE `family_members`
                ADD CONSTRAINT `fk_family_linked_user` 
                FOREIGN KEY (`linked_user_id`) 
                REFERENCES `users` (`id`) 
                ON DELETE SET NULL");
        } catch (\Exception $e) {
            // Constraint might already exist
            error_log("Family API - Error adding fk_family_linked_user constraint: " . $e->getMessage());
        }
        
        return true;
    } catch (\Exception $e) {
        error_log("Family API - Error creating family_members table: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper function to find matching user by full name
 * Returns user ID if exact match found in same organization, null otherwise
 */
function findMatchingUser($db, $firstName, $lastName, $organizationId) {
    $matchingUser = $db->queryOne(
        "SELECT id FROM users 
         WHERE organization_id = :org_id 
         AND LOWER(TRIM(first_name)) = LOWER(TRIM(:first_name))
         AND LOWER(TRIM(last_name)) = LOWER(TRIM(:last_name))
         AND status = 'active'
         LIMIT 1",
        [
            'org_id' => $organizationId,
            'first_name' => $firstName,
            'last_name' => $lastName
        ]
    );
    
    return $matchingUser ? $matchingUser['id'] : null;
}

try {
    // Get organization ID from session/middleware (safer than re-querying)
    $organizationId = PortalAuthMiddleware::getOrganizationId();

    if (!$organizationId || !$memberId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized or session expired']);
        exit;
    }

    // GET /api/portal/family - Get all family members
    if (empty($action) && $method === 'GET') {
        // Ensure table exists
        if (!ensureFamilyMembersTable($db)) {
            echo json_encode([
                'success' => true,
                'family_members' => [],
                'count' => 0,
                'message' => 'Family members feature not available'
            ]);
            exit;
        }
        
        // Check if linked_user_id column exists to build appropriate query
        $hasLinkedUserId = false;
        try {
            $hasLinkedUserId = $db->hasColumn('family_members', 'linked_user_id');
        } catch (\Exception $e) {
            // Column check failed, assume it doesn't exist
            error_log("Family API - Error checking column: " . $e->getMessage());
        }
        
        if ($hasLinkedUserId) {
            $familyMembers = $db->query(
                "SELECT fm.*, 
                        u.email as linked_email,
                        u.phone as linked_phone,
                        u.status as linked_status,
                        CONCAT(u.first_name, ' ', u.last_name) as linked_full_name
                 FROM family_members fm
                 LEFT JOIN users u ON fm.linked_user_id = u.id
                 WHERE fm.parent_user_id = :user_id 
                 ORDER BY fm.created_at DESC",
                ['user_id' => $memberId]
            );
        } else {
            $familyMembers = $db->query(
                "SELECT fm.*
                 FROM family_members fm
                 WHERE fm.parent_user_id = :user_id 
                 ORDER BY fm.created_at DESC",
                ['user_id' => $memberId]
            );
        }

        echo json_encode([
            'success' => true,
            'family_members' => $familyMembers,
            'count' => count($familyMembers)
        ]);
        exit;
    }

    // POST /api/portal/family - Add family member
    if (empty($action) && $method === 'POST') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        // Ensure table exists
        if (!ensureFamilyMembersTable($db)) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Family members feature is not available. Unable to create database table.'
            ]);
            exit;
        }
        
        $errors = [];

        if (empty($data['first_name'])) {
            $errors[] = 'First name is required';
        }

        if (empty($data['last_name'])) {
            $errors[] = 'Last name is required';
        }

        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        // Auto-link to existing user if full name matches
        $linkedUserId = findMatchingUser(
            $db,
            trim($data['first_name']),
            trim($data['last_name']),
            $organizationId
        );

        // Build insert data
        $insertData = [
            'parent_user_id' => $memberId,
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'date_of_birth' => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
            'relationship' => $data['relationship'] ?? null
        ];
        try {
            if ($db->hasColumn('family_members', 'gender')) {
                $g = isset($data['gender']) ? trim((string) $data['gender']) : '';
                if ($g !== '' && in_array($g, ['male', 'female', 'other'], true)) {
                    $insertData['gender'] = $g;
                }
            }
        } catch (\Exception $e) {
            // ignore
        }
        
        // Only include linked_user_id if column exists
        try {
            if ($db->hasColumn('family_members', 'linked_user_id')) {
                $insertData['linked_user_id'] = $linkedUserId;
            }
        } catch (\Exception $e) {
            // Column check failed, skip linked_user_id
            error_log("Family API - Error checking linked_user_id column: " . $e->getMessage());
        }

        $familyMemberId = $db->insert('family_members', $insertData);

        // Fetch family member with linked user data if column exists
        $hasLinkedUserId = false;
        try {
            $hasLinkedUserId = $db->hasColumn('family_members', 'linked_user_id');
        } catch (\Exception $e) {
            error_log("Family API - Error checking linked_user_id column: " . $e->getMessage());
        }
        if ($hasLinkedUserId) {
            $familyMember = $db->queryOne(
                "SELECT fm.*, 
                        u.email as linked_email,
                        u.phone as linked_phone,
                        u.status as linked_status,
                        CONCAT(u.first_name, ' ', u.last_name) as linked_full_name
                 FROM family_members fm
                 LEFT JOIN users u ON fm.linked_user_id = u.id
                 WHERE fm.id = :id",
                ['id' => $familyMemberId]
            );
        } else {
            $familyMember = $db->queryOne(
                "SELECT fm.*
                 FROM family_members fm
                 WHERE fm.id = :id",
                ['id' => $familyMemberId]
            );
        }

        $message = 'Family member added successfully';
        if ($linkedUserId) {
            $message .= ' and linked to existing user account';
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'family_member' => $familyMember,
            'auto_linked' => $linkedUserId !== null
        ]);
        exit;
    }

    // PUT /api/portal/family/{id} - Update family member
    if ($familyMemberId && $method === 'PUT') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        // Ensure table exists
        if (!ensureFamilyMembersTable($db)) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Family members feature is not available. Unable to create database table.'
            ]);
            exit;
        }
        
        // Verify family member belongs to user
        $existing = $db->queryOne(
            "SELECT * FROM family_members WHERE id = :id AND parent_user_id = :user_id",
            ['id' => $familyMemberId, 'user_id' => $memberId]
        );

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Family member not found']);
            exit;
        }

        $updateData = [];
        $nameChanged = false;
        
        if (isset($data['first_name'])) {
            $updateData['first_name'] = trim($data['first_name']);
            $nameChanged = true;
        }
        if (isset($data['last_name'])) {
            $updateData['last_name'] = trim($data['last_name']);
            $nameChanged = true;
        }
        if (isset($data['date_of_birth'])) {
            $updateData['date_of_birth'] = !empty($data['date_of_birth']) ? $data['date_of_birth'] : null;
        }
        if (isset($data['relationship'])) {
            $updateData['relationship'] = $data['relationship'] ?? null;
        }
        try {
            if ($db->hasColumn('family_members', 'gender') && array_key_exists('gender', $data)) {
                $g = trim((string) $data['gender']);
                $updateData['gender'] = ($g !== '' && in_array($g, ['male', 'female', 'other'], true)) ? $g : null;
            }
        } catch (\Exception $e) {
            // ignore
        }

        // Re-check for matching user if name changed
        if ($nameChanged) {
            $firstName = $updateData['first_name'] ?? $existing['first_name'];
            $lastName = $updateData['last_name'] ?? $existing['last_name'];
            
            $linkedUserId = findMatchingUser(
                $db,
                $firstName,
                $lastName,
                $organizationId
            );
            
            // Only include linked_user_id if column exists
            try {
                if ($db->hasColumn('family_members', 'linked_user_id')) {
                    $updateData['linked_user_id'] = $linkedUserId;
                }
            } catch (\Exception $e) {
                // Column check failed, skip linked_user_id
                error_log("Family API - Error checking linked_user_id column: " . $e->getMessage());
            }
        }

        $db->update('family_members', $familyMemberId, $updateData);

        // Fetch updated family member with linked user data if column exists
        $hasLinkedUserId = false;
        try {
            $hasLinkedUserId = $db->hasColumn('family_members', 'linked_user_id');
        } catch (\Exception $e) {
            error_log("Family API - Error checking linked_user_id column: " . $e->getMessage());
        }
        if ($hasLinkedUserId) {
            $updatedMember = $db->queryOne(
                "SELECT fm.*, 
                        u.email as linked_email,
                        u.phone as linked_phone,
                        u.status as linked_status,
                        CONCAT(u.first_name, ' ', u.last_name) as linked_full_name
                 FROM family_members fm
                 LEFT JOIN users u ON fm.linked_user_id = u.id
                 WHERE fm.id = :id",
                ['id' => $familyMemberId]
            );
        } else {
            $updatedMember = $db->queryOne(
                "SELECT fm.*
                 FROM family_members fm
                 WHERE fm.id = :id",
                ['id' => $familyMemberId]
            );
        }

        echo json_encode([
            'success' => true,
            'message' => 'Family member updated successfully',
            'family_member' => $updatedMember
        ]);
        exit;
    }

    // DELETE /api/portal/family/{id} - Remove family member
    if ($familyMemberId && $method === 'DELETE') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        // Ensure table exists
        if (!ensureFamilyMembersTable($db)) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Family members feature is not available. Unable to create database table.'
            ]);
            exit;
        }
        
        // Verify family member belongs to user
        $existing = $db->queryOne(
            "SELECT * FROM family_members WHERE id = :id AND parent_user_id = :user_id",
            ['id' => $familyMemberId, 'user_id' => $memberId]
        );

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Family member not found']);
            exit;
        }

        $db->delete('family_members', $familyMemberId, 'id', false);

        echo json_encode([
            'success' => true,
            'message' => 'Family member removed successfully'
        ]);
        exit;
    }

    // 404 - Endpoint not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log("Portal family API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
