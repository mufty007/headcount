<?php

/**
 * Payment Success Page
 * Shown after successful Stripe checkout
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Utilities;
use Headcount\Services\PortalPaymentService;

// Require authentication
PortalAuthMiddleware::requireAuth();

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found.");
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    die("System initialization failed.");
}

// Get session ID from Stripe
$sessionId = $_GET['session_id'] ?? '';

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$cssBase = $baseUrlPath . '/public/css/';
$apiBase = $baseUrlPath . '/api/portal/';

$payment = null;
$event = null;
$programReg = null;
$program = null;
$isProgram = isset($_GET['type']) && $_GET['type'] === 'program';

if ($sessionId) {
    $db = Database::getInstance();
    $memberId = PortalAuthMiddleware::getMemberId();

    if ($isProgram) {
        $programReg = $db->queryOne(
            "SELECT r.*, pr.title AS program_title, pr.price_amount
             FROM program_registrations r
             INNER JOIN programs pr ON pr.id = r.program_id
             WHERE r.stripe_checkout_session_id = :session_id AND r.user_id = :user_id",
            ['session_id' => $sessionId, 'user_id' => $memberId]
        );
        if ($programReg) {
            $program = [
                'id' => $programReg['program_id'],
                'title' => Utilities::decodeHtmlEntities($programReg['program_title'] ?? ''),
            ];
        }
    } else {
        $payment = $db->queryOne(
            "SELECT p.*, e.title as event_title, e.event_date, e.start_time, e.location
             FROM payments p
             JOIN events e ON p.event_id = e.id
             WHERE p.stripe_checkout_session_id = :session_id
             AND p.user_id = :user_id",
            [
                'session_id' => $sessionId,
                'user_id' => $memberId
            ]
        );

        if ($payment && ($payment['status'] ?? '') === 'pending') {
            try {
                $pps = new PortalPaymentService();
                $pps->reconcileMemberCheckoutSession($sessionId, (int) $memberId);
                $payment = $db->queryOne(
                    "SELECT p.*, e.title as event_title, e.event_date, e.start_time, e.location
                     FROM payments p
                     JOIN events e ON p.event_id = e.id
                     WHERE p.stripe_checkout_session_id = :session_id
                     AND p.user_id = :user_id",
                    [
                        'session_id' => $sessionId,
                        'user_id' => $memberId
                    ]
                );
            } catch (\Throwable $e) {
                error_log('payment-success reconcile: ' . $e->getMessage());
            }
        }

        if ($payment) {
            $event = [
                'id' => $payment['event_id'],
                'title' => Utilities::decodeHtmlEntities($payment['event_title'] ?? ''),
                'event_date' => $payment['event_date'],
                'start_time' => $payment['start_time'],
                'location' => Utilities::decodeHtmlEntities($payment['location'] ?? ''),
            ];
        }
    }
}

$eventPayStatus = $payment ? strtolower((string) ($payment['status'] ?? 'pending')) : '';
$eventPayIsPaid = $eventPayStatus === 'paid';
$eventPayTxnRef = !empty($payment['stripe_payment_intent_id'])
    ? (string) $payment['stripe_payment_intent_id']
    : (string) $sessionId;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - Member Portal</title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="bg-gray-50 dark:bg-slate-900">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full">
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
                <?php if ($programReg && $program): ?>
                    <div class="mb-4">
                        <svg class="mx-auto h-16 w-16 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">You're enrolled!</h1>
                    <p class="text-gray-600 mb-6">Your payment was processed and your program registration is active.</p>
                    <div class="bg-gray-50 rounded-lg p-6 mb-6 text-left">
                        <h2 class="font-semibold text-gray-900 mb-4">Program</h2>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Program:</span>
                                <span class="font-medium"><?php echo htmlspecialchars($program['title']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Status:</span>
                                <span class="font-medium text-green-600">Active</span>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/program-details.php?id=<?php echo (int)$program['id']; ?>"
                           class="block w-full px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                            View Program
                        </a>
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/my-programs.php"
                           class="block w-full px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                            My Programs
                        </a>
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/dashboard.php"
                           class="block w-full px-4 py-2 text-indigo-600 hover:text-indigo-700">
                            Dashboard
                        </a>
                    </div>
                <?php elseif ($payment): ?>
                    <div class="mb-4">
                        <?php if ($eventPayIsPaid): ?>
                        <svg class="mx-auto h-16 w-16 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <?php else: ?>
                        <svg class="mx-auto h-16 w-16 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">Payment Successful!</h1>
                    <p class="text-gray-600 mb-6"><?php echo $eventPayIsPaid
                        ? 'Your payment has been processed successfully.'
                        : 'Stripe accepted your payment; we are finalizing your registration. Refresh this page in a moment or check your email.'; ?></p>
                    
                    <div class="bg-gray-50 rounded-lg p-6 mb-6 text-left">
                        <h2 class="font-semibold text-gray-900 mb-4">Payment Details</h2>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Event:</span>
                                <span class="font-medium"><?php echo htmlspecialchars($payment['event_title']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Amount:</span>
                                <span class="font-medium">$<?php echo number_format($payment['amount'], 2); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Status:</span>
                                <span class="font-medium <?php echo $eventPayIsPaid ? 'text-green-600' : 'text-amber-600'; ?>"><?php echo $eventPayIsPaid ? 'Paid' : 'Processing'; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Reference:</span>
                                <span class="font-mono text-xs break-all"><?php echo htmlspecialchars(strlen($eventPayTxnRef) > 24 ? substr($eventPayTxnRef, 0, 24) . '…' : $eventPayTxnRef); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/payments.php" 
                           class="block w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            View Payment History
                        </a>
                        <?php if ($event): ?>
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/event-details.php?id=<?php echo $event['id']; ?>" 
                           class="block w-full px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                            View Event Details
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/dashboard.php" 
                           class="block w-full px-4 py-2 text-blue-600 hover:text-blue-700">
                            Go to Dashboard
                        </a>
                    </div>
                <?php else: ?>
                    <div class="mb-4">
                        <svg class="mx-auto h-16 w-16 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">Payment Successful!</h1>
                    <p class="text-gray-600 mb-6">Your payment has been processed. You should receive a confirmation email shortly.</p>
                    
                    <div class="space-y-3">
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/dashboard.php" 
                           class="block w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            Go to Dashboard
                        </a>
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/events.php" 
                           class="block w-full px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                            Browse More Events
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
