<?php
/**
 * Notifications API Endpoint
 * Handles fetching and updating notifications
 */

// Start output buffering
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Disable error display
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

// Load autoloader and helpers
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
    $config = require HC_PROJECT_ROOT . '/config/config.php';

    // Initialize database
    Database::getInstance($config['database']);

    // Start session if needed
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Clear any output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json', true);

    // Check authentication
    AuthMiddleware::requireAdminOrCoordinator();
    $organizationId = AuthMiddleware::getOrganizationId();
    $userId = AuthMiddleware::getUserId();
    $db = Database::getInstance();

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'list';

    // GET notifications
    if ($action === 'list' && $method === 'GET') {
        try {
            // Check if notifications table exists
            $tableExists = $db->query("SHOW TABLES LIKE 'notifications'");
            if (empty($tableExists)) {
                // Table doesn't exist, return empty result
                jsonResponse([
                    'success' => true,
                    'notifications' => [],
                    'unread_count' => 0
                ]);
                exit;
            }
            
            $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            
            $where = "organization_id = :org_id AND (user_id IS NULL OR user_id = :user_id)";
            $params = ['org_id' => $organizationId, 'user_id' => $userId];
            
            if ($unreadOnly) {
                $where .= " AND is_read = 0";
            }
            
            $sql = "SELECT * FROM notifications 
                    WHERE $where 
                    ORDER BY created_at DESC 
                    LIMIT " . (int)$limit;
            $notifications = $db->query($sql, $params);
            
            // Get unread count
            $unreadCount = $db->queryOne(
                "SELECT COUNT(*) as count FROM notifications 
                 WHERE organization_id = :org_id AND (user_id IS NULL OR user_id = :user_id) AND is_read = 0",
                ['org_id' => $organizationId, 'user_id' => $userId]
            )['count'] ?? 0;
            
            jsonResponse([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => (int)$unreadCount
            ]);
        } catch (Exception $e) {
            // If table doesn't exist or query fails, return empty result
            error_log("Notifications API error: " . $e->getMessage());
            jsonResponse([
                'success' => true,
                'notifications' => [],
                'unread_count' => 0
            ]);
        }
    }

    // MARK AS READ
    if ($action === 'mark_read' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['id'])) {
            jsonResponse(['success' => false, 'message' => 'Notification ID required'], 400);
        }
        
        try {
            // Verify notification belongs to organization
            $notification = $db->queryOne(
                "SELECT id FROM notifications 
                 WHERE id = :id AND organization_id = :org_id AND (user_id IS NULL OR user_id = :user_id)",
                ['id' => $input['id'], 'org_id' => $organizationId, 'user_id' => $userId]
            );
            
            if (!$notification) {
                jsonResponse(['success' => false, 'message' => 'Notification not found'], 404);
            }
            
            $db->update('notifications', $input['id'], [
                'is_read' => 1,
                'read_at' => date('Y-m-d H:i:s')
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Notification marked as read']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update notification: ' . $e->getMessage()], 500);
        }
    }

    // MARK ALL AS READ
    if ($action === 'mark_all_read' && $method === 'POST') {
        try {
            $db->execute(
                "UPDATE notifications 
                 SET is_read = 1, read_at = NOW() 
                 WHERE organization_id = :org_id AND (user_id IS NULL OR user_id = :user_id) AND is_read = 0",
                ['org_id' => $organizationId, 'user_id' => $userId]
            );
            
            jsonResponse(['success' => true, 'message' => 'All notifications marked as read']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to update notifications: ' . $e->getMessage()], 500);
        }
    }

    jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    
} catch (Exception $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json', true);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ];
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $response['error'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'message' => $e->getMessage()
        ];
    }
    
    echo json_encode($response);
    exit;
}
