<?php
/**
 * Member Relationships API
 * Handles family relationship management between members
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\RelationshipService;
use Headcount\Models\MemberRelationship;

// Initialize
$config = require __DIR__ . '/../../config/config.php';
Database::getInstance($config['database']);

// Get request method and parse input
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF Protection for state-changing operations
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    CsrfMiddleware::verify($input);
}

// Require admin authentication
AuthMiddleware::requireAdmin();

// Get organization ID
$organizationId = AuthMiddleware::getOrganizationId();

// Parse URL segments - use same logic as index.php
// If segments are already set (when included from index.php), use them
if (isset($GLOBALS['api_segments']) && is_array($GLOBALS['api_segments'])) {
    $segments = $GLOBALS['api_segments'];
} elseif (!isset($segments) || !is_array($segments)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($requestUri, PHP_URL_PATH);

    // Find /api in the path and extract everything after it (same as index.php)
    $apiPos = strpos($path, '/api');
    if ($apiPos !== false) {
        // Extract path after /api (skip the '/api' part)
        $path = substr($path, $apiPos + 4); // +4 to skip '/api'
        // Remove query string if present
        $path = strtok($path, '?');
    } else {
        // If /api not found, try to extract from script name
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = dirname($scriptName);
        if (strpos($scriptDir, '/api') !== false) {
            $apiPos = strpos($scriptDir, '/api');
            $basePath = substr($scriptDir, 0, $apiPos);
            if (!empty($basePath) && strpos($path, $basePath) === 0) {
                $path = substr($path, strlen($basePath));
            }
            if (strpos($path, '/api') === 0) {
                $path = substr($path, 4);
            }
        } elseif (strpos($path, '/api') === 0) {
            // Path starts with /api directly
            $path = substr($path, 4);
        }
    }

    $path = trim($path, '/');
    $segments = empty($path) ? [] : explode('/', $path);
    // Filter out empty segments
    $segments = array_filter($segments, function($seg) { return !empty($seg); });
    $segments = array_values($segments); // Re-index array
}

// Now segments should be: ['members', '{id}', 'relationships'] for /api/members/{id}/relationships
// So: segments[0] = 'members', segments[1] = '{id}', segments[2] = 'relationships'
error_log("Relationships API - Segments: " . print_r($segments, true));

// Initialize services
$relationshipService = new RelationshipService();
$relationshipModel = new MemberRelationship();

try {
    // Route: GET /api/members/{id}/relationships - Get all relationships for a member
    if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'members' && isset($segments[1]) && is_numeric($segments[1]) && isset($segments[2]) && $segments[2] === 'relationships') {
        $memberId = (int)$segments[1];
        
        try {
            $db = Database::getInstance();
            $relationships = [];
            try {
                $relationships = $relationshipService->getMemberRelationships($memberId);
            } catch (\Exception $e) {
                error_log("Error getting member relationships: " . $e->getMessage());
                $relationships = []; // Default to empty array if service fails
            }
        
        // Also check family_members table for portal family relationships
        $hasLinkedUserId = false;
        try {
            $hasLinkedUserId = $db->hasColumn('family_members', 'linked_user_id');
        } catch (\Exception $e) {
            error_log("Error checking family_members column: " . $e->getMessage());
            $hasLinkedUserId = false;
        }
        
        $familyRelationships = [];
        
        if ($hasLinkedUserId) {
            // Case 1: Find family members this member has added to their family (parent_user_id = memberId)
            // Include both linked and unlinked family members
            try {
                $familyMembersAdded = $db->query(
                    "SELECT fm.id,
                            fm.linked_user_id as related_member_id,
                            fm.relationship as relationship_type,
                            COALESCE(u.first_name, fm.first_name) as related_first_name,
                            COALESCE(u.last_name, fm.last_name) as related_last_name,
                            u.email as related_email,
                            u.phone as related_phone,
                            fm.date_of_birth as related_date_of_birth
                     FROM family_members fm
                     LEFT JOIN users u ON fm.linked_user_id = u.id AND u.organization_id = :org_id
                     WHERE fm.parent_user_id = :member_id",
                    [
                        'member_id' => $memberId,
                        'org_id' => $organizationId
                    ]
                );
                error_log("Found " . count($familyMembersAdded) . " family members (linked and unlinked) for member $memberId");
            } catch (\Exception $e) {
                error_log("Error querying family_members (added): " . $e->getMessage());
                $familyMembersAdded = [];
            }
            
            // Case 2: Find where this member is linked as a family member (linked_user_id = memberId)
            // This shows who added this member to their family
            try {
                $familyMembersLinked = $db->query(
                    "SELECT fm.id,
                            fm.parent_user_id as related_member_id,
                            fm.relationship as relationship_type,
                            u.first_name as related_first_name,
                            u.last_name as related_last_name,
                            u.email as related_email,
                            u.phone as related_phone
                     FROM family_members fm
                     INNER JOIN users u ON fm.parent_user_id = u.id
                     WHERE fm.linked_user_id = :member_id
                     AND u.organization_id = :org_id",
                    [
                        'member_id' => $memberId,
                        'org_id' => $organizationId
                    ]
                );
            } catch (\Exception $e) {
                error_log("Error querying family_members (linked): " . $e->getMessage());
                $familyMembersLinked = [];
            }
            
            // Convert to same format as member_relationships
            foreach ($familyMembersAdded as $fm) {
                // For unlinked family members (no user account), use a special ID format
                // For linked family members, use the actual user ID
                $relatedId = $fm['related_member_id'] ?: ('unlinked-' . $fm['id']);
                
                $familyRelationships[] = [
                    'id' => 'fm-added-' . $fm['id'], // Prefix to avoid conflicts
                    'member_id' => $memberId,
                    'related_member_id' => $relatedId,
                    'relationship_type' => $fm['relationship_type'] ?: 'other',
                    'related_first_name' => $fm['related_first_name'],
                    'related_last_name' => $fm['related_last_name'],
                    'related_email' => $fm['related_email'],
                    'related_phone' => $fm['related_phone'],
                    'is_linked' => !empty($fm['related_member_id']), // Flag to indicate if linked to a user account
                    'date_of_birth' => $fm['related_date_of_birth'] ?? null
                ];
            }
            
            foreach ($familyMembersLinked as $fm) {
                $familyRelationships[] = [
                    'id' => 'fm-linked-' . $fm['id'], // Prefix to avoid conflicts
                    'member_id' => $memberId,
                    'related_member_id' => $fm['related_member_id'],
                    'relationship_type' => $fm['relationship_type'] ?: 'other',
                    'related_first_name' => $fm['related_first_name'],
                    'related_last_name' => $fm['related_last_name'],
                    'related_email' => $fm['related_email'],
                    'related_phone' => $fm['related_phone']
                ];
            }
        }
        
            // Merge both types of relationships and remove duplicates based on related_member_id
            $allRelationships = array_merge($relationships, $familyRelationships);
            
            // Remove duplicates - if same related_member_id appears in both, keep the member_relationships one
            $uniqueRelationships = [];
            $seenRelatedIds = [];
            foreach ($allRelationships as $rel) {
                $relatedId = $rel['related_member_id'] ?? null;
                if ($relatedId && !isset($seenRelatedIds[$relatedId])) {
                    $uniqueRelationships[] = $rel;
                    $seenRelatedIds[$relatedId] = true;
                } elseif (!$relatedId) {
                    // Include relationships without related_member_id (unlinked family members)
                    $uniqueRelationships[] = $rel;
                }
            }
            
            Utilities::jsonResponse(true, $uniqueRelationships, 'Relationships retrieved successfully');
            exit;
        } catch (\Exception $e) {
            error_log("Relationships API GET error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            // Return empty array instead of error to prevent breaking the page
            Utilities::jsonResponse(true, [], 'Relationships retrieved (some data may be unavailable)');
            exit;
        }
    }

    // Route: POST /api/members/{id}/relationships - Create a new relationship
    if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'members' && isset($segments[1]) && is_numeric($segments[1]) && isset($segments[2]) && $segments[2] === 'relationships') {
        $memberId = (int)$segments[1];
        
        // Validate input
        if (empty($input['related_member_id'])) {
            Utilities::jsonResponse(false, null, 'Related member ID is required', ['related_member_id' => 'Required'], 400);
            exit;
        }

        if (empty($input['relationship_type'])) {
            Utilities::jsonResponse(false, null, 'Relationship type is required', ['relationship_type' => 'Required'], 400);
            exit;
        }

        $relatedMemberId = (int)$input['related_member_id'];
        $relationshipType = $input['relationship_type'];
        $notes = $input['notes'] ?? null;

        // Validate relationship type
        $validTypes = ['spouse', 'parent', 'child', 'sibling', 'guardian', 'ward', 'other'];
        if (!in_array($relationshipType, $validTypes)) {
            Utilities::jsonResponse(false, null, 'Invalid relationship type', ['relationship_type' => 'Must be one of: ' . implode(', ', $validTypes)], 400);
            exit;
        }

        try {
            $result = $relationshipService->createRelationship($memberId, $relatedMemberId, $relationshipType, $notes);
            Utilities::jsonResponse(true, $result, 'Relationship created successfully', [], 201);
        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            if ($statusCode == 409) {
                $statusCode = 400; // Convert conflict to bad request for UI
            }
            Utilities::jsonResponse(false, null, $e->getMessage(), [$e->getMessage()], $statusCode);
        }
        exit;
    }

    // Route: DELETE /api/relationships/{id} - Delete a relationship by ID
    if ($method === 'DELETE' && isset($segments[1]) && $segments[1] === 'relationships' && isset($segments[2]) && is_numeric($segments[2])) {
        $relationshipId = (int)$segments[2];
        
        // Get the relationship to find both members
        $relationship = $relationshipModel->find($relationshipId);
        
        if (!$relationship) {
            Utilities::jsonResponse(false, null, 'Relationship not found', [], 404);
            exit;
        }

        try {
            $relationshipService->deleteRelationship($relationship['member_id'], $relationship['related_member_id']);
            Utilities::jsonResponse(true, null, 'Relationship deleted successfully');
        } catch (\Exception $e) {
            Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
        }
        exit;
    }

    // Route: DELETE /api/members/{id}/relationships/{relatedId} - Delete relationship between two members
    if ($method === 'DELETE' && isset($segments[0]) && $segments[0] === 'members' && isset($segments[1]) && is_numeric($segments[1]) && isset($segments[2]) && $segments[2] === 'relationships' && isset($segments[3]) && is_numeric($segments[3])) {
        $memberId = (int)$segments[1];
        $relatedMemberId = (int)$segments[3];
        
        try {
            $relationshipService->deleteRelationship($memberId, $relatedMemberId);
            Utilities::jsonResponse(true, null, 'Relationship deleted successfully');
        } catch (\Exception $e) {
            Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
        }
        exit;
    }

    // Route: GET /api/members/{id}/family-network - Get family network
    if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'members' && isset($segments[1]) && is_numeric($segments[1]) && isset($segments[2]) && $segments[2] === 'family-network') {
        $memberId = (int)$segments[1];
        $depth = isset($_GET['depth']) ? (int)$_GET['depth'] : 2;
        
        $network = $relationshipService->getFamilyNetwork($memberId, $depth);
        
        Utilities::jsonResponse(true, $network, 'Family network retrieved successfully');
        exit;
    }

    // No matching route
    Utilities::jsonResponse(false, null, 'Endpoint not found', [], 404);

} catch (\Exception $e) {
    error_log("Relationships API error: " . $e->getMessage());
    error_log("Relationships API stack trace: " . $e->getTraceAsString());
    Utilities::jsonResponse(false, null, 'An error occurred: ' . $e->getMessage(), [], 500);
}
