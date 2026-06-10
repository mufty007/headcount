<?php
/**
 * My facility bookings (members)
 */
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\FacilityBookingService;
use Headcount\Services\FacilityService;

PortalAuthMiddleware::requireAuth();

$configFile = HC_PROJECT_ROOT . '/config/config.php';
$config = require $configFile;
Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Database::getInstance($config['database']);

$memberId = PortalAuthMiddleware::getMemberId();
$organizationId = PortalAuthMiddleware::getOrganizationId();

$bookings = [];
$tableOk = (new FacilityService())->tableExists();
if ($tableOk) {
    $bookings = (new FacilityBookingService())->listForUser($memberId, $organizationId);
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$pos = strpos($requestPath, '/portal');
$baseUrlPath = $pos !== false ? rtrim(substr($requestPath, 0, $pos), '/') : '';
$apiBase = $baseUrlPath . '/api/portal/facility-bookings.php';
$csrfToken = CsrfMiddleware::getToken();

$pageTitle = 'My Facility Bookings';
$currentPage = 'my-facility-bookings';
require __DIR__ . '/includes/header.php';

function portalBookingRange($s, $e) {
    return date('M j, Y g:i A', strtotime($s)) . ' – ' . date('g:i A', strtotime($e));
}
?>

<div x-data="{ apiBase: <?= json_encode($apiBase) ?>, csrf: <?= json_encode($csrfToken) ?>, async cancel(id) { if (!confirm('Cancel this pending booking?')) return; const r = await fetch(this.apiBase + '?action=cancel', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'cancel', csrf_token: this.csrf, id }) }); const d = await r.json(); if (d.success) location.reload(); else alert(d.message || 'Failed'); } }">
    <div class="mb-8">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">My Facility Bookings</h1>
                <p class="text-sm md:text-base text-gray-500 dark:text-gray-400 mt-1">Includes bookings you submitted as a guest before completing your profile.</p>
            </div>
            <a href="facilities.php" class="inline-flex items-center justify-center px-5 py-2.5 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 shadow-md shadow-indigo-200 transition-all active:scale-95 whitespace-nowrap">
                Book a facility
            </a>
        </div>
    </div>

    <?php if (!$tableOk): ?>
        <div class="bg-amber-50 dark:bg-amber-500/15 border border-amber-200 dark:border-amber-500/30 rounded-xl p-6 text-amber-900">Facilities not available.</div>
    <?php elseif (empty($bookings)): ?>
        <div class="text-center py-20 bg-gray-50 dark:bg-gray-800 rounded-3xl border-2 border-dashed border-gray-200 dark:border-gray-700">
            <div class="p-4 bg-white dark:bg-gray-800 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4 shadow-sm">
                <svg width="32" height="32" class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">No bookings yet</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-md mx-auto">When you request a facility booking, it will show up here.</p>
            <a href="facilities.php" class="inline-flex items-center justify-center mt-6 px-5 py-2.5 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 shadow-md shadow-indigo-200 transition-all active:scale-95">
                Browse facilities
            </a>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($bookings as $b):
                $st = $b['status'];
                $cls = ['pending' => 'bg-amber-100 dark:bg-amber-500/15 text-amber-800 dark:text-amber-300', 'approved' => 'bg-green-100 dark:bg-green-500/15 text-green-800 dark:text-green-300', 'rejected' => 'bg-red-100 dark:bg-red-500/15 text-red-800 dark:text-red-300', 'cancelled' => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'][$st] ?? 'bg-gray-100 dark:bg-gray-700';
            ?>
            <div class="bento-card">
                <div class="flex justify-between gap-4">
                    <div class="min-w-0">
                        <h3 class="font-bold text-gray-900 dark:text-white"><?= e($b['title']) ?></h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300 mt-1"><?= e($b['facility_name']) ?></p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"><?= e(portalBookingRange($b['start_datetime'], $b['end_datetime'])) ?></p>
                        <?php if (!empty($b['total_amount']) && (float) $b['total_amount'] > 0): ?>
                        <p class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 mt-2">$<?= number_format((float) $b['total_amount'], 2) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <span class="text-xs font-bold px-2 py-1 rounded-full <?= $cls ?>"><?= e(ucfirst($st)) ?></span>
                        <?php if ($st === 'pending'): ?>
                        <button type="button" @click="cancel(<?= (int) $b['id'] ?>)" class="block mt-2 text-xs text-red-600 dark:text-red-300 font-semibold hover:underline">Cancel</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
