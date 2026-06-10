<?php
/**
 * Import Members API Endpoint
 * Handles bulk import of members from CSV data
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
use Headcount\Helpers\Validator;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;

// Start output buffering to prevent any accidental output
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Set JSON response header
header('Content-Type: application/json');

// Load configuration and initialize database
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    jsonResponse(['success' => false, 'message' => 'Configuration file not found'], 500);
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Database connection failed'], 500);
}

// Load helpers

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Check authentication using AuthMiddleware
try {
    AuthMiddleware::requireAdmin();
    $organizationId = AuthMiddleware::getOrganizationId();
} catch (\Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized: ' . $e->getMessage()], 401);
}

CsrfMiddleware::verify();

// Check request method
if (!isPost()) {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$db = Database::getInstance();

// Handle both file upload (FormData) and JSON data
$members = [];
$duplicateAction = 'skip';

// Check if this is a file upload
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    // Handle CSV file upload
    $file = $_FILES['file'];
    
    // Validate file type
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($fileExt !== 'csv') {
        jsonResponse(['success' => false, 'message' => 'Invalid file type. Please upload a CSV file.'], 400);
    }
    
    // Read and parse CSV
    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        jsonResponse(['success' => false, 'message' => 'Failed to read uploaded file'], 400);
    }
    
    // Read header row
    $headers = fgetcsv($handle);
    if ($headers === false) {
        fclose($handle);
        jsonResponse(['success' => false, 'message' => 'Invalid CSV file format'], 400);
    }
    
    // Normalize headers (trim and lowercase)
    $headers = array_map(function($h) {
        return strtolower(trim($h));
    }, $headers);
    
    // Map common column names
    $columnMap = [];
    foreach ($headers as $index => $header) {
        $header = str_replace([' ', '_', '-'], '', $header);
        if (in_array($header, ['firstname', 'first', 'fname', 'givenname'])) {
            $columnMap['first_name'] = $index;
        } elseif (in_array($header, ['lastname', 'last', 'lname', 'surname', 'familyname'])) {
            $columnMap['last_name'] = $index;
        } elseif (in_array($header, ['email', 'e-mail', 'emailaddress'])) {
            $columnMap['email'] = $index;
        } elseif (in_array($header, ['phone', 'phonenumber', 'mobile', 'cell', 'telephone'])) {
            $columnMap['phone'] = $index;
        } elseif (in_array($header, ['gender', 'sex'])) {
            $columnMap['gender'] = $index;
        }
    }
    
    // Check required columns
    if (!isset($columnMap['first_name']) || !isset($columnMap['last_name'])) {
        fclose($handle);
        jsonResponse(['success' => false, 'message' => 'CSV must contain first_name and last_name columns'], 400);
    }
    
    // Read data rows
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < count($headers)) {
            continue; // Skip incomplete rows
        }
        
        $member = [];
        if (isset($columnMap['first_name'])) {
            $member['first_name'] = trim($row[$columnMap['first_name']] ?? '');
        }
        if (isset($columnMap['last_name'])) {
            $member['last_name'] = trim($row[$columnMap['last_name']] ?? '');
        }
        if (isset($columnMap['email'])) {
            $member['email'] = trim($row[$columnMap['email']] ?? '');
        }
        if (isset($columnMap['phone'])) {
            $member['phone'] = trim($row[$columnMap['phone']] ?? '');
        }
        if (isset($columnMap['gender'])) {
            $member['gender'] = strtolower(trim($row[$columnMap['gender']] ?? ''));
        }
        
        if (!empty($member['first_name']) && !empty($member['last_name'])) {
            $members[] = $member;
        }
    }
    
    fclose($handle);
    
    // Get duplicate action from POST data if provided
    $duplicateAction = $_POST['duplicate_action'] ?? 'skip';
    
} else {
    // Handle JSON data (pre-processed members)
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON data: ' . json_last_error_msg()], 400);
    }
    
    $members = $input['members'] ?? [];
    $duplicateAction = $input['duplicate_action'] ?? 'skip';
}

if (empty($members) || !is_array($members)) {
    jsonResponse(['success' => false, 'message' => 'No members data provided'], 400);
}

$imported = 0;
$skipped = 0;
$errors = 0;

foreach ($members as $member) {
    try {
        // Skip if missing required fields
        if (empty($member['first_name']) || empty($member['last_name'])) {
            $errors++;
            continue;
        }

        $emailForDb = null;
        if (isset($member['email']) && trim((string) $member['email']) !== '') {
            $emailForDb = trim(strtolower(trim((string) $member['email'])));
            if (!Validator::email($emailForDb)) {
                $errors++;
                continue;
            }
        }
        
        // Check for duplicates (within same organization)
        $duplicate = null;
        if ($emailForDb !== null) {
            $duplicate = $db->queryOne(
                "SELECT id FROM users WHERE email = ? AND organization_id = ? AND role = 'member' AND status != 'deleted' LIMIT 1",
                [$emailForDb, $organizationId]
            );
        }
        if (!$duplicate && !empty($member['phone'])) {
            $duplicate = $db->queryOne(
                "SELECT id FROM users WHERE phone = ? AND organization_id = ? AND role = 'member' AND status != 'deleted' LIMIT 1",
                [$member['phone'], $organizationId]
            );
        }
        
        if ($duplicate) {
            if ($duplicateAction === 'skip') {
                $skipped++;
                continue;
            } elseif ($duplicateAction === 'update') {
                // Update existing record
                $updateData = [
                    'first_name' => $member['first_name'],
                    'last_name' => $member['last_name']
                ];
                
                if ($emailForDb !== null) {
                    $updateData['email'] = $emailForDb;
                }
                if (!empty($member['phone'])) {
                    $updateData['phone'] = $member['phone'];
                }
                if (!empty($member['gender'])) {
                    $updateData['gender'] = $member['gender'];
                }
                
                $db->update('users', $duplicate['id'], $updateData);
                $imported++;
                continue;
            }
            // If 'create', fall through to insert
        }
        
        // Insert new member
        $insertData = [
            'first_name' => $member['first_name'],
            'last_name' => $member['last_name'],
            'organization_id' => $organizationId,
            'role' => 'member',
            'status' => 'active'
        ];
        
        if ($emailForDb !== null) {
            $insertData['email'] = $emailForDb;
        }
        if (!empty($member['phone'])) {
            $insertData['phone'] = $member['phone'];
        }
        if (!empty($member['gender'])) {
            $insertData['gender'] = $member['gender'];
        }
        
        $db->insert('users', $insertData);
        $imported++;
        
    } catch (\Exception $e) {
        $errors++;
        error_log('Import error: ' . $e->getMessage());
    }
}

jsonResponse([
    'success' => true,
    'message' => "Successfully imported $imported member" . ($imported !== 1 ? 's' : ''),
    'data' => [
        'success' => $imported,
        'failed' => $errors,
        'duplicates' => $skipped
    ],
    'imported' => $imported,
    'skipped' => $skipped,
    'errors' => $errors
]);
