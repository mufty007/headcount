<?php
/**
 * Facility create/edit (admin) — multi-step wizard
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
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\FacilityService;

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();

$db = Database::getInstance();
$userData = $db->queryOne("SELECT first_name, last_name, email, role FROM users WHERE id = :id", ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

$facilityId = (int) get('id', 0);
$svc = new FacilityService();
$facility = null;
$tableOk = $svc->tableExists();
if ($tableOk && $facilityId > 0) {
    $facility = $svc->getByIdForOrg($facilityId, $organizationId);
}

$canEditFacility = AuthMiddleware::can('facilities.manage') || AuthMiddleware::isSuperAdmin();
if (!$canEditFacility && $facilityId > 0) {
    $managed = $svc->getManagedFacilityIds((int) $userId, (int) $organizationId);
    $canEditFacility = in_array($facilityId, $managed, true);
}
if (!$canEditFacility) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$facilityAddons = [];
if ($facilityId > 0) {
    try {
        $facilityAddons = (new \Headcount\Services\FacilityAddonService())->listForFacility($facilityId, false);
    } catch (\Throwable $e) {
        $facilityAddons = [];
    }
}

$defaults = [
    'name' => '',
    'slug' => '',
    'description' => '',
    'location' => '',
    'capacity' => '',
    'status' => 'active',
    'allow_member_booking' => true,
    'allow_guest_booking' => true,
    'is_paid' => false,
    'hourly_rate' => '',
    'discount_percent' => 0,
    'discount_label' => '',
    'images' => [],
    'operating_hours' => $svc->defaultOperatingHours(),
    'blocked_times' => [],
    'member_max_duration_minutes' => 120,
    'member_advance_days' => 30,
    'guest_max_duration_minutes' => 120,
    'guest_advance_days' => 14,
    'staff_max_duration_minutes' => 480,
    'staff_advance_days' => 90,
    'min_duration_minutes' => 30,
    'buffer_minutes' => 0,
    'slot_increment_minutes' => 30,
];

$form = $facility ? array_merge($defaults, $facility) : $defaults;
if (empty($form['operating_hours']) || !is_array($form['operating_hours'])) {
    $form['operating_hours'] = $svc->defaultOperatingHours();
}
if (empty($form['images']) || !is_array($form['images'])) {
    $form['images'] = [];
}
if (empty($form['blocked_times']) || !is_array($form['blocked_times'])) {
    $form['blocked_times'] = [];
}

$eligibleManagers = $tableOk ? $svc->listEligibleManagers($organizationId) : [];
$managerIds = [];
if ($tableOk && $facilityId > 0 && $svc->managersTableExists()) {
    $managerIds = array_map(static fn ($m) => (int) $m['id'], $svc->getManagers($facilityId, $organizationId));
}
$form['manager_ids'] = $managerIds;

$dayLabels = [
    '0' => 'Sunday',
    '1' => 'Monday',
    '2' => 'Tuesday',
    '3' => 'Wednesday',
    '4' => 'Thursday',
    '5' => 'Friday',
    '6' => 'Saturday',
];

require_once __DIR__ . '/includes/layout-vars.php';
$apiUrl = $basePath . '/public/api/facilities.php';
$csrfToken = CsrfMiddleware::getToken();
$pageTitle = $facilityId ? 'Edit Facility' : 'New Facility';
$currentPage = 'facilities';
$adminMainFullWidth = true;
$requiresQuillEditor = true;
$inputClass = 'ta-input w-full';
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in admin-event-wizard w-full min-w-0" style="width:100%;max-width:100%" x-data="facilityEditApp()" x-init="init()">
    <?php
    $pageHeaderTitle = $facilityId ? 'Edit Facility' : 'New Facility';
    $pageHeaderSubtitle = 'Configure images, pricing, availability hours, and booking rules.';
    $pageHeaderBreadcrumb = [
        ['label' => 'Facilities', 'url' => rtrim($adminBase, '/') . '/?page=facilities'],
        ['label' => $facilityId ? 'Edit Facility' : 'New Facility'],
    ];
    $pageHeaderActions = '<a href="' . e(rtrim($adminBase, '/') . '/?page=facilities') . '" class="btn-secondary whitespace-nowrap">Back to list</a>';
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if (!$tableOk): ?>
        <div class="ta-alert ta-alert-warning">Run migrations 059_facilities_domain.sql, 060_facilities_pricing_images.sql, and 061_facility_blocked_times.sql first.</div>
    <?php else: ?>

    <div class="multi-step-progress">
        <div class="step-item active" id="fac-step-item-1" role="button" tabindex="0" onclick="facShowStep(1)">
            <div class="step-circle">1</div>
            <span class="step-label">Basic Info</span>
        </div>
        <div class="step-item" id="fac-step-item-2" role="button" tabindex="0" onclick="facShowStep(2)">
            <div class="step-circle">2</div>
            <span class="step-label">Images &amp; Pricing</span>
        </div>
        <div class="step-item" id="fac-step-item-3" role="button" tabindex="0" onclick="facShowStep(3)">
            <div class="step-circle">3</div>
            <span class="step-label">Availability</span>
        </div>
        <div class="step-item" id="fac-step-item-4" role="button" tabindex="0" onclick="facShowStep(4)">
            <div class="step-circle">4</div>
            <span class="step-label">Booking Rules</span>
        </div>
    </div>

    <form @submit.prevent="save" id="facility-edit-form">
        <p x-show="error" x-text="error" class="text-red-600 text-sm font-medium mb-4"></p>

        <!-- Step 1: Basic Info -->
        <div class="step-panel active" id="fac-panel-1">
            <?php ob_start(); $formSectionCols = 2; ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="form-label">Name <span class="text-red-500">*</span></label>
                    <input type="text" x-model="form.name" required class="<?= e($inputClass) ?>" placeholder="e.g. Main Hall">
                </div>
                <div>
                    <label class="form-label">Slug</label>
                    <input type="text" x-model="form.slug" placeholder="auto-generated if empty" class="<?= e($inputClass) ?>">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Location</label>
                <input type="text" x-model="form.location" class="<?= e($inputClass) ?>" placeholder="Building, room, or address">
            </div>
            <div class="mb-4">
                <label class="form-label" for="facility-description">Description</label>
                <div class="facility-description-editor w-full min-w-0">
                    <textarea id="facility-description" x-model="form.description" rows="6"
                              class="wysiwyg-editor w-full border border-gray-200 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                              placeholder="Describe the space and what it is used for"><?= headcount_wysiwyg_textarea_body($form['description'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="form-label">Capacity</label>
                    <input type="number" x-model.number="form.capacity" min="0" class="<?= e($inputClass) ?>">
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <select x-model="form.status" class="<?= e($inputClass) ?>">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <?php if ($svc->managersTableExists() && !empty($eligibleManagers)): ?>
            <div class="mb-4">
                <label class="form-label">Facility managers</label>
                <p class="text-xs text-gray-500 mb-2 dark:text-gray-400">Assigned managers receive email when a booking is requested and can approve or reject it. If none are assigned, all org admins and coordinators are notified.</p>
                <div class="rounded-xl border border-gray-200 dark:border-gray-600 p-4 max-h-48 overflow-y-auto space-y-2">
                    <?php foreach ($eligibleManagers as $em): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                        <input type="checkbox" value="<?= (int) $em['id'] ?>"
                               :checked="form.manager_ids.includes(<?= (int) $em['id'] ?>)"
                               @change="toggleManager(<?= (int) $em['id'] ?>, $event.target.checked)"
                               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <span><?= e(trim($em['first_name'] . ' ' . $em['last_name'])) ?> <span class="text-gray-400">(<?= e($em['email']) ?> · <?= e($em['role']) ?>)</span></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php elseif (!$svc->managersTableExists()): ?>
            <p class="text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 mb-4">Run migration 064_facility_managers.sql to assign facility managers.</p>
            <?php endif; ?>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Basic Information';
            require __DIR__ . '/components/form-section.php';
            unset($formSectionCols);
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-primary" @click="nextStep(2)">
                    Next: Images &amp; Pricing
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e(rtrim($adminBase, '/') . '/?page=facilities') ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 2: Images & Pricing -->
        <div class="step-panel" id="fac-panel-2">
            <?php ob_start(); ?>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90 mb-3">Images</h3>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">Upload multiple photos. The first image is used as the cover on listing pages.</p>
            <div class="flex flex-wrap gap-3 mb-6" x-show="form.images.length || pendingPreviews.length">
                <template x-for="(img, idx) in form.images" :key="'saved-' + img + idx">
                    <div class="relative w-32 h-32 rounded-xl overflow-hidden border border-gray-200 group dark:border-gray-700">
                        <img :src="imageUrl(img)" alt="" class="w-full h-full object-cover">
                        <button type="button" @click="removeImage(idx)" class="absolute top-1 right-1 w-6 h-6 rounded-full bg-red-600 text-white text-xs opacity-0 group-hover:opacity-100 transition-opacity">&times;</button>
                    </div>
                </template>
                <template x-for="(preview, idx) in pendingPreviews" :key="'pending-' + idx">
                    <div class="relative w-32 h-32 rounded-xl overflow-hidden border border-dashed border-brand-300">
                        <img :src="preview" alt="" class="w-full h-full object-cover opacity-90">
                        <span class="absolute bottom-0 inset-x-0 bg-brand-600/80 text-white text-[10px] text-center py-0.5">New</span>
                    </div>
                </template>
            </div>
            <div class="mb-8">
                <label class="form-label">Add images</label>
                <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple
                       @change="onImagesSelected($event)"
                       class="block w-full text-sm border border-gray-200 rounded-xl px-4 py-2.5 file:mr-4 file:py-1.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 dark:border-gray-700">
            </div>

            <div class="form-section-title">Pricing &amp; Discounts</div>
            <label class="form-toggle cursor-pointer mb-4">
                <input type="checkbox" x-model="form.is_paid" class="rounded border-gray-300 text-brand-600">
                <div>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Paid facility (booked per hour)</span>
                    <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Enable hourly pricing for this space.</p>
                </div>
            </label>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" x-show="form.is_paid" x-cloak>
                <div>
                    <label class="form-label">Hourly rate</label>
                    <input type="number" x-model.number="form.hourly_rate" min="0" step="0.01" class="<?= e($inputClass) ?>" placeholder="0.00">
                </div>
                <div>
                    <label class="form-label">Discount (%)</label>
                    <input type="number" x-model.number="form.discount_percent" min="0" max="100" step="0.01" class="<?= e($inputClass) ?>">
                </div>
                <div class="sm:col-span-2 lg:col-span-1">
                    <label class="form-label">Discount label (optional)</label>
                    <input type="text" x-model="form.discount_label" class="<?= e($inputClass) ?>" placeholder="e.g. Member special 10% off">
                </div>
            </div>
            <div class="mt-6" x-show="form.is_paid" x-cloak>
                <div class="form-section-title">Add-ons &amp; packages</div>
                <p class="form-hint mb-3">Optional extras members can add when booking.</p>
                <div class="space-y-3">
                    <template x-for="(addon, aIdx) in addons" :key="aIdx">
                        <div class="rounded-xl border border-gray-200 p-4 space-y-2 dark:border-gray-700">
                            <div class="flex flex-wrap gap-2">
                                <input type="text" x-model="addon.title" class="<?= e($inputClass) ?> flex-1 min-w-[8rem]" placeholder="Title">
                                <input type="number" min="0" step="0.01" x-model.number="addon.price" class="<?= e($inputClass) ?> w-28" placeholder="Price">
                                <select x-model="addon.kind" class="<?= e($inputClass) ?> w-32">
                                    <option value="addon">Add-on</option>
                                    <option value="package">Package</option>
                                </select>
                                <label class="flex items-center gap-1 text-xs shrink-0"><input type="checkbox" x-model="addon.active" class="rounded"> Active</label>
                                <button type="button" @click="addons.splice(aIdx, 1)" class="text-rose-600 text-sm font-bold">×</button>
                            </div>
                            <textarea x-model="addon.description" rows="2" class="<?= e($inputClass) ?>" placeholder="Description (optional)"></textarea>
                            <textarea x-show="addon.kind === 'package'" x-model="addon.package_items_text" rows="2" class="<?= e($inputClass) ?>" placeholder="Package items, one per line"></textarea>
                        </div>
                    </template>
                    <button type="button" @click="addons.push({ title: '', description: '', price: 0, kind: 'addon', package_items_text: '', active: true })" class="text-sm font-semibold text-brand-600">+ Add add-on</button>
                </div>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Images & Pricing';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" @click="prevStep(1)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="button" class="btn-primary" @click="nextStep(3)">
                    Next: Availability
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
            </div>
        </div>

        <!-- Step 3: Availability -->
        <div class="step-panel" id="fac-panel-3">
            <?php ob_start(); ?>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">Bookings must fall within these hours. Staff can override when approving.</p>

            <div class="flex flex-wrap gap-2 mb-4">
                <button type="button" class="btn-secondary text-sm py-2 px-3" @click="applyHoursPreset('weekday')">Weekdays 9 AM – 9 PM</button>
                <button type="button" class="btn-secondary text-sm py-2 px-3" @click="applyHoursPreset('business')">Mon–Fri 9 AM – 5 PM</button>
                <button type="button" class="btn-secondary text-sm py-2 px-3" @click="copyMondayToAll()">Copy Monday to all days</button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm facility-hours-table">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide dark:bg-gray-800 dark:text-gray-400">
                            <th class="px-4 py-3">Day</th>
                            <th class="px-4 py-3 w-24 text-center">Closed</th>
                            <th class="px-4 py-3">Opens</th>
                            <th class="px-4 py-3 w-8 text-center"></th>
                            <th class="px-4 py-3">Closes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dayLabels as $dayKey => $dayLabel): ?>
                        <tr class="border-t border-gray-100 dark:border-gray-800" :class="form.operating_hours['<?= $dayKey ?>'].closed ? 'bg-gray-50/80' : ''">
                            <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100"><?= e($dayLabel) ?></td>
                            <td class="px-4 py-3 text-center">
                                <input type="checkbox" x-model="form.operating_hours['<?= $dayKey ?>'].closed" class="rounded border-gray-300 text-brand-600" aria-label="<?= e($dayLabel) ?> closed">
                            </td>
                            <td class="px-4 py-3">
                                <input type="time" x-model="form.operating_hours['<?= $dayKey ?>'].open"
                                       :disabled="form.operating_hours['<?= $dayKey ?>'].closed"
                                       class="<?= e($inputClass) ?> py-2 text-sm max-w-[9rem]">
                            </td>
                            <td class="px-2 py-3 text-center text-gray-400">to</td>
                            <td class="px-4 py-3">
                                <input type="time" x-model="form.operating_hours['<?= $dayKey ?>'].close"
                                       :disabled="form.operating_hours['<?= $dayKey ?>'].closed"
                                       class="<?= e($inputClass) ?> py-2 text-sm max-w-[9rem]">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="blocked-times" class="form-section-title mt-10">Blocked / reserved times</div>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">Block one day, a date range, or recurring weekdays (e.g. school hours). Members and guests cannot book during these windows. Staff can still create bookings when approving requests.</p>

            <div class="flex flex-wrap gap-2 mb-4">
                <button type="button" class="btn-secondary text-sm py-2 px-3" @click="addBlockedTime('once')">+ One day</button>
                <button type="button" class="btn-secondary text-sm py-2 px-3" @click="addBlockedTime('range')">+ Date range</button>
                <button type="button" class="btn-secondary text-sm py-2 px-3" @click="addBlockedTime('weekly')">+ Weekly (e.g. school hours)</button>
                <button type="button" class="btn-secondary text-sm py-2 px-3" @click="addSchoolHoursBlock()">School hours preset</button>
            </div>

            <div class="space-y-4 mb-4" x-show="form.blocked_times.length">
                <template x-for="(block, idx) in form.blocked_times" :key="'block-' + idx">
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                            <div class="flex flex-wrap gap-2 text-sm">
                                <label class="inline-flex items-center gap-1.5">
                                    <input type="radio" :name="'block-repeat-' + idx" value="once" x-model="block.repeat" @change="onBlockRepeatChange(block)">
                                    One day
                                </label>
                                <label class="inline-flex items-center gap-1.5">
                                    <input type="radio" :name="'block-repeat-' + idx" value="range" x-model="block.repeat" @change="onBlockRepeatChange(block)">
                                    Date range
                                </label>
                                <label class="inline-flex items-center gap-1.5">
                                    <input type="radio" :name="'block-repeat-' + idx" value="weekly" x-model="block.repeat" @change="onBlockRepeatChange(block)">
                                    Weekly
                                </label>
                            </div>
                            <button type="button" @click="removeBlockedTime(idx)" class="text-red-600 hover:text-red-800 text-sm font-semibold" aria-label="Remove">&times; Remove</button>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label class="block text-sm" x-show="block.repeat === 'once'">
                                <span class="text-xs font-semibold uppercase text-gray-500">Date</span>
                                <input type="date" x-model="block.date" @change="block.start_date = block.date; block.end_date = block.date" class="<?= e($inputClass) ?> mt-1 py-2 text-sm">
                            </label>
                            <label class="block text-sm" x-show="block.repeat !== 'once'">
                                <span class="text-xs font-semibold uppercase text-gray-500">Start date</span>
                                <input type="date" x-model="block.start_date" @change="block.date = block.start_date" class="<?= e($inputClass) ?> mt-1 py-2 text-sm">
                            </label>
                            <label class="block text-sm" x-show="block.repeat !== 'once'">
                                <span class="text-xs font-semibold uppercase text-gray-500">End date</span>
                                <input type="date" x-model="block.end_date" class="<?= e($inputClass) ?> mt-1 py-2 text-sm">
                            </label>
                            <label class="block text-sm">
                                <span class="text-xs font-semibold uppercase text-gray-500">Start time</span>
                                <input type="time" x-model="block.start_time" class="<?= e($inputClass) ?> mt-1 py-2 text-sm">
                            </label>
                            <label class="block text-sm">
                                <span class="text-xs font-semibold uppercase text-gray-500">End time</span>
                                <input type="time" x-model="block.end_time" class="<?= e($inputClass) ?> mt-1 py-2 text-sm">
                            </label>
                            <label class="block text-sm sm:col-span-2 lg:col-span-4">
                                <span class="text-xs font-semibold uppercase text-gray-500">Reason</span>
                                <input type="text" x-model="block.reason" placeholder="e.g. School hours, holiday break, maintenance" class="<?= e($inputClass) ?> mt-1 py-2 text-sm">
                            </label>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-3 text-sm" x-show="block.repeat === 'weekly'">
                            <template x-for="day in weekDayOptions" :key="'d-' + idx + '-' + day.value">
                                <label class="inline-flex items-center gap-1.5">
                                    <input type="checkbox" :value="day.value" :checked="(block.days_of_week || []).includes(day.value)" @change="toggleBlockDay(block, day.value, $event.target.checked)" class="rounded border-gray-300 text-brand-600">
                                    <span x-text="day.label"></span>
                                </label>
                            </template>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-4 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" x-model="block.block_member" class="rounded border-gray-300 text-brand-600"> Block members
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" x-model="block.block_guest" class="rounded border-gray-300 text-brand-600"> Block guests
                            </label>
                        </div>
                    </div>
                </template>
            </div>

            <p x-show="!form.blocked_times.length" class="text-sm text-gray-400 mb-4">No blocked times yet. Add a one-day block, a multi-day range, or weekly school hours.</p>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Available Hours';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" @click="prevStep(2)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="button" class="btn-primary" @click="nextStep(4)">
                    Next: Booking Rules
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
            </div>
        </div>

        <!-- Step 4: Booking Rules -->
        <div class="step-panel" id="fac-panel-4">
            <?php ob_start(); $formSectionCols = 2; ?>
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90 mb-3 md:col-span-2">Who Can Book</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                <label class="form-toggle cursor-pointer">
                    <input type="checkbox" x-model="form.allow_member_booking" class="rounded border-gray-300 text-brand-600">
                    <div>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Allow member booking (portal)</span>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Logged-in members can request bookings.</p>
                    </div>
                </label>
                <label class="form-toggle cursor-pointer">
                    <input type="checkbox" x-model="form.allow_guest_booking" class="rounded border-gray-300 text-brand-600">
                    <div>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Allow guest booking (no login)</span>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Guests can submit requests without an account.</p>
                    </div>
                </label>
            </div>

            <div class="form-section-title">Booking Limits</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="form-label">Member max duration (min)</label>
                    <input type="number" x-model.number="form.member_max_duration_minutes" min="15" class="<?= e($inputClass) ?>">
                </div>
                <div>
                    <label class="form-label">Member advance days</label>
                    <input type="number" x-model.number="form.member_advance_days" min="1" class="<?= e($inputClass) ?>">
                </div>
                <div>
                    <label class="form-label">Guest max duration (min)</label>
                    <input type="number" x-model.number="form.guest_max_duration_minutes" min="15" class="<?= e($inputClass) ?>">
                </div>
                <div>
                    <label class="form-label">Guest advance days</label>
                    <input type="number" x-model.number="form.guest_advance_days" min="1" class="<?= e($inputClass) ?>">
                </div>
                <div>
                    <label class="form-label">Staff max duration (min)</label>
                    <input type="number" x-model.number="form.staff_max_duration_minutes" min="15" class="<?= e($inputClass) ?>">
                </div>
                <div>
                    <label class="form-label">Staff advance days</label>
                    <input type="number" x-model.number="form.staff_advance_days" min="1" class="<?= e($inputClass) ?>">
                </div>
                <div>
                    <label class="form-label">Min duration (min)</label>
                    <input type="number" x-model.number="form.min_duration_minutes" min="15" class="<?= e($inputClass) ?>">
                </div>
                <div>
                    <label class="form-label">Buffer between bookings (min)</label>
                    <input type="number" x-model.number="form.buffer_minutes" min="0" class="<?= e($inputClass) ?>">
                </div>
                <div>
                    <label class="form-label">Slot increment (min)</label>
                    <input type="number" x-model.number="form.slot_increment_minutes" min="15" class="<?= e($inputClass) ?>">
                </div>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Booking Rules';
            require __DIR__ . '/components/form-section.php';
            unset($formSectionCols);
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" @click="prevStep(3)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="submit" :disabled="saving" class="btn-primary">
                    <span x-text="saving ? 'Saving…' : 'Save facility'"></span>
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
window.facCurrentStep = 1;
window.facShowStep = function(step) {
    var totalSteps = 4;
    var prevStep = window.facCurrentStep;
    step = Math.max(1, Math.min(totalSteps, step));
    if (prevStep === 1 && step !== 1) {
        var descEl = document.getElementById('facility-description');
        var wizard = document.querySelector('.admin-event-wizard');
        var app = wizard && wizard._x_dataStack ? wizard._x_dataStack[0] : null;
        if (descEl && window.__quillInstances) {
            var q = window.__quillInstances.get(descEl);
            if (q) {
                var html = q.root.innerHTML;
                var clean = (html === '<p><br></p>') ? '' : html;
                descEl.value = clean;
                descEl.dispatchEvent(new Event('input', { bubbles: true }));
                if (app && app.form) {
                    app.form.description = clean;
                }
            }
        }
    }
    window.facCurrentStep = step;
    for (var i = 1; i <= totalSteps; i++) {
        var panel = document.getElementById('fac-panel-' + i);
        var item = document.getElementById('fac-step-item-' + i);
        if (panel) panel.classList.toggle('active', i === step);
        if (item) {
            item.classList.remove('active', 'completed');
            if (i === step) item.classList.add('active');
            else if (i < step) item.classList.add('completed');
        }
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (step === 1) {
        var wizard = document.querySelector('.admin-event-wizard');
        if (wizard && wizard._x_dataStack && wizard._x_dataStack[0] && typeof wizard._x_dataStack[0].bootFacilityDescriptionEditor === 'function') {
            wizard._x_dataStack[0].bootFacilityDescriptionEditor();
        }
    }
};

function facilityEditApp() {
    const numericFields = [
        'capacity', 'member_max_duration_minutes', 'member_advance_days',
        'guest_max_duration_minutes', 'guest_advance_days',
        'staff_max_duration_minutes', 'staff_advance_days',
        'min_duration_minutes', 'buffer_minutes', 'slot_increment_minutes',
        'hourly_rate', 'discount_percent',
    ];
    return {
        form: <?= json_encode($form, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        facilityId: <?= (int) $facilityId ?>,
        apiUrl: <?= json_encode($apiUrl) ?>,
        csrf: <?= json_encode($csrfToken) ?>,
        imageBase: <?= json_encode(rtrim($basePath, '/') . '/public/api/image.php?path=') ?>,
        newImageFiles: [],
        pendingPreviews: [],
        saving: false,
        error: '',
        addons: <?= json_encode(array_map(static function ($a) {
            return [
                'title' => $a['title'] ?? '',
                'description' => $a['description'] ?? '',
                'price' => (float) ($a['price'] ?? 0),
                'kind' => $a['kind'] ?? 'addon',
                'package_items_text' => implode("\n", is_array($a['package_items'] ?? null) ? $a['package_items'] : []),
                'active' => !empty($a['active']),
            ];
        }, $facilityAddons), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        init() {
            numericFields.forEach((f) => {
                if (this.form[f] === '' || this.form[f] == null) return;
                this.form[f] = Number(this.form[f]);
            });
            this.form.allow_member_booking = !!this.form.allow_member_booking;
            this.form.allow_guest_booking = !!this.form.allow_guest_booking;
            this.form.is_paid = !!this.form.is_paid;
            if (!Array.isArray(this.form.images)) this.form.images = [];
            if (!Array.isArray(this.form.blocked_times)) this.form.blocked_times = [];
            if (!Array.isArray(this.form.manager_ids)) this.form.manager_ids = [];
            this.form.blocked_times = this.form.blocked_times.map((block) => this.normalizeBlockForm(block));
            if (!this.form.operating_hours || typeof this.form.operating_hours !== 'object') {
                this.form.operating_hours = <?= json_encode($svc->defaultOperatingHours()) ?>;
            }
            for (let d = 0; d <= 6; d++) {
                const key = String(d);
                if (!this.form.operating_hours[key]) {
                    this.form.operating_hours[key] = { open: '09:00', close: '21:00', closed: d === '0' || d === '6' };
                }
                this.form.operating_hours[key].closed = !!this.form.operating_hours[key].closed;
            }
            window.facShowStep(1);
            this.bootFacilityDescriptionEditor();
        },
        bootFacilityDescriptionEditor() {
            const run = () => {
                const el = document.getElementById('facility-description');
                if (!el) return;
                if (this.form.description) {
                    el.value = this.form.description;
                }
                if (typeof window.initWYSIWYG !== 'function') return;
                if (!el.dataset.quillInitialized) {
                    window.initWYSIWYG('#facility-description', {
                        onChange: (content) => {
                            this.form.description = content;
                        },
                    });
                } else {
                    el.dispatchEvent(new Event('sync-to-quill'));
                }
            };
            this.$nextTick(() => {
                run();
                if (typeof Quill === 'undefined') {
                    window.addEventListener('load', run, { once: true });
                }
            });
        },
        validateStep(step) {
            this.error = '';
            if (step === 1 && !String(this.form.name || '').trim()) {
                this.error = 'Facility name is required.';
                return false;
            }
            if (step === 2 && this.form.is_paid && !(Number(this.form.hourly_rate) > 0)) {
                this.error = 'Hourly rate is required for paid facilities.';
                return false;
            }
            if (step === 3) {
                for (const block of this.form.blocked_times) {
                    const repeat = block.repeat || 'once';
                    if (!block.start_time || !block.end_time || block.end_time <= block.start_time) {
                        this.error = 'Blocked times must have a valid start and end time (end after start).';
                        return false;
                    }
                    if (repeat === 'once') {
                        if (!block.date) {
                            this.error = 'Each one-day block needs a date.';
                            return false;
                        }
                    } else {
                        if (!block.start_date || !block.end_date) {
                            this.error = 'Date-range and weekly blocks need a start and end date.';
                            return false;
                        }
                        if (block.end_date < block.start_date) {
                            this.error = 'Block end date must be on or after the start date.';
                            return false;
                        }
                    }
                    if (repeat === 'weekly' && !(block.days_of_week || []).length) {
                        this.error = 'Weekly blocks need at least one weekday selected.';
                        return false;
                    }
                }
            }
            return true;
        },
        nextStep(step) {
            if (!this.validateStep(window.facCurrentStep)) {
                window.facShowStep(window.facCurrentStep);
                return;
            }
            if (step !== 1) {
                this.syncDescriptionFromEditor();
            }
            window.facShowStep(step);
        },
        prevStep(step) {
            this.error = '';
            if (window.facCurrentStep === 1) {
                this.syncDescriptionFromEditor();
            }
            window.facShowStep(step);
            if (step === 1) {
                this.bootFacilityDescriptionEditor();
            }
        },
        syncDescriptionFromEditor() {
            const descEl = document.getElementById('facility-description');
            if (!descEl) return;
            const q = window.__quillInstances && window.__quillInstances.get(descEl);
            if (q) {
                const html = q.root.innerHTML;
                this.form.description = (html === '<p><br></p>') ? '' : html;
                descEl.value = this.form.description;
            } else {
                this.form.description = descEl.value || '';
            }
        },
        imageUrl(path) {
            if (!path) return '';
            if (String(path).startsWith('http')) return path;
            return this.imageBase + encodeURIComponent(String(path).replace(/^uploads\//, ''));
        },
        onImagesSelected(e) {
            Array.from(e.target.files || []).forEach((file) => {
                this.newImageFiles.push(file);
                this.pendingPreviews.push(URL.createObjectURL(file));
            });
            e.target.value = '';
        },
        removeImage(idx) {
            this.form.images.splice(idx, 1);
        },
        applyHoursPreset(preset) {
            for (let d = 0; d <= 6; d++) {
                const key = String(d);
                if (!this.form.operating_hours[key]) {
                    this.form.operating_hours[key] = { open: '09:00', close: '21:00', closed: false };
                }
                if (preset === 'weekday') {
                    this.form.operating_hours[key].closed = (d === 0 || d === 6);
                    this.form.operating_hours[key].open = '09:00';
                    this.form.operating_hours[key].close = '21:00';
                } else if (preset === 'business') {
                    this.form.operating_hours[key].closed = (d === 0 || d === 6);
                    this.form.operating_hours[key].open = '09:00';
                    this.form.operating_hours[key].close = '17:00';
                }
            }
        },
        copyMondayToAll() {
            const mon = this.form.operating_hours['1'] || { open: '09:00', close: '21:00', closed: false };
            for (let d = 0; d <= 6; d++) {
                const key = String(d);
                this.form.operating_hours[key] = {
                    open: mon.open || '09:00',
                    close: mon.close || '21:00',
                    closed: !!mon.closed,
                };
            }
        },
        weekDayOptions: [
            { value: 0, label: 'Sun' },
            { value: 1, label: 'Mon' },
            { value: 2, label: 'Tue' },
            { value: 3, label: 'Wed' },
            { value: 4, label: 'Thu' },
            { value: 5, label: 'Fri' },
            { value: 6, label: 'Sat' },
        ],
        isoDate(d) {
            const pad = (n) => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        },
        normalizeBlockForm(block) {
            const b = Object.assign({}, block || {});
            b.block_member = b.block_member !== false && b.block_member !== 0 && b.block_member !== '0';
            b.block_guest = b.block_guest !== false && b.block_guest !== 0 && b.block_guest !== '0';
            let repeat = String(b.repeat || '').toLowerCase();
            const days = Array.isArray(b.days_of_week)
                ? b.days_of_week.map((d) => Number(d)).filter((d) => d >= 0 && d <= 6)
                : [];
            b.days_of_week = days;
            if (!['once', 'range', 'weekly'].includes(repeat)) {
                if (days.length) repeat = 'weekly';
                else if (b.start_date && b.end_date && b.start_date !== b.end_date) repeat = 'range';
                else repeat = 'once';
            }
            b.repeat = repeat;
            if (!b.date && b.start_date) b.date = b.start_date;
            if (!b.start_date && b.date) b.start_date = b.date;
            if (!b.end_date && b.date) b.end_date = b.date;
            if (repeat === 'once' && b.date) {
                b.start_date = b.date;
                b.end_date = b.date;
            }
            if (!Array.isArray(b.days_of_week)) b.days_of_week = [];
            return b;
        },
        onBlockRepeatChange(block) {
            if (block.repeat === 'once') {
                if (!block.date) block.date = block.start_date || this.isoDate(new Date());
                block.start_date = block.date;
                block.end_date = block.date;
                block.days_of_week = [];
            } else if (block.repeat === 'range') {
                if (!block.start_date) block.start_date = block.date || this.isoDate(new Date());
                if (!block.end_date) block.end_date = block.start_date;
                block.date = block.start_date;
                block.days_of_week = [];
            } else if (block.repeat === 'weekly') {
                if (!block.start_date) block.start_date = block.date || this.isoDate(new Date());
                if (!block.end_date) {
                    const end = new Date(block.start_date + 'T12:00:00');
                    end.setMonth(end.getMonth() + 9);
                    block.end_date = this.isoDate(end);
                }
                block.date = block.start_date;
                if (!(block.days_of_week || []).length) block.days_of_week = [1, 2, 3, 4, 5];
            }
        },
        toggleBlockDay(block, day, checked) {
            day = Number(day);
            if (!Array.isArray(block.days_of_week)) block.days_of_week = [];
            if (checked) {
                if (!block.days_of_week.includes(day)) block.days_of_week.push(day);
                block.days_of_week.sort((a, b) => a - b);
            } else {
                block.days_of_week = block.days_of_week.filter((d) => d !== day);
            }
        },
        addBlockedTime(repeat) {
            repeat = repeat || 'once';
            const start = new Date();
            start.setDate(start.getDate() + 1);
            const startStr = this.isoDate(start);
            let endStr = startStr;
            let days = [];
            let reason = '';
            let startTime = '09:00';
            let endTime = '12:00';
            if (repeat === 'range') {
                const end = new Date(start);
                end.setDate(end.getDate() + 6);
                endStr = this.isoDate(end);
                reason = 'Holiday / closed period';
            } else if (repeat === 'weekly') {
                const end = new Date(start);
                end.setMonth(end.getMonth() + 9);
                endStr = this.isoDate(end);
                days = [1, 2, 3, 4, 5];
                startTime = '08:00';
                endTime = '15:00';
                reason = 'School hours';
            }
            this.form.blocked_times.push(this.normalizeBlockForm({
                repeat,
                date: startStr,
                start_date: startStr,
                end_date: endStr,
                days_of_week: days,
                start_time: startTime,
                end_time: endTime,
                reason,
                block_member: true,
                block_guest: true,
            }));
        },
        addSchoolHoursBlock() {
            this.addBlockedTime('weekly');
            const last = this.form.blocked_times[this.form.blocked_times.length - 1];
            if (last) {
                last.reason = 'School hours';
                last.start_time = '08:00';
                last.end_time = '15:00';
                last.days_of_week = [1, 2, 3, 4, 5];
            }
        },
        removeBlockedTime(idx) {
            this.form.blocked_times.splice(idx, 1);
        },
        toggleManager(id, checked) {
            id = Number(id);
            if (!Array.isArray(this.form.manager_ids)) this.form.manager_ids = [];
            if (checked) {
                if (!this.form.manager_ids.includes(id)) this.form.manager_ids.push(id);
            } else {
                this.form.manager_ids = this.form.manager_ids.filter((x) => x !== id);
            }
        },
        async save() {
            if (!this.validateStep(1)) {
                window.facShowStep(1);
                return;
            }
            if (!this.validateStep(2)) {
                window.facShowStep(2);
                return;
            }
            if (!this.validateStep(3)) {
                window.facShowStep(3);
                return;
            }
            this.syncDescriptionFromEditor();
            this.saving = true;
            this.error = '';
            const payload = {
                ...this.form,
                action: 'save',
                csrf_token: this.csrf,
                capacity: this.form.capacity === '' ? null : this.form.capacity,
                addons: (this.addons || []).map((a) => ({
                    title: a.title,
                    description: a.description,
                    price: a.price,
                    kind: a.kind,
                    package_items: String(a.package_items_text || '').split(/\r?\n/),
                    active: !!a.active,
                })),
            };
            if (this.facilityId) payload.id = this.facilityId;
            const fd = new FormData();
            fd.append('payload', JSON.stringify(payload));
            this.newImageFiles.forEach((file) => fd.append('facility_images[]', file));
            try {
                const res = await fetch(this.apiUrl + '?action=save', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd,
                });
                const data = await res.json();
                if (!data.success) {
                    this.error = data.message || 'Save failed';
                    window.facShowStep(4);
                    return;
                }
                window.location.href = <?= json_encode(rtrim($adminBase, '/') . '/?page=facilities') ?>;
            } catch (e) {
                this.error = 'Network error';
                window.facShowStep(4);
            } finally {
                this.saving = false;
            }
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
