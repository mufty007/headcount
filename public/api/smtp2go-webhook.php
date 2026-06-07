<?php
/**
 * SMTP2GO Webhook endpoint
 * TICKET-005: Receive delivered, open, click, bounce, unsubscribe events; persist to email_campaign_events.
 * Configure this URL in SMTP2GO Settings > Webhooks. Optional: secure with query param or IP allowlist.
 */
if (ob_get_level()) ob_end_clean();
ob_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (empty($payload) && $raw !== '') {
        parse_str($raw, $payload);
    }
    if (empty($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Empty body']);
        exit;
    }

    $event = isset($payload['event']) ? strtolower(trim($payload['event'])) : '';
    $allowed = ['delivered', 'open', 'click', 'bounce', 'unsubscribe', 'spam', 'reject', 'processed', 'resubscribe'];
    if (!in_array($event, $allowed, true)) {
        echo json_encode(['success' => true, 'message' => 'Event ignored']);
        exit;
    }

    $config = require __DIR__ . '/../../config/config.php';
    $db = Database::getInstance($config['database']);

    $tables = $db->query("SHOW TABLES LIKE 'email_campaign_events'");
    if (empty($tables)) {
        echo json_encode(['success' => true, 'message' => 'Table not present']);
        exit;
    }

    $emailId = $payload['email_id'] ?? $payload['email-id'] ?? null;
    $recipient = $payload['rcpt'] ?? $payload['recipients'] ?? '';
    if (is_array($recipient)) $recipient = $recipient[0] ?? '';
    $recipient = trim($recipient);
    $eventTime = isset($payload['time']) ? date('Y-m-d H:i:s', (int) $payload['time']) : date('Y-m-d H:i:s');
    $linkUrl = $payload['context']['url'] ?? $payload['link_url'] ?? $payload['url'] ?? null;
    if (is_string($linkUrl)) $linkUrl = substr($linkUrl, 0, 2048);

    $eventTypeMap = [
        'delivered' => 'delivered',
        'open' => 'opened',
        'click' => 'clicked',
        'bounce' => 'bounced',
        'unsubscribe' => 'unsubscribed',
        'spam' => 'unsubscribed',
        'reject' => 'bounced',
    ];
    $eventType = $eventTypeMap[$event] ?? null;
    if ($eventType === null) {
        echo json_encode(['success' => true, 'message' => 'Event type not stored']);
        exit;
    }

    $logRow = null;
    if ($emailId !== null && $emailId !== '') {
        $logRow = $db->queryOne("SELECT id, campaign_id FROM email_logs WHERE smtp_message_id = ? LIMIT 1", [$emailId]);
    }
    if (!$logRow || empty($logRow['campaign_id'])) {
        echo json_encode(['success' => true, 'message' => 'No campaign log for this message']);
        exit;
    }

    $db->insert('email_campaign_events', [
        'campaign_id' => $logRow['campaign_id'],
        'email_log_id' => $logRow['id'],
        'recipient_email' => $recipient,
        'event_type' => $eventType,
        'event_at' => $eventTime,
        'link_url' => $linkUrl,
        'smtp_message_id' => $emailId,
    ]);

    if ($eventType === 'unsubscribed' && $recipient !== '') {
        $orgRow = $db->queryOne("SELECT organization_id FROM email_logs WHERE id = ?", [$logRow['id']]);
        if ($orgRow) {
            $db->query(
                "INSERT INTO email_unsubscribes (organization_id, email, campaign_id, unsubscribed_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE unsubscribed_at = NOW(), campaign_id = VALUES(campaign_id)",
                [$orgRow['organization_id'], $recipient, $logRow['campaign_id']]
            );
        }
    }

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    error_log("SMTP2GO webhook error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal error']);
}
