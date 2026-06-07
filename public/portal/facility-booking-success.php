<?php
/**
 * Facility booking payment success (public — guest or member).
 */
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Services\FacilityPaymentService;
use Headcount\Services\FacilityBookingService;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
$config = require $configFile;
Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Database::getInstance($config['database']);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$pos = strpos($requestPath, '/portal');
$baseUrlPath = $pos !== false ? rtrim(substr($requestPath, 0, $pos), '/') : '';

$sessionId = trim((string) ($_GET['session_id'] ?? ''));
$booking = null;
$error = '';

if ($sessionId !== '') {
    $paySvc = new FacilityPaymentService();
    $result = $paySvc->finalizeCheckoutFromSession($sessionId);
    if ($result['success'] && !empty($result['booking'])) {
        $booking = $result['booking'];
    } else {
        $error = $result['message'] ?? 'We could not confirm your payment yet. If you were charged, contact the organization.';
    }
} else {
    $error = 'Missing payment session.';
}

$pageTitle = 'Booking request received';
$currentPage = 'facility-details';
$isLoggedIn = false;
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-xl mx-auto px-4 py-8">
    <?php if ($booking): ?>
    <div class="p-6 bg-green-50 border border-green-200 rounded-2xl">
        <h1 class="text-xl font-bold text-green-900">Payment authorized</h1>
        <p class="text-sm text-green-800 mt-2">Your booking request for <strong><?= e($booking['facility_name'] ?? '') ?></strong> has been received.</p>
        <p class="text-sm text-green-800 mt-2">Your card has a temporary authorization hold. You will only be charged if staff approves your request. If not approved, the hold is released automatically (usually within a few days).</p>
        <p class="text-sm text-gray-700 mt-4"><strong>Event:</strong> <?= e($booking['title'] ?? '') ?></p>
        <p class="text-sm text-gray-700"><strong>When:</strong> <?= e(date('F j, Y g:i A', strtotime($booking['start_datetime']))) ?> – <?= e(date('g:i A', strtotime($booking['end_datetime']))) ?></p>
        <?php if (!empty($booking['total_amount']) && (float) $booking['total_amount'] > 0): ?>
        <p class="text-sm font-semibold text-indigo-700 mt-2">Estimated total: $<?= number_format((float) $booking['total_amount'], 2) ?></p>
        <?php endif; ?>
        <div class="mt-6 flex flex-col gap-2">
            <a href="<?= e($baseUrlPath) ?>/portal/facility-details.php?facility=<?= e(urlencode($booking['facility_slug'] ?? '')) ?>"
               class="text-center py-2.5 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700">Back to facility</a>
            <a href="<?= e($baseUrlPath) ?>/portal/facilities.php" class="text-center text-sm text-indigo-600 font-semibold hover:underline">All facilities</a>
        </div>
    </div>
    <?php else: ?>
    <div class="p-6 bg-amber-50 border border-amber-200 rounded-2xl">
        <h1 class="text-xl font-bold text-amber-900">Could not confirm payment</h1>
        <p class="text-sm text-amber-800 mt-2"><?= e($error) ?></p>
        <a href="<?= e($baseUrlPath) ?>/portal/facilities.php" class="inline-block mt-4 text-indigo-600 font-semibold hover:underline">Return to facilities</a>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
