<?php

/**

 * Facilities list (admin)

 */

require_once __DIR__ . '/../../vendor/autoload.php';

require_once __DIR__ . '/../../src/helpers.php';



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
    if (!empty($f['thumbnail_url'])) {
        return (string) $f['thumbnail_url'];
    }
    $first = null;
    if (!empty($f['images']) && is_array($f['images'])) {
        $first = $f['images'][0] ?? null;
    } elseif (!empty($f['image'])) {
        $first = $f['image'];
    }
    if ($first === null || trim((string) $first) === '') {
        return null;
    }
    if (filter_var($first, FILTER_VALIDATE_URL)) {
        return (string) $first;
    }
    $path = ltrim(str_replace('\\', '/', (string) $first), '/');
    if (strpos($path, 'uploads/') === 0) {
        $path = substr($path, strlen('uploads/'));
    }

    return rtrim($basePath, '/') . '/public/api/image.php?path=' . rawurlencode($path);
}

?>



<div class="animate-fade-in" x-data="adminFacilitiesPage()" x-init="init()">

    <?php

    $pageHeaderTitle = 'Facilities';

    $pageHeaderSubtitle = 'Manage bookable spaces and rooms.';

    ob_start();

    if ($tableOk): ?>

    <div class="flex flex-wrap items-center gap-2 sm:gap-3" role="group" aria-label="View mode">

        <div class="flex items-center gap-1 bg-gray-100 dark:bg-slate-800 rounded-xl p-1">

            <button type="button" @click="setView('grid')" :class="viewMode === 'grid' ? 'bg-white dark:bg-slate-700 text-indigo-600 shadow-sm ring-1 ring-indigo-200' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60'" class="px-3 py-2 rounded-lg transition-all font-bold text-sm" title="Grid view">

                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>

            </button>

            <button type="button" @click="setView('list')" :class="viewMode === 'list' ? 'bg-white dark:bg-slate-700 text-indigo-600 shadow-sm ring-1 ring-indigo-200' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60'" class="px-3 py-2 rounded-lg transition-all font-bold text-sm" title="List view">

                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>

            </button>

        </div>

    </div>

    <a href="<?= e(rtrim($adminBase, '/') . '/?page=facility-edit') ?>" class="page-header-btn-primary whitespace-nowrap flex-shrink-0">

        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>

        New Facility

    </a>

    <a href="<?= e(rtrim($adminBase, '/') . '/?page=facility-bookings') ?>" class="page-header-btn-secondary whitespace-nowrap flex-shrink-0">Booking queue</a>

    <?php endif;

    $pageHeaderActions = ob_get_clean();

    require __DIR__ . '/components/page-header.php';

    ?>



    <?php if ($error): ?>

        <div class="bg-red-50 border border-red-200 rounded-xl p-6 mb-6 text-red-900"><?= e($error) ?></div>

    <?php elseif (!$tableOk): ?>

        <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 mb-6 text-amber-900">

            <p class="font-semibold">Facilities tables are not installed yet.</p>

            <p class="text-sm mt-2">Run <code class="bg-amber-100 px-1 rounded font-mono">database/migrations/059_facilities_domain.sql</code> on your database.</p>

        </div>

    <?php else: ?>



    <div class="bento-card mb-6">

        <form method="GET" action="<?= e(rtrim($adminBase, '/') . '/') ?>" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">

            <input type="hidden" name="page" value="facilities">

            <div>

                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Status</label>

                <select name="status" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">

                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>

                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>

                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>

                </select>

            </div>

            <div class="md:col-span-2">

                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Search</label>

                <input type="search" name="search" value="<?= e($searchFilter) ?>" placeholder="Name, location, description…" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">

            </div>

            <div>

                <button type="submit" class="w-full px-4 py-2.5 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700">Filter</button>

            </div>

        </form>

    </div>



    <?php if (empty($allFacilities)): ?>

        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl p-10 text-center">

            <p class="text-gray-600 dark:text-slate-300">No facilities yet. Create your first bookable space.</p>

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

        <div class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden shadow-sm flex flex-col">

            <div class="h-40 bg-gradient-to-br from-indigo-100 to-slate-100 dark:from-slate-800 dark:to-slate-700 relative overflow-hidden">

                <?php if ($thumb): ?>

                <img src="<?= e($thumb) ?>" alt="<?= e($f['name']) ?>" class="w-full h-full object-cover">

                <?php endif; ?>

                <span class="absolute top-3 right-3 text-xs font-semibold px-2 py-1 rounded-full <?= ($f['status'] ?? '') === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' ?>"><?= e(ucfirst($f['status'] ?? 'inactive')) ?></span>

            </div>

            <div class="p-5 flex flex-col flex-1">

                <h3 class="text-lg font-bold text-gray-900 dark:text-white"><a href="<?= e($detailsUrl) ?>" class="hover:text-indigo-600"><?= e($f['name']) ?></a></h3>

                <?php if (!empty($f['location'])): ?>

                <p class="text-sm text-gray-500 mt-1 line-clamp-1"><?= e($f['location']) ?></p>

                <?php endif; ?>

                <?php if (!empty($f['is_paid']) && (float) ($f['hourly_rate'] ?? 0) > 0): ?>

                <p class="text-sm font-semibold text-indigo-600 mt-2">$<?= number_format((float) $f['hourly_rate'], 2) ?> / hr</p>

                <?php endif; ?>

                <div class="flex flex-wrap gap-2 mt-3 text-xs">

                    <?php if (!empty($f['allow_member_booking'])): ?><span class="px-2 py-1 bg-indigo-50 text-indigo-700 rounded">Members</span><?php endif; ?>

                    <?php if (!empty($f['allow_guest_booking'])): ?><span class="px-2 py-1 bg-sky-50 text-sky-700 rounded">Guests</span><?php endif; ?>

                </div>

                <div class="mt-auto pt-4 flex flex-wrap gap-2">

                    <a href="<?= e($detailsUrl) ?>" class="inline-flex items-center justify-center px-4 py-2 text-sm font-bold rounded-xl bg-indigo-600 text-white hover:bg-indigo-700">Manage</a>

                    <a href="<?= e($editUrl) ?>" class="inline-flex items-center justify-center px-4 py-2 text-sm font-bold rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">Edit</a>

                    <a href="<?= e($bookUrl) ?>" class="inline-flex items-center justify-center px-4 py-2 text-sm font-bold rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">Bookings</a>

                </div>

            </div>

        </div>

        <?php endforeach; ?>

    </div>



    <!-- List view -->

    <div x-show="viewMode === 'list'" x-cloak class="ta-table-wrap mb-8 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-slate-700 dark:bg-slate-900">

        <table class="ta-table w-full">

            <thead>

                <tr>

                    <th class="w-20">Photo</th>

                    <th>Facility</th>

                    <th>Status</th>

                    <th>Booking</th>

                    <th class="text-right w-48">Actions</th>

                </tr>

            </thead>

            <tbody>

                <?php foreach ($facilities as $f):

                    $editUrl = rtrim($adminBase, '/') . '/?page=facility-edit&id=' . (int) $f['id'];

                    $bookUrl = rtrim($adminBase, '/') . '/?page=facility-bookings&facility_id=' . (int) $f['id'];

                    $thumb = adminFacilityThumbUrl($f, $basePath);

                ?>

                <tr>

                    <td>

                        <div class="w-14 h-14 rounded-lg overflow-hidden bg-gradient-to-br from-indigo-100 to-slate-100 flex-shrink-0">

                            <?php if ($thumb): ?>

                            <img src="<?= e($thumb) ?>" alt="<?= e($f['name']) ?>" class="w-full h-full object-cover">

                            <?php endif; ?>

                        </div>

                    </td>

                    <td>

                        <div class="font-bold text-gray-900 dark:text-white"><a href="<?= e($detailsUrl) ?>" class="hover:text-indigo-600"><?= e($f['name']) ?></a></div>

                        <?php if (!empty($f['location'])): ?><div class="text-sm text-gray-500"><?= e($f['location']) ?></div><?php endif; ?>

                    </td>

                    <td><span class="text-xs font-semibold px-2 py-1 rounded-full <?= ($f['status'] ?? '') === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' ?>"><?= e(ucfirst($f['status'] ?? 'inactive')) ?></span></td>

                    <td class="text-sm text-gray-600">

                        <?php if (!empty($f['allow_member_booking'])): ?>Members<?php endif; ?>

                        <?php if (!empty($f['allow_member_booking']) && !empty($f['allow_guest_booking'])): ?> · <?php endif; ?>

                        <?php if (!empty($f['allow_guest_booking'])): ?>Guests<?php endif; ?>

                    </td>

                    <td class="text-right">

                        <div class="inline-flex flex-wrap justify-end gap-2">

                            <a href="<?= e($detailsUrl) ?>" class="px-3 py-1.5 text-sm font-bold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Manage</a>

                            <a href="<?= e($editUrl) ?>" class="px-3 py-1.5 text-sm font-bold rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50">Edit</a>

                            <a href="<?= e($bookUrl) ?>" class="px-3 py-1.5 text-sm font-bold rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50">Bookings</a>

                        </div>

                    </td>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>



    <?php if ((int) $pagination['total_pages'] > 1): ?>

    <nav class="flex items-center justify-between px-1 py-4" aria-label="Facilities pagination">

        <p class="text-sm text-gray-500">

            Showing <?= (int) $pagination['offset'] + 1 ?>–<?= min((int) $pagination['offset'] + (int) $pagination['per_page'], (int) $pagination['total']) ?> of <?= (int) $pagination['total'] ?>

        </p>

        <div class="flex items-center gap-2">

            <?php if ($pageNum > 1): ?>

            <a href="<?= e(adminFacilitiesPageUrl($adminListBase, array_filter(['status' => $statusFilter !== 'all' ? $statusFilter : null, 'search' => $searchFilter !== '' ? $searchFilter : null, 'p' => $pageNum - 1]))) ?>" class="px-3 py-2 text-sm font-semibold rounded-lg border border-gray-200 hover:bg-gray-50">Previous</a>

            <?php endif; ?>

            <?php if ($pageNum < (int) $pagination['total_pages']): ?>

            <a href="<?= e(adminFacilitiesPageUrl($adminListBase, array_filter(['status' => $statusFilter !== 'all' ? $statusFilter : null, 'search' => $searchFilter !== '' ? $searchFilter : null, 'p' => $pageNum + 1]))) ?>" class="px-3 py-2 text-sm font-semibold rounded-lg border border-gray-200 hover:bg-gray-50">Next</a>

            <?php endif; ?>

        </div>

    </nav>

    <?php endif; ?>

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

