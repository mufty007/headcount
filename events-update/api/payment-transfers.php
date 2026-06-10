<?php
/**
 * Payment Transfers API — get_payments, Stripe reconcile, refunds (JSON).
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
use Headcount\Integrations\StripeService;
use Headcount\Helpers\Security;
use Headcount\Services\ActivityLogger;
use Headcount\Services\EmailService;

try {
    $config = require __DIR__ . '/../../config/config.php';
    Database::getInstance($config['database']);

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json', true);

    $organizationId = AuthMiddleware::getOrganizationId();
    $userId = AuthMiddleware::getUserId();
    $db = Database::getInstance();

    $method = $_SERVER['REQUEST_METHOD'];
    $jsonInput = [];
    if ($method === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $jsonInput = $decoded;
                }
            }
        }
    }
    // Merge so JSON bodies work (PHP does not populate $_POST for application/json)
    $postBody = array_merge((array) $_POST, $jsonInput);
    $action = trim((string) ($_GET['action'] ?? ($postBody['action'] ?? '')));
    AuthMiddleware::requireAdminOrCoordinator();

    // GET payments for an event
    if ($action === 'get_payments' && $method === 'GET') {
        $eventId = $_GET['event_id'] ?? null;
        if (!$eventId) {
            jsonResponse(['success' => false, 'message' => 'Event ID is required'], 400);
        }
        
        // Verify event belongs to organization
        $event = $db->queryOne(
            "SELECT id, title FROM events WHERE id = :id AND organization_id = :org_id",
            ['id' => $eventId, 'org_id' => $organizationId]
        );
        
        if (!$event) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
        }
        
        // LEFT JOIN: if user_id is orphaned (FK off / legacy data), still return the payment row
        $payments = $db->query(
            "SELECT p.*,
                    COALESCE(
                        NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                        'Unknown user'
                    ) AS user_name,
                    u.email AS user_email
             FROM payments p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.event_id = :event_id
             ORDER BY p.created_at DESC",
            ['event_id' => $eventId]
        );
        
        jsonResponse([
            'success' => true,
            'payments' => $payments
        ]);
    }

    // POST reconcile pending Stripe checkouts for an event (admin or coordinator)
    if ($action === 'reconcile_event' && $method === 'POST') {
        $eventId = (int) ($postBody['event_id'] ?? 0);
        if ($eventId <= 0) {
            jsonResponse(['success' => false, 'message' => 'event_id is required'], 400);
        }
        $event = $db->queryOne(
            'SELECT id FROM events WHERE id = :id AND organization_id = :org_id',
            ['id' => $eventId, 'org_id' => $organizationId]
        );
        if (!$event) {
            jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
        }
        try {
            $pps = new \Headcount\Services\PortalPaymentService();
            $result = $pps->reconcilePendingPaymentsForEvent($eventId);
            jsonResponse($result);
        } catch (\Throwable $e) {
            error_log('reconcile_event: ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // POST reconcile all pending checkouts for the signed-in organization (admin or coordinator)
    if ($action === 'reconcile_organization' && $method === 'POST') {
        try {
            $pps = new \Headcount\Services\PortalPaymentService();
            $result = $pps->reconcilePendingPaymentsForOrganization((int) $organizationId);
            jsonResponse($result);
        } catch (\Throwable $e) {
            error_log('reconcile_organization: ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // POST refund — requires the refunds.process capability (role default or per-user override)
    if ($action === 'refund' && $method === 'POST') {
        if (!AuthMiddleware::can('refunds.process')) {
            jsonResponse(['success' => false, 'message' => 'You do not have permission to process refunds'], 403);
        }

        $input = $postBody;
        $paymentId = isset($input['payment_id']) ? (int)$input['payment_id'] : 0;
        $reason = trim($input['reason'] ?? '');
        $refundAmount = isset($input['amount']) ? (float)$input['amount'] : null;

        if (!$paymentId || $reason === '') {
            jsonResponse(['success' => false, 'message' => 'payment_id and reason are required'], 400);
        }

        $payment = $db->queryOne(
            "SELECT p.*, e.title as event_title, e.organization_id 
             FROM payments p 
             JOIN events e ON p.event_id = e.id 
             WHERE p.id = :id",
            ['id' => $paymentId]
        );
        if (!$payment || (int)$payment['organization_id'] !== (int)$organizationId) {
            jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
        }

        $paymentMethod = $payment['payment_method'] ?? 'stripe';
        if ($paymentMethod === 'cash') {
            jsonResponse(['success' => false, 'message' => 'Cash payments cannot be refunded via Stripe'], 400);
        }
        if (empty($payment['stripe_payment_intent_id'])) {
            jsonResponse(['success' => false, 'message' => 'Payment has no Stripe transaction to refund'], 400);
        }
        if ($payment['status'] !== 'paid') {
            jsonResponse(['success' => false, 'message' => 'Only paid payments can be refunded'], 400);
        }
        $alreadyRefunded = (float)($payment['refund_amount'] ?? 0);
        $paymentTotal = (float)$payment['amount'];
        if ($alreadyRefunded >= $paymentTotal) {
            jsonResponse(['success' => false, 'message' => 'Payment is already fully refunded'], 400);
        }

        $org = $db->queryOne(
            "SELECT stripe_secret_key_encrypted, stripe_webhook_secret_encrypted, smtp_api_key_encrypted, smtp_from_email, smtp_from_name FROM organizations WHERE id = :id",
            ['id' => $organizationId]
        );
        if (empty($org['stripe_secret_key_encrypted'])) {
            jsonResponse(['success' => false, 'message' => 'Stripe is not configured for this organization'], 400);
        }

        $key = $config['security']['encryption_key'] ?? null;
        if (empty($key)) {
            $key = hash('sha256', ($config['app']['name'] ?? 'Headcount') . ($config['database']['name'] ?? 'headcount_dev'));
        }
        if (strlen($key) < 32) {
            $key = hash('sha256', $key . 'headcount_salt');
        }
        $encryptionKey = substr($key, 0, 32);
        $secretKey = Security::decrypt($org['stripe_secret_key_encrypted'], $encryptionKey);
        $webhookSecret = !empty($org['stripe_webhook_secret_encrypted'])
            ? Security::decrypt($org['stripe_webhook_secret_encrypted'], $encryptionKey) : null;

        try {
            $stripeService = new StripeService($secretKey, $webhookSecret);
            $stripeService->refundPayment($payment['stripe_payment_intent_id'], $refundAmount);
        } catch (\Exception $e) {
            error_log('Refund Stripe error: ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Refund failed: ' . $e->getMessage()], 500);
        }

        $refundAmountActual = $refundAmount !== null ? $refundAmount : ($paymentTotal - $alreadyRefunded);
        $newRefundTotal = $alreadyRefunded + $refundAmountActual;
        $newStatus = $newRefundTotal >= $paymentTotal ? 'refunded' : 'paid';

        $db->execute(
            "UPDATE payments SET refund_amount = :refund_amount, refunded_at = :refunded_at, refund_reason = :refund_reason, status = :status, updated_at = NOW() WHERE id = :id",
            [
                'refund_amount' => $newRefundTotal,
                'refunded_at' => date('Y-m-d H:i:s'),
                'refund_reason' => $reason,
                'status' => $newStatus,
                'id' => $paymentId
            ]
        );

        $logger = new ActivityLogger($organizationId, $userId);
        $logger->logRefundInitiated($paymentId, $refundAmountActual, $reason, $userId);

        $toEmail = $db->queryOne("SELECT email FROM users WHERE id = :id", ['id' => $payment['user_id']]);
        if (!empty($toEmail['email']) && !empty($org['smtp_api_key_encrypted'])) {
            $smtpKey = Security::decrypt($org['smtp_api_key_encrypted'], $encryptionKey);
            $emailConfig = [
                'api_key' => $smtpKey,
                'from_email' => $org['smtp_from_email'] ?? '',
                'from_name' => $org['smtp_from_name'] ?? '',
            ];
            $emailService = new EmailService($emailConfig);
            $subject = 'Refund Processed – ' . $payment['event_title'];
            $body = '<p>Your refund of $' . number_format($refundAmountActual, 2) . ' for the event "' . htmlspecialchars($payment['event_title']) . '" has been processed.</p>';
            if ($reason) {
                $body .= '<p>Reason: ' . htmlspecialchars($reason) . '</p>';
            }
            $body .= '<p>If you have any questions, please contact the event organizer.</p>';
            $brandingOrg = $db->queryOne("SELECT name, logo_path FROM organizations WHERE id = :id", ['id' => $organizationId]);
            $appConfig = require __DIR__ . '/../../config/config.php';
            $logoUrl = null;
            if (!empty($brandingOrg['logo_path'])) {
                $logoUrl = (strpos($brandingOrg['logo_path'], 'http') === 0) ? $brandingOrg['logo_path'] : rtrim($appConfig['app']['url'] ?? '', '/') . '/public/' . ltrim($brandingOrg['logo_path'], '/');
            }
            $body = wrapEmailWithBranding($body, $logoUrl, $brandingOrg['name'] ?? '');
            try {
                $emailService->sendEmail($toEmail['email'], $subject, $body, $organizationId, ['template' => 'refund_confirmation']);
            } catch (\Exception $e) {
                error_log('Refund confirmation email failed: ' . $e->getMessage());
            }
        }

        jsonResponse([
            'success' => true,
            'message' => 'Refund processed successfully',
            'refund_amount' => $refundAmountActual,
            'status' => $newStatus,
        ]);
    }

    // Invalid action
    jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    
} catch (\Throwable $e) {
    error_log("Payment transfers API error: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $payload = [
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ];
    header('Content-Type: application/json; charset=UTF-8', true, 500);
    $out = json_encode($payload, $flags);
    if ($out === false) {
        echo '{"success":false,"message":"Server error"}';
    } else {
        echo $out;
    }
}
