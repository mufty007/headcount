<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\RecurringEventService;

// Start output buffering
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    // Load config
    $config = require __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../src/helpers.php';

    // Initialize database
    Database::getInstance($config['database']);

    // Start session if needed
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Clear any output
    ob_clean();
    header('Content-Type: application/json');

    // Check authentication
    AuthMiddleware::requireAdmin();
    $organizationId = AuthMiddleware::getOrganizationId();
    $userId = AuthMiddleware::getUserId();

    $db = Database::getInstance();
    $action = $_GET['action'] ?? 'generate';

    // GENERATE instances for a recurring event
    if ($action === 'generate' && isset($_GET['event_id'])) {
        $eventId = (int)$_GET['event_id'];
        
        // Verify event belongs to organization
        $event = $db->queryOne(
            "SELECT id, organization_id FROM events WHERE id = ? AND organization_id = ?",
            [$eventId, $organizationId]
        );
        
        if (!$event) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
        }
        
        // Get recurring event data
        $recurring = $db->queryOne(
            "SELECT * FROM recurring_events WHERE parent_event_id = ?",
            [$eventId]
        );
        
        if (!$recurring) {
            jsonResponse(['success' => false, 'message' => 'Event is not a recurring event'], 400);
        }
        
        try {
            $recurringService = new RecurringEventService();
            $recurrenceData = [
                'recurrence_type' => $recurring['recurrence_type'],
                'interval' => $recurring['interval'],
                'end_type' => $recurring['end_type'],
                'end_after_count' => $recurring['end_after_count'],
                'end_date' => $recurring['end_date'],
                'days_of_week' => $recurring['days_of_week']
            ];
            
            if (($recurring['recurrence_type'] ?? '') === 'custom' && !empty($recurring['custom_dates'])) {
                $recurrenceData['custom_dates'] = $recurring['custom_dates'];
            }

            $generatedIds = $recurringService->generateInstances($eventId, $recurrenceData);
            if (($recurring['recurrence_type'] ?? '') === 'custom' && !empty($recurring['custom_dates'])) {
                RecurringEventService::pruneStaleCustomSeriesChildren($db, $eventId, (string) $recurring['custom_dates']);
            }
            
            jsonResponse([
                'success' => true,
                'generated_count' => count($generatedIds),
                'event_ids' => $generatedIds,
                'message' => 'Generated ' . count($generatedIds) . ' event instance(s)'
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to generate instances: ' . $e->getMessage()], 500);
        }
    }

    // DELETE all instances of a recurring event
    if ($action === 'delete_instances' && isPost()) {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['event_id'])) {
            jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
        }
        
        $eventId = (int)$input['event_id'];
        
        // Verify event belongs to organization
        $event = $db->queryOne(
            "SELECT id, organization_id FROM events WHERE id = ? AND organization_id = ?",
            [$eventId, $organizationId]
        );
        
        if (!$event) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
        }
        
        try {
            $recurringService = new RecurringEventService();
            $deletedCount = $recurringService->deleteAllInstances($eventId);
            
            jsonResponse([
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "Deleted {$deletedCount} instance(s)"
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to delete instances: ' . $e->getMessage()], 500);
        }
    }

    // GET recurring event info
    if ($action === 'get' && isset($_GET['event_id'])) {
        $eventId = (int)$_GET['event_id'];
        
        $recurring = $db->queryOne(
            "SELECT re.*, 
                    (SELECT COUNT(*) FROM events WHERE parent_event_id = re.parent_event_id) as instance_count,
                    (SELECT MAX(event_date) FROM events WHERE parent_event_id = re.parent_event_id) as last_instance_date
             FROM recurring_events re
             WHERE re.parent_event_id = ? AND re.organization_id = ?",
            [$eventId, $organizationId]
        );
        
        if ($recurring) {
            jsonResponse(['success' => true, 'recurring' => $recurring]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Recurring event not found'], 404);
        }
    }

    // GENERATE all upcoming instances (for cron)
    if ($action === 'generate_all' && isPost()) {
        try {
            $recurringService = new RecurringEventService();
            $generatedCount = $recurringService->generateUpcomingInstances();
            
            jsonResponse([
                'success' => true,
                'generated_count' => $generatedCount,
                'message' => "Generated {$generatedCount} event instance(s)"
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Failed to generate instances: ' . $e->getMessage()], 500);
        }
    }

    jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
    exit;
} catch (Error $e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
    exit;
} finally {
    ob_end_flush();
}
?>
