<?php
// Disable error display, log errors instead
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set error handler to return JSON only on critical errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (error_reporting() === 0) return false;
    $msg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($msg);
    
    $critical = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR, E_USER_ERROR];
    if (in_array($errno, $critical) && !headers_sent()) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'message' => 'Server error occurred', 'error' => $msg]);
        exit;
    }
    return true;
});

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

header('Content-Type: application/json');

// Check authentication and admin role (handles API responses automatically)
if (!AuthMiddleware::requireAdmin()) {
    exit; // requireAdmin() already sent JSON response
}

$organizationId = AuthMiddleware::getOrganizationId();

$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

// Verify CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $token ?? $input['csrf_token'] ?? null;
    
    if (!$token || !Security::verifyCSRFToken($token)) {
        jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
    }
}

// Read action from GET or POST body (campaign builder POSTs action in body)
// $input is already decoded above from php://input during CSRF check
$action = $_GET['action'] ?? null;
if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($input)) {
    $action = $input['action'] ?? null;
}
$action = $action ?? 'get';

// LIST templates (optional type filter for campaign builder)
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $typeFilter = $_GET['template_type'] ?? null;
    $sql = "SELECT id, name, subject, template_type, thumbnail_path, design_json FROM email_templates WHERE organization_id = ?";
    $params = [$organizationId];
    if ($typeFilter === 'custom') {
        $sql .= " AND template_type = 'custom'";
    }
    $sql .= " ORDER BY template_type, name, id";
    $templates = $db->query($sql, $params);
    jsonResponse(['success' => true, 'templates' => $templates]);
    exit;
}

// GET single template
if (($action === 'get' || $action === '') && isset($_GET['id'])) {
    $templateId = (int)$_GET['id'];
    $template = $db->queryOne("SELECT * FROM email_templates WHERE id = ? AND (organization_id = ? OR organization_id IS NULL)", [$templateId, $organizationId]);
    
    if ($template) {
        jsonResponse(['success' => true, 'template' => $template]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Template not found'], 404);
    }
    exit;
}

// CREATE template
if ($action === 'create' && isPost()) {
    // $input already parsed during CSRF check
    
    try {
        // For non-custom types, allow only one template per type per org. Custom allows multiple.
        if (($input['template_type'] ?? '') !== 'custom') {
            $existing = $db->queryOne("SELECT id FROM email_templates WHERE organization_id = ? AND template_type = ?", [$organizationId, $input['template_type']]);
            if ($existing) {
                jsonResponse(['success' => false, 'message' => 'A template of this type already exists'], 400);
            }
        }
        
        $insertData = [
            'organization_id' => $organizationId,
            'template_type' => $input['template_type'] ?? 'custom',
            'subject' => $input['subject'] ?? ($input['name'] ?? 'Untitled'),
            'body_html' => $input['body_html'] ?? '',
            'is_default' => false
        ];
        $cols = $db->query("SHOW COLUMNS FROM email_templates");
        $colNames = array_column($cols, 'Field');
        if (in_array('body_blocks', $colNames) && array_key_exists('body_blocks', $input) && $input['body_blocks'] !== null) {
            $insertData['body_blocks'] = is_string($input['body_blocks']) ? $input['body_blocks'] : json_encode($input['body_blocks']);
        }
        if (in_array('name', $colNames) && isset($input['name'])) {
            $insertData['name'] = $input['name'];
        }
        if (in_array('design_json', $colNames) && isset($input['design_json'])) {
            $insertData['design_json'] = is_string($input['design_json']) ? $input['design_json'] : json_encode($input['design_json']);
        }
        $db->insert('email_templates', $insertData);
        
        jsonResponse(['success' => true, 'message' => 'Template created successfully']);
    } catch (Exception $e) {
        error_log("Create template error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to create template: ' . $e->getMessage()], 500);
    }
    exit;
}

// UPDATE template
if ($action === 'update' && isPost()) {
    // $input already parsed during CSRF check
    
    try {
        // Check if template exists and belongs to organization
        $template = $db->queryOne("SELECT * FROM email_templates WHERE id = ? AND (organization_id = ? OR organization_id IS NULL)", [$input['id'], $organizationId]);
        if (!$template) {
            jsonResponse(['success' => false, 'message' => 'Template not found'], 404);
        }
        
        // Don't allow updating default templates from other organizations
        if ($template['is_default'] && $template['organization_id'] != $organizationId && $template['organization_id'] !== null) {
            jsonResponse(['success' => false, 'message' => 'Cannot update default templates from other organizations'], 403);
        }
        
        // If changing template type to a non-custom type, check for conflicts (only one per type). Custom allows multiple.
        if ($input['template_type'] != $template['template_type'] && ($input['template_type'] ?? '') !== 'custom') {
            $existing = $db->queryOne("SELECT id FROM email_templates WHERE organization_id = ? AND template_type = ? AND id != ?", [$organizationId, $input['template_type'], $input['id']]);
            if ($existing) {
                jsonResponse(['success' => false, 'message' => 'A template of this type already exists'], 400);
            }
        }
        
        $updateData = [
            'template_type' => $input['template_type'] ?? $template['template_type'],
            'subject' => $input['subject'] ?? $template['subject'],
            'body_html' => $input['body_html'] ?? $template['body_html']
        ];
        $cols = $db->query("SHOW COLUMNS FROM email_templates");
        $colNames = array_column($cols, 'Field');
        if (in_array('body_blocks', $colNames) && array_key_exists('body_blocks', $input)) {
            $updateData['body_blocks'] = $input['body_blocks'] === null ? null : (is_string($input['body_blocks']) ? $input['body_blocks'] : json_encode($input['body_blocks']));
        }
        if (in_array('name', $colNames) && array_key_exists('name', $input)) {
            $updateData['name'] = $input['name'];
        }
        if (in_array('design_json', $colNames) && array_key_exists('design_json', $input)) {
            $updateData['design_json'] = $input['design_json'] === null ? null : (is_string($input['design_json']) ? $input['design_json'] : json_encode($input['design_json']));
        }
        $db->update('email_templates', $input['id'], $updateData);
        
        jsonResponse(['success' => true, 'message' => 'Template updated successfully']);
    } catch (Exception $e) {
        error_log("Update template error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update template: ' . $e->getMessage()], 500);
    }
    exit;
}

// DELETE template
if ($action === 'delete' && isPost()) {
    // $input already parsed during CSRF check
    
    try {
        // Check if template exists and belongs to organization
        $template = $db->queryOne("SELECT * FROM email_templates WHERE id = ? AND organization_id = ?", [$input['id'], $organizationId]);
        if (!$template) {
            jsonResponse(['success' => false, 'message' => 'Template not found'], 404);
        }
        
        // Don't allow deleting default templates
        if ($template['is_default']) {
            jsonResponse(['success' => false, 'message' => 'Cannot delete default templates'], 400);
        }
        
        $db->delete('email_templates', $input['id'], 'id', false); // Hard delete, no soft delete
        jsonResponse(['success' => true, 'message' => 'Template deleted successfully']);
    } catch (Exception $e) {
        error_log("Delete template error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to delete template: ' . $e->getMessage()], 500);
    }
    exit;
}

// DUPLICATE template
if ($action === 'duplicate' && isPost()) {
    // $input already parsed during CSRF check
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id < 1) {
        jsonResponse(['success' => false, 'message' => 'Template ID required'], 400);
        exit;
    }
    $template = $db->queryOne("SELECT * FROM email_templates WHERE id = ? AND (organization_id = ? OR organization_id IS NULL)", [$id, $organizationId]);
    if (!$template) {
        jsonResponse(['success' => false, 'message' => 'Template not found'], 404);
        exit;
    }
    unset($template['id'], $template['created_at'], $template['updated_at']);
    $template['organization_id'] = $organizationId;
    $template['is_default'] = false;
    $template['name'] = ($template['name'] ?? '') . ' (Copy)';
    $template['subject'] = $template['subject'] . ' (Copy)';
    $db->insert('email_templates', $template);
    jsonResponse(['success' => true, 'message' => 'Template duplicated']);
    exit;
}

// PREVIEW template
if ($action === 'preview' && isset($_GET['id'])) {
    $templateId = (int)$_GET['id'];
    $template = $db->queryOne("SELECT * FROM email_templates WHERE id = ? AND (organization_id = ? OR organization_id IS NULL)", [$templateId, $organizationId]);
    
    if ($template) {
        // Sample data for preview
        $sampleData = [
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john@example.com',
            'event_name' => 'Friday Night Service',
            'event_date' => 'December 15, 2024',
            'event_time' => '7:00 PM',
            'location' => 'Main Hall',
            'event_description' => 'Join us for an evening of worship and fellowship.',
            'rsvp_link' => '#rsvp',
            'event_link' => '#event',
            'amount' => '25.00',
            'payment_id' => 'pi_123456789',
            'payment_date' => 'December 10, 2024'
        ];
        
        $subject = $template['subject'];
        $body = $template['body_html'];
        
        foreach ($sampleData as $key => $value) {
            $subject = str_replace('{' . $key . '}', $value, $subject);
            $body = str_replace('{' . $key . '}', $value, $body);
        }
        
        jsonResponse(['success' => true, 'subject' => $subject, 'body' => $body]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Template not found'], 404);
    }
    exit;
}

// If we get here, no valid action was found
jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
?>
