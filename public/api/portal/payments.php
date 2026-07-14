<?php

/**
 * Portal Payments API
 * Handles payment operations for members (requires authentication)
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Services\PortalPaymentService;
use Headcount\Services\EventSeriesHelper;
use Headcount\Services\PotluckCategoryService;
use Headcount\Services\EventTicketSelectionService;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database initialization failed']);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

// Set JSON header
header('Content-Type: application/json');

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract action/ID from path
$pathSegments = explode('/', trim($path, '/'));
$action = $pathSegments[count($pathSegments) - 1] ?? '';
$secondLast = count($pathSegments) >= 2 ? $pathSegments[count($pathSegments) - 2] : '';

// Get input data (when routed from index.php, $data is already set and php://input is consumed)
if (!isset($data) || !is_array($data)) {
    $input = json_decode(@file_get_contents('php://input'), true) ?? [];
    $data = array_merge($_POST, $input);
}

try {
    $paymentService = new PortalPaymentService();
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('Portal payments PortalPaymentService init: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Payment service unavailable. Please try again.']);
    exit;
}

try {
    // POST /api/portal/payments/webhook - Stripe webhook (no auth required)
    // Must check before requiring auth
    if ($action === 'webhook' && $method === 'POST') {
        $payload = @file_get_contents('php://input');
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        
        if (empty($payload) || empty($signature)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing payload or signature']);
            exit;
        }
        
        $result = $paymentService->handleWebhook($payload, $signature);
        echo json_encode($result);
        exit;
    }

    // Require authentication for other endpoints
    PortalAuthMiddleware::requireAuth();
    $memberId = PortalAuthMiddleware::getMemberId();

    // POST /api/portal/payments/checkout - Create checkout session
    if ($action === 'checkout' && $method === 'POST') {
        // Verify CSRF - Pass $data since we already read php://input
        CsrfMiddleware::verify($data);

        $eventId = (int)($data['event_id'] ?? 0);
        if ($eventId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event ID is required']);
            exit;
        }

        $guests = max(0, min(100, (int) ($data['guests'] ?? 0)));

        $ticketsRaw = isset($data['tickets']) && is_array($data['tickets']) ? $data['tickets'] : [];
        $tickets = [];
        foreach ($ticketsRaw as $t) {
            $typeId = (int) ($t['ticket_type_id'] ?? $t['ticket_type_id'] ?? 0);
            $qty = (int) ($t['quantity'] ?? 0);
            if ($typeId <= 0 || $qty <= 0) {
                continue;
            }
            $tickets[] = ['ticket_type_id' => $typeId, 'quantity' => $qty];
        }

        $db = Database::getInstance();
        $eventCheckout = $db->queryOne(
            'SELECT * FROM events WHERE id = ? AND status = \'published\'',
            [$eventId]
        );
        if (is_array($eventCheckout)) {
            $eventCheckout = EventSeriesHelper::mergeSeriesParentPolicyFields($db, $eventCheckout);
        }
        $orgRow = $db->queryOne(
            'SELECT rsvp_waiver_enabled, rsvp_waiver_checkbox_label, rsvp_waiver_full_text FROM organizations WHERE id = :id',
            ['id' => PortalAuthMiddleware::getOrganizationId()]
        );
        $waiverErr = headcount_waiver_validation_error(is_array($orgRow) ? $orgRow : null, $data);
        if ($waiverErr !== null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $waiverErr]);
            exit;
        }
        $pendingCheckout = ['waiver_accepted' => !empty($data['waiver_accepted']) || !empty($data['waiverAccepted'])];
        if (!empty($data['question_answers']) && is_array($data['question_answers'])) {
            $pendingCheckout['question_answers'] = $data['question_answers'];
        }
        if ($eventCheckout && !empty($eventCheckout['is_potluck'])) {
            $hasExtPay = $db->hasColumn('rsvps', 'potluck_quantity')
                && $db->hasColumn('rsvps', 'potluck_serving_side')
                && $db->hasColumn('rsvps', 'potluck_party_adults')
                && $db->hasColumn('rsvps', 'potluck_party_children');
            $requirePayDish = PotluckCategoryService::requiresPotluckDishCategoryFromRequest($data);
            $potluckAllowedPay = PotluckCategoryService::parsePotluckAllowedSlugsFromEvent($eventCheckout);
            $norm = PotluckCategoryService::normalizePotluckSignup($data, $hasExtPay, $requirePayDish, $potluckAllowedPay);
            if (!$norm['ok']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $norm['error']]);
                exit;
            }
            if (!empty($norm['slug'])) {
                $pendingCheckout['potluck_category'] = $norm['slug'];
                $pendingCheckout['potluck_item_note'] = $norm['note'];
                if ($norm['quantity'] !== null) {
                    $pendingCheckout['potluck_quantity'] = (int) $norm['quantity'];
                }
                if ($norm['serving_side'] !== null) {
                    $pendingCheckout['potluck_serving_side'] = (string) $norm['serving_side'];
                }
            }
            if ($norm['party_adults'] !== null) {
                $pendingCheckout['potluck_party_adults'] = (int) $norm['party_adults'];
            }
            if ($norm['party_children'] !== null) {
                $pendingCheckout['potluck_party_children'] = (int) $norm['party_children'];
            }
            if ($norm['party_adults'] !== null && $norm['party_children'] !== null && empty($tickets)) {
                $guests = max(0, min(100, (int) $norm['party_adults'] + (int) $norm['party_children'] - 1));
            }
        }

        if (!empty($tickets)) {
            $typeMapPay = EventTicketSelectionService::loadTypeMapForEvent($db, $eventId);
            $orgTzPay = EventTicketSelectionService::orgTimezoneForEvent($db, is_array($eventCheckout) ? $eventCheckout : []);
            $rulesPay = EventTicketSelectionService::validateSelectionRules($tickets, $typeMapPay, $orgTzPay);
            if (!$rulesPay['ok']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $rulesPay['message'] ?? 'Invalid ticket selection.']);
                exit;
            }
            $quotePay = EventTicketSelectionService::quoteSelection($tickets, $typeMapPay);
            if ($quotePay['totalAmount'] <= 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Your ticket selection is free. Submit RSVP without payment instead.',
                ]);
                exit;
            }
            $pendingCheckout['tickets'] = $tickets;
        }

        try {
            $result = $paymentService->createCheckoutSession($eventId, $memberId, $guests, $tickets, $pendingCheckout);
        } catch (\Throwable $e) {
            error_log('Portal checkout error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage() ?: 'Checkout failed. Ensure Stripe is configured in Admin → Settings → Payments (Stripe) → Configure.'
            ]);
            exit;
        }

        if ($result['success']) {
            echo json_encode($result);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
        exit;
    }

    // GET /api/portal/payments/history - Get payment history
    if ($action === 'history' && $method === 'GET') {
        $payments = $paymentService->getPaymentHistory($memberId);
        
        echo json_encode([
            'success' => true,
            'payments' => $payments,
            'count' => count($payments)
        ]);
        exit;
    }

    // GET /api/portal/payments/receipt/{id} - Get receipt (PDF or HTML)
    if ($secondLast === 'receipt' && is_numeric($action) && $method === 'GET') {
        $receiptId = (int)$action;
        
        $payment = $paymentService->getPayment($receiptId, $memberId);
        
        if (!$payment) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Payment not found']);
            exit;
        }

        // Return receipt HTML
        header('Content-Type: text/html');
        echo generateReceiptHTML($payment);
        exit;
    }

    // 404 - Endpoint not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Throwable $e) {
    http_response_code(500);
    error_log("Portal payments API error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ? ('An error occurred: ' . $e->getMessage()) : 'An error occurred. Please try again.'
    ]);
}

/**
 * Generate receipt HTML (simple version)
 */
function generateReceiptHTML($payment) {
    $eventDate = date('F j, Y', strtotime($payment['event_date']));
    $paymentDate = date('F j, Y g:i A', strtotime($payment['created_at']));
    
    return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Receipt</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .receipt-details { border-top: 2px solid #000; border-bottom: 2px solid #000; padding: 20px 0; margin: 20px 0; }
                .row { display: flex; justify-content: space-between; margin: 10px 0; }
                .total { font-size: 18px; font-weight: bold; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Payment Receipt</h1>
            </div>
            <div class='receipt-details'>
                <div class='row'>
                    <span>Event:</span>
                    <span>{$payment['event_title']}</span>
                </div>
                <div class='row'>
                    <span>Date:</span>
                    <span>{$eventDate}</span>
                </div>
                <div class='row'>
                    <span>Payment Date:</span>
                    <span>{$paymentDate}</span>
                </div>
                <div class='row'>
                    <span>Amount:</span>
                    <span>\${$payment['amount']}</span>
                </div>
                <div class='row'>
                    <span>Status:</span>
                    <span>" . ucfirst($payment['status']) . "</span>
                </div>
                <div class='row'>
                    <span>Transaction ID:</span>
                    <span>{$payment['stripe_payment_intent_id']}</span>
                </div>
            </div>
            <div class='total'>
                Total Paid: \${$payment['amount']}
            </div>
        </body>
        </html>
    ";
}
