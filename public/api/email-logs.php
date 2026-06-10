<?php
/**
 * Email Logs API
 * List email log entries for the organization; POST action=resend to resend a failed/sent email
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
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\EmailService;
use Headcount\Services\PortalEmailService;

header('Content-Type: application/json');

if (!AuthMiddleware::requireAdmin()) {
    exit;
}

$organizationId = AuthMiddleware::getOrganizationId();
if ($organizationId === null || (int)$organizationId < 1) {
    $organizationId = 1;
} else {
    $organizationId = (int)$organizationId;
}

$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

// POST resend: resend a single email by log id
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? $_POST['action'] ?? '';
    $logId = isset($input['id']) ? (int)$input['id'] : (int)($_POST['id'] ?? 0);

    if ($action === 'resend' && $logId > 0) {
        $log = $db->queryOne("SELECT * FROM email_logs WHERE id = :id AND organization_id = :org_id", [
            'id' => $logId,
            'org_id' => $organizationId
        ]);
        if (!$log) {
            jsonResponse(['success' => false, 'message' => 'Email log entry not found.'], 404);
            exit;
        }

        $org = $db->queryOne(
            "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
            [$organizationId]
        );
        if (!$org || empty($org['smtp_from_email'])) {
            jsonResponse(['success' => false, 'message' => 'Email is not configured. Configure SMTP in Settings > Email.'], 400);
            exit;
        }

        $apiKey = null;
        if (!empty($org['smtp_api_key'])) {
            $apiKey = base64_decode($org['smtp_api_key'], true);
        }
        if (($apiKey === false || empty($apiKey)) && !empty($org['smtp_api_key_encrypted'])) {
            $encKey = $config['security']['encryption_key'] ?? null;
            if ($encKey && class_exists(\Headcount\Helpers\Security::class)) {
                $apiKey = \Headcount\Helpers\Security::decrypt($org['smtp_api_key_encrypted'], $encKey);
            }
        }
        if (empty($apiKey)) {
            jsonResponse(['success' => false, 'message' => 'Invalid API key. Reconfigure email in Settings.'], 400);
            exit;
        }

        $emailConfig = [
            'api_key' => $apiKey,
            'from_email' => $org['smtp_from_email'],
            'from_name' => $org['smtp_from_name'] ?? null,
            'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
        ];

        $to = $log['recipient_email'];
        $subject = $log['subject'];
        $emailType = $log['email_type'] ?? 'custom';

        $body = '';
        $canReconstructRsvp = ($emailType === 'rsvp_confirmation' || $emailType === 'confirmation' || $emailType === 'custom')
            && !empty($log['event_id']) && !empty($log['recipient_user_id']);
        if ($canReconstructRsvp) {
            $event = $db->queryOne("SELECT * FROM events WHERE id = :id AND organization_id = :org_id", ['id' => $log['event_id'], 'org_id' => $organizationId]);
            $member = $db->queryOne("SELECT * FROM users WHERE id = :id", ['id' => $log['recipient_user_id']]);
            if ($event && $member) {
                $portalEmail = new PortalEmailService($emailConfig);
                $body = $portalEmail->buildRSVPConfirmationBody($event, $member);
            }
        }
        if ($body === '') {
            $body = '<p>This is a resent copy of the following message.</p><p><strong>Subject:</strong> ' . htmlspecialchars($subject) . '</p><p>If you have questions, please contact us.</p>';
        }

        $emailService = new EmailService($emailConfig);
        $result = $emailService->resendToLog((int)$log['id'], $to, $subject, $body, $organizationId);

        if ($result['success']) {
            jsonResponse(['success' => true, 'message' => 'Email resent successfully.', 'status' => 'sent']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Resend failed: ' . ($result['error'] ?? 'Unknown error'), 'status' => 'failed'], 400);
        }
        exit;
    }
}

$limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$status = $_GET['status'] ?? null;
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;

$where = ["el.organization_id = :org_id"];
$params = ['org_id' => $organizationId];

if ($status !== null && $status !== '') {
    $where[] = "el.status = :status";
    $params['status'] = $status;
}
if ($eventId > 0) {
    $where[] = "el.event_id = :event_id";
    $params['event_id'] = $eventId;
}

$whereClause = implode(' AND ', $where);

$sql = "SELECT el.id, el.event_id, el.recipient_user_id, el.recipient_email, el.subject, 
        el.email_type, el.status, el.error_message, el.sent_at, el.created_at,
        e.title as event_title,
        u.first_name as recipient_first_name, u.last_name as recipient_last_name
        FROM email_logs el
        LEFT JOIN events e ON el.event_id = e.id
        LEFT JOIN users u ON el.recipient_user_id = u.id
        WHERE {$whereClause}
        ORDER BY el.created_at DESC
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

$logs = $db->query($sql, $params);

$countSql = "SELECT COUNT(*) as count FROM email_logs el WHERE {$whereClause}";
$total = (int)$db->queryOne($countSql, $params)['count'];

jsonResponse([
    'success' => true,
    'logs' => $logs,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset
]);
