<?php
/**
 * Facilities listing (public — members and guests)
 */
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Services\FacilityService;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
$config = require $configFile;
Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Database::getInstance($config['database']);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
if (preg_match('#/portal(/.*)?$#', $requestPath, $matches)) {
    $pos = strpos($requestPath, '/portal');
    $baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
} else {
    $baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
}
$baseUrlPath = rtrim($baseUrlPath, '/');

$isLoggedIn = PortalAuthMiddleware::isAuthenticated();
$orgId = headcount_resolve_portal_organization_id(
    $isLoggedIn ? PortalAuthMiddleware::getOrganizationId() : null,
    $config,
    Database::getInstance()
);

$facilities = [];
$tableOk = false;
if ($orgId) {
    $svc = new FacilityService();
    $tableOk = $svc->tableExists();
    if ($tableOk) {
        if ($isLoggedIn) {
            $memberFacilities = $svc->listBookableForRole($orgId, 'member');
            $guestFacilities = $svc->listBookableForRole($orgId, 'guest');
            $byId = [];
            foreach (array_merge($memberFacilities, $guestFacilities) as $f) {
                $byId[$f['id']] = $f;
            }
            $facilities = array_values($byId);
        } else {
            $facilities = $svc->listBookableForRole($orgId, 'guest');
        }
    }
}

$pageTitle = 'Facilities';
$currentPage = 'facilities';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 tracking-tight">Facilities</h1>
            <p class="text-sm md:text-base text-gray-500 mt-1">Book community spaces. Requests require staff approval.</p>
        </div>
    </div>
</div>

<?php if (!$tableOk): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-amber-900">Facilities are not set up yet.</div>
<?php elseif (empty($facilities)): ?>
    <div class="text-center py-20 bg-gray-50 rounded-3xl border-2 border-dashed border-gray-200">
        <div class="p-4 bg-white rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4 shadow-sm">
            <svg width="32" height="32" class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
        </div>
        <h3 class="text-lg font-bold text-gray-900">No bookable facilities at this time</h3>
        <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">When spaces become available, they will appear here for you to browse and request.</p>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($facilities as $f): ?>
            <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition-shadow flex flex-col">
                <a href="<?= e($baseUrlPath) ?>/portal/facility-details.php?facility=<?= e(urlencode($f['slug'])) ?>" class="block">
                    <?php if (!empty($f['thumbnail_url'])): ?>
                    <img src="<?= e($f['thumbnail_url']) ?>" alt="<?= e($f['name']) ?>" class="w-full h-44 object-cover bg-gray-100" loading="lazy"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <div class="w-full h-44 bg-gradient-to-br from-indigo-100 to-slate-100 hidden"></div>
                    <?php else: ?>
                    <div class="w-full h-44 bg-gradient-to-br from-indigo-100 to-slate-100"></div>
                    <?php endif; ?>
                </a>
                <div class="p-6 flex flex-col flex-1">
                <a href="<?= e($baseUrlPath) ?>/portal/facility-details.php?facility=<?= e(urlencode($f['slug'])) ?>" class="hover:text-indigo-700">
                    <h2 class="text-lg font-bold text-gray-900"><?= e($f['name']) ?></h2>
                </a>
                <?php if (!empty($f['location'])): ?>
                <p class="text-sm text-gray-500 mt-1"><?= e($f['location']) ?></p>
                <?php endif; ?>
                <?php if (!empty($f['is_paid']) && (float) ($f['hourly_rate'] ?? 0) > 0): ?>
                <p class="text-sm font-semibold text-indigo-700 mt-2">$<?= number_format((float) $f['hourly_rate'], 2) ?> / hr</p>
                <?php endif; ?>
                <?php
                $facDesc = headcount_facility_description_for_display((string) ($f['description'] ?? ''));
                if ($facDesc['has']):
                ?>
                <p class="text-sm text-gray-600 mt-3 line-clamp-2"><?= e(strip_tags($facDesc['html'])) ?></p>
                <?php endif; ?>
                <div class="mt-auto pt-4 flex flex-col gap-2">
                    <a href="<?= e($baseUrlPath) ?>/portal/facility-details.php?facility=<?= e(urlencode($f['slug'])) ?>"
                       class="block text-center py-2 text-sm font-semibold text-indigo-600 hover:underline">View details</a>
                    <?php if (!empty($f['allow_guest_booking'])): ?>
                    <a href="<?= e($baseUrlPath) ?>/portal/facility-book-guest.php?facility=<?= e(urlencode($f['slug'])) ?>"
                       class="block text-center py-2.5 border border-indigo-200 text-indigo-700 font-bold rounded-xl hover:bg-indigo-50">Book as guest</a>
                    <?php endif; ?>
                    <?php if ($isLoggedIn && !empty($f['allow_member_booking'])): ?>
                    <a href="<?= e($baseUrlPath) ?>/portal/facility-book.php?facility=<?= e(urlencode($f['slug'])) ?>"
                       class="block text-center py-2.5 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700">Book as member</a>
                    <?php elseif (!$isLoggedIn && !empty($f['allow_member_booking'])): ?>
                    <a href="<?= e($baseUrlPath) ?>/portal/login.php?redirect=<?= e(urlencode($baseUrlPath . '/portal/facility-book.php?facility=' . $f['slug'])) ?>"
                       class="block text-center py-2.5 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700">Log in to book</a>
                    <?php endif; ?>
                </div>
                </div>
            </div>
            <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
