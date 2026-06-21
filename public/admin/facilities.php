<?php

/**

 * Facilities list (admin)

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

use Headcount\Helpers\Utilities;

use Headcount\Middleware\AuthMiddleware;

use Headcount\Services\FacilityService;



if (empty($_SESSION['user_id'])) {

    AuthMiddleware::requireAdminOrCoordinator();

}

$organizationId = AuthMiddleware::getOrganizationId();

$userId = AuthMiddleware::getUserId();



$db = Database::getInstance();

$userData = $db->queryOne("SELECT first_name, last_name, email, role FROM users WHERE id = :id", ['id' => $userId]);

$user = $userData ? [

    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),

    'email' => $userData['email'],

    'role' => $userData['role'] ?? 'admin',

] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];



$statusFilter = get('status', 'all');

$searchFilter = trim((string) get('search', ''));

$pageNum = max(1, (int) get('p', 1));

$perPage = 12;



$facilities = [];

$allFacilities = [];

$tableOk = false;

$error = null;

$pagination = ['total' => 0, 'total_pages' => 1, 'current_page' => 1, 'per_page' => $perPage, 'offset' => 0];

try {

    $svc = new FacilityService();

    $tableOk = $svc->tableExists();

    if ($tableOk) {

        $filters = [];

        if ($statusFilter !== 'all') {

            $filters['status'] = $statusFilter;

        }

        if ($searchFilter !== '') {

            $filters['search'] = $searchFilter;

        }

        $allFacilities = $svc->listForOrg($organizationId, $filters);

        $pagination = Utilities::paginate(count($allFacilities), $pageNum, $perPage);

        $facilities = array_slice($allFacilities, (int) $pagination['offset'], (int) $pagination['per_page']);

    }

} catch (\Exception $e) {

    $error = $e->getMessage();

}



require_once __DIR__ . '/includes/layout-vars.php';

$adminListBase = rtrim($adminBase, '/');

$pageTitle = 'Facilities';

$currentPage = 'facilities';

require __DIR__ . '/includes/header.php';



function adminFacilitiesPageUrl($adminBase, array $params = []) {

    $q = array_merge(['page' => 'facilities'], $params);

    $q = array_filter($q, static function ($v) {

        return $v !== null && $v !== '';

    });

    return rtrim($adminBase, '/') . '/?' . http_build_query($q);

}

function adminFacilityThumbUrl(array $f, string $basePath): ?string
{
    return headcount_facility_thumb_url($f, $basePath);
}

?>



<div class="animate-fade-in" x-data="adminFacilitiesPage()" x-init="init()">

    <?php

    $pageHeaderTitle = 'Facilities';

    $pageHeaderSubtitle = 'Manage bookable spaces and rooms.';

    ob_start();

    if ($tableOk): ?>

    <div class="flex flex-wrap items-center gap-2 sm:gap-3" role="group" aria-label="View mode">

        <div class="flex items-center gap-1 bg-gray-100 dark:bg-gray-800 rounded-xl p-1">

            <button type="button" @click="setView('grid')" :class="viewMode === 'grid' ? 'bg-white dark:bg-gray-700 text-brand-600 shadow-sm ring-1 ring-brand-200' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60'" class="px-3 py-2 rounded-lg transition-all font-bold text-sm" title="Grid view">

                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>

            </button>

            <button type="button" @click="setView('list')" :class="viewMode === 'list' ? 'bg-white dark:bg-gray-700 text-brand-600 shadow-sm ring-1 ring-brand-200' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60'" class="px-3 py-2 rounded-lg transition-all font-bold text-sm" title="List view">

                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>

            </button>

        </div>

    </div>

    <a href="<?= e(rtrim($adminBase, '/') . '/?page=facility-edit') ?>" class="page-header-btn-primary whitespace-nowrap flex-shrink-0">

        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>

        New Facility

    </a>

    <a href="<?= e(rtrim($adminBase, '/') . '/?page=facility-bookings') ?>" class="page-header-btn-secondary whitespace-nowrap flex-shrink-0">Booking queue</a>
    <a href="<?= e($navUrls['facility-bookings-calendar'] ?? (rtrim($adminBase, '/') . '/?page=facility-bookings-calendar')) ?>" class="page-header-btn-secondary whitespace-nowrap flex-shrink-0">Bookings calendar</a>

    <?php endif;

    $pageHeaderActions = ob_get_clean();

    require __DIR__ . '/components/page-header.php';

    ?>



    <?php if ($error): ?>

        <div class="ta-alert ta-alert-error mb-6"><?= e($error) ?></div>

    <?php elseif (!$tableOk): ?>

        <div class="ta-alert ta-alert-warning mb-6 flex-col items-start">

            <p class="font-semibold">Facilities tables are not installed yet.</p>

            <p class="text-sm mt-2">Run <code class="bg-amber-100 dark:bg-amber-900/30 px-1 rounded font-mono">database/migrations/059_facilities_domain.sql</code> on your database.</p>

        </div>

    <?php else: ?>



    <?php
    $filterBarAction = rtrim($adminBase, '/') . '/';
    $filterBarHiddenFields = [['name' => 'page', 'value' => 'facilities']];
    $filterBarFields = [
        ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'value' => $statusFilter, 'width' => 'w-40', 'options' => [
            'all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive',
        ]],
        ['name' => 'search', 'type' => 'search', 'label' => 'Search', 'value' => $searchFilter, 'placeholder' => 'Name, location, description…', 'width' => 'w-72'],
    ];
    require __DIR__ . '/components/filter-bar.php';
    ?>



    <?php if (empty($allFacilities)): ?>

        <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">

            <p class="text-gray-600 dark:text-gray-300">No facilities yet. Create your first bookable space.</p>

        </div>

    <?php else: ?>



    <!-- Grid view -->

    <div x-show="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">

        <?php foreach ($facilities as $f):

            $detailsUrl = rtrim($adminBase, '/') . '/?page=facility-details&id=' . (int) $f['id'];
            $editUrl = rtrim($adminBase, '/') . '/?page=facility-edit&id=' . (int) $f['id'];

            $bookUrl = rtrim($adminBase, '/') . '/?page=facility-bookings&facility_id=' . (int) $f['id'];

            $thumb = adminFacilityThumbUrl($f, $basePath);

        ?>

        <div class="flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">

            <div class="h-40 bg-gradient-to-br from-brand-100 to-gray-100 dark:from-gray-800 dark:to-gray-700 relative overflow-hidden">

                <?php if ($thumb): ?>

                <img src="<?= e($thumb) ?>" alt="<?= e($f['name']) ?>" class="w-full h-full object-cover">

                <?php endif; ?>

                <span class="absolute top-3 right-3 text-xs font-semibold px-2 py-1 rounded-full <?= ($f['status'] ?? '') === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' ?> dark:bg-gray-800 dark:text-gray-300"><?= e(ucfirst($f['status'] ?? 'inactive')) ?></span>

            </div>

            <div class="p-5 flex flex-col flex-1">

                <h3 class="text-lg font-bold text-gray-900 dark:text-white"><a href="<?= e($detailsUrl) ?>" class="hover:text-brand-600"><?= e($f['name']) ?></a></h3>

                <?php if (!empty($f['location'])): ?>

                <p class="text-sm text-gray-500 mt-1 line-clamp-1 dark:text-gray-400"><?= e($f['location']) ?></p>

                <?php endif; ?>

                <?php if (!empty($f['is_paid']) && (float) ($f['hourly_rate'] ?? 0) > 0): ?>

                <p class="text-sm font-semibold text-brand-600 mt-2">$<?= number_format((float) $f['hourly_rate'], 2) ?> / hr</p>

                <?php endif; ?>

                <div class="flex flex-wrap gap-2 mt-3 text-xs">

                    <?php if (!empty($f['allow_member_booking'])): ?><span class="px-2 py-1 bg-brand-50 text-brand-700 rounded">Members</span><?php endif; ?>

                    <?php if (!empty($f['allow_guest_booking'])): ?><span class="px-2 py-1 bg-sky-50 text-sky-700 rounded">Guests</span><?php endif; ?>

                </div>

                <div class="mt-auto pt-4 flex flex-wrap gap-2">

                    <a href="<?= e($detailsUrl) ?>" class="btn-primary">Manage</a>

                    <a href="<?= e($editUrl) ?>" class="btn-secondary">Edit</a>

                    <a href="<?= e($bookUrl) ?>" class="btn-secondary">Bookings</a>

                </div>

            </div>

        </div>

        <?php endforeach; ?>

    </div>



    <!-- List view -->
    <?php
    $facilityStatusVariants = ['active' => 'success', 'inactive' => 'gray'];
    $tableColumns = [
        ['key' => 'photo', 'label' => 'Photo', 'type' => 'raw', 'raw_key' => 'photo_html', 'class' => 'w-20'],
        ['key' => 'name', 'label' => 'Facility', 'type' => 'raw', 'raw_key' => 'name_html'],
        ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'badge_variant_key' => 'status_variant'],
        ['key' => 'booking', 'label' => 'Booking', 'type' => 'text'],
        ['key' => 'actions', 'label' => 'Actions', 'type' => 'actions', 'actions_key' => 'actions_html', 'class' => 'text-right w-48'],
    ];
    $tableRows = [];
    foreach ($facilities as $f) {
        $detailsUrl = rtrim($adminBase, '/') . '/?page=facility-details&id=' . (int) $f['id'];
        $editUrl = rtrim($adminBase, '/') . '/?page=facility-edit&id=' . (int) $f['id'];
        $bookUrl = rtrim($adminBase, '/') . '/?page=facility-bookings&facility_id=' . (int) $f['id'];
        $thumb = adminFacilityThumbUrl($f, $basePath);
        $photoHtml = '<div class="h-14 w-14 flex-shrink-0 overflow-hidden rounded-lg bg-gradient-to-br from-brand-100 to-gray-100 dark:from-gray-800 dark:to-gray-700">';
        if ($thumb) {
            $photoHtml .= '<img src="' . e($thumb) . '" alt="' . e($f['name']) . '" class="h-full w-full object-cover">';
        }
        $photoHtml .= '</div>';
        $nameHtml = '<div class="font-bold text-gray-900 dark:text-white/90"><a href="' . e($detailsUrl) . '" class="hover:text-brand-600">' . e($f['name']) . '</a></div>';
        if (!empty($f['location'])) {
            $nameHtml .= '<div class="text-theme-sm text-gray-500 dark:text-gray-400">' . e($f['location']) . '</div>';
        }
        $bookingParts = [];
        if (!empty($f['allow_member_booking'])) { $bookingParts[] = 'Members'; }
        if (!empty($f['allow_guest_booking'])) { $bookingParts[] = 'Guests'; }
        $actionsHtml = '<div class="inline-flex flex-wrap justify-end gap-2">'
            . '<a href="' . e($detailsUrl) . '" class="btn-primary py-1.5 px-3 text-xs">Manage</a>'
            . '<a href="' . e($editUrl) . '" class="btn-secondary py-1.5 px-3 text-xs">Edit</a>'
            . '<a href="' . e($bookUrl) . '" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-bold text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700">Bookings</a>'
            . '</div>';
        $tableRows[] = [
            'photo_html' => $photoHtml,
            'name_html' => $nameHtml,
            'status' => ucfirst($f['status'] ?? 'inactive'),
            'status_variant' => $facilityStatusVariants[$f['status'] ?? ''] ?? 'gray',
            'booking' => implode(' · ', $bookingParts) ?: '—',
            'actions_html' => $actionsHtml,
        ];
    }
    ?>
    <div x-show="viewMode === 'list'" x-cloak class="mb-8">
        <?php require __DIR__ . '/components/data-table.php'; ?>
    </div>



    <?php if ((int) $pagination['total_pages'] > 1):
        $paginationBaseUrl = adminFacilitiesPageUrl($adminListBase, array_filter([
            'status' => $statusFilter !== 'all' ? $statusFilter : null,
            'search' => $searchFilter !== '' ? $searchFilter : null,
        ]));
        $paginationCurrentPage = $pageNum;
        $paginationTotalPages = (int) $pagination['total_pages'];
        $paginationTotal = (int) $pagination['total'];
        $paginationPerPage = (int) $pagination['per_page'];
        require __DIR__ . '/components/pagination.php';
    endif; ?>

    <?php endif; ?>

    <?php endif; ?>

</div>



<script>

function adminFacilitiesPage() {

    return {

        viewMode: 'grid',

        init() {

            try {

                const saved = localStorage.getItem('admin_facilities_view');

                if (saved === 'grid' || saved === 'list') this.viewMode = saved;

            } catch (e) {}

        },

        setView(mode) {

            this.viewMode = mode;

            try { localStorage.setItem('admin_facilities_view', mode); } catch (e) {}

        },

    };

}

</script>



<?php require __DIR__ . '/includes/footer.php'; ?>

