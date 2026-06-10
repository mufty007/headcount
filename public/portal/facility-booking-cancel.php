<?php
/**
 * Facility booking payment cancelled.
 */
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
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

$bookingId = (int) ($_GET['booking_id'] ?? 0);
if ($bookingId > 0) {
    $db = Database::getInstance();
    $row = $db->queryOne(
        "SELECT organization_id, payment_status FROM facility_bookings WHERE id = :id",
        ['id' => $bookingId]
    );
    if ($row && ($row['payment_status'] ?? '') === 'awaiting_checkout') {
        $bookSvc = new FacilityBookingService();
        $paySvc = new \Headcount\Services\FacilityPaymentService();
        if ($paySvc->facilityPaymentsEnabled()) {
            $paySvc->releaseForBooking($bookingId, (int) $row['organization_id'], 'abandoned');
        }
        $db->update('facility_bookings', $bookingId, ['status' => 'cancelled']);
    }
}

$pageTitle = 'Payment cancelled';
$currentPage = 'facilities';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-xl mx-auto px-4 py-8">
    <div class="p-6 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl">
        <h1 class="text-xl font-bold text-gray-900 dark:text-white">Payment cancelled</h1>
        <p class="text-sm text-gray-600 dark:text-gray-300 mt-2">No payment was taken. You can return to the facility and try again when ready.</p>
        <a href="<?= e($baseUrlPath) ?>/portal/facilities.php" class="inline-block mt-4 py-2.5 px-4 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700">Browse facilities</a>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
