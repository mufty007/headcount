<?php
/**
 * Bulk Email API
 * Handles sending emails to multiple members
 */
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// On fatal error, try to return JSON so the client sees a message
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . ($err['message'] ?? 'Unknown error')]);
    }
});

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\EmailService;

try {
    // Check authentication and admin role
    if (!AuthMiddleware::requireAdmin()) {
        exit;
    }

    $organizationId = AuthMiddleware::getOrganizationId();
    $config = require __DIR__ . '/../../config/config.php';
    $db = Database::getInstance($config['database']);

    // Verify CSRF token
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $token ?? $input['csrf_token'] ?? null;

    if (!$token || !Security::verifyCSRFToken($token)) {
        jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
        exit;
    }

    $userIds = $input['user_ids'] ?? [];
    $sendTo = $input['send_to'] ?? 'members'; // 'all' | 'event' | 'members'
    $eventId = isset($input['event_id']) ? (int)$input['event_id'] : null;
    $templateId = $input['template_id'] ?? null;
    $customSubject = $input['subject'] ?? null;
    $customBody = $input['body'] ?? null;

    // Resolve recipients by send_to type
    if ($sendTo === 'all') {
        $rows = $db->query(
            "SELECT id FROM users WHERE organization_id = ? AND role = 'member' AND status = 'active' AND email IS NOT NULL AND email != ''",
            [$organizationId]
        );
        $userIds = array_column($rows, 'id');
    } elseif ($sendTo === 'event' && $eventId > 0) {
        $event = $db->queryOne("SELECT id, title FROM events WHERE id = ? AND organization_id = ?", [$eventId, $organizationId]);
        if (!$event) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
            exit;
        }
        $rows = $db->query(
            "SELECT user_id FROM rsvps WHERE event_id = ? AND status = 'yes'",
            [$eventId]
        );
        $userIds = array_values(array_unique(array_column($rows, 'user_id')));
    }

    if (empty($userIds)) {
        $msg = $sendTo === 'all' ? 'No members with email addresses found' : ($sendTo === 'event' ? 'No RSVPs found for this event' : 'No recipients selected');
        jsonResponse(['success' => false, 'message' => $msg], 400);
        exit;
    }

    // Get subject and body from template or custom input
    $subject = $customSubject;
    $body = $customBody;

    if ($templateId) {
        $template = $db->queryOne(
            "SELECT * FROM email_templates WHERE id = ? AND (organization_id = ? OR organization_id IS NULL)",
            [$templateId, $organizationId]
        );
        if ($template) {
            $subject = $template['subject'];
            $body = $template['body_html'];
        }
    }

    if (empty($subject) || empty($body)) {
        jsonResponse(['success' => false, 'message' => 'Email subject and body are required'], 400);
        exit;
    }

    // Get recipient data
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT id, email, first_name, last_name, phone FROM users 
            WHERE id IN ($placeholders) 
            AND organization_id = ? 
            AND status = 'active'
            AND email IS NOT NULL 
            AND email != ''";

    $params = array_merge($userIds, [$organizationId]);
    $recipients = $db->query($sql, $params);

    if (empty($recipients)) {
        jsonResponse(['success' => false, 'message' => 'No valid recipients found with email addresses'], 400);
        exit;
    }

    // Initialize Email Service
    // Resolve SMTP credentials using the same pattern as email-logs.php, rsvps.php, guest-rsvp.php, etc.
    $org = $db->queryOne(
        "SELECT name, logo_path, smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
        [$organizationId]
    );

    $smtpApiKey = null;

    // Step 1: try legacy base64-encoded smtp_api_key column (set by older Settings UI)
    if (!empty($org['smtp_api_key'])) {
        $decoded = base64_decode($org['smtp_api_key'], true);
        if ($decoded !== false && !empty($decoded)) {
            $smtpApiKey = $decoded;
        }
    }

    // Step 2: try AES-encrypted smtp_api_key_encrypted column (set by current Settings UI)
    if (empty($smtpApiKey) && !empty($org['smtp_api_key_encrypted'])) {
        $encKey = $config['security']['encryption_key'] ?? null;
        if ($encKey) {
            $decrypted = Security::decrypt($org['smtp_api_key_encrypted'], $encKey);
            if ($decrypted !== false && !empty($decrypted)) {
                $smtpApiKey = $decrypted;
            }
        }
        // If no encryption_key in config, fall through — error will surface below
    }

    if (!empty($smtpApiKey) && !empty($org['smtp_from_email'])) {
        $smtpConfig = [
            'api_key'    => $smtpApiKey,
            'from_email' => $org['smtp_from_email'],
            'from_name'  => $org['smtp_from_name'] ?? '',
            'reply_to'   => $org['smtp_reply_to']  ?? $org['smtp_from_email'],
        ];
    } else {
        // Fall back to global config.php smtp2go settings (dev/fallback only)
        if (!empty($config['smtp2go']['api_key'])) {
            $smtpConfig = $config['smtp2go'];
        } else {
            $debugHint = '';
            if (!empty($config['app']['debug'])) {
                if (empty($org['smtp_api_key']) && empty($org['smtp_api_key_encrypted'])) {
                    $debugHint = ' [No SMTP key found in organization record]';
                } elseif (empty($config['security']['encryption_key'])) {
                    $debugHint = ' [smtp_api_key_encrypted found but config security.encryption_key is not set]';
                } else {
                    $debugHint = ' [smtp_api_key_encrypted found but decryption returned empty — key mismatch?]';
                }
            }
            jsonResponse(['success' => false, 'message' => 'Email service is not configured. Please add your SMTP2GO API key in Settings → Email (Email SMTP tab).' . $debugHint], 503);
            exit;
        }
    }

    $emailService = new EmailService($smtpConfig);

    $appUrl = rtrim($config['app']['url'] ?? '', '/');
    $logoUrl = !empty($org['logo_path']) ? buildLogoUrlForEmail($appUrl, $org['logo_path']) : null;

    foreach ($recipients as &$recipient) {
        $recipient['organization_name'] = $org['name'] ?? 'The Organization';
    }

    $results = $emailService->sendBulk($recipients, $subject, $body, $organizationId, [
        'template' => $templateId ? 'template_' . $templateId : 'bulk_custom',
        'email_type' => 'bulk_admin',
        'logo_url' => $logoUrl,
        'org_name' => $org['name'] ?? ''
    ]);

    jsonResponse([
        'success' => true,
        'message' => "Successfully sent {$results['sent']} emails. {$results['failed']} failed.",
        'details' => $results
    ]);
} catch (\Throwable $e) {
    if (!function_exists('jsonResponse')) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
        exit;
    }
    error_log("Bulk email error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $payload = ['success' => false, 'message' => 'An error occurred while sending emails: ' . $e->getMessage()];
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        $payload['debug'] = ['file' => $e->getFile(), 'line' => $e->getLine()];
    }
    jsonResponse($payload, 500);
}
