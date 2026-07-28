<?php
/**
 * Import Members API Endpoint
 * Handles bulk import of members from CSV data with smart-fill and auto-tagging
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

/**
 * Digit variants for phone matching (exact match, US/CA +1 tolerant).
 *
 * @return string[]
 */
function import_phone_digit_variants(string $phone): array
{
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === null || $digits === '' || strlen($digits) < 7) {
        return [];
    }

    $variants = [$digits];
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $variants[] = substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        $variants[] = '1' . $digits;
    }

    return array_values(array_unique($variants));
}

/**
 * Find an existing member by email, then by digit-normalized phone.
 *
 * @return array|null
 */
function import_find_duplicate($db, int $organizationId, ?string $email, ?string $phone): ?array
{
    $select = "SELECT id, first_name, last_name, email, phone, gender FROM users
               WHERE organization_id = ? AND role = 'member' AND status != 'deleted'";

    if ($email !== null && $email !== '') {
        $row = $db->queryOne($select . ' AND email = ? LIMIT 1', [$organizationId, $email]);
        if ($row) {
            return $row;
        }
    }

    if ($phone !== null && trim($phone) !== '') {
        $variants = import_phone_digit_variants($phone);
        if (!empty($variants)) {
            $digitsSql = headcount_sql_phone_digits('phone');
            $placeholders = implode(', ', array_fill(0, count($variants), '?'));
            $params = array_merge([$organizationId], $variants);
            $row = $db->queryOne(
                $select . " AND {$digitsSql} IN ({$placeholders}) LIMIT 1",
                $params
            );
            if ($row) {
                return $row;
            }
        }
    }

    return null;
}

/**
 * Build smart-fill update payload: only fill empty fields on the existing member.
 *
 * @return array<string, mixed>
 */
function import_build_smart_fill_patch(array $existing, array $member, ?string $emailForDb): array
{
    $patch = [];
    $fillable = [
        'first_name' => $member['first_name'] ?? null,
        'last_name' => $member['last_name'] ?? null,
        'email' => $emailForDb,
        'phone' => !empty($member['phone']) ? trim((string) $member['phone']) : null,
        'gender' => !empty($member['gender']) ? strtolower(trim((string) $member['gender'])) : null,
    ];

    foreach ($fillable as $field => $incoming) {
        if ($incoming === null || $incoming === '') {
            continue;
        }
        $current = $existing[$field] ?? null;
        if ($current === null || trim((string) $current) === '') {
            $patch[$field] = $incoming;
        }
    }

    return $patch;
}

/**
 * Assign selected import tags to a member (ignore already-assigned).
 *
 * @param int[] $tagIds
 */
function import_assign_selected_tags($db, int $memberId, array $tagIds): void
{
    foreach ($tagIds as $tagId) {
        try {
            $db->insert('member_tags', [
                'user_id' => $memberId,
                'tag_id' => (int) $tagId,
            ]);
        } catch (\Exception $e) {
            // Already assigned or constraint race — ignore
        }
    }
}

/**
 * Assign default tags (All Members + gender) on create — mirrors MemberService::assignDefaultTags.
 */
function import_assign_default_tags($db, int $memberId, int $organizationId, ?string $gender = null): void
{
    try {
        $allMembersTag = $db->queryOne(
            'SELECT id FROM tags WHERE name = ? AND organization_id = ?',
            ['All Members', $organizationId]
        );

        if (!$allMembersTag) {
            $allMembersTagId = $db->insert('tags', [
                'organization_id' => $organizationId,
                'name' => 'All Members',
                'color' => '#3B82F6',
            ]);
        } else {
            $allMembersTagId = $allMembersTag['id'];
        }

        try {
            $db->insert('member_tags', [
                'user_id' => $memberId,
                'tag_id' => $allMembersTagId,
            ]);
        } catch (\Exception $e) {
            // already assigned
        }

        if ($gender) {
            $genderLower = strtolower(trim($gender));
            $genderTagName = '';
            if ($genderLower === 'female' || $genderLower === 'f') {
                $genderTagName = 'Female Member';
            } elseif ($genderLower === 'male' || $genderLower === 'm') {
                $genderTagName = 'Male Member';
            }

            if ($genderTagName !== '') {
                $genderTag = $db->queryOne(
                    'SELECT id FROM tags WHERE name = ? AND organization_id = ?',
                    [$genderTagName, $organizationId]
                );

                if (!$genderTag) {
                    $genderTagId = $db->insert('tags', [
                        'organization_id' => $organizationId,
                        'name' => $genderTagName,
                        'color' => ($genderLower === 'female' || $genderLower === 'f') ? '#EC4899' : '#3B82F6',
                    ]);
                } else {
                    $genderTagId = $genderTag['id'];
                }

                try {
                    $db->insert('member_tags', [
                        'user_id' => $memberId,
                        'tag_id' => $genderTagId,
                    ]);
                } catch (\Exception $e) {
                    // already assigned
                }
            }
        }
    } catch (\Exception $e) {
        error_log('Import default tags error: ' . $e->getMessage());
    }
}

// Handle both file upload (FormData) and JSON data
$members = [];
$duplicateAction = 'smart_fill';
$tagIds = [];

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
    $headers = array_map(function ($h) {
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

    // Get duplicate action and tags from POST data if provided
    $duplicateAction = $_POST['duplicate_action'] ?? 'smart_fill';
    if (isset($_POST['tag_ids'])) {
        $rawTags = $_POST['tag_ids'];
        if (is_string($rawTags)) {
            $decoded = json_decode($rawTags, true);
            $tagIds = is_array($decoded) ? $decoded : (array) $rawTags;
        } elseif (is_array($rawTags)) {
            $tagIds = $rawTags;
        }
    }
} else {
    // Handle JSON data (pre-processed members)
    $input = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON data: ' . json_last_error_msg()], 400);
    }

    $members = $input['members'] ?? [];
    $duplicateAction = $input['duplicate_action'] ?? 'smart_fill';
    $tagIds = $input['tag_ids'] ?? [];
}

// Normalize duplicate action
$allowedActions = ['smart_fill', 'skip', 'update'];
if (!in_array($duplicateAction, $allowedActions, true)) {
    // Legacy 'create' treated as smart_fill (no longer allow intentional duplicates via API default path)
    $duplicateAction = 'smart_fill';
}

// Validate and normalize tag IDs (must belong to this organization)
$tagIds = array_values(array_unique(array_filter(array_map('intval', (array) $tagIds), static function ($id) {
    return $id > 0;
})));

if (!empty($tagIds)) {
    $placeholders = implode(', ', array_fill(0, count($tagIds), '?'));
    $params = array_merge([$organizationId], $tagIds);
    $validTags = $db->query(
        "SELECT id FROM tags WHERE organization_id = ? AND id IN ({$placeholders})",
        $params
    );
    $validIds = array_map('intval', array_column($validTags ?: [], 'id'));
    if (count($validIds) !== count($tagIds)) {
        jsonResponse(['success' => false, 'message' => 'One or more selected tags are invalid for this organization'], 400);
    }
    $tagIds = $validIds;
}

if (empty($members) || !is_array($members)) {
    jsonResponse(['success' => false, 'message' => 'No members data provided'], 400);
}

$imported = 0;
$updated = 0;
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
            $emailForDb = strtolower(trim((string) $member['email']));
            if (!Validator::email($emailForDb)) {
                $errors++;
                continue;
            }
        }

        $phone = !empty($member['phone']) ? trim((string) $member['phone']) : null;
        $gender = !empty($member['gender']) ? strtolower(trim((string) $member['gender'])) : null;

        $duplicate = import_find_duplicate($db, (int) $organizationId, $emailForDb, $phone);

        if ($duplicate) {
            if ($duplicateAction === 'skip') {
                $skipped++;
                continue;
            }

            if ($duplicateAction === 'update') {
                // Overwrite provided fields
                $updateData = [
                    'first_name' => $member['first_name'],
                    'last_name' => $member['last_name'],
                ];

                if ($emailForDb !== null) {
                    $updateData['email'] = $emailForDb;
                }
                if ($phone !== null) {
                    $updateData['phone'] = $phone;
                }
                if ($gender !== null) {
                    $updateData['gender'] = $gender;
                }

                $db->update('users', $duplicate['id'], $updateData);
                import_assign_selected_tags($db, (int) $duplicate['id'], $tagIds);
                $updated++;
                continue;
            }

            // smart_fill (default)
            $patch = import_build_smart_fill_patch($duplicate, $member, $emailForDb);
            if (empty($patch)) {
                // Nothing to fill — still apply selected tags if any? Plan says:
                // "apply to every newly created or smart-filled/updated member"
                // Pure skip = no update, so no tag assign on pure duplicates
                $skipped++;
                continue;
            }

            $db->update('users', $duplicate['id'], $patch);
            import_assign_selected_tags($db, (int) $duplicate['id'], $tagIds);
            $updated++;
            continue;
        }

        // Insert new member
        $insertData = [
            'first_name' => $member['first_name'],
            'last_name' => $member['last_name'],
            'organization_id' => $organizationId,
            'role' => 'member',
            'status' => 'active',
        ];

        if ($emailForDb !== null) {
            $insertData['email'] = $emailForDb;
        }
        if ($phone !== null) {
            $insertData['phone'] = $phone;
        }
        if ($gender !== null) {
            $insertData['gender'] = $gender;
        }

        $newId = $db->insert('users', $insertData);
        import_assign_default_tags($db, (int) $newId, (int) $organizationId, $gender);
        import_assign_selected_tags($db, (int) $newId, $tagIds);
        $imported++;
    } catch (\Exception $e) {
        $errors++;
        error_log('Import error: ' . $e->getMessage());
    }
}

jsonResponse([
    'success' => true,
    'message' => "Successfully imported $imported member" . ($imported !== 1 ? 's' : '')
        . ($updated > 0 ? ", updated $updated" : ''),
    'data' => [
        'success' => $imported,
        'updated' => $updated,
        'failed' => $errors,
        'duplicates' => $skipped,
    ],
    'imported' => $imported,
    'updated' => $updated,
    'skipped' => $skipped,
    'errors' => $errors,
]);
