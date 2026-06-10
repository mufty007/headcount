<?php

/**
 * Admin Refund Requests API
 * GET: list refund requests (pending or all)
 * POST: action=approve|deny with request_id, (deny: admin_notes)
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
use Headcount\Integrations\StripeService;
use Headcount\Helpers\Security;
use Headcount\Services\ActivityLogger;
use Headcount\Services\EmailService;

header('Content-Type: application/json');
AuthMiddleware::requireAdminOrCoordinator();

$config = require HC_PROJECT_ROOT . '/config/config.php';
Database::getInstance($config['database']);
$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$db = Database::getInstance();

// Refund processing requires the refunds.process capability (role default or per-user override)
if (!AuthMiddleware::can('refunds.process')) {
    jsonResponse(['success' => false, 'message' => 'You do not have permission to process refunds'], 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

$tableCheck = $db->query("SHOW TABLES LIKE 'refund_requests'");
if (empty($tableCheck)) {
    jsonResponse(['success' => false, 'message' => 'Refund requests not available'], 503);
}

if ($method === 'GET') {
    $status = $_GET['status'] ?? 'pending';
    $where = "WHERE rr.organization_id = :org_id";
    $params = ['org_id' => $organizationId];
    if ($status === 'pending') {
        $where .= " AND rr.status = 'pending'";
    }
    $rows = $db->query(
        "SELECT rr.*, e.title as event_title, e.event_date,
                u.first_name, u.last_name, u.email as user_email,
                p.amount as payment_amount, p.stripe_payment_intent_id, p.payment_method
         FROM refund_requests rr
         JOIN events e ON rr.event_id = e.id
         JOIN users u ON rr.user_id = u.id
         LEFT JOIN payments p ON rr.payment_id = p.id
         $where
         ORDER BY rr.created_at DESC",
        $params
    );
    jsonResponse(['success' => true, 'requests' => $rows]);
}

if ($method !== 'POST' || !in_array($action, ['approve', 'deny'], true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$requestId = isset($input['request_id']) ? (int)$input['request_id'] : 0;
$adminNotes = trim($input['admin_notes'] ?? '');

if (!$requestId) {
    jsonResponse(['success' => false, 'message' => 'request_id required'], 400);
}

$req = $db->queryOne(
    "SELECT rr.*, e.title as event_title, e.organization_id 
     FROM refund_requests rr JOIN events e ON rr.event_id = e.id 
     WHERE rr.id = :id",
    ['id' => $requestId]
);
if (!$req || (int)$req['organization_id'] !== (int)$organizationId) {
    jsonResponse(['success' => false, 'message' => 'Request not found'], 404);
}
if ($req['status'] !== 'pending') {
    jsonResponse(['success' => false, 'message' => 'Request already processed'], 400);
}

if ($action === 'deny') {
    $db->execute(
        "UPDATE refund_requests SET status = 'denied', reviewed_by = :reviewed_by, reviewed_at = NOW(), admin_notes = :admin_notes WHERE id = :id",
        ['reviewed_by' => $userId, 'admin_notes' => $adminNotes ?: null, 'id' => $requestId]
    );
    $toEmail = $db->queryOne("SELECT email FROM users WHERE id = :id", ['id' => $req['user_id']]);
    if (!empty($toEmail['email'])) {
        $org = $db->queryOne("SELECT smtp_from_email, smtp_from_name, smtp_api_key_encrypted FROM organizations WHERE id = :id", ['id' => $organizationId]);
        if (!empty($org['smtp_api_key_encrypted'])) {
            $key = $config['security']['encryption_key'] ?? hash('sha256', ($config['app']['name'] ?? 'Headcount') . ($config['database']['name'] ?? 'headcount_dev'));
            if (strlen($key) < 32) $key = hash('sha256', $key . 'headcount_salt');
            $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], substr($key, 0, 32));
            $emailService = new EmailService([
                'api_key' => $apiKey,
                'from_email' => $org['smtp_from_email'] ?? '',
                'from_name' => $org['smtp_from_name'] ?? '',
            ]);
            $subject = 'Refund request – ' . $req['event_title'];
            $body = '<p>Your refund request for "' . htmlspecialchars($req['event_title']) . '" has been declined.</p>';
            if ($adminNotes) $body .= '<p>Note: ' . htmlspecialchars($adminNotes) . '</p>';
            $brandingOrg = $db->queryOne("SELECT name, logo_path FROM organizations WHERE id = :id", ['id' => $organizationId]);
            $logoUrl = null;
            if (!empty($brandingOrg['logo_path'])) {
                $logoUrl = (strpos($brandingOrg['logo_path'], 'http') === 0) ? $brandingOrg['logo_path'] : rtrim($config['app']['url'] ?? '', '/') . '/public/' . ltrim($brandingOrg['logo_path'], '/');
            }
            $body = wrapEmailWithBranding($body, $logoUrl, $brandingOrg['name'] ?? '');
            try {
                $emailService->sendEmail($toEmail['email'], $subject, $body, $organizationId);
            } catch (\Exception $e) {
                error_log('Refund denial email error: ' . $e->getMessage());
            }
        }
    }
    jsonResponse(['success' => true, 'message' => 'Request denied']);
}

// Approve: process Stripe refund and update payment + request
if (!$req['payment_id']) {
    jsonResponse(['success' => false, 'message' => 'No payment linked to this request'], 400);
}
$payment = $db->queryOne("SELECT * FROM payments WHERE id = :id", ['id' => $req['payment_id']]);
if (!$payment || (int)$payment['event_id'] !== (int)$req['event_id']) {
    jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
}
if (($payment['payment_method'] ?? 'stripe') === 'cash') {
    jsonResponse(['success' => false, 'message' => 'Cash payments cannot be refunded via Stripe'], 400);
}
if (empty($payment['stripe_payment_intent_id'])) {
    jsonResponse(['success' => false, 'message' => 'Payment has no Stripe transaction'], 400);
}

$org = $db->queryOne(
    "SELECT stripe_secret_key_encrypted, stripe_webhook_secret_encrypted, smtp_api_key_encrypted, smtp_from_email, smtp_from_name FROM organizations WHERE id = :id",
    ['id' => $organizationId]
);
if (empty($org['stripe_secret_key_encrypted'])) {
    jsonResponse(['success' => false, 'message' => 'Stripe not configured'], 400);
}

$key = $config['security']['encryption_key'] ?? null;
if (empty($key)) $key = hash('sha256', ($config['app']['name'] ?? 'Headcount') . ($config['database']['name'] ?? 'headcount_dev'));
if (strlen($key) < 32) $key = hash('sha256', $key . 'headcount_salt');
$encKey = substr($key, 0, 32);
$secretKey = Security::decrypt($org['stripe_secret_key_encrypted'], $encKey);
$webhookSecret = !empty($org['stripe_webhook_secret_encrypted']) ? Security::decrypt($org['stripe_webhook_secret_encrypted'], $encKey) : null;

try {
    $stripeService = new StripeService($secretKey, $webhookSecret);
    $stripeService->refundPayment($payment['stripe_payment_intent_id'], null);
} catch (\Exception $e) {
    error_log('Refund request Stripe error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Refund failed: ' . $e->getMessage()], 500);
}

$amount = (float)$payment['amount'];
$db->execute(
    "UPDATE payments SET refund_amount = :ra, refunded_at = NOW(), refund_reason = :reason, status = 'refunded', updated_at = NOW() WHERE id = :id",
    ['ra' => $amount, 'reason' => 'User refund request approved', 'id' => $payment['id']]
);
$db->execute(
    "UPDATE refund_requests SET status = 'approved', reviewed_by = :rb, reviewed_at = NOW() WHERE id = :id",
    ['rb' => $userId, 'id' => $requestId]
);

$logger = new ActivityLogger($organizationId, $userId);
$logger->logRefundInitiated($payment['id'], $amount, 'User refund request approved', $userId);

$toEmail = $db->queryOne("SELECT email FROM users WHERE id = :id", ['id' => $req['user_id']]);
if (!empty($toEmail['email']) && !empty($org['smtp_api_key_encrypted'])) {
    $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $encKey);
    $emailService = new EmailService([
        'api_key' => $apiKey,
        'from_email' => $org['smtp_from_email'] ?? '',
        'from_name' => $org['smtp_from_name'] ?? '',
    ]);
    $subject = 'Refund processed – ' . $req['event_title'];
    $body = '<p>Your refund of $' . number_format($amount, 2) . ' for "' . htmlspecialchars($req['event_title']) . '" has been processed.</p>';
    $brandingOrg = $db->queryOne("SELECT name, logo_path FROM organizations WHERE id = :id", ['id' => $organizationId]);
    $logoUrl = null;
    if (!empty($brandingOrg['logo_path'])) {
        $logoUrl = (strpos($brandingOrg['logo_path'], 'http') === 0) ? $brandingOrg['logo_path'] : rtrim($config['app']['url'] ?? '', '/') . '/public/' . ltrim($brandingOrg['logo_path'], '/');
    }
    $body = wrapEmailWithBranding($body, $logoUrl, $brandingOrg['name'] ?? '');
    try {
        $emailService->sendEmail($toEmail['email'], $subject, $body, $organizationId);
    } catch (\Exception $e) {
        error_log('Refund approval email error: ' . $e->getMessage());
    }
}

jsonResponse(['success' => true, 'message' => 'Refund processed and user notified']);
